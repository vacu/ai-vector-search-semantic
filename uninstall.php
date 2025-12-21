<?php
/**
 * Uninstall script - Clean up all plugin data
 * This file is executed when the plugin is deleted via the WordPress admin
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// List of all plugin options to delete
$opts = [
    // Main settings
    'aivesese_url',
    'aivesese_key',
    'aivesese_store',
    'aivesese_openai',
    'aivesese_enable_search',
    'aivesese_semantic_toggle',
    'aivesese_auto_sync',
    'aivesese_enable_pdp_similar',
    'aivesese_enable_cart_below',
    'aivesese_enable_woodmart_integration',

    // Connection settings
    'aivesese_connection_mode',
    'aivesese_license_key',
    'aivesese_api_activated',
    'aivesese_postgres_connection_string',

    // Search settings
    'aivesese_search_results_limit',

    // Lite mode settings
    'aivesese_lite_index_limit',
    'aivesese_lite_avg_search_time',
    'aivesese_lite_stopwords',
    'aivesese_lite_synonyms',

    // Version tracking
    'aivesese_plugin_version',
    'aivesese_analytics_db_version',

    // Schema installation tracking
    'aivesese_schema_installed',
    'aivesese_schema_version',
    'aivesese_schema_install_method',

    // Admin notices dismissals
    'aivesese_sql_v2_dismissed',
    'aivesese_cli_upgrade_notice_dismissed',
    'aivesese_show_welcome_notice',
    'aivesese_master_key_notice_dismissed',

    // Analytics dismissals (dynamic - use pattern matching)
    // These are handled separately below
];

// Delete all standard options
foreach ($opts as $opt) {
    delete_option($opt);
}

// Delete all analytics-related dismissal options (pattern: aivesese_analytics_*)
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('aivesese_analytics_') . '%'
));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('aivesese_opportunity_shown_') . '%'
));

// Clean up user meta for help toggle
delete_metadata('user', 0, '_aivesese_help_open', '', true);

// Clear all scheduled hooks
if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('aivesese_rebuild_lite_index');
    wp_clear_scheduled_hook('aivs_cleanup_analytics');
}

// Delete all transients and cached data
$transient_prefixes = [
    'aivesese_lite_index_',
    'fts_',
    'sem_',
    'recs_',
    'aivesese_sim_',
];

foreach ($transient_prefixes as $prefix) {
    $escaped_prefix = $wpdb->esc_like($prefix) . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $escaped_prefix,
        '_transient_timeout_' . $escaped_prefix
    ));
}

// Drop analytics table if it exists
$table_name = $wpdb->prefix . 'aivs_search_analytics';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Clear object cache if available
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}
