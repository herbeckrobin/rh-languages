<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * <head>-Ausgabe: hreflang-Alternates (+ x-default) und das html-lang-Attribut.
 *
 * Einzige Quelle für hreflang (nicht doppelt mit rh-seo). Die Canonical bleibt
 * bei rh-seo bzw. Core und referenziert automatisch die eigene Sprachversion,
 * weil jede Übersetzung ein eigener Post mit eigenem Permalink ist.
 */
final class Head
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        if (Features::enabled(Features::HREFLANG)) {
            add_action('wp_head', [$this, 'renderAlternates'], 3);
        }

        if (Features::enabled(Features::HTML_LANG)) {
            // Priorität hoch, damit wir nach rh-seos forceLang laufen (das die
            // Site-Locale erzwingt): auf einer mehrsprachigen Site gewinnt die
            // aktive Sprache.
            add_filter('language_attributes', [$this, 'filterHtmlLang'], 99, 1);
        }

        if (Features::enabled(Features::VIEW_TRANSITIONS)) {
            add_action('wp_head', [$this, 'renderViewTransitionCss'], 4);
        }
    }

    /**
     * hreflang-Alternates für alle vorhandenen Sprachversionen + x-default.
     */
    public function renderAlternates(): void
    {
        if (is_admin() || is_feed() || is_404()) {
            return;
        }

        $urls = $this->alternateUrls();
        if ($urls === []) {
            return;
        }

        $default = $this->languages->defaultCode();
        $out = "\n";

        foreach ($urls as $code => $url) {
            $language = $this->languages->config()->byCode($code);
            if ($language === null) {
                continue;
            }
            $out .= '<link rel="alternate" hreflang="' . esc_attr($language->hreflang) . '" href="' . esc_url($url) . '">' . "\n";
        }

        if (isset($urls[$default])) {
            $out .= '<link rel="alternate" hreflang="x-default" href="' . esc_url($urls[$default]) . '">' . "\n";
        }

        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alle Werte einzeln escaped
    }

    /**
     * Map Sprachcode => URL der jeweiligen Sprachversion des aktuellen Objekts.
     *
     * @return array<string, string>
     */
    private function alternateUrls(): array
    {
        // Startseite ZUERST prüfen: die statische Front-Page ist auch is_singular(),
        // aber ihre kanonische URL ist die Sprach-Wurzel (/ bzw. /de/), nicht der
        // Post-Permalink der Übersetzung.
        if (is_front_page()) {
            $urls = [];
            foreach ($this->languages->config()->codes() as $code) {
                $urls[$code] = rh_lang_home_url($code);
            }

            return $urls;
        }

        // Einzelseite/-post: echte Übersetzungen der Gruppe.
        if (is_singular()) {
            $postId = get_queried_object_id();
            $urls = [];
            foreach ($this->languages->translations($postId) as $code => $translationId) {
                $permalink = get_permalink($translationId);
                if (is_string($permalink)) {
                    $urls[$code] = $permalink;
                }
            }

            return $urls;
        }

        // Archive/Sonstiges: gleicher (nicht sprach-eigener) Pfad je Sprach-Prefix.
        $base = $this->currentBasePath();
        $root = $this->languages->homeRoot();
        $urls = [];
        foreach ($this->languages->config()->all() as $language) {
            $urls[$language->code] = $language->isDefault
                ? $root . '/' . $base
                : $root . '/' . $language->code . '/' . $base;
        }

        return $urls;
    }

    /**
     * Aktueller Request-Pfad ohne Home-Basis und ohne führenden Sprachcode.
     */
    private function currentBasePath(): string
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $homePath = trim((string) wp_parse_url($this->languages->homeRoot(), PHP_URL_PATH), '/');
        $path = trim($path, '/');

        if ($homePath !== '' && str_starts_with($path, $homePath)) {
            $path = trim(substr($path, strlen($homePath)), '/');
        }

        $segments = $path === '' ? [] : explode('/', $path);
        if (($segments[0] ?? '') !== '' && $this->languages->config()->has($segments[0])) {
            array_shift($segments);
        }

        $base = implode('/', $segments);

        return $base === '' ? '' : $base . '/';
    }

    /**
     * html-lang-Attribut auf die aktive Sprache setzen.
     */
    public function filterHtmlLang(string $output): string
    {
        $language = $this->languages->config()->byCode($this->languages->current());
        if ($language === null) {
            return $output;
        }

        $lang = str_replace('_', '-', $language->locale !== '' ? $language->locale : $language->hreflang);

        if (str_contains($output, 'lang=')) {
            return (string) preg_replace('/lang="[^"]*"/', 'lang="' . esc_attr($lang) . '"', $output);
        }

        return trim($output . ' lang="' . esc_attr($lang) . '"');
    }

    /**
     * Weicher Cross-Fade beim Seitenwechsel (Progressive Enhancement).
     * Browser ohne Support ignorieren die Regel.
     */
    public function renderViewTransitionCss(): void
    {
        echo "<style id=\"rh-languages-vt\">@view-transition{navigation:auto}</style>\n";
    }
}
