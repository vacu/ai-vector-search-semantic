<?php
/**
 * Main plugin orchestrator class with API support
 */
class AIVectorSearch_Plugin {

    private static $instance = null;
    private $encryption_manager;
    private $connection_manager; // New
    private $supabase_client;
    private $api_client; // New
    private $openai_client;
    private $product_sync;
    private $search_handler;
    private $recommendations;
    private $admin_interface;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
        $this->init_hooks();
    }

    private function init_components() {
        $this->encryption_manager = AIVectorSearch_Encryption_Manager::instance();
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance(); // New
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
        $this->api_client = AIVectorSearch_API_Client::instance(); // New
        $this->openai_client = AIVectorSearch_OpenAI_Client::instance();
        $this->product_sync = AIVectorSearch_Product_Sync::instance();
        $this->search_handler = AIVectorSearch_Search_Handler::instance();
        $this->recommendations = AIVectorSearch_Recommendations::instance();
        $this->admin_interface = AIVectorSearch_Admin_Interface::instance();
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'ensure_store_id'], 11);

        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'on_activation']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivation']);

        // Add admin notices for mode switching
        add_action('admin_notices', [$this, 'show_mode_switch_notices']);
    }

    public function ensure_store_id() {
        $store = get_option('aivesese_store', '');

        // Basic UUID v4 format check
        $is_uuid = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $store
        );

        if (!$is_uuid) {
            $new = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : wp_generate_password(36, false); // fallback

            update_option('aivesese_store', $new, false); // autoload = false
        }
    }

    public function on_activation() {
        // Ensure store ID is generated on activation
        $this->ensure_store_id();

        // Set default options
        $defaults = [
            'aivesese_connection_mode' => 'self_hosted', // Default to self-hosted
            'aivesese_semantic_toggle' => '0',
            'aivesese_auto_sync' => '0',
            'aivesese_enable_pdp_similar' => '1',
            'aivesese_enable_cart_below' => '1',
            'aivesese_enable_woodmart_integration' => '0',
        ];

        foreach ($defaults as $option => $default_value) {
            if (get_option($option) === false) {
                update_option($option, $default_value);
            }
        }

        // Clear any existing caches
        $this->clear_plugin_caches();

        // Show welcome notice
        update_option('aivesese_show_welcome_notice', '1');
    }

    public function on_deactivation() {
        // Clear plugin caches
        $this->clear_plugin_caches();
    }

    private function clear_plugin_caches() {
        global $wpdb;

        // Delete all transients that start with our prefixes
        $prefixes = ['fts_', 'sem_', 'recs_', 'aivesese_sim_'];

        foreach ($prefixes as $prefix) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $prefix . '%'
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . $prefix . '%'
            ));
        }
    }

    /**
     * Show admin notices for mode switching and welcome
     */
    public function show_mode_switch_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Welcome notice for new installs
        if (get_option('aivesese_show_welcome_notice') === '1') {
            $this->show_welcome_notice();
        }

        // Connection mode notices
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

        if ($connection_mode === 'api' && empty(get_option('aivesese_license_key'))) {
            $this->show_api_setup_notice();
        }

        if ($connection_mode === 'self_hosted' && (!get_option('aivesese_url') || !get_option('aivesese_key'))) {
            $this->show_self_hosted_setup_notice();
        }
    }

    private function show_welcome_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg('aivesese_dismiss_welcome', '1'),
            'aivesese_welcome_nonce'
        );

        echo '<div class="notice notice-info is-dismissible aivesese-welcome-notice">';
        echo '<h3>🎉 Welcome to AI Vector Search!</h3>';
        echo '<p>Thank you for installing AI Vector Search. You have two ways to get started:</p>';
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0;">';

        // API Option
        echo '<div style="padding: 15px; border: 2px solid #0073aa; border-radius: 8px;">';
        echo '<h4 style="margin-top: 0;">🚀 Managed API Service (Recommended)</h4>';
        echo '<ul style="margin: 10px 0;">';
        echo '<li>✅ No setup required</li>';
        echo '<li>✅ Professional support</li>';
        echo '<li>✅ Automatic maintenance</li>';
        echo '</ul>';
        echo '<p><a href="https://zzzsolutions.ro/ai-search-service" target="_blank" class="button button-primary">Get License Key</a></p>';
        echo '</div>';

        // Self-hosted Option
        echo '<div style="padding: 15px; border: 2px solid #666; border-radius: 8px;">';
        echo '<h4 style="margin-top: 0;">⚙️ Self-Hosted (DIY)</h4>';
        echo '<ul style="margin: 10px 0;">';
        echo '<li>🔧 Requires Supabase setup</li>';
        echo '<li>🔧 Manual configuration</li>';
        echo '<li>💰 Pay only API usage</li>';
        echo '</ul>';
        echo '<p><a href="' . admin_url('options-general.php?page=aivesese') . '" class="button">Configure Now</a></p>';
        echo '</div>';

        echo '</div>';
        echo '<p><a href="' . esc_url($dismiss_url) . '" style="float: right; margin-top: 10px;">Dismiss this notice</a></p>';
        echo '</div>';

        // Handle dismissal
        if (isset($_GET['aivesese_dismiss_welcome']) && check_admin_referer('aivesese_welcome_nonce')) {
            update_option('aivesese_show_welcome_notice', '0');
            wp_safe_redirect(remove_query_arg(['aivesese_dismiss_welcome', '_wpnonce']));
            exit;
        }
    }

    private function show_api_setup_notice() {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>AI Vector Search:</strong> API mode is selected but no license key is configured. ';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '">Configure your license key</a> or ';
        echo '<a href="https://zzzsolutions.ro/ai-search-service" target="_blank">get a license</a>.</p>';
        echo '</div>';
    }

    private function show_self_hosted_setup_notice() {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>AI Vector Search:</strong> Self-hosted mode requires Supabase configuration. ';
        echo '<a href="' . admin_url('options-general.php?page=aivesese') . '">Complete your setup</a>.</p>';
        echo '</div>';
    }

    // Getter methods for accessing components
    public function get_encryption_manager() {
        return $this->encryption_manager;
    }

    public function get_connection_manager() {
        return $this->connection_manager;
    }

    public function get_supabase_client() {
        return $this->supabase_client;
    }

    public function get_api_client() {
        return $this->api_client;
    }

    public function get_openai_client() {
        return $this->openai_client;
    }

    public function get_product_sync() {
        return $this->product_sync;
    }

    public function get_search_handler() {
        return $this->search_handler;
    }

    public function get_recommendations() {
        return $this->recommendations;
    }

    public function get_admin_interface() {
        return $this->admin_interface;
    }

    // Utility methods
    public function is_woocommerce_active(): bool {
        return class_exists('WooCommerce');
    }

    public function get_plugin_version(): string {
        return AIVESESE_PLUGIN_VERSION;
    }

    public function get_plugin_path(): string {
        return AIVESESE_PLUGIN_PATH;
    }

    public function get_plugin_url(): string {
        return AIVESESE_PLUGIN_URL;
    }

    /**
     * Check if the plugin is properly configured
     */
    public function is_configured(): bool {
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

        if ($connection_mode === 'api') {
            return !empty(get_option('aivesese_license_key')) &&
                   get_option('aivesese_api_activated') === '1';
        } else {
            return !empty(get_option('aivesese_url')) &&
                   !empty(get_option('aivesese_key')) &&
                   !empty(get_option('aivesese_store'));
        }
    }

    /**
     * Get current connection mode
     */
    public function get_connection_mode(): string {
        return get_option('aivesese_connection_mode', 'self_hosted');
    }

    /**
     * Check if semantic search is available in current mode
     */
    public function is_semantic_search_available(): bool {
        return $this->connection_manager->is_semantic_search_available();
    }
}
