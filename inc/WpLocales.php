<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Vorgefertigte Sprachliste aus dem WordPress-Core.
 *
 * Basis ist `wp_get_available_translations()` (die offizielle Locale-Liste von
 * WordPress.org, von Core wochenweise gecacht) plus `en_US` (die Basis-Locale,
 * die in der Liste fehlt). Daraus leiten wir Code (ISO-639-Kürzel), Bezeichnung
 * (nativer Name) und hreflang ab, damit im Sprachen-Tab nur echte WP-Sprachen
 * wählbar sind, keine frei erfundenen.
 */
final class WpLocales
{
    /**
     * @return array<string, array{label: string, code: string, hreflang: string}>
     *         Key = WP-Locale (de_DE), sortiert nach nativem Namen.
     */
    public static function available(): array
    {
        // Basis: eingebaute Fallback-Liste (immer verfügbar, kein Netzzugriff).
        $out = self::fallback();

        // Bereits gecachte Vollliste mitnehmen, OHNE einen HTTP-Call auszulösen.
        // wp_get_available_translations() würde bei leerem/altem Transient live zu
        // api.wordpress.org gehen und auf abgeschotteten Hostings den Sprachen-Tab
        // hängen lassen. Darum nur den Transient lesen, falls WP ihn schon hat.
        $cached = get_site_transient('available_translations');
        if (is_array($cached)) {
            foreach ($cached as $locale => $data) {
                $iso = is_array($data['iso'] ?? null) ? $data['iso'] : [];
                $code = self::deriveCode((string) $locale, $iso);
                $out[(string) $locale] = [
                    'label' => (string) ($data['native_name'] ?? $locale),
                    'code' => $code,
                    'hreflang' => $code,
                ];
            }
        }

        // Lokal installierte Sprachen (kein Netzzugriff) ergänzen.
        if (function_exists('get_available_languages')) {
            foreach (get_available_languages() as $locale) {
                if (! isset($out[$locale])) {
                    $code = self::deriveCode((string) $locale, []);
                    $out[(string) $locale] = ['label' => (string) $locale, 'code' => $code, 'hreflang' => $code];
                }
            }
        }

        uasort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Eingebaute Liste gängiger Sprachen (native Namen), damit der Sprachen-Tab
     * auch ohne Netzzugriff auf api.wordpress.org nutzbar bleibt.
     *
     * @return array<string, array{label: string, code: string, hreflang: string}>
     */
    private static function fallback(): array
    {
        $locales = [
            'en_US' => ['English', 'en'],
            'en_GB' => ['English (UK)', 'en'],
            'de_DE' => ['Deutsch', 'de'],
            'de_AT' => ['Deutsch (Österreich)', 'de'],
            'de_CH' => ['Deutsch (Schweiz)', 'de'],
            'fr_FR' => ['Français', 'fr'],
            'es_ES' => ['Español', 'es'],
            'it_IT' => ['Italiano', 'it'],
            'nl_NL' => ['Nederlands', 'nl'],
            'pt_PT' => ['Português', 'pt'],
            'pt_BR' => ['Português do Brasil', 'pt'],
            'pl_PL' => ['Polski', 'pl'],
            'ru_RU' => ['Русский', 'ru'],
            'uk' => ['Українська', 'uk'],
            'cs_CZ' => ['Čeština', 'cs'],
            'sk_SK' => ['Slovenčina', 'sk'],
            'hu_HU' => ['Magyar', 'hu'],
            'ro_RO' => ['Română', 'ro'],
            'tr_TR' => ['Türkçe', 'tr'],
            'da_DK' => ['Dansk', 'da'],
            'sv_SE' => ['Svenska', 'sv'],
            'nb_NO' => ['Norsk bokmål', 'nb'],
            'fi' => ['Suomi', 'fi'],
            'el' => ['Ελληνικά', 'el'],
            'zh_CN' => ['简体中文', 'zh'],
            'ja' => ['日本語', 'ja'],
            'ar' => ['العربية', 'ar'],
        ];

        $out = [];
        foreach ($locales as $locale => [$label, $code]) {
            $out[$locale] = ['label' => $label, 'code' => $code, 'hreflang' => $code];
        }

        return $out;
    }

    /**
     * Metadaten zu einer einzelnen Locale (oder null).
     *
     * @return array{label: string, code: string, hreflang: string}|null
     */
    public static function get(string $locale): ?array
    {
        $all = self::available();

        return $all[$locale] ?? null;
    }

    /**
     * @param array<int, string> $iso
     */
    private static function deriveCode(string $locale, array $iso): string
    {
        // ISO-639-1 (zweistellig) bevorzugen, sonst erster ISO-Eintrag, sonst
        // der Sprachteil der Locale. Hinweis: WP indexiert die iso-Arrays
        // 1-basiert (Keys 1/2/3), darum über die Werte iterieren, nicht $iso[0].
        foreach ($iso as $candidate) {
            if (strlen((string) $candidate) === 2) {
                return (string) $candidate;
            }
        }

        $values = array_values($iso);
        if ($values !== []) {
            return (string) $values[0];
        }

        return strtolower(substr($locale, 0, 2));
    }
}
