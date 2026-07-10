<?php

declare(strict_types=1);

namespace RhLanguages;

use WP;

/**
 * URL-Routing: Default-Sprache ohne Prefix, alle anderen mit Prefix
 * (`/about/` = en, `/de/ueber/` = de).
 *
 * Kernmechanik (Polylang-erprobt): über `rewrite_rules_array` wird jede
 * bestehende Rewrite-Regel je Nicht-Default-Sprache mit dem Sprach-Prefix
 * dupliziert und um `&rh_lang=<code>` ergänzt. Dadurch bleibt die REQUEST_URI
 * unangetastet (wichtig für redirect_canonical) und die Sprache kommt sauber als
 * Query-Var an. Der `request`/`parse_request`-Hook liest sie und setzt die aktive
 * Sprache, bevor die Haupt-Query läuft.
 *
 * Die Link-Filter hängen den Prefix an die von WP generierten URLs: Permalinks
 * eines Posts nach dessen EIGENER Sprache, Term-/Archiv-Links nach der AKTUELLEN
 * Sprache (Terms sind nicht sprach-eigen, sie leben im aktuellen Kontext).
 */
final class Routing
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_filter('query_vars', [$this, 'registerQueryVar']);
        add_filter('rewrite_rules_array', [$this, 'addLanguageRules']);
        add_action('parse_request', [$this, 'detectLanguage']);
        add_action('template_redirect', [$this, 'guardDefaultPrefix'], 1);
        add_action('template_redirect', [$this, 'enforceCanonicalLanguage'], 2);

        // Permalinks nach der Sprache DES POSTS.
        add_filter('post_link', [$this, 'filterPostPermalink'], 10, 2);
        add_filter('page_link', [$this, 'filterPagePermalink'], 10, 2);
        add_filter('post_type_link', [$this, 'filterPostPermalink'], 10, 2);

        // Term-/Archiv-Links nach der AKTUELLEN Sprache.
        add_filter('term_link', [$this, 'filterCurrentContextLink'], 10, 1);
        add_filter('post_type_archive_link', [$this, 'filterCurrentContextLink'], 10, 1);

        // Die nackte Startseite (Logo/Site-Title) nach der aktuellen Sprache.
        add_filter('home_url', [$this, 'filterHomeUrl'], 10, 4);
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function registerQueryVar(array $vars): array
    {
        $vars[] = 'rh_lang';

        return $vars;
    }

    /**
     * Jede Rewrite-Regel je Nicht-Default-Sprache mit Prefix duplizieren.
     * Kein zusätzliches Capture-Group (literaler Prefix), darum bleiben die
     * `$matches[N]` der Original-Query unverändert.
     *
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function addLanguageRules(array $rules): array
    {
        if (! $this->languages->config()->isMultilingual()) {
            return $rules;
        }

        $prefixed = [];

        foreach ($this->languages->config()->nonDefault() as $language) {
            $code = $language->code;

            // Sprach-Startseite (/de/ -> Front-Query mit rh_lang).
            $prefixed[$code . '/?$'] = 'index.php?rh_lang=' . $code;

            foreach ($rules as $regex => $query) {
                // Sitemap-Regeln nicht duplizieren: /de/wp-sitemap.xml wäre sonst
                // eine byte-identische, separat indexierbare Kopie (Crawl-Budget).
                if (str_contains($regex, 'sitemap') || str_contains($query, 'sitemap')) {
                    continue;
                }
                $prefixed[$code . '/' . $regex] = $this->appendLangVar($query, $code);
            }
        }

        // Prefix-Regeln zuerst, damit sie vor der generischen pagename-Regel matchen.
        return $prefixed + $rules;
    }

    private function appendLangVar(string $query, string $code): string
    {
        $separator = str_contains($query, '?') ? '&' : '?';

        return $query . $separator . 'rh_lang=' . $code;
    }

    /**
     * Aktive Sprache aus dem URL-Pfad-Prefix setzen (autoritativ und
     * injection-sicher). Bewusst NICHT aus der Query-Var rh_lang, die als public
     * Var per ?rh_lang=xx manipulierbar wäre.
     */
    public function detectLanguage(WP $wp): void
    {
        $path = $this->stripHomePath((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $segments = $path === '' ? [] : explode('/', $path);
        $first = $segments[0] ?? '';

        $code = ($first !== '' && $first !== $this->languages->defaultCode() && $this->languages->config()->has($first))
            ? $first
            : $this->languages->defaultCode();

        $this->languages->setCurrent($code);
    }

    /**
     * Default-Sprache darf nicht unter ihrem eigenen Prefix erreichbar sein:
     * `/en/...` (en = Default) -> 301 auf die prefixlose URL (Duplicate-Content).
     */
    public function guardDefaultPrefix(): void
    {
        if (is_admin()) {
            return;
        }

        // Nur greifen, wenn nichts Echtes aufgelöst wurde. Existiert real eine
        // Seite/Kategorie mit einem Slug, der zufällig dem Default-Code entspricht
        // (z.B. /de/... bei Default de), darf sie NICHT wegredirectet werden.
        if (! is_404()) {
            return;
        }

        $default = $this->languages->defaultCode();
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = $this->stripHomePath($path);

        $segments = explode('/', trim($path, '/'));
        if (($segments[0] ?? '') !== $default) {
            return;
        }

        array_shift($segments);
        $target = $this->languages->homeRoot() . '/' . ($segments !== [] ? implode('/', $segments) . '/' : '');

        wp_safe_redirect(esc_url_raw($target), 301);
        exit;
    }

    /**
     * Eine Seite hat genau eine kanonische URL: die mit ihrem Sprach-Prefix.
     * Wird sie unter der falschen Sprach-URL aufgerufen (z.B. eine EN-Seite ohne
     * /en/, weil WP bei pagename-Queries den tax_query ignoriert), auf den echten
     * Permalink umleiten. Deckt auch den Wechsel der Standardsprache ab: alte
     * URLs zeigen dann per 301 auf die neue kanonische.
     */
    public function enforceCanonicalLanguage(): void
    {
        // Vorschau/Editor-Preview eines (evtl. noch nicht übersetzten) Entwurfs
        // nicht auf den öffentlichen Permalink umleiten.
        if (is_admin() || is_preview() || ! is_singular()) {
            return;
        }

        // Startseite NICHT kanonisieren: die Sprache der Startseite regelt bereits
        // Frontend::frontPageForLanguage. Ist die Startseite in der aktuellen
        // Sprache noch nicht übersetzt, fällt page_on_front auf die Default-Seite
        // zurück, deren Permalink (home_url) wieder den aktuellen Prefix bekäme,
        // das ergäbe eine 301-Endlosschleife auf /xx/.
        if (is_front_page() || is_home()) {
            return;
        }

        $postId = get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        if ($this->languages->langOfPost($postId) === $this->languages->current()) {
            return;
        }

        $target = get_permalink($postId);
        if (is_string($target) && $target !== '') {
            wp_safe_redirect($target, 301);
            exit;
        }
    }

    private function stripHomePath(string $path): string
    {
        $homePath = trim((string) wp_parse_url($this->languages->homeRoot(), PHP_URL_PATH), '/');
        $path = trim($path, '/');

        if ($homePath !== '' && str_starts_with($path, $homePath)) {
            $path = trim(substr($path, strlen($homePath)), '/');
        }

        return $path;
    }

    // --- Link-Filter ---

    /**
     * Permalink eines Posts/CPT nach der Sprache des Posts prefixen.
     */
    public function filterPostPermalink(string $url, mixed $post): string
    {
        $postId = is_object($post) ? (int) $post->ID : (int) $post;
        if ($postId <= 0) {
            return $url;
        }

        return $this->prefixUrl($url, $this->languages->langOfPost($postId));
    }

    /**
     * page_link liefert die Post-ID als zweiten Parameter (nicht das Objekt).
     */
    public function filterPagePermalink(string $url, int $postId): string
    {
        return $this->prefixUrl($url, $this->languages->langOfPost($postId));
    }

    /**
     * Term-/Archiv-Links nach der aktuellen Sprache prefixen.
     */
    public function filterCurrentContextLink(string $url): string
    {
        return $this->prefixUrl($url, $this->languages->current());
    }

    /**
     * Die nackte Startseite (home_url('/'), z.B. Logo/Site-Title-Block) auf die
     * aktuelle Sprache prefixen. Nur die Wurzel (leerer Pfad), nicht home_url()
     * mit Pfad (Assets, interne Links). Nicht im Admin/AJAX/REST.
     */
    public function filterHomeUrl(string $url, ?string $path, ?string $scheme = null, ?int $blogId = null): string
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $url;
        }
        // Multisite: nur die aktuelle Site prefixen, nicht home_url einer anderen.
        if ($blogId !== null && function_exists('get_current_blog_id') && $blogId !== get_current_blog_id()) {
            return $url;
        }
        if (! $this->languages->config()->isMultilingual() || $this->languages->isCurrentDefault()) {
            return $url;
        }
        if (ltrim((string) $path, '/') !== '') {
            return $url;
        }

        $code = $this->languages->current();
        if (str_ends_with(untrailingslashit($url), '/' . $code)) {
            return $url;
        }

        return $this->languages->homeRoot() . '/' . $code . '/';
    }

    /**
     * Fügt einer same-origin-URL das Sprach-Prefix hinzu (Default: unverändert).
     */
    private function prefixUrl(string $url, string $code): string
    {
        if ($code === $this->languages->defaultCode()) {
            return $url;
        }

        $home = $this->languages->homeRoot() . '/';
        if (! str_starts_with($url, $home)) {
            return $url;
        }

        $rest = substr($url, strlen($home));

        // Schon geprefixt? (idempotent)
        if ($rest === $code || str_starts_with($rest, $code . '/')) {
            return $url;
        }

        return $home . $code . '/' . $rest;
    }
}
