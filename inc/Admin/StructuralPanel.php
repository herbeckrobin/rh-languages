<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use RhLanguages\Duplicator;
use RhLanguages\Features;
use RhLanguages\Languages;
use WP_Post;

/**
 * Management-Panel im Sprachen-Tab: listet die strukturellen Bausteine (Menüs,
 * Template-Parts) mit ihren Übersetzungen pro Sprache und macht das Anlegen
 * diskoverbar. Ohne dieses Panel sieht man im Site-Editor nur zwei gleichnamige
 * Bausteine und hat keinen Weg, eine Übersetzung anzulegen.
 *
 * Reine Wiederverwendung: dieselben Kürzel wie die Post-Listen-Spalte, derselbe
 * Anlege-Handler (CreateController), dieselbe Site-Editor-Verlinkung.
 */
final class StructuralPanel
{
    private const CAP = 'manage_options';

    public function __construct(private readonly Languages $languages)
    {
    }

    public function boot(): void
    {
        // Nach LanguagesPage (Priorität 10) rendern.
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render'], 20);
    }

    public function render(string $tab): void
    {
        if ($tab !== LanguagesPage::TAB || ! current_user_can(self::CAP)) {
            return;
        }

        $navOn = Features::enabled(Features::NAV_PER_LANGUAGE);
        $partsOn = Features::enabled(Features::TEMPLATE_PART_PER_LANGUAGE);
        if (! $navOn && ! $partsOn) {
            return;
        }

        echo '<h3 class="rhlang-h">' . esc_html__('Strukturelle Übersetzungen', 'rh-languages') . '</h3>';
        echo '<p class="rhlang-intro">' . esc_html__('Menüs und Template-Parts (Footer, Header) übersetzt du wie Seiten: pro Sprache eine eigene Version, die im Site-Editor gepflegt wird. Kürzel anklicken zum Bearbeiten oder "+ anlegen".', 'rh-languages') . '</p>';

        if ($navOn) {
            $this->renderNavigations();
        }
        if ($partsOn) {
            $this->renderTemplateParts();
        }
    }

    private function renderNavigations(): void
    {
        $default = $this->languages->defaultCode();

        // Nur Menüs, die tatsächlich per core/navigation "ref" eingebunden sind.
        // Der Sprach-Swap wirkt nur auf ref-referenzierte Navs, alles andere
        // (verwaiste Autosave-/Test-Navs) ist nicht übersetzbar und wäre nur Rauschen.
        $anchors = $this->navigationAnchors($default);

        echo '<h4 class="rhlang-sub">' . esc_html__('Menüs', 'rh-languages') . '</h4>';
        if ($anchors === []) {
            echo '<p class="rhlang-hint">' . esc_html__('Kein Menü im Einsatz. Sobald ein Navigations-Block in einem Template oder Template-Part ein Menü nutzt, erscheint es hier.', 'rh-languages') . '</p>';

            return;
        }

        echo '<div class="rhlang-struct">';
        foreach ($anchors as $nav) {
            $translations = $this->languages->translations($nav->ID, Duplicator::EXISTING_STATUSES);
            $this->renderRow(
                $nav->post_title !== '' ? $nav->post_title : __('(ohne Titel)', 'rh-languages'),
                SiteEditorLink::nav($nav->ID),
                $default,
                $translations,
                fn(int $id): string => SiteEditorLink::nav($id),
                fn(string $code): string => CreateController::url($nav->ID, $code)
            );
        }
        echo '</div>';
    }

    /**
     * Anker-Menüs (in Standardsprache) für alle tatsächlich genutzten Navs: pro
     * Übersetzungsgruppe genau eine Zeile. Ein per ref genutztes Menü in einer
     * Sekundärsprache wird auf seinen Default-Anker abgebildet.
     *
     * @return array<int, WP_Post>
     */
    private function navigationAnchors(string $default): array
    {
        $anchors = [];
        foreach ($this->usedNavigationIds() as $navId) {
            $nav = get_post($navId);
            if (! $nav instanceof WP_Post || $nav->post_type !== 'wp_navigation') {
                continue;
            }

            $anchorId = $this->languages->langOfPost($navId) === $default
                ? $navId
                : ($this->languages->getTranslation($navId, $default, Duplicator::EXISTING_STATUSES) ?? $navId);

            if (isset($anchors[$anchorId])) {
                continue;
            }
            $anchor = get_post($anchorId);
            if ($anchor instanceof WP_Post) {
                $anchors[$anchorId] = $anchor;
            }
        }

        return $anchors;
    }

    /**
     * Alle per core/navigation "ref" eingebundenen Menü-IDs, aus DB-Inhalten
     * (Templates, Parts, Muster, Seiten) UND Theme-Datei-Templates/-Parts.
     *
     * @return array<int, int>
     */
    private function usedNavigationIds(): array
    {
        global $wpdb;

        /** @var array<int, string> $contents */
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft') AND post_content LIKE '%wp:navigation%'"
        );

        foreach (['wp_template', 'wp_template_part'] as $type) {
            foreach (get_block_templates([], $type) as $template) {
                $contents[] = (string) $template->content;
            }
        }

        $ids = [];
        foreach ($contents as $content) {
            if (preg_match_all('/"ref"\s*:\s*(\d+)/', (string) $content, $matches)) {
                foreach ($matches[1] as $ref) {
                    $ids[(int) $ref] = (int) $ref;
                }
            }
        }

        return $ids;
    }

    private function renderTemplateParts(): void
    {
        $default = $this->languages->defaultCode();
        $theme = get_stylesheet();
        // get_block_templates liefert Theme-Datei-Parts + angepasste Post-Parts
        // gemischt; der Inserter-Filter (Frontend) blendet Sekundärsprachen bereits aus.
        $parts = get_block_templates([], 'wp_template_part');

        echo '<h4 class="rhlang-sub">' . esc_html__('Template-Parts', 'rh-languages') . '</h4>';
        if ($parts === []) {
            echo '<p class="rhlang-hint">' . esc_html__('Dieses Theme hat keine Template-Parts.', 'rh-languages') . '</p>';

            return;
        }

        echo '<div class="rhlang-struct">';
        foreach ($parts as $part) {
            $slug = (string) $part->slug;
            $baseId = isset($part->wp_id) && $part->wp_id ? (int) $part->wp_id : $this->languages->templatePartPostId($slug, $theme);

            // Sekundärsprachlicher Part (Sicherheitsnetz, falls der Filter aus ist): überspringen.
            if ($baseId !== null && $this->languages->langOfPost($baseId) !== $default) {
                continue;
            }

            $translations = $baseId !== null ? $this->languages->translations($baseId, Duplicator::EXISTING_STATUSES) : [];
            // WP_Block_Template->title ist ein String (das ->rendered-Objektformat
            // kommt erst aus dem REST-Controller).
            $title = is_string($part->title) && $part->title !== '' ? $part->title : $slug;

            $this->renderRow(
                $title,
                SiteEditorLink::part($theme, $slug),
                $default,
                $translations,
                fn(int $id): string => $this->partEditLink($id, $theme),
                fn(string $code): string => CreateController::urlForPart($slug, $theme, $code)
            );
        }
        echo '</div>';
    }

    /**
     * Eine Baustein-Zeile: Titel + Bearbeiten-Link + Sprach-Kürzel.
     *
     * @param array<string, int>       $translations code => Post-ID
     * @param callable(int): string    $editLinkFor  Editor-URL einer Übersetzung
     * @param callable(string): string $createFor    Anlege-URL für eine Sprache
     */
    private function renderRow(string $title, string $selfEditUrl, string $selfCode, array $translations, callable $editLinkFor, callable $createFor): void
    {
        echo '<div class="rhbp-card rhlang-struct__row">';
        echo '<a class="rhlang-struct__title" href="' . esc_url($selfEditUrl) . '">' . esc_html($title) . '</a>';
        echo '<span class="rhlang-col">';

        foreach ($this->languages->config()->all() as $language) {
            $code = $language->code;
            $upper = strtoupper($code);
            $label = $language->label;

            if ($code === $selfCode) {
                printf(
                    '<span class="rhlang-col__tag is-self" title="%1$s">%2$s</span>',
                    esc_attr(sprintf(/* translators: %s: language name */ __('Diese Version: %s', 'rh-languages'), $label)),
                    esc_html($upper)
                );
                continue;
            }

            if (isset($translations[$code])) {
                printf(
                    '<a class="rhlang-col__tag is-linked" href="%1$s" title="%2$s">%3$s</a>',
                    esc_url($editLinkFor($translations[$code])),
                    esc_attr(sprintf(/* translators: %s: language name */ __('%s bearbeiten', 'rh-languages'), $label)),
                    esc_html($upper)
                );
                continue;
            }

            printf(
                '<a class="rhlang-col__tag is-create" href="%1$s" title="%2$s">%3$s +</a>',
                esc_url($createFor($code)),
                esc_attr(sprintf(/* translators: %s: language name */ __('%s anlegen', 'rh-languages'), $label)),
                esc_html($upper)
            );
        }

        echo '</span></div>';
    }

    private function partEditLink(int $postId, string $theme): string
    {
        $post = get_post($postId);

        return $post instanceof WP_Post ? SiteEditorLink::part($theme, $post->post_name) : '';
    }
}
