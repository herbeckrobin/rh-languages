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

    /** @var array<string, ?int> Request-Memoization für templatePartPostId(). */
    private array $templatePartCache = [];

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

    /**
     * Der aktuelle Request-Pfad je konfigurierter Sprache neu geprefixt, als
     * [ code => url ]. Für Archive und andere Nicht-Singular-Kontexte (Taxonomie,
     * Kategorie, Datum, Autor): das Gegenstück liegt unter demselben Pfad, nur mit
     * bzw. ohne Sprach-Prefix. Geteilte Rechnung für die hreflang-Alternates (Head)
     * UND den Sprach-Switcher (rh_lang_links), damit beide nicht auseinanderlaufen.
     *
     * @return array<string, string>
     */
    public function currentPathUrls(): array
    {
        $base = $this->currentBasePath();
        $root = $this->homeRoot();

        $urls = [];
        foreach ($this->config->all() as $language) {
            $urls[$language->code] = $language->isDefault
                ? $root . '/' . $base
                : $root . '/' . $language->code . '/' . $base;
        }

        return $urls;
    }

    /**
     * Aktueller Request-Pfad ohne Home-Basis und ohne führenden Sprachcode
     * (mit Trailing-Slash, leer für die Wurzel).
     */
    private function currentBasePath(): string
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $homePath = trim((string) wp_parse_url($this->homeRoot(), PHP_URL_PATH), '/');
        $path = trim($path, '/');

        if ($homePath !== '' && str_starts_with($path, $homePath)) {
            $path = trim(substr($path, strlen($homePath)), '/');
        }

        $segments = $path === '' ? [] : explode('/', $path);
        if (($segments[0] ?? '') !== '' && $this->config->has($segments[0])) {
            array_shift($segments);
        }

        $base = implode('/', $segments);

        return $base === '' ? '' : $base . '/';
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

        // Bewusst die übersetzbaren Post-Types EXPLIZIT, nicht 'any': 'any' schließt
        // Post-Types mit exclude_from_search=true aus (u.a. wp_navigation), sonst
        // fände der Menü-Swap die übersetzten Menüs nie. Betrifft jeden solchen Typ.
        $members = get_posts([
            'post_type' => $this->taxonomy->postTypes(),
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

    /**
     * wp_template_part-Post-ID per Slug + Theme (spiegelt die Query aus
     * render_block_core_template_part). Null bei reinem Theme-Datei-Part.
     *
     * Request-memoisiert (mehrere template-part-Blöcke mit gleichem Slug pro Seite
     * feuern sonst je eine Query). $useCache=false erzwingt eine frische Query,
     * z.B. für den Re-Check unter einem Lock beim Materialisieren.
     */
    public function templatePartPostId(string $slug, ?string $theme = null, bool $useCache = true): ?int
    {
        $theme ??= get_stylesheet();
        $cacheKey = $theme . '|' . $slug;

        if ($useCache && array_key_exists($cacheKey, $this->templatePartCache)) {
            return $this->templatePartCache[$cacheKey];
        }

        $query = new \WP_Query([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name__in' => [$slug],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
            self::QUERY_SKIP => true,
            'tax_query' => [
                [
                    'taxonomy' => 'wp_theme',
                    'field' => 'name',
                    'terms' => $theme,
                ],
            ],
        ]);

        $result = $query->posts !== [] ? (int) $query->posts[0] : null;

        return $this->templatePartCache[$cacheKey] = $result;
    }

    // --- Zuweisung ---

    public function assignLanguage(int $postId, string $code): void
    {
        if (! $this->config->has($code)) {
            $code = $this->config->defaultCode();
        }
        wp_set_object_terms($postId, $code, Taxonomy::TAX_LANG, false);
        $this->translationCache = [];
        $this->templatePartCache = [];
    }

    public function assignGroup(int $postId, int $groupTermId): void
    {
        wp_set_object_terms($postId, [$groupTermId], Taxonomy::TAX_GROUP, false);
        $this->translationCache = [];
        $this->templatePartCache = [];
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
