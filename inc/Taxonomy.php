<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Registriert die zwei versteckten Taxonomien, die das ganze Datenmodell tragen.
 *
 * - `rh_lang`: ein Term pro Sprache (`en`, `de`). Jeder Post genau ein Term,
 *   das ist die Sprachzuordnung.
 * - `rh_lang_group`: ein Term pro Übersetzungsgruppe. Alle Sprachversionen eines
 *   Inhalts teilen denselben Group-Term (reine Term-Beziehung, kein serialisierter
 *   Blob, keine UUID). Beim Löschen eines Posts räumt Core die Beziehung auf.
 *
 * Beide sind `public=false`, `show_ui=false`, `rewrite=false`, `query_var=false`:
 * sie sind reine interne Infrastruktur, nie im Editor sichtbar, nie in der URL.
 */
final class Taxonomy
{
    public const TAX_LANG = 'rh_lang';
    public const TAX_GROUP = 'rh_lang_group';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Die Post-Types, die übersetzbar sind. Default: alle öffentlichen
     * (ohne Anhänge). Über den Filter eingrenzbar oder erweiterbar
     * (z.B. `wp_navigation` in Stufe 6).
     *
     * @return array<int, string>
     */
    public function postTypes(): array
    {
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);

        /**
         * Liste der übersetzbaren Post-Types.
         *
         * @param array<int, string> $types
         */
        $filtered = apply_filters('rh-blueprint/languages/post_types', array_values($types));

        return is_array($filtered) ? array_values(array_unique(array_map('strval', $filtered))) : array_values($types);
    }

    /**
     * Beide Taxonomien registrieren. Läuft auf `init` (über den Core-Hook).
     */
    public function register(): void
    {
        $postTypes = $this->postTypes();

        $hidden = [
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'show_admin_column' => false,
            'show_tagcloud' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ];

        register_taxonomy(self::TAX_LANG, $postTypes, $hidden + [
            'labels' => ['name' => __('Sprachen', 'rh-languages')],
        ]);

        register_taxonomy(self::TAX_GROUP, $postTypes, $hidden + [
            'labels' => ['name' => __('Übersetzungsgruppen', 'rh-languages')],
        ]);
    }

    /**
     * Für jede konfigurierte Sprache den rh_lang-Term sicherstellen.
     * Idempotent, günstig, kann bei Bedarf mehrfach laufen.
     */
    public function ensureLanguageTerms(): void
    {
        foreach ($this->config->all() as $language) {
            if (term_exists($language->code, self::TAX_LANG)) {
                continue;
            }
            wp_insert_term($language->label, self::TAX_LANG, ['slug' => $language->code]);
        }
    }

    /**
     * Legt einen frischen Übersetzungsgruppen-Term an und gibt seine ID zurück.
     * Der Term-Name ist nur ein interner Marker.
     */
    public function createGroupTerm(): ?int
    {
        $slug = 'grp-' . substr(md5(uniqid('', true)), 0, 12);
        $result = wp_insert_term($slug, self::TAX_GROUP, ['slug' => $slug]);

        if (is_wp_error($result)) {
            return null;
        }

        return (int) $result['term_id'];
    }
}
