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

    public function handle(): void
    {
        $sourceId = isset($_GET['source']) ? absint($_GET['source']) : 0;
        $targetCode = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, self::ACTION . '_' . $sourceId . '_' . $targetCode)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-languages'));
        }
        if ($sourceId <= 0 || ! current_user_can('edit_post', $sourceId)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-languages'));
        }

        $result = (new Duplicator($this->languages))->duplicate($sourceId, $targetCode);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(get_edit_post_link($result, 'raw'));
        exit;
    }
}
