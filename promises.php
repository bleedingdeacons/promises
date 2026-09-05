<?php

/**
 * Plugin Name: Promises
 * Description: Model Context Protocol server for Unity. Exposes Unity's members, groups, meetings and positions — and, when Trusted is active, its telephone-responder rota — as MCP tools over an authenticated WordPress REST endpoint, so an MCP client can read and work the intergroup's data directly.
 * Version: 1.1.6
 * Build date: 2026/08/15
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Requires Plugins: unity
 * GitHub Plugin URI: https://github.com/bleedingdeacons/promises
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/promises
 * Text Domain: promises
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Kill switch — set define('PROMISES_KILL', true) in wp-config.php to stand the
// plugin down without deactivating it. Worth having on this one in particular:
// Promises is a remote data surface, so "turn it off now" is a thing an admin
// may need to do without touching the plugins screen.
if (defined('PROMISES_KILL') && PROMISES_KILL) {
    return;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$promises_plugin_data = get_plugin_data(__FILE__, false, false);
define('PROMISES_VERSION', $promises_plugin_data['Version']);
define('PROMISES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PROMISES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PROMISES_PLUGIN_FILE', __FILE__);

// The single wp_options row holding the API key hash and the tool-surface
// toggles. Removed on uninstall.
define('PROMISES_OPTION_KEY', 'promises_settings');

// The MCP revision this server implements. Sent back on every response and in
// the initialize result; see Mcp\Server for the down-level negotiation.
define('PROMISES_MCP_PROTOCOL_VERSION', '2026-07-28');

// Autoloader for Promises namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Promises\\';
        $base_dir = PROMISES_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('promises')->error('Promises Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Promises Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('promises')->critical('Promises Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Promises Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// -----------------------------------------------------------------------------
// Boot
//
// Unity is a hard dependency and is declared in the header, so activation is
// blocked without it. Booting from unity/loaded rather than plugins_loaded is
// what actually matters though: the container Promises resolves every
// repository from does not exist until Unity fires that action.
//
// Trusted is *not* a dependency. Its rota tools are registered only when its
// services are present in the same container — see Core\PromisesServiceProvider.
// -----------------------------------------------------------------------------
// Both listeners are added at include time — before Unity fires either action
// during plugins_loaded — so wiring works regardless of plugin load order.
add_action('unity/register_services', static function ($container): void {
    (new \Promises\Core\PromisesServiceProvider())->register($container);
});

add_action('unity/loaded', static function ($container): void {
    \Promises\Plugin::instance()->boot($container);
});

// The admin screen is the only part that does not need Unity's container: it
// manages this plugin's own option row. Registered on the core hook so the
// settings page — and therefore the "Unity is missing" notice — is reachable
// even when unity/loaded never fires.
add_action('plugins_loaded', function () {
    if (is_admin() && class_exists(\Promises\Admin\SettingsPage::class)) {
        (new \Promises\Admin\SettingsPage())->register();
    }
});
