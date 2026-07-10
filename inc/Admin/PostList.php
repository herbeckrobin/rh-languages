<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use RhLanguages\Duplicator;
use RhLanguages\Languages;
use RhLanguages\Taxonomy;
use WP_Query;

/**
 * Sprach-Spalte + Sprach-Filter in den Beitragslisten der übersetzbaren
 * Post-Types.
 *
 * Die Spalte zeigt pro konfigurierter Sprache ein kompaktes Kürzel (Polylang-
 * Muster): die eigene Sprache des Beitrags ist markiert, vorhandene
 * Übersetzungen sind Links zum Bearbeiten, fehlende (nur von der Default-Version
 * aus) ein "+"-Link zum Anlegen.
 */
final class PostList
{
    private const FILTER_VAR = 'rh_lang_filter';

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        if (! $this->languages->config()->isMultilingual()) {
            return;
        }

        foreach ($this->languages->taxonomy()->postTypes() as $postType) {
            if ($postType === 'wp_navigation') {
                continue;
            }
            add_filter("manage_{$postType}_posts_columns", [$this, 'addColumn']);
            add_action("manage_{$postType}_posts_custom_column", [$this, 'renderColumn'], 10, 2);
        }

        add_action('restrict_manage_posts', [$this, 'renderFilter']);
        add_action('pre_get_posts', [$this, 'applyFilter']);
        add_action('admin_print_styles-edit.php', [$this, 'printColumnStyles']);
    }

    /**
     * Kompakte Spalten-Styles inline (admin.css lädt nur auf der Settings-Seite).
     */
    public function printColumnStyles(): void
    {
        echo '<style>'
            . '.rhlang-col{display:inline-flex;gap:4px;flex-wrap:wrap}'
            . '.rhlang-col__tag{display:inline-flex;align-items:center;justify-content:center;min-width:1.9em;height:1.7em;padding:0 .35em;font-size:11px;font-weight:600;line-height:1;border-radius:3px;text-decoration:none;border:1px solid transparent}'
            . '.rhlang-col__tag.is-self{background:#2271b1;color:#fff}'
            . '.rhlang-col__tag.is-linked{background:#f0f6fc;color:#2271b1;border-color:#2271b1}'
            . '.rhlang-col__tag.is-create{background:#fff;color:#646970;border-color:#c3c4c7;border-style:dashed}'
            . '.rhlang-col__tag.is-create:hover{color:#2271b1;border-color:#2271b1}'
            . '.rhlang-col__tag.is-empty{background:#f6f7f7;color:#c3c4c7}'
            . '</style>';
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        $out = [];
        $inserted = false;
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['rh_lang'] = __('Sprachen', 'rh-languages');
                $inserted = true;
            }
        }
        if (! $inserted) {
            $out['rh_lang'] = __('Sprachen', 'rh-languages');
        }

        return $out;
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'rh_lang') {
            return;
        }

        $selfLang = $this->languages->langOfPost($postId);
        $isDefaultPost = $selfLang === $this->languages->config()->defaultCode();
        $translations = $this->languages->translations($postId, Duplicator::EXISTING_STATUSES);

        echo '<span class="rhlang-col">';
        foreach ($this->languages->config()->all() as $language) {
            $code = $language->code;
            $upper = strtoupper($code);
            $title = $language->label;

            if ($code === $selfLang) {
                printf(
                    '<span class="rhlang-col__tag is-self" title="%1$s">%2$s</span>',
                    esc_attr(sprintf(/* translators: %s: language name */ __('Diese Version: %s', 'rh-languages'), $title)),
                    esc_html($upper)
                );
                continue;
            }

            if (isset($translations[$code])) {
                $editUrl = get_edit_post_link($translations[$code]);
                printf(
                    '<a class="rhlang-col__tag is-linked" href="%1$s" title="%2$s">%3$s</a>',
                    esc_url((string) $editUrl),
                    esc_attr(sprintf(/* translators: %s: language name */ __('%s bearbeiten', 'rh-languages'), $title)),
                    esc_html($upper)
                );
                continue;
            }

            if ($isDefaultPost) {
                printf(
                    '<a class="rhlang-col__tag is-create" href="%1$s" title="%2$s">%3$s +</a>',
                    esc_url(CreateController::url($postId, $code)),
                    esc_attr(sprintf(/* translators: %s: language name */ __('%s anlegen', 'rh-languages'), $title)),
                    esc_html($upper)
                );
                continue;
            }

            printf('<span class="rhlang-col__tag is-empty" title="%1$s">%2$s</span>', esc_attr($title), esc_html($upper));
        }
        echo '</span>';
    }

    public function renderFilter(string $postType): void
    {
        if (! in_array($postType, $this->languages->taxonomy()->postTypes(), true)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Filter, kein State-Change.
        $selected = isset($_GET[self::FILTER_VAR]) ? sanitize_key(wp_unslash($_GET[self::FILTER_VAR])) : '';

        echo '<select name="' . esc_attr(self::FILTER_VAR) . '">';
        echo '<option value="">' . esc_html__('Alle Sprachen', 'rh-languages') . '</option>';
        foreach ($this->languages->config()->all() as $language) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($language->code),
                selected($selected, $language->code, false),
                esc_html($language->label)
            );
        }
        echo '</select>';
    }

    public function applyFilter(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Filter, kein State-Change.
        $code = isset($_GET[self::FILTER_VAR]) ? sanitize_key(wp_unslash($_GET[self::FILTER_VAR])) : '';
        if ($code === '' || ! $this->languages->config()->has($code)) {
            return;
        }

        $taxQuery = $query->get('tax_query');
        $taxQuery = is_array($taxQuery) ? $taxQuery : [];
        $taxQuery[] = [
            'taxonomy' => Taxonomy::TAX_LANG,
            'field' => 'slug',
            'terms' => $code,
        ];
        $query->set('tax_query', $taxQuery);
    }
}
