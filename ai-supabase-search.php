<?php
/**
 * Plugin Name: AI Vector Search (Semantic)
 * Description: Supabase‑powered WooCommerce search with optional semantic (OpenAI) matching, Woodmart live‑search support, and product recommendation
 * Version: 0.14.0
 * Author: ZZZ Solutions
 * License: GPLv2 or later
 * Text Domain: ai-vector-search-semantic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Stable Tag: 0.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIVESESE_PLUGIN_VERSION', '0.14.0');
define('AIVESESE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AIVESESE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'AIVectorSearch_') !== 0) {
        return;
    }

    $class_file = str_replace('_', '/', substr($class, 15)); // Remove 'AIVectorSearch_' prefix
    $file_path = AIVESESE_PLUGIN_PATH . 'includes/class-' . strtolower($class_file) . '.php';

    if (file_exists($file_path)) {
        require_once $file_path;
    }
});

// Load plugin classes
require_once AIVESESE_PLUGIN_PATH . 'includes/class-encryption-manager.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-supabase-client.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-openai-client.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-product-sync.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-search-handler.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-recommendations.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-admin-interface.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-plugin.php';

// Initialize the plugin
function aivesese_init() {
    return AIVectorSearch_Plugin::instance();
}

// Start the plugin
add_action('plugins_loaded', 'aivesese_init');

// Legacy function for backward compatibility
if (!function_exists('aivesese_passthru')) {
    function aivesese_passthru($value) {
        return is_string($value) ? $value : '';
    }
}
