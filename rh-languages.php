<?php

/**
 * Plugin Name:       RH Languages
 * Plugin URI:        https://github.com/herbeckrobin/rh-languages
 * Update URI:        https://github.com/herbeckrobin/rh-languages
 * Description:       Schlanke Mehrsprachigkeit für WordPress (FSE): eine Übersetzung ist ein echter Post, Prefix-URLs, Sprach-Switcher, hreflang, Theme-Strings per gettext. Kein externer Dienst. Teil der rh-blueprint Kollektion.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-languages
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHLANG_VERSION', '0.1.1');
define('RHLANG_PLUGIN_FILE', __FILE__);
define('RHLANG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RHLANG_PLUGIN_URL', plugin_dir_url(__FILE__));

$rhlang_autoload = RHLANG_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhlang_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Languages:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhlang_autoload;
require_once RHLANG_PLUGIN_DIR . 'inc/api.php';

// Aktivierung: Migration + Rewrite-Flush anstoßen. Beides läuft verzögert über
// Option-Flags (siehe Plugin/Migration), damit es nicht vom Aktivierungs-Timing
// abhängt (Taxonomien werden erst auf `init` registriert).
register_activation_hook(__FILE__, ['RhLanguages\\Plugin', 'onActivate']);
register_deactivation_hook(__FILE__, ['RhLanguages\\Plugin', 'onDeactivate']);

RhLanguages\Plugin::boot();
