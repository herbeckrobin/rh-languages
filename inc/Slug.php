<?php

declare(strict_types=1);

namespace RhLanguages;

use WP_Query;

/**
 * Erlaubt denselben `post_name` pro Sprache.
 *
 * WordPress erzwingt in `wp_unique_post_slug()` Eindeutigkeit des Slugs pro
 * post_type (+ Parent) und hängt sonst `-2`/`-3` an. Bei übersetzbaren Typen ist
 * das unnötig: das Sprach-Prefix (`/de/`) disambiguiert die URL bereits, und
 * `Query::filterByLanguage` löst pro Sprache den passenden Post auf. Der Filter
 * gibt den Original-Slug zurück, wenn WP das Suffix nur wegen eines Posts einer
 * ANDEREN Sprache angehängt hat. Kollidiert der Slug mit einem Post DERSELBEN
 * Sprache, bleibt das Suffix (echte Kollision).
 */
final class Slug
{
    /** Post-Status, die als echte Slug-Belegung zählen. */
    private const STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        add_filter('wp_unique_post_slug', [$this, 'shareAcrossLanguages'], 10, 6);
        add_filter('the_posts', [$this, 'disambiguateSingular'], 10, 2);
    }

    /**
     * Löst geteilte Slugs bei Einzelansichten auf.
     *
     * WordPress wendet die Sprach-tax_query (Query::filterByLanguage) bei
     * `is_singular` NICHT an: ein Single gilt im Core als per Name/ID eindeutig,
     * die tax_query landet gar nicht im SQL. Teilen sich EN und DE denselben Slug,
     * liefert die Namens-Query BEIDE Posts und WP nimmt den neueren, die
     * Default-Sprach-URL (`/artwork/what-if/`) landet dann auf der Übersetzung
     * (301 nach `/de/`). Deshalb hier die Ergebnisliste eines Singles auf den Post
     * der aktiven Sprache eingrenzen. Die Standardsprache schließt term-lose Posts
     * ein (wie der Query-Filter). Findet sich kein Sprach-Treffer, bleibt die Liste
     * unverändert (kein unerwartetes 404).
     *
     * @param array<int, \WP_Post> $posts
     * @return array<int, \WP_Post>
     */
    public function disambiguateSingular(array $posts, WP_Query $query): array
    {
        if (is_admin() || ! $query->is_singular() || count($posts) < 2) {
            return $posts;
        }
        if (! is_object_in_taxonomy($posts[0]->post_type, Taxonomy::TAX_LANG)) {
            return $posts;
        }

        $current = $this->languages->current();
        $default = $this->languages->defaultCode();

        $matched = [];
        foreach ($posts as $post) {
            $terms = get_the_terms($post->ID, Taxonomy::TAX_LANG);
            $lang = (is_array($terms) && $terms !== []) ? (string) $terms[0]->slug : '';
            if ($lang === $current || ($current === $default && $lang === '')) {
                $matched[] = $post;
            }
        }

        return $matched !== [] ? $matched : $posts;
    }

    /**
     * @param string $slug     Der von WP berechnete (ggf. suffixierte) Slug.
     * @param int    $postId   Post, für den der Slug gebildet wird.
     * @param string $status   Post-Status (ungenutzt, Signatur-Vorgabe).
     * @param string $type     Post-Type.
     * @param int    $parent   Parent-ID (Hierarchie).
     * @param string $original Der gewünschte Slug ohne Suffix.
     */
    public function shareAcrossLanguages(string $slug, int $postId, string $status, string $type, int $parent, string $original): string
    {
        // Nur übersetzbare Typen, und nur wenn WP überhaupt ein Suffix anhängte.
        if ($slug === $original || ! in_array($type, $this->languages->taxonomy()->postTypes(), true)) {
            return $slug;
        }

        // Nur eingreifen, wenn ein ECHTER Sprach-Term hängt. langOfPost() würde
        // sonst auf die Default-Sprache zurückfallen. Zur Insert-Zeit ist der Term
        // noch nicht gesetzt (der Duplicator macht den Slug danach sauber), dann
        // lassen wir WPs Suffix stehen.
        $terms = get_the_terms($postId, Taxonomy::TAX_LANG);
        if (! is_array($terms) || $terms === []) {
            return $slug;
        }
        $lang = (string) $terms[0]->slug;

        // Ist der Original-Slug in DIESER Sprache frei (kein anderer Post gleicher
        // Sprache, gleichen Typs, gleichen Parents)?
        $clash = new WP_Query([
            'post_type' => $type,
            'post_name__in' => [$original],
            'post_parent' => $parent,
            'post_status' => self::STATUSES,
            'posts_per_page' => 1,
            'post__not_in' => [$postId],
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => Taxonomy::TAX_LANG,
                    'field' => 'slug',
                    'terms' => $lang,
                ],
            ],
        ]);

        return $clash->have_posts() ? $slug : $original;
    }
}
