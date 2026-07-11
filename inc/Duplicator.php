<?php

declare(strict_types=1);

namespace RhLanguages;

use WP_Error;
use WP_Post;

/**
 * Legt eine Übersetzung als Draft an: dupliziert den Quell-Post in die
 * Zielsprache (post_content 1:1, Featured Image + Meta selektiv), setzt
 * rh_lang = Zielsprache und hängt denselben Übersetzungsgruppen-Term an.
 *
 * Gemeinsame Logik für den REST-Endpoint (Editor-Sidebar) und den
 * Anlege-Link in der Beitragsliste.
 */
final class Duplicator
{
    /** Meta-Keys, die NICHT mitkopiert werden (interne Locks). */
    private const SKIP_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date'];

    /** Post-Status, die als "Übersetzung existiert bereits" zählen. */
    public const EXISTING_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /** Site-Editor-Bausteine ohne eigenen Content-Titel -> Sprach-Suffix. */
    private const STRUCTURAL_TYPES = ['wp_navigation', 'wp_template_part'];

    public function __construct(private readonly Languages $languages)
    {
    }

    /**
     * @return int|WP_Error Post-ID der (neuen oder bereits vorhandenen) Übersetzung.
     */
    public function duplicate(int $sourceId, string $targetCode): int|WP_Error
    {
        $source = get_post($sourceId);
        if (! $source instanceof WP_Post) {
            return new WP_Error('rhlang_no_source', __('Quell-Beitrag nicht gefunden.', 'rh-languages'), ['status' => 404]);
        }

        $config = $this->languages->config();
        if (! $config->has($targetCode) || $targetCode === $config->defaultCode()) {
            return new WP_Error('rhlang_bad_target', __('Ungültige Zielsprache.', 'rh-languages'), ['status' => 400]);
        }

        // Nur übersetzbare Post-Types. Ohne das ließe sich (dank edit_post-Recht am
        // Quell-Post) ein beliebiger Post-Type duplizieren, den ein Drittplugin
        // registriert hat und der gar nicht für Mehrsprachigkeit gedacht ist.
        if (! in_array($source->post_type, $this->languages->taxonomy()->postTypes(), true)) {
            return new WP_Error('rhlang_bad_type', __('Dieser Inhaltstyp ist nicht übersetzbar.', 'rh-languages'), ['status' => 400]);
        }

        // Anlege-Recht für den Ziel-Post-Type prüfen (nicht nur edit_post am Quell-Post).
        $typeObject = get_post_type_object($source->post_type);
        if ($typeObject === null || ! current_user_can($typeObject->cap->create_posts)) {
            return new WP_Error('rhlang_forbidden', __('Keine Berechtigung, diesen Inhaltstyp anzulegen.', 'rh-languages'), ['status' => 403]);
        }

        // Redaktionsregel: nur von der Default-Sprache aus übersetzen.
        if ($this->languages->langOfPost($sourceId) !== $config->defaultCode()) {
            return new WP_Error(
                'rhlang_not_default',
                __('Übersetzungen bitte von der Version in der Standardsprache aus anlegen.', 'rh-languages'),
                ['status' => 400]
            );
        }

        // Schon vorhanden? Dann die bestehende zurückgeben, kein Duplikat.
        $existing = $this->languages->getTranslation($sourceId, $targetCode, self::EXISTING_STATUSES);
        if ($existing !== null) {
            return $existing;
        }

        // Atomarer Lock gegen Doppelklick/parallele Tabs (TOCTOU): add_option ist
        // durch den Unique-Index auf option_name atomar. Wer den Lock nicht
        // bekommt, gibt das Ergebnis des anderen Requests zurück (oder "busy").
        if (! $this->acquireLock($sourceId, $targetCode)) {
            $again = $this->languages->getTranslation($sourceId, $targetCode, self::EXISTING_STATUSES);

            return $again ?? new WP_Error('rhlang_busy', __('Wird bereits angelegt, bitte kurz warten.', 'rh-languages'), ['status' => 409]);
        }

        try {
            // Strukturelle Bausteine (Nav, Template-Part) teilen sich im Site-Editor
            // sonst den Default-Namen -> Sprach-Suffix, damit sie unterscheidbar sind.
            $title = $source->post_title;
            if (in_array($source->post_type, self::STRUCTURAL_TYPES, true)) {
                $language = $this->languages->config()->byCode($targetCode);
                $suffix = $language !== null ? $language->label : strtoupper($targetCode);
                $title = trim($title) . ' (' . $suffix . ')';
            }

            // Strukturelle Bausteine rendern live (kein Draft-Review wie bei
            // Seiten/Posts), also direkt publish, sonst findet der Render-Swap sie
            // nicht (getTranslation sucht standardmäßig nur publish).
            $newId = wp_insert_post([
                'post_type' => $source->post_type,
                'post_status' => in_array($source->post_type, self::STRUCTURAL_TYPES, true) ? 'publish' : 'draft',
                'post_title' => $title,
                'post_content' => $source->post_content,
                'post_excerpt' => $source->post_excerpt,
                'post_parent' => (int) $source->post_parent,
                'menu_order' => (int) $source->menu_order,
                'comment_status' => $source->comment_status,
                'ping_status' => $source->ping_status,
            ], true);

            if (is_wp_error($newId)) {
                return $newId;
            }

            $newId = (int) $newId;

            $this->copyMeta($sourceId, $newId);
            $this->copyTemplatePartTerms($source, $newId);

            $group = $this->languages->ensureGroup($sourceId);
            $this->languages->assignLanguage($newId, $targetCode);
            if ($group !== null) {
                $this->languages->assignGroup($newId, $group);
            }

            return $newId;
        } finally {
            $this->releaseLock($sourceId, $targetCode);
        }
    }

    /**
     * Template-Part-Kopie: wp_theme-Term (PFLICHT, sonst findet der Renderer die
     * Kopie nicht) und wp_template_part_area (Editor-Konsistenz) von der Quelle
     * übernehmen.
     */
    private function copyTemplatePartTerms(WP_Post $source, int $targetId): void
    {
        if ($source->post_type !== 'wp_template_part') {
            return;
        }

        foreach (['wp_theme', 'wp_template_part_area'] as $taxonomy) {
            $terms = wp_get_object_terms($source->ID, $taxonomy, ['fields' => 'slugs']);
            if (! is_wp_error($terms) && $terms !== []) {
                wp_set_object_terms($targetId, $terms, $taxonomy, false);
            }
        }
    }

    /**
     * Template-Part per Slug (statt Post-ID) übersetzen. Ist der Part nur eine
     * Theme-Datei (kein Post), wird zuerst der Basis-Post materialisiert, damit
     * die Übersetzungsgruppe einen Default-Post hat. Für die Site-Editor-UI +
     * das Management-Panel (Footer/Header sind oft reine Theme-Dateien).
     */
    public function duplicateTemplatePartBySlug(string $slug, string $theme, string $targetCode): int|WP_Error
    {
        $baseId = $this->ensureTemplatePartBase($slug, $theme);
        if (is_wp_error($baseId)) {
            return $baseId;
        }

        return $this->duplicate($baseId, $targetCode);
    }

    /**
     * Basis-Post eines Template-Parts sicherstellen: vorhandenen Post nehmen oder
     * aus der Theme-Datei materialisieren (Content + wp_theme + area), plus
     * Default-Sprache und Gruppe. Gibt die Post-ID zurück.
     */
    private function ensureTemplatePartBase(string $slug, string $theme): int|WP_Error
    {
        $existing = $this->languages->templatePartPostId($slug, $theme);
        if ($existing !== null) {
            return $existing;
        }

        // Ohne Lock legen zwei fast gleichzeitige Requests (Doppelklick auf einen
        // reinen Theme-Datei-Part) je einen Basis-Post an, der zweite mit
        // abweichendem Slug (footer-2) -> verwaistes Duplikat. Lock + Re-Check.
        $lock = 'rhlang_lock_part_' . md5($theme . '|' . $slug);
        if (! $this->acquireNamedLock($lock)) {
            $again = $this->languages->templatePartPostId($slug, $theme, false);

            return $again ?? new WP_Error('rhlang_busy', __('Wird bereits angelegt, bitte kurz warten.', 'rh-languages'), ['status' => 409]);
        }

        try {
            $existing = $this->languages->templatePartPostId($slug, $theme, false);
            if ($existing !== null) {
                return $existing;
            }

            $fileTemplate = get_block_file_template($theme . '//' . $slug, 'wp_template_part');
            if (! $fileTemplate || empty($fileTemplate->content)) {
                return new WP_Error('rhlang_no_part', __('Template-Part nicht gefunden.', 'rh-languages'), ['status' => 404]);
            }

            $newId = wp_insert_post([
                'post_type' => 'wp_template_part',
                'post_status' => 'publish',
                'post_name' => $slug,
                'post_title' => $fileTemplate->title !== '' ? $fileTemplate->title : $slug,
                'post_content' => $fileTemplate->content,
            ], true);

            if (is_wp_error($newId)) {
                return $newId;
            }

            $newId = (int) $newId;

            wp_set_object_terms($newId, $theme, 'wp_theme', false);
            wp_set_object_terms($newId, $fileTemplate->area !== '' ? $fileTemplate->area : 'uncategorized', 'wp_template_part_area', false);

            $this->languages->assignLanguage($newId, $this->languages->defaultCode());
            $this->languages->ensureGroup($newId);

            return $newId;
        } finally {
            $this->releaseNamedLock($lock);
        }
    }

    private function lockKey(int $sourceId, string $targetCode): string
    {
        return 'rhlang_lock_' . $sourceId . '_' . $targetCode;
    }

    private function acquireLock(int $sourceId, string $targetCode): bool
    {
        return $this->acquireNamedLock($this->lockKey($sourceId, $targetCode));
    }

    private function releaseLock(int $sourceId, string $targetCode): void
    {
        $this->releaseNamedLock($this->lockKey($sourceId, $targetCode));
    }

    /**
     * Atomarer Lock über einen benannten Key. add_option scheitert, wenn der Key
     * existiert (Unique-Index auf option_name). Ein verwaister Lock (Request
     * abgestürzt) wird nach 30s gestohlen.
     */
    private function acquireNamedLock(string $key): bool
    {
        if (add_option($key, time(), '', 'no')) {
            return true;
        }

        $held = (int) get_option($key, 0);
        if ($held > 0 && (time() - $held) > 30) {
            delete_option($key);

            return add_option($key, time(), '', 'no');
        }

        return false;
    }

    private function releaseNamedLock(string $key): void
    {
        delete_option($key);
    }

    /**
     * Meta selektiv kopieren: öffentliche Custom-Fields plus eine kleine
     * Allow-Liste sicherer geschützter Keys (Featured Image, Seiten-Template).
     *
     * Bewusst NICHT "alles außer ein paar Locks": geschützte Meta (Unterstrich-
     * Präfix) anderer Plugins (Tokens, Lizenz-/Formulardaten) sollen nicht blind
     * in einen neuen, dem User gehörenden Draft wandern. Der Filter erlaubt
     * Kundenprojekten, die Liste anzupassen.
     */
    private function copyMeta(int $sourceId, int $targetId): void
    {
        $meta = get_post_meta($sourceId);
        if (! is_array($meta)) {
            return;
        }

        /** @var array<int, string> $allowedProtected */
        $allowedProtected = apply_filters(
            'rh-languages/duplicate/protected_meta',
            ['_thumbnail_id', '_wp_page_template'],
            $sourceId,
            $targetId
        );

        foreach ($meta as $key => $values) {
            $key = (string) $key;
            if (in_array($key, self::SKIP_META, true) || ! is_array($values)) {
                continue;
            }
            // Geschützte Keys nur, wenn explizit erlaubt.
            if (is_protected_meta($key, 'post') && ! in_array($key, $allowedProtected, true)) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
            }
        }
    }
}
