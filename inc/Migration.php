<?php

declare(strict_types=1);

namespace RhLanguages;

use WP_Query;

/**
 * Einmalige Bestands-Migration beim Aktivieren/ersten Konfigurieren.
 *
 * Jeder vorhandene Post eines übersetzbaren Post-Types, der noch keinen
 * rh_lang-Term hat, bekommt die Default-Sprache und eine eigene, frische
 * Übersetzungsgruppe. Danach ist der Bestand sauber einsprachig, die weiteren
 * Sprachen werden pro Post im Editor nachgezogen.
 *
 * Idempotent (überspringt bereits zugeordnete Posts) und batch-basiert, damit
 * auch große Bestände nicht in ein Memory-Limit laufen.
 */
final class Migration
{
    private const BATCH = 200;

    public function __construct(private readonly Languages $languages)
    {
    }

    public function run(): void
    {
        $postTypes = $this->languages->taxonomy()->postTypes();
        if ($postTypes === []) {
            return;
        }

        $default = $this->languages->defaultCode();
        $lastFirstId = 0;
        $safety = 0;

        do {
            $query = new WP_Query([
                'post_type' => $postTypes,
                'post_status' => 'any',
                'posts_per_page' => self::BATCH,
                'paged' => 1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                Languages::QUERY_SKIP => true, // eigenen Sprachfilter aushebeln
                'tax_query' => [
                    [
                        'taxonomy' => Taxonomy::TAX_LANG,
                        'operator' => 'NOT EXISTS',
                    ],
                ],
            ]);

            if ($query->posts === []) {
                break;
            }

            // Fortschritts-Schutz: könnte eine Zuweisung fehlschlagen, bliebe der
            // Post in der NOT-EXISTS-Menge und die Schleife liefe endlos. Bei
            // gleicher erster ID wie in der Vorrunde (kein Fortschritt) abbrechen.
            $firstId = (int) $query->posts[0];
            if ($firstId === $lastFirstId) {
                break;
            }
            $lastFirstId = $firstId;

            foreach ($query->posts as $postId) {
                $postId = (int) $postId;
                $this->languages->assignLanguage($postId, $default);
                $this->languages->ensureGroup($postId);
            }

            // Die gerade migrierten Posts fallen aus dem NOT-EXISTS-Filter, darum
            // immer Seite 1 abarbeiten, solange eine volle Batch zurückkommt.
            $more = $query->post_count === self::BATCH;
            wp_reset_postdata();
        } while ($more && ++$safety < 10000);
    }
}
