<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Blendet den Sprach-Switcher automatisch fix oben rechts ein (Feature-Schalter
 * "Sprach-Switcher automatisch anzeigen").
 *
 * Rendert denselben SSR-Block wie die manuelle Variante, damit es genau eine
 * Ausgabe-Logik gibt. Ist der Schalter aus, bleibt der Switcher als frei
 * platzierbarer Block "Sprach-Switcher" verfügbar.
 */
final class AutoSwitcher
{
    public function boot(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_footer', [$this, 'render']);
    }

    public function enqueue(): void
    {
        // Block-Style manuell laden, da wir erst im Footer rendern (nach wp_head).
        if (wp_style_is('rh-language-switcher', 'registered')) {
            wp_enqueue_style('rh-language-switcher');
        }
    }

    public function render(): void
    {
        if (is_admin()) {
            return;
        }

        $html = do_blocks('<!-- wp:rh/language-switcher /-->');
        if (trim(wp_strip_all_tags($html)) === '' && ! str_contains($html, 'rh-language-switcher')) {
            return;
        }

        echo '<div class="rh-language-switcher-floating">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block-Ausgabe bereits escaped
    }
}
