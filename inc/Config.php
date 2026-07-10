<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Sprach-Registry (Single Source of Truth).
 *
 * Liest die Sprachliste aus der Setting-Gruppe `languages` (Option
 * `rhbp_settings_languages`, Key `list`). Jeder Eintrag ist ein Assoc-Array
 * (code/locale/label/hreflang/is_default), das über Language::fromArray()
 * zum Value-Object wird. Ist keine Sprache konfiguriert, ist das Modul ein
 * No-Op (isConfigured() === false).
 */
final class Config
{
    public const GROUP_ID = 'languages';
    public const FIELD_LIST = 'list';

    /** @var array<int, Language>|null Lazy-Cache pro Request. */
    private ?array $languages = null;

    /**
     * Alle konfigurierten Sprachen, Reihenfolge wie gespeichert.
     *
     * Die Standardsprache wird NICHT gespeichert, sondern zur Laufzeit aus der
     * WordPress-Sprache (Einstellungen → Allgemein) abgeleitet: die Sprache, die
     * die Site-Locale trifft, ist Default (ohne URL-Prefix). So folgt der Default
     * automatisch der WP-Sprache, statt manuell gewählt zu werden.
     *
     * @return array<int, Language>
     */
    public function all(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $raw = rhbp_setting(self::GROUP_ID, self::FIELD_LIST, []);
        $list = is_array($raw) ? $raw : [];

        $languages = [];
        $seen = [];
        foreach ($list as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $language = Language::fromArray($entry);
            if ($language === null || isset($seen[$language->code])) {
                continue;
            }
            $seen[$language->code] = true;
            // isDefault wird gleich zentral gesetzt, hier neutralisieren.
            $languages[] = new Language($language->code, $language->locale, $language->label, $language->hreflang, false);
        }

        return $this->languages = $this->markDefault($languages);
    }

    /**
     * Markiert die Standardsprache anhand der WP-Site-Locale.
     *
     * @param array<int, Language> $languages
     * @return array<int, Language>
     */
    private function markDefault(array $languages): array
    {
        if ($languages === []) {
            return $languages;
        }

        $defaultCode = $this->resolveDefaultCode($languages);

        return array_map(
            static fn (Language $l): Language => new Language(
                $l->code,
                $l->locale,
                $l->label,
                $l->hreflang,
                $l->code === $defaultCode
            ),
            $languages
        );
    }

    /**
     * @param array<int, Language> $languages Nicht leer.
     */
    private function resolveDefaultCode(array $languages): string
    {
        $siteLocale = $this->siteLocale();

        // 1. Exakte Locale-Übereinstimmung (de_DE == de_DE).
        foreach ($languages as $language) {
            if ($language->locale === $siteLocale) {
                return $language->code;
            }
        }

        // 2. Sprach-Code-Übereinstimmung (de_AT -> de).
        $siteCode = strtolower(substr($siteLocale, 0, 2));
        foreach ($languages as $language) {
            if ($language->code === $siteCode) {
                return $language->code;
            }
        }

        // 3. Fallback: erste Sprache.
        return $languages[0]->code;
    }

    /**
     * WordPress-Site-Sprache (Einstellungen → Allgemein). Stabil, unabhängig von
     * switch_to_locale() auf dem Frontend (liest die Rohoption, nicht get_locale).
     */
    public function siteLocale(): string
    {
        $locale = (string) get_option('WPLANG');

        return $locale !== '' ? $locale : 'en_US';
    }

    public function isConfigured(): bool
    {
        return $this->all() !== [];
    }

    /**
     * Mehr als eine Sprache: erst dann sind Prefix-Routing, Switcher und
     * hreflang sinnvoll.
     */
    public function isMultilingual(): bool
    {
        return count($this->all()) > 1;
    }

    public function default(): Language
    {
        $languages = $this->all();

        foreach ($languages as $language) {
            if ($language->isDefault) {
                return $language;
            }
        }

        // Fallback: erste Sprache, sonst ein neutraler en-Default.
        return $languages[0] ?? new Language('en', 'en_US', 'English', 'en', true);
    }

    public function defaultCode(): string
    {
        return $this->default()->code;
    }

    public function byCode(string $code): ?Language
    {
        foreach ($this->all() as $language) {
            if ($language->code === $code) {
                return $language;
            }
        }

        return null;
    }

    public function has(string $code): bool
    {
        return $this->byCode($code) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_map(static fn (Language $l): string => $l->code, $this->all());
    }

    /**
     * Alle Sprachen außer der Default-Sprache (die einzigen mit URL-Prefix).
     *
     * @return array<int, Language>
     */
    public function nonDefault(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Language $l): bool => ! $l->isDefault
        ));
    }

    /**
     * Cache invalidieren (nach einem Settings-Save).
     */
    public function flush(): void
    {
        $this->languages = null;
    }
}
