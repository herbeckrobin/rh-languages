=== RH Languages ===
Contributors: robinherbeck
Tags: multilingual, translation, i18n, hreflang, language switcher
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lean multilingual for WordPress (FSE): a translation is a real post, prefixed URLs, language switcher, hreflang, theme strings via gettext.

== Description ==

RH Languages adds multilingual support the WordPress-native way, without a third-party dependency and without an external service (GDPR-friendly). Each translation is a real post the client edits normally in the editor. Language versions are linked through two hidden taxonomies (one term per language, one term per translation group), the Polylang model, kept lean and core-native.

= Features =

* Language registry (code, locale, label, hreflang, one default) under RH Blueprint > Sprachen
* URL routing: default language without prefix, others prefixed (/about/ vs /de/ueber/), with a 301 guard against duplicate content
* Language separation of front-end queries (opt out per query with 'rhlang_skip' => true)
* Language switcher as an SSR block (rh/language-switcher), linking to the counterpart of the current page
* hreflang + x-default and the html lang attribute, from a single source (coexists with rh-seo)
* Editor UI: a pinned language icon in the editor header, opening a Languages sidebar with "create translation" (duplicates as a draft)
* Post list language column and filter
* One navigation menu and one template part (footer, header) per language: wp_navigation and wp_template_part are translatable, the right version renders per language
* A "Structural translations" panel in the Languages tab that lists menus and template parts with their per-language versions and one-click create (also links into the Site Editor to edit them)
* A static front page per language
* Theme strings follow the language via switch_to_locale() (theme .mo required)

= Deliberately out of scope =

String scanner, machine translation, translation memory, custom database tables, cookie/browser-language redirects (breaks full-page caching, SEO-risky). This is the lean 80 percent, not a WPML clone.

Part of the rh-blueprint collection. Requires pretty permalinks and a block theme.

== Changelog ==

= 0.2.3 =
* Fix: taxonomy archives of post types that rh_lang is not attached to (e.g. a `series` archive on an `artwork` post type) came up empty on non-default languages. The language filter still applied its strict `rh_lang` clause, but those posts can never carry a language term, so all of them were filtered out (found 0 on /de/). The filter now only applies to a custom taxonomy archive when rh_lang is actually registered for one of the taxonomy's post types (its real object_type, not the time-dependent translatable list). Such post types are shown language-neutrally (same items under every language); once rh_lang is attached to them, language separation kicks in automatically. Category and tag archives are unaffected.

= 0.2.2 =
* Fix: the language switcher pointed at the language home page on every archive (taxonomy, category, date, author) instead of the same archive in the other language. `rh_lang_links()` only resolved a target on singular views and fell back to the home root otherwise. It now uses the same per-language path computation as the hreflang alternates (extracted into a shared method), so both stay in sync. Singular views (translation permalinks) and the front page (language roots) are unchanged.

= 0.2.1 =
* Fix: custom taxonomy archives (e.g. a `series` archive on a custom post type) fell back to the index template. The language filter appended its `rh_lang` clause before the archive's own taxonomy in the tax_query, so WordPress picked the language term as the queried object (body class `tax-rh_lang` instead of `tax-series`). The queried object is now resolved and cached before the clause is appended, on taxonomy archives only. Category and tag archives were never affected (they resolve their subject via dedicated query vars).

= 0.2.0 =
* New: template parts (footer, header, ...) are translatable just like navigation menus. On /de/ the translated part renders; the swap replaces the template-part slug so WordPress' own renderer resolves the per-language version (theme + area terms are inherited on the copy).
* New: "Structural translations" panel in the Languages tab, listing the menus actually in use (referenced by a navigation block) and every template part, each with per-language tags. Existing translations link into the Site Editor, missing ones offer one-click create. Theme-file parts (an unmodified footer.html) are materialized into a base post on first translation.
* New: translations of structural blocks get a language suffix in the title (e.g. "Footer (English)"), so the Site Editor no longer shows two identically named blocks.
* Fix: translated navigation menus are now published (not draft), so the per-language navigation swap actually finds and renders them.

= 0.1.4 =
* Fix: translated menus (and any exclude_from_search post type, e.g. wp_navigation) were not found by translations(), because the internal query used post_type => 'any' which skips those types. Now queries the translatable post types explicitly, so the per-language navigation swap works.

= 0.1.3 =
* New: the default language is now an explicit choice in the Languages tab, decoupled from the WordPress site language (keep a German admin with English as the front-end default). Falls back to the WP language when unset.
* Fix: when a translation is the static front page, switcher and hreflang now link to the language root (/ resp. /de/) instead of the raw post permalink (avoids duplicate URLs for the same home).
* Doc: query opt-out is 'rhlang_skip' => true (readme said the old 'rh_lang' => 'all').

= 0.1.2 =
* New: template tags for custom switchers anywhere in the theme, rh_lang_links() (per-language code/label/hreflang/url/current) and rh_lang_switcher_html(), a [rh_language_switcher] shortcode, and copy-paste snippets on the settings page.

= 0.1.1 =
* Fix: language home (/xx/) showed the blog index instead of the (translated) static front page, because the rewrite target carried an extra query var that blocks WordPress' static-front-page swap. The language now comes from the URL path only. Rewrite rules are rebuilt automatically after the update.

= 0.1.0 =
* Initial release: taxonomy data model, prefix routing, language query separation, switcher block, hreflang + html lang, per-language front page and navigation, gettext locale switch, editor sidebar with create-as-draft, post list column and filter.
