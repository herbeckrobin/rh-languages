<?php

/**
 * Globale Helper des Sprach-Moduls. Von der Haupt-Plugin-Datei per require
 * geladen, damit sie unabhängig vom Boot-Zeitpunkt verfügbar sind.
 *
 * Alle delegieren an den Languages-Singleton und degradieren sauber, wenn das
 * Modul (noch) nicht gebootet oder keine Sprache konfiguriert ist.
 */

declare(strict_types=1);

use RhLanguages\Languages;

if (! function_exists('rh_lang_current')) {
    /**
     * Aktive Sprache des laufenden Requests (Code, z.B. `de`).
     */
    function rh_lang_current(): string
    {
        $api = Languages::instance();

        return $api !== null ? $api->current() : 'en';
    }
}

if (! function_exists('rh_lang_default')) {
    /**
     * Code der Default-Sprache (ohne URL-Prefix).
     */
    function rh_lang_default(): string
    {
        $api = Languages::instance();

        return $api !== null ? $api->defaultCode() : 'en';
    }
}

if (! function_exists('rh_lang_all')) {
    /**
     * Alle konfigurierten Sprachen als Value-Objects.
     *
     * @return array<int, \RhLanguages\Language>
     */
    function rh_lang_all(): array
    {
        $api = Languages::instance();

        return $api !== null ? $api->config()->all() : [];
    }
}

if (! function_exists('rh_lang_of_post')) {
    /**
     * Sprache eines Posts (Code).
     */
    function rh_lang_of_post(int $postId): string
    {
        $api = Languages::instance();

        return $api !== null ? $api->langOfPost($postId) : rh_lang_default();
    }
}

if (! function_exists('rh_lang_group_of')) {
    /**
     * Übersetzungsgruppen-Term-ID eines Posts, oder null.
     */
    function rh_lang_group_of(int $postId): ?int
    {
        $api = Languages::instance();

        return $api !== null ? $api->groupOfPost($postId) : null;
    }
}

if (! function_exists('rh_lang_get_translation')) {
    /**
     * Post-ID des Gegenstücks in Sprache `$code`, oder null.
     *
     * @param array<int, string> $statuses
     */
    function rh_lang_get_translation(int $postId, string $code, array $statuses = ['publish']): ?int
    {
        $api = Languages::instance();

        return $api !== null ? $api->getTranslation($postId, $code, $statuses) : null;
    }
}

if (! function_exists('rh_lang_translations')) {
    /**
     * Alle vorhandenen Sprachversionen: [ 'en' => 12, 'de' => 34 ].
     *
     * @param array<int, string> $statuses
     * @return array<string, int>
     */
    function rh_lang_translations(int $postId, array $statuses = ['publish']): array
    {
        $api = Languages::instance();

        return $api !== null ? $api->translations($postId, $statuses) : [];
    }
}

if (! function_exists('rh_lang_home_url')) {
    /**
     * Startseiten-URL einer Sprache (Default ohne Prefix, sonst mit).
     */
    function rh_lang_home_url(?string $code = null): string
    {
        $api = Languages::instance();
        if ($api === null) {
            return home_url('/');
        }

        $code ??= $api->current();
        $base = $api->homeRoot();

        // Rohe Wurzel nutzen (nicht home_url), sonst würde der home_url-Filter die
        // Zielsprache je nach aktuellem Kontext falsch mit einrechnen.
        return $code === $api->defaultCode()
            ? $base . '/'
            : $base . '/' . $code . '/';
    }
}

if (! function_exists('rh_lang_links')) {
    /**
     * Die Sprach-Links des aktuellen (oder eines gegebenen) Objekts, fertig zum
     * Loopen im Theme-Template. Pro konfigurierter Sprache:
     *   [ 'code', 'label', 'hreflang', 'url', 'current' => bool ]
     *
     * Auf einer Einzelseite zeigt `url` auf das Gegenstück derselben Gruppe,
     * sonst (Archiv/Startseite ohne eigenes Objekt) auf die Sprach-Startseite.
     *
     * @param int|null $postId Objekt-ID, oder null für das aktuelle.
     * @return array<int, array{code: string, label: string, hreflang: string, url: string, current: bool}>
     */
    function rh_lang_links(?int $postId = null): array
    {
        $api = Languages::instance();
        if ($api === null || ! $api->config()->isConfigured()) {
            return [];
        }

        $current = $api->current();
        $queried = $postId ?? (is_singular() ? (int) get_queried_object_id() : 0);
        $translations = $queried > 0 ? $api->translations($queried) : [];

        $out = [];
        foreach ($api->config()->all() as $language) {
            $code = $language->code;

            if ($queried > 0 && isset($translations[$code])) {
                $url = get_permalink($translations[$code]);
            } else {
                $url = rh_lang_home_url($code);
            }
            if (! is_string($url) || $url === '') {
                continue;
            }

            $out[] = [
                'code' => $code,
                'label' => $language->label,
                'hreflang' => $language->hreflang,
                'url' => $url,
                'current' => $code === $current,
            ];
        }

        return $out;
    }
}

if (! function_exists('rh_lang_switcher_html')) {
    /**
     * Fertiges `<ul>`-Markup des Switchers (dieselbe Ausgabe wie Block/Shortcode).
     * Wird intern von render.php und dem Shortcode genutzt, im Theme nur nötig,
     * wenn man das Standard-Markup 1:1 will, sonst lieber rh_lang_links() loopen.
     *
     * @param string $wrapperAttributes Fertige Attribute fürs <ul> (z.B. aus
     *                                   get_block_wrapper_attributes()); leer = eigene Klasse.
     */
    function rh_lang_switcher_html(string $wrapperAttributes = ''): string
    {
        $links = rh_lang_links();
        if (count($links) < 2) {
            return '';
        }

        $items = '';
        foreach ($links as $link) {
            $class = 'rh-language-switcher__item' . ($link['current'] ? ' is-current' : '');
            $aria = $link['current'] ? ' aria-current="true"' : '';
            $items .= '<li class="' . esc_attr($class) . '">'
                . '<a href="' . esc_url($link['url']) . '" hreflang="' . esc_attr($link['hreflang']) . '"' . $aria . '>'
                . esc_html($link['label'])
                . '</a></li>';
        }

        $attributes = $wrapperAttributes !== '' ? $wrapperAttributes : 'class="rh-language-switcher"';

        return '<ul ' . $attributes . '>' . $items . '</ul>';
    }
}
