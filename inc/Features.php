<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Feature-Schalter des Moduls. Jede Funktion ist im Sprachen-Tab an-/abschaltbar,
 * gespeichert in der Gruppe `languages` unter `feature_<key>`.
 *
 * Kern-Sprachtrennung (Taxonomien, Query-Filter, Routing) ist bewusst KEIN
 * Schalter, das ist das Fundament. Schaltbar ist nur, was optionaler Komfort
 * oder Frontend-Ausgabe ist.
 */
final class Features
{
    public const HREFLANG = 'hreflang';
    public const HTML_LANG = 'html_lang';
    public const VIEW_TRANSITIONS = 'view_transitions';
    public const EDITOR_SIDEBAR = 'editor_sidebar';
    public const POST_COLUMN = 'post_column';
    public const NAV_PER_LANGUAGE = 'nav_per_language';
    public const TEMPLATE_PART_PER_LANGUAGE = 'template_part_per_language';
    public const FRONT_PAGE_PER_LANGUAGE = 'front_page_per_language';
    public const LOCALE_SWITCH = 'locale_switch';
    public const AUTO_SWITCHER = 'auto_switcher';

    /**
     * Schalter mit Default und Beschreibung (für die Settings-UI).
     *
     * @return array<string, array{label: string, description: string, default: bool}>
     */
    public static function all(): array
    {
        return [
            self::HREFLANG => [
                'label' => __('hreflang-Tags', 'rh-languages'),
                'description' => __('Alternate-Links (+ x-default) im <head> für Suchmaschinen.', 'rh-languages'),
                'default' => true,
            ],
            self::HTML_LANG => [
                'label' => __('html-lang-Attribut', 'rh-languages'),
                'description' => __('Setzt <html lang> auf die aktive Sprache.', 'rh-languages'),
                'default' => true,
            ],
            self::LOCALE_SWITCH => [
                'label' => __('Theme-Texte übersetzen', 'rh-languages'),
                'description' => __('Schaltet die Locale pro Sprache um, damit Theme-Strings (__()) und Datumsformate folgen.', 'rh-languages'),
                'default' => true,
            ],
            self::FRONT_PAGE_PER_LANGUAGE => [
                'label' => __('Startseite pro Sprache', 'rh-languages'),
                'description' => __('/de/ zeigt die übersetzte Startseite statt der Standard-Startseite.', 'rh-languages'),
                'default' => true,
            ],
            self::NAV_PER_LANGUAGE => [
                'label' => __('Menü pro Sprache', 'rh-languages'),
                'description' => __('Der Navigations-Block nutzt das übersetzte Menü der aktiven Sprache.', 'rh-languages'),
                'default' => true,
            ],
            self::TEMPLATE_PART_PER_LANGUAGE => [
                'label' => __('Template-Parts pro Sprache', 'rh-languages'),
                'description' => __('Footer, Header und andere Template-Parts übersetzbar. Auf /de/ rendert der übersetzte Part.', 'rh-languages'),
                'default' => true,
            ],
            self::EDITOR_SIDEBAR => [
                'label' => __('Sprach-Sidebar im Editor', 'rh-languages'),
                'description' => __('Angepinntes Sprach-Icon oben rechts, mit "+ Übersetzung anlegen".', 'rh-languages'),
                'default' => true,
            ],
            self::POST_COLUMN => [
                'label' => __('Sprach-Spalte in Listen', 'rh-languages'),
                'description' => __('Spalte mit Links zu den Übersetzungen und Filter in den Beitragslisten.', 'rh-languages'),
                'default' => true,
            ],
            self::AUTO_SWITCHER => [
                'label' => __('Sprach-Switcher automatisch anzeigen', 'rh-languages'),
                'description' => __('Blendet den Switcher fix oben rechts ein. Sonst als Block "Sprach-Switcher" frei platzierbar.', 'rh-languages'),
                'default' => false,
            ],
            self::VIEW_TRANSITIONS => [
                'label' => __('Weiche Übergänge beim Sprachwechsel', 'rh-languages'),
                'description' => __('View Transitions (Progressive Enhancement, Browser ohne Support ignorieren es).', 'rh-languages'),
                'default' => false,
            ],
        ];
    }

    public static function enabled(string $key): bool
    {
        $defaults = self::all();
        $default = $defaults[$key]['default'] ?? true;

        return (bool) rhbp_setting(Config::GROUP_ID, 'feature_' . $key, $default);
    }
}
