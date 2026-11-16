<?php
/*
Plugin Name: AV-PvH AI Search
Plugin URI: https://github.com/grmt/avpvh-ai-search
Description: AI-powered search workspace for WordPress using Google Gemini. Smart answers, source links, chats, prompts, and documents in one place. Based on Geweb AI Search by Evgen Gavrilov.
Version: 3.0.1
Author: grmt
Author URI: https://github.com/grmt
License: GPL2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: avpvh-ai-search
Domain Path: /languages
Update URI: https://github.com/grmt/avpvh-ai-search
*/

defined('ABSPATH') || exit;

/*
 * Geweb AI Search (upstream, or this plugin before it was renamed) uses the same PHP
 * namespace and database names. Running both at once is fatal, so stay inactive
 * while it is active and tell the admin how to switch over without losing data.
 */
$avpvh_srch_active_plugins = array_merge(
    (array) get_option('active_plugins', []),
    array_keys((array) (is_multisite() ? get_site_option('active_sitewide_plugins', []) : []))
);
if (in_array('geweb-ai-search/geweb-ai-search.php', $avpvh_srch_active_plugins, true)) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__('AV-PvH AI Search is not running because Geweb AI Search is still active. Deactivate Geweb AI Search; AV-PvH AI Search then takes over its settings, index and chats. Do not use "Delete" on Geweb AI Search: its uninstaller removes the API key and index settings. Remove its folder from the server instead.', 'avpvh-ai-search')
            . '</p></div>';
    });
    unset($avpvh_srch_active_plugins);
    return;
}
unset($avpvh_srch_active_plugins);

// Plugin version
if (!defined('AVPVH_SRCH_VERSION')) {
    define('AVPVH_SRCH_VERSION', '3.0.1');
}

// Plugin directory path
if (!defined('AVPVH_SRCH_PATH')) {
    define('AVPVH_SRCH_PATH', plugin_dir_path(__FILE__));
}

// Plugin directory URL
if (!defined('AVPVH_SRCH_URL')) {
    define('AVPVH_SRCH_URL', plugin_dir_url(__FILE__));
}

// Load HTML to Markdown library
require_once AVPVH_SRCH_PATH . 'libs/md/vendor/autoload.php';

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'Geweb\\AISearch\\';
    $baseDir = AVPVH_SRCH_PATH . 'classes/';
    static $fallbackMap = null;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    if ($fallbackMap === null) {
        $fallbackMap = [];
        foreach (glob($baseDir . '*/*.php') ?: [] as $nestedFile) {
            $fallbackMap[basename($nestedFile, '.php')] = $nestedFile;
        }
    }

    if (isset($fallbackMap[$relativeClass]) && file_exists($fallbackMap[$relativeClass])) {
        require_once $fallbackMap[$relativeClass];
    }
});

// Initialize plugin
add_action('plugins_loaded', function () {
    static $postIndexManager = null;
    static $plugin = null;

    \Geweb\AISearch\PluginUpdateGuard::boot();

    if ($postIndexManager === null) {
        $postIndexManager = new \Geweb\AISearch\PostIndexManager();
    }

    if ($plugin === null) {
        $plugin = new \Geweb\AISearch\WP();
    }
});

// Plugin activation hook
register_activation_hook(__FILE__, function () {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('AV-PvH AI Search requires PHP 7.2 or higher (for Sodium support). Your current version is ' . PHP_VERSION);
    }

    if (!class_exists('\\Geweb\\AISearch\\DocumentStore')) {
        wp_die('AV-PvH AI Search could not load the DocumentStore class during activation.');
    }

    // Create custom database tables
    \Geweb\AISearch\DocumentStore::install();
    \Geweb\AISearch\MarkdownCacheStore::install();

    \Geweb\AISearch\WP::ensureFrontendAiPageExists();
    \Geweb\AISearch\WP::registerFrontendAiRewrite();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
