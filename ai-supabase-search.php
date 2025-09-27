<?php
/**
 * Plugin Name: AI Vector Search (Semantic)
 * Description: Supabase‑powered WooCommerce search with optional semantic matching, live‑search support, and product recommendation.
 * Version: 0.18.0
 * Author: ZZZ Solutions
 * License: GPLv2 or later
 * Text Domain: ai-vector-search-semantic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Stable Tag: 0.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIVESESE_PLUGIN_VERSION', '0.18.0');
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
require_once AIVESESE_PLUGIN_PATH . 'includes/class-lite-engine.php';
require_once AIVESESE_PLUGIN_PATH . 'includes/class-lite-mode-ajax.php';

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

    // Handle dismissal early (before output) and persist
    if (isset($_GET['aivesese_dismiss_cli_upgrade']) && check_admin_referer('aivesese_cli_upgrade_nonce')) {
        update_option('aivesese_cli_upgrade_notice_dismissed', time());
        wp_safe_redirect(remove_query_arg(['aivesese_dismiss_cli_upgrade', '_wpnonce']));
        exit;
    }

    // Show enhanced setup notice (skip if dismissed)
    $current_version = get_option('aivesese_plugin_version');
    $dismissed = get_option('aivesese_cli_upgrade_notice_dismissed');
    if (!$dismissed && $current_version && version_compare($current_version, '0.17.0', '<')) {
        $dismiss_url = wp_nonce_url(
            add_query_arg('aivesese_dismiss_cli_upgrade', '1'),
            'aivesese_cli_upgrade_nonce'
        );

        echo '<div class="notice notice-info is-dismissible">';
        echo '<h3>🎉 AI Vector Search - WP-CLI Support Added!</h3>';
        echo '<p><strong>New Professional Installation Method:</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>⚡ <strong>One-command schema installation:</strong> <code>wp aivs install-schema</code></li>';
        echo '<li>🔍 <strong>Status checking:</strong> <code>wp aivs check-schema</code></li>';
        echo '<li>📦 <strong>Product syncing:</strong> <code>wp aivs sync-products</code></li>';
        echo '<li>🔗 <strong>Connection testing:</strong> <code>wp aivs test-connection</code></li>';
        echo '</ul>';
        echo '<p><strong>Setup:</strong> Add your PostgreSQL connection string in ';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '"><strong>Settings → AI Supabase</strong></a> ';
        echo 'and use WP-CLI commands for reliable schema management.</p>';
        echo '<p>';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '" class="button button-primary">Configure Connection</a> ';
        echo '<a href="' . esc_url($dismiss_url) . '" class="button">Dismiss</a>';
        echo '</p>';
        echo '</div>';
    }
});

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $connection_mode = get_option('aivesese_connection_mode', 'lite');

    $settings_link = '<a href="' . admin_url('options-general.php?page=aivesese') . '">Settings</a>';
    $status_link = '<a href="' . admin_url('options-general.php?page=aivesese-status') . '">Status</a>';

    // Add WP-CLI indicator
    $cli_indicator = '';
    if (defined('WP_CLI') && WP_CLI && get_option('aivesese_postgres_connection_string')) {
        $cli_indicator = '<span style="color: #46b450; font-weight: bold;">⚡ CLI Ready</span>';
    } elseif (defined('WP_CLI') && WP_CLI) {
        $cli_indicator = '<span style="color: #ffc107; font-weight: bold;">⚡ CLI Available</span>';
    }

    if ($connection_mode === 'api') {
        $mode_indicator = '<span style="color: #0073aa; font-weight: bold;">API Mode</span>';
    } elseif ($connection_mode === 'lite') {
        $mode_indicator = '<span style="color: #00a32a; font-weight: bold;">Lite Mode</span>';
    } else {
        $mode_indicator = '<span style="color: #666;">Self-Hosted</span>';
    }

    $result_links = [$settings_link, $status_link, $mode_indicator];
    if ($cli_indicator) {
        $result_links[] = $cli_indicator;
    }

    return array_merge($result_links, $links);
}, 10, 1);

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
        $connection_mode = get_option('aivesese_connection_mode', 'lite');
        $classes .= ' aivesese-admin aivesese-mode-' . $connection_mode;
    }
    return $classes;
});

add_action('plugins_loaded', function() {
    $current_version = get_option('aivesese_plugin_version', '0');

    if (version_compare($current_version, AIVESESE_PLUGIN_VERSION, '<')) {
        // Plugin was updated, run database updates
        aivesese_update_database();
        update_option('aivesese_plugin_version', AIVESESE_PLUGIN_VERSION);
    }
});

function aivesese_update_database() {
    // Create/update analytics table
    $analytics = AIVectorSearch_Analytics::instance();
    $analytics->create_table();

    // Any other database updates for future versions
    // if (version_compare($old_version, '0.17.0', '<')) {
    //     // Run updates for version 0.17.0
    // }
}

// Load WP-CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once AIVESESE_PLUGIN_PATH . 'includes/class-cli-commands.php';
}

// Add PostgreSQL connection string to encryption manager
add_filter('pre_update_option_aivesese_postgres_connection_string', function($value, $old_value, $option) {
    if (empty($value)) {
        return $value;
    }

    $encryption_manager = AIVectorSearch_Encryption_Manager::instance();
    return wp_json_encode($encryption_manager->encrypt($value));
}, 10, 3);

add_filter('option_aivesese_postgres_connection_string', function($value) {
    if (empty($value)) {
        return $value;
    }

    $arr = json_decode($value, true);
    if (is_array($arr)) {
        $encryption_manager = AIVectorSearch_Encryption_Manager::instance();
        return $encryption_manager->decrypt($arr);
    }

    return $value; // Return as-is if not encrypted (backwards compatibility)
});

// Add WP-CLI commands help to status page
add_action('aivesese_status_page_footer', function() {
    if (!defined('WP_CLI') || !WP_CLI) {
        return;
    }

    $has_connection = !empty(get_option('aivesese_postgres_connection_string'));

    echo '<div class="wp-cli-status-section">';
    echo '<h2>⚡ WP-CLI Commands</h2>';

    if ($has_connection) {
        echo '<div class="notice notice-success inline">';
        echo '<p><strong>✅ WP-CLI is available and PostgreSQL connection is configured!</strong></p>';
        echo '</div>';

        echo '<h3>Available Commands:</h3>';
        echo '<div class="cli-commands-grid">';

        $commands = [
            'wp aivs install-schema' => 'Install or update database schema',
            'wp aivs check-schema' => 'Check current schema status',
            'wp aivs test-connection' => 'Test PostgreSQL connection',
            'wp aivs sync-products' => 'Sync WooCommerce products to database'
        ];

        foreach ($commands as $command => $description) {
            echo '<div class="cli-command-card">';
            echo '<code>' . esc_html($command) . '</code>';
            echo '<p>' . esc_html($description) . '</p>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p><strong>💡 Recommended workflow:</strong></p>';
        echo '<ol>';
        echo '<li><code>wp aivs test-connection</code> - Verify database access</li>';
        echo '<li><code>wp aivs install-schema</code> - Install database schema</li>';
        echo '<li><code>wp aivs sync-products</code> - Sync your products</li>';
        echo '<li><code>wp aivs check-schema</code> - Verify everything is working</li>';
        echo '</ol>';

    } else {
        echo '<div class="notice notice-warning inline">';
        echo '<p><strong>⚠️ WP-CLI is available but PostgreSQL connection string is not configured.</strong></p>';
        echo '<p>Add your connection string in <a href="' . admin_url('options-general.php?page=aivesese') . '">Settings</a> to enable WP-CLI commands.</p>';
        echo '</div>';
    }

    echo '</div>';

    // Add styles
    ?>
    <style>
    .wp-cli-status-section {
        margin-top: 30px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #0073aa;
    }

    .cli-commands-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .cli-command-card {
        background: #fff;
        padding: 15px;
        border-radius: 6px;
        border-left: 3px solid #46b450;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .cli-command-card code {
        display: block;
        background: #23282d;
        color: #46b450;
        padding: 8px 12px;
        border-radius: 4px;
        margin-bottom: 10px;
        font-weight: bold;
    }

    .cli-command-card p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }
    </style>
    <?php
});

// Update version for CLI upgrade notice
if (!get_option('aivesese_plugin_version') || version_compare(get_option('aivesese_plugin_version'), AIVESESE_PLUGIN_VERSION, '<')) {
    update_option('aivesese_plugin_version', AIVESESE_PLUGIN_VERSION);
}
