<?php
/**
 * Plugin Name: AI Vector Search (Semantic)
 * Description: Supabase‑powered WooCommerce search with optional semantic matching, live‑search support, and product recommendation.
 * Version: 0.16.0
 * Author: ZZZ Solutions
 * License: GPLv2 or later
 * Text Domain: ai-vector-search-semantic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Stable Tag: 0.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIVESESE_PLUGIN_VERSION', '0.16.0');
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
require_once AIVESESE_PLUGIN_PATH . 'includes/class-api-client.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-connection-manager.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-openai-client.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-product-sync.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-search-handler.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-recommendations.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-admin-interface.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-analytics.php';
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

// Add upgrade notice for existing users
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Show upgrade notice for existing installations
    $current_version = get_option('aivesese_plugin_version');
    if ($current_version && version_compare($current_version, '0.16.0', '<')) {
        $dismiss_url = wp_nonce_url(
            add_query_arg('aivesese_dismiss_upgrade', '1'),
            'aivesese_upgrade_nonce'
        );

        echo '<div class="notice notice-info is-dismissible">';
        echo '<h3>🎉 AI Vector Search - New API Service Available!</h3>';
        echo '<p><strong>Great news!</strong> We now offer a managed API service that eliminates the need for Supabase setup:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>✅ <strong>No more database setup</strong> - We handle everything</li>';
        echo '<li>✅ <strong>Professional support included</strong> - Get help when you need it</li>';
        echo '<li>✅ <strong>Automatic updates and maintenance</strong> - Always up to date</li>';
        echo '<li>✅ <strong>Guaranteed uptime</strong> - 99.9% SLA</li>';
        echo '</ul>';
        echo '<p>Your existing setup will continue to work perfectly. The new API option is available in ';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '"><strong>Settings → AI Supabase</strong></a> ';
        echo 'when you\'re ready to upgrade.</p>';
        echo '<p>';
        echo '<a href="https://zzzsolutions.ro/ai-search-service" target="_blank" class="button button-primary">Learn More About API Service</a> ';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '" class="button">View Settings</a> ';
        echo '<a href="' . esc_url($dismiss_url) . '" class="button">Dismiss</a>';
        echo '</p>';
        echo '</div>';

        // Handle dismissal
        if (isset($_GET['aivesese_dismiss_upgrade']) && check_admin_referer('aivesese_upgrade_nonce')) {
            update_option('aivesese_upgrade_notice_dismissed', time());
            wp_safe_redirect(remove_query_arg(['aivesese_dismiss_upgrade', '_wpnonce']));
            exit;
        }
    }

    // Update version
    if (!$current_version || version_compare($current_version, AIVESESE_PLUGIN_VERSION, '<')) {
        update_option('aivesese_plugin_version', AIVESESE_PLUGIN_VERSION);
    }
});

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

    $settings_link = '<a href="' . admin_url('options-general.php?page=aivesese') . '">Settings</a>';
    $status_link = '<a href="' . admin_url('options-general.php?page=aivesese-status') . '">Status</a>';

    if ($connection_mode === 'api') {
        $mode_indicator = '<span style="color: #0073aa; font-weight: bold;">API Mode</span>';
    } else {
        $mode_indicator = '<span style="color: #666;">Self-Hosted</span>';
    }

    array_unshift($links, $settings_link, $status_link, $mode_indicator);

    return $links;
});

// Add plugin row meta
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $row_meta = [
            'docs' => '<a href="https://zzzsolutions.ro/docs/ai-search" target="_blank">Documentation</a>',
            'support' => '<a href="https://zzzsolutions.ro/support" target="_blank">Support</a>',
            'api_service' => '<a href="https://zzzsolutions.ro/ai-search-service" target="_blank" style="color: #0073aa; font-weight: bold;">🚀 Get API Service</a>'
        ];

        return array_merge($links, $row_meta);
    }

    return $links;
}, 10, 2);

// Add admin body class for styling
add_filter('admin_body_class', function($classes) {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'aivesese') !== false) {
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');
        $classes .= ' aivesese-admin aivesese-mode-' . $connection_mode;
    }
    return $classes;
});
