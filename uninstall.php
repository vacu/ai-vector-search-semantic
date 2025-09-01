<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

$opts = [
    'aivesese_url',
    'aivesese_key',
    'aivesese_store',
    'aivesese_openai',
    'aivesese_semantic_toggle',
    'aivesese_auto_sync',
    'aivesese_enable_pdp_similar',
    'aivesese_enable_cart_below',
    'aivesese_enable_woodmart_integration',
];

foreach ($opts as $opt) {
    delete_option($opt);
}
