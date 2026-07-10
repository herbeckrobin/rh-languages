<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Schaltet auf dem Frontend die aktive Locale um, damit Theme- und WP-Core-Strings
 * (`__()`, Datumsformate, "Weiterlesen") der Sprache der Seite folgen, unabhängig
 * von der Backend-Sprache des eingeloggten Redakteurs.
 *
 * Zeitpunkt `wp`: da ist die Query geparst und die aktive Sprache steht
 * (Routing::detectLanguage lief auf parse_request). switch_to_locale() lädt die
 * bereits registrierten Textdomains in der Ziel-Locale neu; Templates rendern
 * danach. WordPress stellt am Request-Ende automatisch zurück.
 *
 * Voraussetzung im Theme: alle fixen sichtbaren Strings laufen durch
 * `__()`/`esc_html__()`, und es liegt eine passende `<textdomain>-<locale>.mo`
 * (z.B. `languages/`) vor.
 */
final class Locale
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        if (! Features::enabled(Features::LOCALE_SWITCH)) {
            return;
        }
        add_action('wp', [$this, 'switchLocale']);
    }

    public function switchLocale(): void
    {
        if (is_admin()) {
            return;
        }

        $language = $this->languages->config()->byCode($this->languages->current());
        if ($language === null || $language->locale === '') {
            return;
        }

        if ($language->locale !== determine_locale() && function_exists('switch_to_locale')) {
            switch_to_locale($language->locale);
        }
    }
}
