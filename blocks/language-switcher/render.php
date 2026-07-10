<?php
/**
 * Sprach-Switcher (SSR).
 *
 * Rendert pro konfigurierter Sprache einen Link. Auf Einzelseiten zeigt der Link
 * auf das Gegenstück derselben Übersetzungsgruppe (Fallback: Sprach-Startseite,
 * wenn noch keine Übersetzung existiert). Die aktuelle Sprache bekommt
 * aria-current. Reiner Text-Switch, keine Flaggen.
 *
 * @package rh-languages
 */

if (! function_exists('rh_lang_all')) {
    return;
}

$rhls_languages = rh_lang_all();
if (count($rhls_languages) < 2) {
    return;
}

$rhls_current = rh_lang_current();
$rhls_queried = is_singular() ? (int) get_queried_object_id() : 0;
$rhls_translations = $rhls_queried > 0 ? rh_lang_translations($rhls_queried) : array();

$rhls_items = '';

foreach ($rhls_languages as $rhls_lang) {
    $rhls_code = $rhls_lang->code;

    if ($rhls_queried > 0 && isset($rhls_translations[$rhls_code])) {
        $rhls_url = get_permalink($rhls_translations[$rhls_code]);
    } else {
        $rhls_url = rh_lang_home_url($rhls_code);
    }

    if (! is_string($rhls_url) || '' === $rhls_url) {
        continue;
    }

    $rhls_is_current = ($rhls_code === $rhls_current);
    $rhls_aria = $rhls_is_current ? ' aria-current="true"' : '';
    $rhls_class = 'rh-language-switcher__item' . ($rhls_is_current ? ' is-current' : '');

    $rhls_items .= '<li class="' . esc_attr($rhls_class) . '">'
        . '<a href="' . esc_url($rhls_url) . '" hreflang="' . esc_attr($rhls_lang->hreflang) . '"' . $rhls_aria . '>'
        . esc_html($rhls_lang->label)
        . '</a></li>';
}

if ('' === $rhls_items) {
    return;
}

$rhls_wrapper = get_block_wrapper_attributes(array('class' => 'rh-language-switcher'));

echo '<ul ' . $rhls_wrapper . '>' . $rhls_items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Werte einzeln escaped
