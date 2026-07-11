<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Frontend-Feinschliff, der die Sprachtrennung komplett macht:
 *
 * - Statische Startseite pro Sprache (`pre_option_page_on_front` /
 *   `pre_option_page_for_posts`), sonst zeigt `/de/` die Default-Startseite.
 * - `wp_navigation` als übersetzbaren Post-Type behandeln und den vom
 *   Navigation-Block referenzierten Menü-Post pro Sprache umbiegen
 *   (`render_block_data`), damit das Menü sprachrichtig ist.
 */
final class Frontend
{
    private bool $resolvingOption = false;

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        if (Features::enabled(Features::FRONT_PAGE_PER_LANGUAGE)) {
            add_filter('pre_option_page_on_front', [$this, 'frontPageForLanguage']);
            add_filter('pre_option_page_for_posts', [$this, 'postsPageForLanguage']);
        }

        if (Features::enabled(Features::NAV_PER_LANGUAGE)) {
            add_filter('rh-blueprint/languages/post_types', [$this, 'addNavigationPostType']);
            add_filter('render_block_data', [$this, 'swapNavigationRef']);
        }

        if (Features::enabled(Features::TEMPLATE_PART_PER_LANGUAGE)) {
            add_filter('rh-blueprint/languages/post_types', [$this, 'addTemplatePartPostType']);
            add_filter('render_block_data', [$this, 'swapTemplatePartSlug']);
        }
    }

    /**
     * Menüs (wp_navigation) sind übersetzbar wie normale Posts.
     *
     * @param array<int, string> $types
     * @return array<int, string>
     */
    public function addNavigationPostType(array $types): array
    {
        $types[] = 'wp_navigation';

        return $types;
    }

    /**
     * Template-Parts (Footer/Header) sind übersetzbar wie normale Posts.
     *
     * @param array<int, string> $types
     * @return array<int, string>
     */
    public function addTemplatePartPostType(array $types): array
    {
        $types[] = 'wp_template_part';

        return $types;
    }

    public function frontPageForLanguage(mixed $pre): mixed
    {
        return $this->translatedOption('page_on_front', $pre);
    }

    public function postsPageForLanguage(mixed $pre): mixed
    {
        return $this->translatedOption('page_for_posts', $pre);
    }

    /**
     * Für die aktive Nicht-Default-Sprache die übersetzte Seiten-ID der Option
     * zurückgeben. Default-Sprache und fehlende Übersetzung fallen auf den
     * gespeicherten Wert zurück.
     */
    private function translatedOption(string $option, mixed $pre): mixed
    {
        if ($this->resolvingOption || $this->languages->isCurrentDefault()) {
            return $pre;
        }

        $this->resolvingOption = true;
        $baseId = (int) get_option($option);
        $this->resolvingOption = false;

        if ($baseId <= 0) {
            return $pre;
        }

        $translated = $this->languages->getTranslation($baseId, $this->languages->current());

        return $translated !== null ? $translated : $pre;
    }

    /**
     * Den vom Navigation-Block referenzierten Menü-Post auf die aktuelle Sprache
     * umbiegen (vor dem Rendern).
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public function swapNavigationRef(array $parsed): array
    {
        if (($parsed['blockName'] ?? '') !== 'core/navigation' || $this->languages->isCurrentDefault()) {
            return $parsed;
        }

        $ref = (int) ($parsed['attrs']['ref'] ?? 0);
        if ($ref <= 0) {
            return $parsed;
        }

        $translated = $this->languages->getTranslation($ref, $this->languages->current());
        if ($translated !== null) {
            $parsed['attrs']['ref'] = $translated;
        }

        return $parsed;
    }

    /**
     * Den vom Template-Part-Block referenzierten Part auf die aktuelle Sprache
     * umbiegen. core/template-part referenziert über slug + theme (nicht über eine
     * Post-ID), also tauschen wir das `slug`-Attribut. WP-Core löst den Part dann
     * über seine eigene WP_Query (post_name + wp_theme-Term) auf.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public function swapTemplatePartSlug(array $parsed): array
    {
        if (($parsed['blockName'] ?? '') !== 'core/template-part' || $this->languages->isCurrentDefault()) {
            return $parsed;
        }
        // Editor-Canvas / ServerSideRender nicht anfassen, dort soll die Basis stehen.
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $parsed;
        }

        $slug = isset($parsed['attrs']['slug']) ? (string) $parsed['attrs']['slug'] : '';
        if ($slug === '') {
            return $parsed;
        }

        $translatedSlug = $this->translatedTemplatePartSlug($slug);
        if ($translatedSlug !== null) {
            $parsed['attrs']['slug'] = $translatedSlug;
        }

        return $parsed;
    }

    /**
     * Slug der Template-Part-Übersetzung in der aktuellen Sprache, oder null.
     */
    private function translatedTemplatePartSlug(string $slug): ?string
    {
        $basePostId = $this->languages->templatePartPostId($slug);
        if ($basePostId === null) {
            return null; // reiner Theme-Datei-Part (kein Post) -> keine Übersetzung
        }

        $translationId = $this->languages->getTranslation($basePostId, $this->languages->current());
        if ($translationId === null || $translationId === $basePostId) {
            return null;
        }

        $translated = get_post($translationId);

        return $translated instanceof \WP_Post ? $translated->post_name : null;
    }
}
