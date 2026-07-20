<?php

declare(strict_types=1);

namespace RhLanguages;

use WP_CLI;

/**
 * WP-CLI-Kommandos von rh-languages. Nur unter WP-CLI registriert.
 */
final class Cli
{
    /** Post-Status, die als echte Slug-Belegung zählen. */
    private const STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    public function __construct(private readonly Languages $languages)
    {
    }

    public function register(): void
    {
        WP_CLI::add_command('rhlang sync-slugs', [$this, 'syncSlugs']);
    }

    /**
     * Übernimmt bei Übersetzungen den Slug der Standardsprach-Version und entfernt
     * so unnötige `-2`-Suffixe. WP hängt die an, weil der Slug im post_type schon
     * belegt ist; das Sprach-Prefix (`/de/`) disambiguiert die URL aber bereits
     * (siehe Slug::shareAcrossLanguages). Idempotent, mehrfach ausführbar.
     *
     * Vor dem Live-Gang gefahrlos (URLs noch nicht indexiert). Auf indexierten
     * Seiten Backup + Prüfung der 301-Weiterleitungen (WP legt `_wp_old_slug` an).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Nur anzeigen, was geändert würde. Schreibt nichts.
     *
     * [--post_type=<type>]
     * : Nur diesen Post-Type behandeln. Default: alle übersetzbaren.
     *
     * ## EXAMPLES
     *
     *     wp rhlang sync-slugs --dry-run
     *     wp rhlang sync-slugs --post_type=artwork
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function syncSlugs(array $args, array $assoc): void
    {
        if (! $this->languages->config()->isConfigured()) {
            WP_CLI::error('Keine Sprachen konfiguriert.');
        }

        $dryRun = isset($assoc['dry-run']);
        $default = $this->languages->defaultCode();
        $types = isset($assoc['post_type'])
            ? [(string) $assoc['post_type']]
            : $this->languages->taxonomy()->postTypes();

        $changed = 0;
        $suffixKept = 0;

        foreach ($types as $type) {
            $ids = get_posts([
                'post_type' => $type,
                'post_status' => self::STATUSES,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => true,
            ]);

            foreach ($ids as $postId) {
                $postId = (int) $postId;

                // Nur echte Sprach-Terme (langOfPost() defaultet sonst).
                $terms = get_the_terms($postId, Taxonomy::TAX_LANG);
                if (! is_array($terms) || $terms === []) {
                    continue;
                }
                $lang = (string) $terms[0]->slug;

                // Standardsprache besitzt den kanonischen Slug.
                if ($lang === $default) {
                    continue;
                }

                $translations = $this->languages->translations($postId, self::STATUSES);
                if (! isset($translations[$default])) {
                    continue; // kein Standardsprach-Gegenstück -> kein kanonischer Slug
                }

                $target = get_post_field('post_name', $translations[$default]);
                $current = get_post_field('post_name', $postId);
                if (! is_string($target) || $target === '' || $current === $target) {
                    continue;
                }

                if ($dryRun) {
                    WP_CLI::log(sprintf('[dry-run] #%d (%s): %s -> %s', $postId, $lang, $current, $target));
                    $changed++;
                    continue;
                }

                wp_update_post(['ID' => $postId, 'post_name' => $target]);
                $resulting = (string) get_post_field('post_name', $postId);

                if ($resulting === $target) {
                    WP_CLI::log(sprintf('#%d (%s): %s -> %s', $postId, $lang, $current, $resulting));
                    $changed++;
                } else {
                    WP_CLI::warning(sprintf('#%d (%s): Ziel "%s" in dieser Sprache belegt, Slug bleibt "%s"', $postId, $lang, $target, $resulting));
                    $suffixKept++;
                }
            }
        }

        WP_CLI::success(sprintf('%s: %d Slugs %s, %d mit Suffix belassen.', $dryRun ? 'Dry-Run' : 'Fertig', $changed, $dryRun ? 'würden geändert' : 'geändert', $suffixKept));
    }
}
