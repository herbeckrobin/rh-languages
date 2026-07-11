<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use RhLanguages\Duplicator;
use RhLanguages\Languages;

/**
 * Anlege-Link aus der Beitragsliste: erzeugt die Übersetzung als Draft und
 * springt in deren Editor. GET mit Nonce, nutzt denselben Duplicator wie der
 * REST-Endpoint der Editor-Sidebar.
 */
final class CreateController
{
    public const ACTION = 'rhlang_create';

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Nonce-gesicherter Anlege-Link für Quelle + Zielsprache.
     */
    public static function url(int $sourceId, string $targetCode): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['action' => self::ACTION, 'source' => $sourceId, 'lang' => $targetCode],
                admin_url('admin-post.php')
            ),
            self::ACTION . '_' . $sourceId . '_' . $targetCode
        );
    }

    /**
     * Anlege-Link für einen Template-Part per Slug + Theme (statt Post-ID), für
     * reine Theme-Datei-Parts, die noch keinen Post haben (Footer/Header).
     */
    public static function urlForPart(string $slug, string $theme, string $targetCode): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['action' => self::ACTION, 'part_slug' => $slug, 'part_theme' => $theme, 'lang' => $targetCode],
                admin_url('admin-post.php')
            ),
            self::ACTION . '_part_' . $slug . '_' . $targetCode
        );
    }

    public function handle(): void
    {
        $targetCode = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
        $partSlug = isset($_GET['part_slug']) ? sanitize_title(wp_unslash($_GET['part_slug'])) : '';

        $result = $partSlug !== ''
            ? $this->handlePart($partSlug, $targetCode)
            : $this->handlePost($targetCode);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        $post = get_post($result);
        $redirect = $post instanceof \WP_Post && in_array($post->post_type, ['wp_navigation', 'wp_template_part'], true)
            ? SiteEditorLink::forPost($post)
            : (string) get_edit_post_link($result, 'raw');

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * @return int|\WP_Error
     */
    private function handlePost(string $targetCode)
    {
        $sourceId = isset($_GET['source']) ? absint($_GET['source']) : 0;

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, self::ACTION . '_' . $sourceId . '_' . $targetCode)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-languages'));
        }
        if ($sourceId <= 0 || ! current_user_can('edit_post', $sourceId)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-languages'));
        }

        return (new Duplicator($this->languages))->duplicate($sourceId, $targetCode);
    }

    /**
     * @return int|\WP_Error
     */
    private function handlePart(string $slug, string $targetCode)
    {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, self::ACTION . '_part_' . $slug . '_' . $targetCode)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-languages'));
        }
        // Template-Parts leben im Site-Editor -> passende Fähigkeit.
        if (! current_user_can('edit_theme_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-languages'));
        }

        // Theme immer aus dem aktiven Theme, nie aus dem GET-Wert (kein Anlegen
        // von Parts für ein fremdes/nicht existentes Theme).
        return (new Duplicator($this->languages))->duplicateTemplatePartBySlug($slug, get_stylesheet(), $targetCode);
    }
}
