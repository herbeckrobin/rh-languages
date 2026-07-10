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
            $newId = wp_insert_post([
                'post_type' => $source->post_type,
                'post_status' => 'draft',
                'post_title' => $source->post_title,
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

    private function lockKey(int $sourceId, string $targetCode): string
    {
        return 'rhlang_lock_' . $sourceId . '_' . $targetCode;
    }

    /**
     * Atomarer Lock. add_option scheitert, wenn der Key existiert. Ein verwaister
     * Lock (Request abgestürzt) wird nach 30s gestohlen.
     */
    private function acquireLock(int $sourceId, string $targetCode): bool
    {
        $key = $this->lockKey($sourceId, $targetCode);

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

    private function releaseLock(int $sourceId, string $targetCode): void
    {
        delete_option($this->lockKey($sourceId, $targetCode));
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
