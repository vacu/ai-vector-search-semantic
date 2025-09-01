<?php
/**
 * Main plugin orchestrator class
 */
class AIVectorSearch_Plugin {

    private static $instance = null;
    private $encryption_manager;
    private $supabase_client;
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
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
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

    // Getter methods for accessing components
    public function get_encryption_manager() {
        return $this->encryption_manager;
    }

    public function get_supabase_client() {
        return $this->supabase_client;
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
}
