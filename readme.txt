=== RH Languages ===
Contributors: robinherbeck
Tags: multilingual, translation, i18n, hreflang, language switcher
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lean multilingual for WordPress (FSE): a translation is a real post, prefixed URLs, language switcher, hreflang, theme strings via gettext.

== Description ==

RH Languages adds multilingual support the WordPress-native way, without a third-party dependency and without an external service (GDPR-friendly). Each translation is a real post the client edits normally in the editor. Language versions are linked through two hidden taxonomies (one term per language, one term per translation group), the Polylang model, kept lean and core-native.

= Features =

* Language registry (code, locale, label, hreflang, one default) under RH Blueprint > Sprachen
* URL routing: default language without prefix, others prefixed (/about/ vs /de/ueber/), with a 301 guard against duplicate content
* Language separation of front-end queries (opt out per query with 'rh_lang' => 'all')
* Language switcher as an SSR block (rh/language-switcher), linking to the counterpart of the current page
* hreflang + x-default and the html lang attribute, from a single source (coexists with rh-seo)
* Editor UI: a pinned language icon in the editor header, opening a Languages sidebar with "create translation" (duplicates as a draft)
* Post list language column and filter
* One navigation menu per language (wp_navigation is translatable), and a static front page per language
* Theme strings follow the language via switch_to_locale() (theme .mo required)

= Deliberately out of scope =

String scanner, machine translation, translation memory, custom database tables, cookie/browser-language redirects (breaks full-page caching, SEO-risky). This is the lean 80 percent, not a WPML clone.

Part of the rh-blueprint collection. Requires pretty permalinks and a block theme.

== Changelog ==

= 0.1.1 =
* Fix: language home (/xx/) showed the blog index instead of the (translated) static front page, because the rewrite target carried an extra query var that blocks WordPress' static-front-page swap. The language now comes from the URL path only. Rewrite rules are rebuilt automatically after the update.

= 0.1.0 =
* Initial release: taxonomy data model, prefix routing, language query separation, switcher block, hreflang + html lang, per-language front page and navigation, gettext locale switch, editor sidebar with create-as-draft, post list column and filter.
