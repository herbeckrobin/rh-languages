<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use WP_Post;

/**
 * Baut die Site-Editor-Bearbeiten-URLs für strukturelle Bausteine deterministisch,
 * unabhängig von get_edit_post_link() (das für wp_template_part einen Composite-
 * Pfad "theme//slug" braucht und ohne current_user leer zurückkommt).
 *
 * Format aus dem registrierten _edit_link:
 *   wp_navigation:    site-editor.php?p=/wp_navigation/{id}&canvas=edit
 *   wp_template_part: site-editor.php?p=/wp_template_part/{theme}%2F%2F{slug}&canvas=edit
 *
 * Wichtig: die Composite-ID "theme//slug" MUSS url-kodiert sein (WP kodiert das
 * "//" zu %2F%2F), sonst parst der Site-Editor-Router den Pfad falsch und meldet
 * "Element existiert nicht".
 */
final class SiteEditorLink
{
    public static function nav(int $postId): string
    {
        return admin_url('site-editor.php?p=/wp_navigation/' . $postId . '&canvas=edit');
    }

    public static function part(string $theme, string $slug): string
    {
        return admin_url('site-editor.php?p=/wp_template_part/' . rawurlencode($theme . '//' . $slug) . '&canvas=edit');
    }

    /**
     * Passenden Editor-Link für einen strukturellen Post finden. Für Nav die
     * Post-ID, für Template-Parts Theme-Term + post_name.
     */
    public static function forPost(WP_Post $post): string
    {
        if ($post->post_type === 'wp_navigation') {
            return self::nav($post->ID);
        }

        if ($post->post_type === 'wp_template_part') {
            $themes = wp_get_object_terms($post->ID, 'wp_theme', ['fields' => 'names']);
            $theme = ! is_wp_error($themes) && $themes !== [] ? (string) $themes[0] : get_stylesheet();

            return self::part($theme, $post->post_name);
        }

        return (string) get_edit_post_link($post->ID, 'raw');
    }
}
