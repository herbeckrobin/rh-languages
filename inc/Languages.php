<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Laufzeit-API des Moduls und öffentlicher Service für andere rh-Module.
 *
 * Hält die Config, den Taxonomie-Helper und die aktive Sprache des laufenden
 * Requests. Kapselt alle Lese-/Schreibzugriffe auf die Taxonomien, damit das
 * Datenmodell an genau einer Stelle lebt. Als Singleton ansprechbar (für die
 * globalen rh_lang_* Helper) und zusätzlich in der Core-Service-Registry
 * registriert (für Cross-Modul-Zugriff, z.B. rh-seo).
 */
final class Languages
{
    /**
     * NICHT-öffentliches WP_Query-Arg, mit dem interne Abfragen den Sprachfilter
     * überspringen (Switcher, Sitemap, getTranslation, Migration). Bewusst kein
     * public Query-Var, damit es nicht per URL (?...) gesetzt werden kann.
     */
    public const QUERY_SKIP = 'rhlang_skip';

    private static ?self $instance = null;

    private ?string $current = null;

    /** @var array<string, array<string, int>> Request-Memoization für translations(). */
    private array $translationCache = [];

    public function __construct(
        private readonly Config $config,
        private readonly Taxonomy $taxonomy,
    ) {
    }

    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function taxonomy(): Taxonomy
    {
        return $this->taxonomy;
    }

    // --- Aktive Sprache ---

    public function current(): string
    {
        return $this->current ?? $this->config->defaultCode();
    }

    public function setCurrent(string $code): void
    {
        $this->current = $this->config->has($code) ? $code : $this->config->defaultCode();
    }

    public function defaultCode(): string
    {
        return $this->config->defaultCode();
    }

    /**
     * Rohe Startseiten-URL OHNE Sprach-Prefix und ohne den home_url-Filter
     * (liest die `home`-Option, nicht home_url()). Interner Baustein für alle
     * Prefix-Berechnungen, damit der home_url-Filter (der die nackte Startseite
     * pro Sprache umbiegt) nicht in unsere eigenen URL-Rechnungen kaskadiert.
     * Ohne Trailing-Slash, mit korrektem Request-Scheme.
     */
    public function homeRoot(): string
    {
        // Schema aus der gespeicherten home-Option übernehmen (nicht set_url_scheme,
        // das kippt in CLI/Nicht-SSL-Kontexten auf http und bricht dann den
        // Prefix-Vergleich gegen die https-Permalinks).
        return untrailingslashit((string) get_option('home'));
    }

    public function isCurrentDefault(): bool
    {
        return $this->current() === $this->config->defaultCode();
    }

    // --- Lookups ---

    public function langOfPost(int $postId): string
    {
        $terms = get_the_terms($postId, Taxonomy::TAX_LANG);
        if (is_array($terms) && $terms !== []) {
            return (string) $terms[0]->slug;
        }

        return $this->config->defaultCode();
    }

    public function groupOfPost(int $postId): ?int
    {
        $terms = get_the_terms($postId, Taxonomy::TAX_GROUP);
        if (is_array($terms) && $terms !== []) {
            return (int) $terms[0]->term_id;
        }

        return null;
    }

    /**
     * Post-ID des Gegenstücks in Sprache `$code`, oder null.
     *
     * @param array<int, string> $statuses Zu berücksichtigende Post-Status.
     */
    public function getTranslation(int $postId, string $code, array $statuses = ['publish']): ?int
    {
        return $this->translations($postId, $statuses)[$code] ?? null;
    }

    /**
     * Alle vorhandenen Sprachversionen: [ 'en' => 12, 'de' => 34 ].
     *
     * EINE Query pro Post (alle Gruppen-Mitglieder auf einmal), plus Request-
     * Memoization, damit sich Head (hreflang), Switcher, Editor-Sidebar und die
     * Post-Listen-Spalte die Auflösung teilen statt je Sprache eine Query zu
     * feuern (vermeidet N+1).
     *
     * @param array<int, string> $statuses
     * @return array<string, int>
     */
    public function translations(int $postId, array $statuses = ['publish']): array
    {
        $cacheKey = $postId . '|' . implode(',', $statuses);
        if (isset($this->translationCache[$cacheKey])) {
            return $this->translationCache[$cacheKey];
        }

        $group = $this->groupOfPost($postId);

        // Ohne Gruppe ist der Post seine einzige Sprachversion.
        if ($group === null) {
            return $this->translationCache[$cacheKey] = [$this->langOfPost($postId) => $postId];
        }

        $members = get_posts([
            'post_type' => 'any',
            'post_status' => $statuses,
            'numberposts' => -1,
            'fields' => 'ids',
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
            self::QUERY_SKIP => true, // eigenen Sprachfilter aushebeln
            'tax_query' => [
                [
                    'taxonomy' => Taxonomy::TAX_GROUP,
                    'field' => 'term_id',
                    'terms' => $group,
                ],
            ],
        ]);

        $out = [];
        foreach ($members as $memberId) {
            $memberId = (int) $memberId;
            // langOfPost nutzt get_the_terms (WP-objekt-gecacht), günstig.
            $out[$this->langOfPost($memberId)] = $memberId;
        }

        return $this->translationCache[$cacheKey] = $out;
    }

    // --- Zuweisung ---

    public function assignLanguage(int $postId, string $code): void
    {
        if (! $this->config->has($code)) {
            $code = $this->config->defaultCode();
        }
        wp_set_object_terms($postId, $code, Taxonomy::TAX_LANG, false);
        $this->translationCache = [];
    }

    public function assignGroup(int $postId, int $groupTermId): void
    {
        wp_set_object_terms($postId, [$groupTermId], Taxonomy::TAX_GROUP, false);
        $this->translationCache = [];
    }

    /**
     * Stellt sicher, dass der Post einer Übersetzungsgruppe angehört, und gibt
     * die Group-Term-ID zurück (legt bei Bedarf eine neue Gruppe an).
     */
    public function ensureGroup(int $postId): ?int
    {
        $existing = $this->groupOfPost($postId);
        if ($existing !== null) {
            return $existing;
        }

        $group = $this->taxonomy->createGroupTerm();
        if ($group !== null) {
            $this->assignGroup($postId, $group);
        }

        return $group;
    }
}
