# RH Languages

Schlanke Mehrsprachigkeit für WordPress (FSE), als Modul der rh-blueprint Kollektion. Kein Polylang/WPML, keine Lizenz, kein externer Dienst. So viel WordPress-Core wie möglich, so wenig Custom wie nötig.

## Grundprinzip

Eine Übersetzung ist ein **eigener echter Post**, den der Kunde ganz normal im Editor pflegt. Kein Feld-im-Feld-Übersetzen. Die Sprachversionen werden über zwei versteckte Taxonomien verknüpft:

- `rh_lang`: ein Term pro Sprache (`en`, `de`), ein Term pro Post.
- `rh_lang_group`: ein Term pro Übersetzungsgruppe, alle Sprachversionen teilen ihn.

Das ist der Polylang-Ansatz, aber schlank und core-nativ (kein serialisierter Blob, kein eigenes DB-Schema).

## Was es kann

- **Sprach-Registry** im rh-Blueprint-Menü (Tab "Sprachen"): Code, Locale, Bezeichnung, hreflang, eine Standardsprache.
- **URL-Routing**: Standardsprache ohne Prefix, alle anderen mit (`/about/` vs `/de/ueber/`). Duplicate-Content-Schutz per 301.
- **Sprachtrennung**: Frontend-Queries zeigen nur Inhalte der aktiven Sprache (Opt-out per `'rhlang_skip' => true`).
- **Sprach-Switcher** als SSR-Block (`rh/language-switcher`): verlinkt aufs Gegenstück der aktuellen Seite, reiner Text-Switch, keine Flaggen.
- **hreflang + x-default** und das `html lang`-Attribut, aus einer Quelle (koexistiert mit rh-seo).
- **Editor-UI**: angepinntes Sprach-Icon oben rechts im Editor-Header. Klick öffnet die Sprachen-Sidebar mit "+ Übersetzung anlegen" (dupliziert als Draft, springt in den neuen Beitrag).
- **Post-Listen**: Sprach-Spalte + Filter.
- **Menü pro Sprache**: `wp_navigation` ist übersetzbar, der Navigation-Block wird sprachrichtig umgebogen.
- **Startseite pro Sprache** (`page_on_front` / `page_for_posts`).
- **Theme-Strings** folgen der Sprache über `switch_to_locale()` (Theme-`.mo` vorausgesetzt).

## Bewusst nicht drin

String-Scanner, automatische Maschinenübersetzung, Translation Memory, eigene DB-Tabellen, Cookie-/Browser-Language-Redirect (bricht Full-Page-Cache, SEO-heikel). Das ist das schlanke 80%, kein WPML-Nachbau.

## Voraussetzungen

- WordPress 6.5+, PHP 8.1+, Pretty Permalinks.
- Block-Theme (FSE). Für übersetzte Theme-Texte müssen die fixen Strings durch `__()`/`esc_html__()` laufen und eine passende `<textdomain>-<locale>.mo` vorliegen.

## API

Globale Helper (für Theme/andere Module):

```php
rh_lang_current(): string
rh_lang_default(): string
rh_lang_all(): array               // Language[]
rh_lang_of_post( int $id ): string
rh_lang_group_of( int $id ): ?int
rh_lang_get_translation( int $id, string $code ): ?int
rh_lang_translations( int $id ): array   // [ 'en' => 12, 'de' => 34 ]
rh_lang_home_url( ?string $code = null ): string

// Eigene Switcher-Buttons frei im Theme:
rh_lang_links( ?int $postId = null ): array   // pro Sprache: code, label, hreflang, url, current(bool)
rh_lang_switcher_html( string $wrapperAttributes = '' ): string   // fertiges <ul> (wie Block/Shortcode)
```

Für eigene Buttons `rh_lang_links()` im Template loopen. Alternativ der Shortcode `[rh_language_switcher]`, der Block "Sprach-Switcher" oder die Auto-Anzeige (Funktions-Schalter). Fertige Copy-Paste-Snippets stehen im Sprachen-Tab.

Cross-Modul über die Core-Registry: `rh_blueprint()->services()->get('languages')`.

Filter für die übersetzbaren Post-Types: `rh-blueprint/languages/post_types`.

## Teil der rh-blueprint Kollektion

Bündelt `rh-blueprint-core` (Settings-Framework, Update-Mechanismus). Auto-Update über GitHub Releases.
