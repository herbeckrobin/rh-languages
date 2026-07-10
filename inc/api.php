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
