<?php

declare(strict_types=1);

namespace RhLanguages;

use RhLanguages\Admin\TranslateController;
use WP_Post;

/**
 * Angepinntes Sprach-Icon im Editor-Header (oben rechts, in der
 * `.interface-pinned-items`-Gruppe neben "Einstellungen" und "Stile").
 *
 * Buildless über die window.wp.*-Globals (registerPlugin + PluginSidebar). Das
 * Icon zeigt den aktuellen Sprachcode als Badge, ein Klick öffnet die
 * "Sprachen"-Sidebar mit der Übersetzungsliste und "+ anlegen". Die aktuellen
 * Post-Daten (Sprache, vorhandene Übersetzungen) werden per wp_localize_script
 * gespiegelt, damit das Panel ohne Extra-Roundtrip rendert.
 */
final class EditorSidebar
{
    private const ALL_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $post = get_post();
        if (! $post instanceof WP_Post) {
            return;
        }

        if (! in_array($post->post_type, $this->languages->taxonomy()->postTypes(), true)) {
            return;
        }

        $js = RHLANG_PLUGIN_DIR . 'assets/js/editor-sidebar.js';
        if (! file_exists($js)) {
            return;
        }

        wp_enqueue_script(
            'rh-languages-editor',
            RHLANG_PLUGIN_URL . 'assets/js/editor-sidebar.js',
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch'],
            (string) filemtime($js),
            true
        );

        wp_localize_script('rh-languages-editor', 'rhLangEditor', $this->data($post));

        $css = RHLANG_PLUGIN_DIR . 'assets/css/editor.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'rh-languages-editor',
                RHLANG_PLUGIN_URL . 'assets/css/editor.css',
                [],
                (string) filemtime($css)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function data(WP_Post $post): array
    {
        $config = $this->languages->config();
        $postId = (int) $post->ID;
        $currentLang = $this->languages->langOfPost($postId);

        $languagesList = [];
        foreach ($config->all() as $language) {
            $languagesList[] = [
                'code' => $language->code,
                'label' => $language->label,
                'isDefault' => $language->isDefault,
            ];
        }

        $translations = [];
        foreach ($this->languages->translations($postId, self::ALL_STATUSES) as $code => $translationId) {
            $translations[$code] = [
                'id' => $translationId,
                'editUrl' => get_edit_post_link($translationId, 'raw'),
                'status' => get_post_status($translationId),
            ];
        }

        return [
            'current' => [
                'id' => $postId,
                'lang' => $currentLang,
                'isDefault' => $currentLang === $config->defaultCode(),
            ],
            'defaultCode' => $config->defaultCode(),
            'languages' => $languagesList,
            'translations' => $translations,
            'rest' => [
                'root' => esc_url_raw(rest_url(TranslateController::NAMESPACE . '/translate')),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
            'strings' => [
                'title' => __('Sprachen', 'rh-languages'),
                'currentLabel' => __('Aktuelle Sprache', 'rh-languages'),
                'edit' => __('Bearbeiten', 'rh-languages'),
                'create' => __('anlegen', 'rh-languages'),
                'creating' => __('Wird angelegt …', 'rh-languages'),
                'onlyFromDefault' => __('Übersetzungen von der Standardsprache aus anlegen.', 'rh-languages'),
                'draft' => __('Entwurf', 'rh-languages'),
                'error' => __('Konnte nicht angelegt werden.', 'rh-languages'),
            ],
        ];
    }
}
