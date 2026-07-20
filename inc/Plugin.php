<?php

declare(strict_types=1);

namespace RhLanguages;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhLanguages\Admin\CreateController;
use RhLanguages\Admin\LanguagesPage;
use RhLanguages\Admin\PostList;
use RhLanguages\Admin\StructuralPanel;
use RhLanguages\Admin\TranslateController;
use RhLanguages\Blocks\SwitcherBlock;

/**
 * Bootstrap von rh-languages.
 *
 * Hängt am Core-Hook `rh-blueprint/core/booted` (feuert auf `init`), weil
 * Taxonomien, Rewrite-Regeln und __() erst ab `init` laufen dürfen. Baut den
 * Languages-Singleton (die Laufzeit-API) und verdrahtet die Subsysteme
 * (Query-Filter, Routing, Head, Frontend, Editor, Admin) nur, wenn mindestens
 * eine Sprache konfiguriert ist. Ohne Konfiguration ist das Modul ein No-Op.
 */
final class Plugin
{
    private const OPT_FLUSH = 'rhlang_flush';
    private const OPT_SETUP = 'rhlang_needs_setup';
    private const OPT_VERSION = 'rhlang_version';

    public static function boot(): void
    {
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        // Languages-Singleton so früh wie möglich bereitstellen, damit die
        // globalen rh_lang_* Helper auch vor dem Core-Boot sinnvoll antworten.
        $config = new Config();
        $taxonomy = new Taxonomy($config);
        Languages::setInstance(new Languages($config, $taxonomy));

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        load_plugin_textdomain('rh-languages', false, dirname(plugin_basename(RHLANG_PLUGIN_FILE)) . '/languages');

        $languages = Languages::instance();
        if ($languages === null) {
            return;
        }

        $languages->config()->flush();

        // Cross-Modul-API: andere Module (rh-seo) fragen die aktive Sprache ab.
        $core->services()->register('languages', $languages, 1);

        // Taxonomien immer registrieren (auch ohne Config, damit bestehende
        // Zuweisungen erhalten bleiben), Terms nur bei vorhandener Config.
        $languages->taxonomy()->register();
        $languages->taxonomy()->ensureLanguageTerms();

        // Settings-Tab immer anbieten, damit Sprachen überhaupt anlegbar sind.
        $core->settings()->registerTab('languages', __('Sprachen', 'rh-languages'), 35);
        (new LanguagesPage($languages->config()))->boot();

        // Verzögertes Setup (Migration + Rewrite-Flush) verarbeiten.
        add_action('admin_init', [self::class, 'maybeSetup']);
        add_action('init', [self::class, 'maybeFlush'], 99);

        // Nach einem Plugin-Update Rewrite-Regeln neu bauen, falls sich deren
        // Inhalt geändert hat (der Regel-Key bleibt gleich, die DB-Kopie nicht).
        if (get_option(self::OPT_VERSION) !== RHLANG_VERSION) {
            update_option(self::OPT_FLUSH, 1);
            update_option(self::OPT_VERSION, RHLANG_VERSION);
        }

        // Wechselt die WordPress-Sprache (Einstellungen → Allgemein), ändert sich
        // die Standardsprache und damit die Prefix-Zuordnung. Rewrite-Regeln beim
        // nächsten Request neu bauen, sonst 404 auf den neu geprefixten URLs.
        add_action('update_option_WPLANG', [self::class, 'onSiteLanguageChanged']);
        add_action('add_option_WPLANG', [self::class, 'onSiteLanguageChanged']);

        // Dashboard-Quick-Link beisteuern.
        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('Sprachen', 'rh-languages'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=languages'),
                'icon' => 'translation',
            ];
            return $links;
        });

        if (! $languages->config()->isConfigured()) {
            return;
        }

        // --- Fundament (nicht abschaltbar): Sprachtrennung + Routing ---
        (new Query($languages))->boot();
        (new Routing($languages))->boot();

        // --- Optionale Funktionen (Feature-Schalter im Sprachen-Tab) ---
        (new Head($languages))->boot();      // hreflang / html lang / view transitions
        (new Frontend($languages))->boot();  // Startseite + Menü + Template-Parts pro Sprache
        (new Locale($languages))->boot();    // Theme-Texte übersetzen

        // Objekt-Typ-Zuordnung der versteckten Taxonomien an die übersetzbaren
        // Post-Types nachziehen (taxonomy()->register() lief früher im Boot und
        // kannte die per Feature-Filter ergänzten Typen wie nav/template-part noch
        // nicht). ZUSÄTZLICH spät auf init:99 wiederholen: der Core bootet auf
        // init:1, also VOR der üblichen CPT-Registrierung (Theme/Plugin auf
        // init:10). Ein Attach nur hier verpasst spät registrierte CPTs (z.B.
        // `artwork`) je nach Reihenfolge, dann hängt rh_lang nicht an ihnen, der
        // Sprach-Filter überspringt ihre Archive und zeigt übersetzte CPTs
        // sprachneutral (EN+DE-Dubletten). init:99 läuft nach allen CPTs, das
        // macht die Zuordnung deterministisch. Idempotent.
        self::attachTranslatableObjectTypes();
        add_action('init', [self::class, 'attachTranslatableObjectTypes'], 99);

        // Switcher-Block immer registrieren (frei platzierbar), Auto-Anzeige optional.
        (new SwitcherBlock($languages))->boot();
        (new Shortcode())->boot();
        if (Features::enabled(Features::AUTO_SWITCHER)) {
            (new AutoSwitcher())->boot();
        }

        // Anlege-Handler (Duplizieren als Draft) wird von Editor UND Liste genutzt.
        (new CreateController($languages))->boot();

        // Management-Panel für strukturelle Bausteine (Menüs, Template-Parts).
        (new StructuralPanel($languages))->boot();

        if (Features::enabled(Features::EDITOR_SIDEBAR)) {
            (new TranslateController($languages))->boot();
            (new EditorSidebar($languages))->boot();
        }

        if (Features::enabled(Features::POST_COLUMN)) {
            (new PostList($languages))->boot();
        }
    }

    /**
     * Hängt die versteckten Sprach-Taxonomien (rh_lang, rh_lang_group) an alle
     * übersetzbaren Post-Types. Idempotent, läuft inline beim Core-Boot (init:1)
     * UND spät auf init:99, damit auch CPTs erfasst werden, die erst nach dem
     * Core-Boot registriert werden (Theme/Plugin auf init:10).
     */
    public static function attachTranslatableObjectTypes(): void
    {
        $languages = Languages::instance();
        if ($languages === null || ! $languages->config()->isConfigured()) {
            return;
        }

        foreach ($languages->taxonomy()->postTypes() as $translatableType) {
            register_taxonomy_for_object_type(Taxonomy::TAX_LANG, $translatableType);
            register_taxonomy_for_object_type(Taxonomy::TAX_GROUP, $translatableType);
        }
    }

    /**
     * Aktivierung: Migration + Rewrite-Flush anstoßen (verzögert über Flags,
     * unabhängig vom Aktivierungs-Timing).
     */
    public static function onActivate(): void
    {
        update_option(self::OPT_SETUP, 1);
        update_option(self::OPT_FLUSH, 1);
    }

    public static function onDeactivate(): void
    {
        flush_rewrite_rules(false);
    }

    /**
     * Einmalige Migration des Bestands (admin_init, geflaggt).
     */
    public static function maybeSetup(): void
    {
        if (! get_option(self::OPT_SETUP)) {
            return;
        }

        // Die Bulk-Zuweisung ist potenziell teuer, nur für berechtigte Nutzer.
        if (! current_user_can('manage_options')) {
            return;
        }

        $languages = Languages::instance();
        if ($languages !== null && $languages->config()->isConfigured()) {
            (new Migration($languages))->run();
            delete_option(self::OPT_SETUP);
            update_option(self::OPT_FLUSH, 1);
        }
    }

    /**
     * Rewrite-Regeln neu bauen, wenn geflaggt (Aktivierung/Config-Änderung).
     * NUR hier, nie pro Request.
     */
    public static function maybeFlush(): void
    {
        if (get_option(self::OPT_FLUSH)) {
            flush_rewrite_rules(false);
            delete_option(self::OPT_FLUSH);
        }
    }

    /**
     * WordPress-Sprache wurde umgestellt: nur Rewrite-Regeln neu bauen (die
     * Standardsprache und damit die Prefixe ändern sich). Keine Migration nötig,
     * die Sprachzuordnung der Posts bleibt unverändert.
     */
    public static function onSiteLanguageChanged(): void
    {
        update_option(self::OPT_FLUSH, 1);
    }

    /**
     * Von der Settings-Page nach einem Sprach-Save aufgerufen: beim nächsten
     * Request Rewrite-Regeln neu bauen und Migration nachziehen.
     */
    public static function scheduleFlush(): void
    {
        update_option(self::OPT_FLUSH, 1);
        update_option(self::OPT_SETUP, 1);
    }
}
