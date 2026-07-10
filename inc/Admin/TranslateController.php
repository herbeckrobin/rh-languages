<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use RhLanguages\Duplicator;
use RhLanguages\Languages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST-Endpoint für "+ Übersetzung anlegen" (Editor-Sidebar).
 * Die eigentliche Duplizier-Logik liegt im Duplicator (geteilt mit dem
 * Anlege-Link der Beitragsliste).
 */
final class TranslateController
{
    public const NAMESPACE = 'rh-languages/v1';

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/translate', [
            'methods' => 'POST',
            'callback' => [$this, 'handleTranslate'],
            'permission_callback' => [$this, 'canTranslate'],
            'args' => [
                'source' => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'lang' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
    }

    public function canTranslate(WP_REST_Request $request): bool
    {
        $source = (int) $request->get_param('source');

        return $source > 0 && current_user_can('edit_post', $source);
    }

    public function handleTranslate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $sourceId = (int) $request->get_param('source');
        $targetCode = (string) $request->get_param('lang');

        $existing = $this->languages->getTranslation($sourceId, $targetCode, Duplicator::EXISTING_STATUSES);

        $result = (new Duplicator($this->languages))->duplicate($sourceId, $targetCode);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'id' => $result,
            'created' => $existing === null,
            'editUrl' => get_edit_post_link($result, 'raw'),
        ], $existing === null ? 201 : 200);
    }
}
