<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Shortcode `[rh_language_switcher]` für Inhalt/Widgets. Gleiche Ausgabe wie der
 * Block (beide über rh_lang_switcher_html()).
 */
final class Shortcode
{
    public function boot(): void
    {
        add_shortcode('rh_language_switcher', [$this, 'render']);
    }

    public function render(mixed $atts = []): string
    {
        if (! function_exists('rh_lang_switcher_html')) {
            return '';
        }

        // Block-Style laden, damit der Shortcode überall gleich aussieht.
        if (wp_style_is('rh-language-switcher', 'registered')) {
            wp_enqueue_style('rh-language-switcher');
        }

        return rh_lang_switcher_html();
    }
}
