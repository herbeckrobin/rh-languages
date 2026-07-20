<?php

declare(strict_types=1);

namespace RhLanguages;

use WP_Query;

/**
 * Trennt die Sprachen auf dem Frontend.
 *
 * Hängt für übersetzbare Post-Types eine `tax_query` auf die aktive Sprache an,
 * greift dadurch auch für `new WP_Query` in Theme-Blocks (solange die kein
 * `suppress_filters=true` setzen). Opt-out pro Query über das nicht-öffentliche
 * Arg `Languages::QUERY_SKIP` (Switcher, Sitemap, Cross-Sprach-Listen).
 */
final class Query
{
    /**
     * Strukturelle Bausteine: ihre Sprachwahl macht komplett der Render-Swap
     * (slug/ref), NICHT der Query-Filter. Sie dürfen nie sprachgefiltert werden,
     * sonst versteckt der Filter Sekundärsprach-Parts vor dem Site-Editor (REST
     * ist nicht is_admin()) und "Element existiert nicht".
     */
    private const STRUCTURAL_TYPES = ['wp_navigation', 'wp_template_part'];

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_action('pre_get_posts', [$this, 'filterByLanguage']);

        // Die Core-Sitemap muss ALLE Sprachen listen (jede Sprachversion mit
        // eigener URL), nicht nur die Default-Sprache. Sonst fehlen die
        // Übersetzungen in der Sitemap.
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemapAllLanguages']);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function sitemapAllLanguages(array $args): array
    {
        $args[Languages::QUERY_SKIP] = true;

        return $args;
    }

    public function filterByLanguage(WP_Query $query): void
    {
        // Nur Frontend filtern. Admin UND WP-CLI sehen alle Sprachen (sonst
        // versteckt der Filter Nicht-Default-Posts vor `wp post list` u.ä.).
        if (is_admin() || (defined('WP_CLI') && WP_CLI) || ! $this->languages->config()->isMultilingual()) {
            return;
        }

        // Opt-out über ein NICHT-öffentliches Arg (rhlang_skip). Bewusst nicht der
        // public Query-Var rh_lang, sonst könnte ein Besucher per ?rh_lang=all den
        // Sprachfilter an jeder URL aushebeln.
        if ($query->get(Languages::QUERY_SKIP)) {
            return;
        }

        // appliesTo() hat für is_tax-Archive das Archiv-Subjekt bereits aufgelöst
        // und gecached (siehe dort), bevor wir hier den rh_lang-Clause anhängen.
        if (! $this->appliesTo($query)) {
            return;
        }

        $taxQuery = $query->get('tax_query');
        $taxQuery = is_array($taxQuery) ? $taxQuery : [];

        $current = $this->languages->current();

        if ($current === $this->languages->defaultCode()) {
            // Standardsprache schließt Posts OHNE Sprach-Term mit ein. So bleiben
            // noch nicht migrierte Inhalte immer sichtbar (unter der Default-
            // Sprache), auch wenn die Migration nie lief oder unvollständig blieb.
            // Ohne das würde jeder termlose Post vom Frontend verschwinden.
            $taxQuery[] = [
                'relation' => 'OR',
                [
                    'taxonomy' => Taxonomy::TAX_LANG,
                    'field' => 'slug',
                    'terms' => $current,
                ],
                [
                    'taxonomy' => Taxonomy::TAX_LANG,
                    'operator' => 'NOT EXISTS',
                ],
            ];
        } else {
            $taxQuery[] = [
                'taxonomy' => Taxonomy::TAX_LANG,
                'field' => 'slug',
                'terms' => $current,
            ];
        }

        $query->set('tax_query', $taxQuery);
    }

    /**
     * Gilt der Sprachfilter für diese Query? Nur, wenn sie (auch) übersetzbare
     * Post-Types betrifft.
     */
    private function appliesTo(WP_Query $query): bool
    {
        $translatable = $this->languages->taxonomy()->postTypes();

        // Custom-Taxonomie-Archiv (is_tax, NICHT Category/Tag): den Filter nur
        // anwenden, wenn rh_lang WIRKLICH an einem Post-Type der abgefragten
        // Taxonomie hängt. Sonst filtert der Sprachfilter Archive von CPTs ohne
        // rh_lang-Term leer (z.B. `series` auf `artwork`: rh_lang ist an artwork
        // nicht registriert, deren Posts können nie einen Sprach-Term tragen, der
        // strikte rh_lang-Clause wirft auf /de/ alle raus → found=0). Maßgeblich
        // sind die WIRKLICH registrierten object_types von rh_lang, NICHT
        // postTypes(): letzteres ist zeitabhängig und listet auch CPTs, die zur
        // Registrierungszeit von rh_lang (init) noch nicht existierten. Der
        // get_queried_object()-Aufruf cached zugleich das korrekte Archiv-Subjekt
        // aus der noch unveränderten tax_query, bevor filterByLanguage den Clause
        // anhängt (sonst reiht WP beim zweiten parse_tax_query in get_posts() die
        // query-var-Taxonomie hinter unseren tax_query und get_queried_object()
        // nähme rh_lang als Subjekt → Template-Hierarchie fällt auf index).
        if ($query->is_tax()) {
            $object = $query->get_queried_object();
            if ($object instanceof \WP_Term) {
                $archiveTax = get_taxonomy($object->taxonomy);
                $langTax = get_taxonomy(Taxonomy::TAX_LANG);
                $archiveTypes = $archiveTax ? (array) $archiveTax->object_type : [];
                $attached = $langTax ? (array) $langTax->object_type : [];

                return (bool) array_intersect($archiveTypes, $attached);
            }
        }

        $postType = $query->get('post_type');

        // Leerer post_type: WP-Default ist 'post' (bei is_singular kann es auch
        // eine Seite sein, dann greift der pagename-Zweig unten). 'any' zählt.
        if ($postType === '' || $postType === []) {
            // Feeds/Suche/Archive ohne expliziten Typ: nur anwenden, wenn 'post'
            // übersetzbar ist. Singular-Requests tragen den Typ ohnehin.
            return in_array('post', $translatable, true);
        }

        if ($postType === 'any') {
            return true;
        }

        $types = is_array($postType) ? $postType : [$postType];
        foreach ($types as $type) {
            // Strukturelle Typen nie sprachfiltern (Render-Swap regelt die Sprache).
            if (in_array($type, self::STRUCTURAL_TYPES, true)) {
                continue;
            }
            if (in_array($type, $translatable, true)) {
                return true;
            }
        }

        return false;
    }
}
