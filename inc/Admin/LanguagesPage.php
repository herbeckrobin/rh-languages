<?php

declare(strict_types=1);

namespace RhLanguages\Admin;

use RhBlueprint\Core\Admin\Ui;
use RhBlueprint\Core\Admin\Assets;
use RhLanguages\Config;
use RhLanguages\Features;
use RhLanguages\Plugin;
use RhLanguages\WpLocales;
use RhBlueprint\Core\Settings\SettingsPage;

/**
 * Settings-Tab "Sprachen": Sprach-Registry + Funktions-Schalter.
 *
 * Sprachen werden aus der vorgefertigten WordPress-Liste gewählt (kein Freitext),
 * Code/Bezeichnung/hreflang leiten sich daraus ab. Darunter schaltet eine
 * Funktions-Matrix die optionalen Bausteine an/aus. Kein GroupInterface,
 * stattdessen eigener Content über die tab_content-Hooks und ein admin-post-
 * Handler, der in die Option `rhbp_settings_languages` schreibt.
 */
final class LanguagesPage
{
    public const TAB = 'languages';
    private const CAP = 'manage_options';
    private const ACTION_SAVE = 'rhlang_save';
    private const NONCE = 'rhlang_save_nonce';
    private const EXTRA_ROWS = 2;

    public function __construct(private readonly Config $config)
    {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (! Assets::onSettings()) {
            return;
        }
        $abs = RHLANG_PLUGIN_DIR . 'assets/css/admin.css';
        if (! file_exists($abs)) {
            return;
        }
        wp_enqueue_style(
            'rh-languages-admin',
            RHLANG_PLUGIN_URL . 'assets/css/admin.css',
            ['rh-blueprint-settings'],
            (string) filemtime($abs)
        );
    }

    public function renderMessage(string $tab): void
    {
        if ($tab !== self::TAB) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige nach Redirect.
        $message = isset($_GET['rhlang_message']) ? sanitize_key(wp_unslash($_GET['rhlang_message'])) : '';
        if ($message !== 'saved') {
            return;
        }
        echo '<div class="rhbp-callout rhbp-callout--success">' . esc_html__('Gespeichert.', 'rh-languages') . '</div>';
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can(self::CAP)) {
            return;
        }

        $this->config->flush();
        $languages = $this->config->all();
        $locales = WpLocales::available();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION_SAVE, self::NONCE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';

        $this->renderLanguages($languages, $locales);
        $this->renderFeatures();

        echo '<p><button type="submit" class="rhbp-btn rhbp-btn--primary">' . esc_html__('Speichern', 'rh-languages') . '</button></p>';
        echo '</form>';

        $this->renderSnippets();
    }

    /**
     * @param array<int, \RhLanguages\Language>                                  $languages
     * @param array<string, array{label: string, code: string, hreflang: string}> $locales
     */
    private function renderLanguages(array $languages, array $locales): void
    {
        $siteLocale = $this->config->siteLocale();
        $siteLabel = $locales[$siteLocale]['label'] ?? $siteLocale;

        echo '<h3 class="rhlang-h">' . esc_html__('Sprachen', 'rh-languages') . '</h3>';
        echo '<p class="rhlang-intro">' . esc_html__('Sprachen aus der WordPress-Liste wählen. Die Standardsprache (ohne URL-Prefix) markieren, alle anderen bekommen ihren Code als Prefix (z.B. /de/). Zeile leer lassen zum Entfernen.', 'rh-languages') . '</p>';

        echo '<p class="rhlang-note">'
            . sprintf(
                /* translators: %s: current WordPress site language name */
                esc_html__('Die Standardsprache ist frei wählbar und unabhängig von der WordPress-Sprache (aktuell: %s). So kannst du z.B. ein deutsches Backend behalten und trotzdem Englisch als Standard fahren. Ohne eigene Wahl folgt die Standardsprache der WordPress-Sprache.', 'rh-languages'),
                '<strong>' . esc_html($siteLabel) . '</strong>'
            )
            . '</p>';

        echo '<table class="rhlang-table"><thead><tr>';
        echo '<th class="rhlang-cell-default">' . esc_html__('Standard', 'rh-languages') . '</th>';
        echo '<th>' . esc_html__('Sprache', 'rh-languages') . '</th>';
        echo '<th>' . esc_html__('URL-Prefix', 'rh-languages') . '</th>';
        echo '</tr></thead><tbody>';

        // Frische Installation: die WP-Sprache vorbelegen (sie wird ohnehin zum
        // Standard, siehe Config::resolveDefaultCode).
        $seedLocale = $languages === [] && isset($locales[$siteLocale]) ? $siteLocale : '';

        $rowCount = count($languages) + self::EXTRA_ROWS;
        for ($i = 0; $i < $rowCount; $i++) {
            $language = $languages[$i] ?? null;

            if ($language === null && $i === 0 && $seedLocale !== '') {
                $selectedLocale = $seedLocale;
                $isDefault = true;
                $code = $locales[$seedLocale]['code'] ?? '';
            } else {
                $selectedLocale = $language?->locale ?? '';
                $isDefault = $language !== null ? $language->isDefault : false;
                $code = $language?->code ?? '';
            }

            echo '<tr>';

            echo '<td class="rhlang-cell-default"><input type="radio" name="lang_default" value="' . esc_attr((string) $i) . '"' . checked($isDefault, true, false) . '></td>';

            echo '<td><select name="lang_locale[' . (int) $i . ']" class="rhlang-select">';
            echo '<option value="">' . esc_html__('(keine)', 'rh-languages') . '</option>';
            foreach ($locales as $locale => $meta) {
                printf(
                    '<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr($locale),
                    selected($selectedLocale, $locale, false),
                    esc_html($meta['label'] . ' (' . $locale . ')')
                );
            }
            echo '</select></td>';

            if ($code === '') {
                $prefix = '';
            } elseif ($isDefault) {
                $prefix = esc_html__('(kein Prefix)', 'rh-languages');
            } else {
                $prefix = '/' . esc_html($code) . '/';
            }
            echo '<td class="rhlang-prefix">' . $prefix . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderFeatures(): void
    {
        echo '<h3 class="rhlang-h">' . esc_html__('Funktionen', 'rh-languages') . '</h3>';
        echo '<p class="rhlang-intro">' . esc_html__('Optionale Bausteine an- oder abschalten. Die Sprachtrennung selbst (Inhalte pro Sprache, URL-Prefix) ist immer aktiv.', 'rh-languages') . '</p>';

        echo '<div class="rhlang-features">';
        foreach (Features::all() as $key => $feature) {
            $on = Features::enabled($key);
            echo '<div class="rhbp-card rhlang-feature">';
            echo '<div class="rhlang-feature__text"><strong>' . esc_html($feature['label']) . '</strong><span>' . esc_html($feature['description']) . '</span></div>';
            echo Ui::switch([
                'name' => 'feature[' . $key . ']',
                'checked' => $on,
            ]);
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Copy-Paste-Snippets, um den Switcher frei im Theme zu platzieren.
     */
    private function renderSnippets(): void
    {
        $php = "<?php foreach ( rh_lang_links() as \$l ) : ?>\n"
            . "  <a href=\"<?php echo esc_url( \$l['url'] ); ?>\"\n"
            . "     hreflang=\"<?php echo esc_attr( \$l['hreflang'] ); ?>\"\n"
            . "     class=\"lang <?php echo \$l['current'] ? 'is-active' : ''; ?>\">\n"
            . "    <?php echo esc_html( \$l['label'] ); ?>\n"
            . "  </a>\n"
            . "<?php endforeach; ?>";

        echo '<h3 class="rhlang-h">' . esc_html__('Eigene Switcher-Buttons', 'rh-languages') . '</h3>';
        echo '<p class="rhlang-intro">' . esc_html__('Den Sprach-Switcher frei im Theme platzieren. rh_lang_links() gibt dir pro Sprache code, label, hreflang, url und current (bool) zum eigenen Markup. Gleiche Auswahl-Logik wie Block und Shortcode.', 'rh-languages') . '</p>';

        $this->snippet(__('PHP im Theme-Template (volle Kontrolle über das Markup)', 'rh-languages'), 'rhlang-snip-php', $php);
        $this->snippet(__('Shortcode (in Inhalt oder Widget)', 'rh-languages'), 'rhlang-snip-sc', '[rh_language_switcher]');
        $this->snippet(__('Standard-Markup 1:1 im Template', 'rh-languages'), 'rhlang-snip-fn', "<?php echo rh_lang_switcher_html(); ?>");

        echo '<p class="rhlang-hint">' . esc_html__('Ohne Argument nutzt rh_lang_links() das aktuelle Objekt (Einzelseite = Gegenstück der Übersetzungsgruppe, sonst die Sprach-Startseite). Für ein bestimmtes Objekt: rh_lang_links( $post_id ).', 'rh-languages') . '</p>';
    }

    private function snippet(string $label, string $id, string $code): void
    {
        echo '<div class="rhlang-snippet">';
        echo '<div class="rhlang-snippet__head"><strong>' . esc_html($label) . '</strong>';
        echo '<button type="button" class="rhbp-btn rhbp-btn--ghost" data-rhbp-copy="#' . esc_attr($id) . '">' . esc_html__('Kopieren', 'rh-languages') . '</button></div>';
        echo '<pre id="' . esc_attr($id) . '" class="rhlang-code">' . esc_html($code) . '</pre>';
        echo '</div>';
    }

    public function handleSave(): void
    {
        if (! isset($_POST[self::NONCE]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::ACTION_SAVE)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-languages'));
        }
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-languages'));
        }

        // Sprachen aus den gewählten WP-Locales bauen (kein Freitext).
        $chosen = isset($_POST['lang_locale']) && is_array($_POST['lang_locale']) ? wp_unslash($_POST['lang_locale']) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce oben geprüft.
        $defaultIndex = isset($_POST['lang_default']) ? (int) $_POST['lang_default'] : -1;

        $available = WpLocales::available();
        $list = [];
        $seen = [];
        $defaultCode = '';

        foreach ($chosen as $index => $rawLocale) {
            $locale = sanitize_text_field((string) $rawLocale);
            if ($locale === '' || ! isset($available[$locale])) {
                continue;
            }
            $meta = $available[$locale];
            $code = $meta['code'];
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            if ((int) $index === $defaultIndex) {
                $defaultCode = $code;
            }

            $list[] = [
                'code' => $code,
                'locale' => $locale,
                'label' => $meta['label'],
                'hreflang' => $meta['hreflang'],
            ];
        }

        rhbp_update_setting(Config::GROUP_ID, Config::FIELD_LIST, $list);
        // Explizite Standardsprache (leer = fällt auf die WP-Sprache zurück).
        rhbp_update_setting(Config::GROUP_ID, Config::FIELD_DEFAULT, $defaultCode);

        // Funktions-Schalter.
        $features = isset($_POST['feature']) && is_array($_POST['feature']) ? $_POST['feature'] : [];
        foreach (array_keys(Features::all()) as $key) {
            rhbp_update_setting(Config::GROUP_ID, 'feature_' . $key, isset($features[$key]));
        }

        // Rewrite-Regeln neu bauen + Bestand migrieren.
        Plugin::scheduleFlush();

        wp_safe_redirect(add_query_arg(
            ['page' => SettingsPage::MENU_SLUG, 'tab' => self::TAB, 'rhlang_message' => 'saved'],
            admin_url('admin.php')
        ));
        exit;
    }
}
