<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$opts = [
    // Main settings
    'aivesese_url',
    'aivesese_key',
    'aivesese_store',
    'aivesese_openai',
    'aivesese_semantic_toggle',
    'aivesese_auto_sync',
    'aivesese_enable_pdp_similar',
    'aivesese_enable_cart_below',
    'aivesese_enable_woodmart_integration',

    // Admin notices dismissals
    'aivesese_sql_v2_dismissed',
];

foreach ($opts as $opt) {
    delete_option($opt);

    // Also clean up user meta for help toggle
    delete_metadata('user', 0, '_aivesese_help_open', '', true);
}
