<?php
/**
 * Sprach-Switcher (SSR). Die Markup-Erzeugung liegt in rh_lang_switcher_html()
 * (geteilt mit dem [rh_language_switcher]-Shortcode und als Basis für eigene
 * Theme-Snippets via rh_lang_links()).
 *
 * @package rh-languages
 */

if (! function_exists('rh_lang_switcher_html')) {
    return;
}

$rhls_wrapper = get_block_wrapper_attributes(array('class' => 'rh-language-switcher'));

echo rh_lang_switcher_html($rhls_wrapper); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Werte in rh_lang_switcher_html einzeln escaped
