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
}
