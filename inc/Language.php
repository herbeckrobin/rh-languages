<?php

declare(strict_types=1);

namespace RhLanguages;

/**
 * Immutable Value-Object für eine konfigurierte Sprache.
 *
 * Der `code` ist der Kurz-Slug (`en`, `de`) und dient zugleich als URL-Prefix
 * und als rh_lang-Term-Slug. `locale` ist die WP-Locale (`en_GB`, `de_DE`) für
 * switch_to_locale/gettext. `hreflang` ist der Wert fürs alternate-Tag.
 */
final class Language
{
    public function __construct(
        public readonly string $code,
        public readonly string $locale,
        public readonly string $label,
        public readonly string $hreflang,
        public readonly bool $isDefault,
    ) {
    }

    /**
     * Baut eine Sprache aus einem gespeicherten Assoc-Array (mit Fallbacks).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $code = sanitize_key((string) ($data['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $locale = (string) ($data['locale'] ?? '');
        $label = (string) ($data['label'] ?? '');
        $hreflang = (string) ($data['hreflang'] ?? '');

        return new self(
            code: $code,
            locale: $locale !== '' ? $locale : $code,
            label: $label !== '' ? $label : strtoupper($code),
            hreflang: $hreflang !== '' ? $hreflang : $code,
            isDefault: ! empty($data['is_default']),
        );
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'locale' => $this->locale,
            'label' => $this->label,
            'hreflang' => $this->hreflang,
            'is_default' => $this->isDefault,
        ];
    }
}
