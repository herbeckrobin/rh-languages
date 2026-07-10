<?php

declare(strict_types=1);

namespace RhLanguages\Blocks;

use RhLanguages\Languages;

/**
 * Registriert den SSR-Sprach-Switcher-Block (buildless, Muster wie rh-blocks).
 *
 * block.json wird serverseitig registriert (render.php macht die Ausgabe), das
 * Editor-Script lädt über window.wp.* und bekommt die block.json-Metadaten per
 * wp_localize_script gespiegelt. Der Block trägt keine eigenen Attribute (nur
 * Block-Supports), darum kein 400-Risiko am block-renderer-Endpoint.
 */
final class SwitcherBlock
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register'], 20);
    }

    public function register(): void
    {
        $dir = RHLANG_PLUGIN_DIR . 'blocks/language-switcher';
        $url = RHLANG_PLUGIN_URL . 'blocks/language-switcher';

        $js = $dir . '/index.js';
        $css = $dir . '/style.css';
        $json = $dir . '/block.json';

        if (! file_exists($js) || ! file_exists($json)) {
            return;
        }

        wp_register_script(
            'rh-language-switcher-editor',
            $url . '/index.js',
            ['wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n'],
            (string) filemtime($js),
            true
        );

        $meta = json_decode((string) file_get_contents($json), true);
        wp_localize_script('rh-language-switcher-editor', 'rhLanguageSwitcherMeta', is_array($meta) ? $meta : []);

        // Sprach-Labels für die Editor-Vorschau spiegeln.
        $labels = [];
        foreach ($this->languages->config()->all() as $language) {
            $labels[] = $language->label;
        }
        wp_localize_script('rh-language-switcher-editor', 'rhLanguageSwitcherLabels', $labels);

        if (file_exists($css)) {
            wp_register_style('rh-language-switcher', $url . '/style.css', [], (string) filemtime($css));
        }

        register_block_type($dir);
    }
}
