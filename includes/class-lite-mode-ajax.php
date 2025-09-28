<?php
/**
 * Lite Mode AJAX Handlers
 * File: includes/class-lite-mode-ajax.php
 */
class AIVectorSearch_Lite_Mode_Ajax {

    private static $instance = null;
    private $lite_engine;
    private $search_handler;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->lite_engine = AIVectorSearch_Lite_Engine::instance();
        $this->search_handler = AIVectorSearch_Search_Handler::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin AJAX handlers
        add_action('wp_ajax_aivesese_rebuild_lite_index', [$this, 'handle_rebuild_index']);
        add_action('wp_ajax_aivesese_test_lite_search', [$this, 'handle_test_search']);

        // Public AJAX handlers for frontend search
        add_action('wp_ajax_aivesese_lite_search', [$this, 'handle_frontend_search']);
        add_action('wp_ajax_nopriv_aivesese_lite_search', [$this, 'handle_frontend_search']);

        // Mode switching handlers
        add_action('wp_ajax_aivesese_switch_mode', [$this, 'handle_switch_mode']);
        add_action('wp_ajax_aivesese_get_mode_stats', [$this, 'handle_get_mode_stats']);
    }

    /**
     * Handle index rebuild request
     */
    public function handle_rebuild_index() {
        // Verify nonce and permissions
        if (!check_ajax_referer('aivesese_lite_actions', 'nonce', false) ||
            !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        try {
            $result = $this->lite_engine->force_rebuild_index();

            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['message'],
                    'stats' => $result['stats']
                ]);
            } else {
                wp_send_json_error(['message' => $result['message']]);
            }

        } catch (Exception $e) {
            error_log('AIVectorSearch: Index rebuild error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Index rebuild failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle test search request
     */
    public function handle_test_search() {
        // Verify nonce and permissions
        if (!check_ajax_referer('aivesese_lite_actions', 'nonce', false) ||
            !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $search_term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));

        if (empty($search_term)) {
            wp_send_json_error(['message' => 'Search term is required']);
            return;
        }

        try {
            $start_time = microtime(true);

            // Perform search using lite engine
            $product_ids = $this->lite_engine->search_products($search_term, 10);

            $search_time = round((microtime(true) - $start_time) * 1000); // milliseconds

            // Get product details
            $products = [];
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $products[] = [
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'stock_status' => $product->get_stock_status()
                    ];
                }
            }

            wp_send_json_success([
                'products' => $products,
                'search_time' => $search_time,
                'total_found' => count($products)
            ]);

        } catch (Exception $e) {
            error_log('AIVectorSearch: Test search error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Test search failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle frontend search requests
     */
    public function handle_frontend_search() {
        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'aivesese_search_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        $query = sanitize_text_field(wp_unslash($_REQUEST['query'] ?? ''));
        $configured_limit = aivesese_get_search_results_limit();
        $requested_limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 0;
        $limit = $requested_limit > 0 ? min($requested_limit, $configured_limit) : $configured_limit;

        if (empty($query) || strlen($query) < 2) {
            wp_send_json_error(['message' => 'Query too short']);
            return;
        }

        try {
            $start_time = microtime(true);

            // Use the search handler for consistent results
            $product_ids = $this->search_handler->search_products($query, $limit);

            $search_time = round((microtime(true) - $start_time) * 1000);

            if (empty($product_ids)) {
                wp_send_json_success([
                    'products' => [],
                    'found' => 0,
                    'search_time' => $search_time,
                    'mode' => 'lite',
                    'suggestions' => $this->get_search_suggestions($query)
                ]);
                return;
            }

            // Format products for response
            $products = [];
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;

                $image_id = get_post_thumbnail_id($product_id);
                $image_url = $image_id ? wp_get_attachment_image_src($image_id, 'woocommerce_gallery_thumbnail')[0] : '';

                $products[] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'price_html' => $product->get_price_html(),
                    'price' => $product->get_price(),
                    'image' => $image_url,
                    'url' => add_query_arg([
                        'from_search' => '1',
                        'search_term' => urlencode($query)
                    ], $product->get_permalink()),
                    'stock_status' => $product->get_stock_status(),
                    'in_stock' => $product->is_in_stock(),
                    'rating' => $product->get_average_rating(),
                    'review_count' => $product->get_review_count()
                ];
            }

            // Include lite mode specific data
            wp_send_json_success([
                'products' => $products,
                'found' => count($products),
                'search_time' => $search_time,
                'mode' => 'lite',
                'mode_label' => 'Lite Mode (Local)',
                'upgrade_available' => true,
                'upgrade_message' => count($products) > 5 ?
                    'Great results! Upgrade for even better AI-powered search.' :
                    'Limited results? Upgrade for semantic search capabilities.'
            ]);

        } catch (Exception $e) {
            error_log('AIVectorSearch: Frontend search error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Search failed']);
        }
    }

    /**
     * Handle mode switching requests
     */
    public function handle_switch_mode() {
        // Verify nonce and permissions
        if (!check_ajax_referer('aivesese_admin_nonce', 'nonce', false) ||
            !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $new_mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? ''));
        $valid_modes = ['lite', 'self_hosted', 'api'];

        if (!in_array($new_mode, $valid_modes)) {
            wp_send_json_error(['message' => 'Invalid mode specified']);
            return;
        }

        try {
            $connection_manager = AIVectorSearch_Connection_Manager::instance();
            $result = $connection_manager->switch_mode($new_mode);

            if ($result['success']) {
                // Get updated stats
                $stats = $this->get_current_mode_stats();

                wp_send_json_success([
                    'message' => $result['message'],
                    'new_mode' => $new_mode,
                    'stats' => $stats,
                    'requires_sync' => $result['requires_sync'] ?? false
                ]);
            } else {
                wp_send_json_error(['message' => $result['message']]);
            }

        } catch (Exception $e) {
            error_log('AIVectorSearch: Mode switch error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Mode switch failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle request for current mode statistics
     */
    public function handle_get_mode_stats() {
        // Verify nonce and permissions
        if (!check_ajax_referer('aivesese_admin_nonce', 'nonce', false) ||
            !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        try {
            $stats = $this->get_current_mode_stats();
            wp_send_json_success($stats);

        } catch (Exception $e) {
            error_log('AIVectorSearch: Get stats error - ' . $e->getMessage());
            wp_send_json_error(['message' => 'Failed to get statistics']);
        }
    }

    /**
     * Get search suggestions for empty results
     */
    private function get_search_suggestions(string $query): array {
        $suggestions = [];

        // Get popular categories
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'orderby' => 'count',
            'order' => 'DESC',
            'hide_empty' => true,
            'number' => 3
        ]);

        if (!empty($categories) && !is_wp_error($categories)) {
            foreach ($categories as $category) {
                $suggestions[] = [
                    'text' => $category->name,
                    'type' => 'category',
                    'url' => get_term_link($category)
                ];
            }
        }

        // Add some common search refinements
        if (strlen($query) > 4) {
            $partial = substr($query, 0, -1);
            $suggestions[] = [
                'text' => $partial,
                'type' => 'refinement'
            ];
        }

        return $suggestions;
    }

    /**
     * Get current mode statistics
     */
    private function get_current_mode_stats(): array {
        $connection_manager = AIVectorSearch_Connection_Manager::instance();
        $mode = $connection_manager->get_mode();
        $config = $connection_manager->get_config_summary();

        $stats = [
            'mode' => $mode,
            'mode_label' => $config['mode_label'],
            'health_status' => $connection_manager->get_health_status(),
            'synced_count' => $connection_manager->get_synced_count(),
            'supports' => $connection_manager->supports_advanced_features()
        ];

        // Add mode-specific data
        if ($mode === 'lite') {
            $lite_stats = $this->lite_engine->get_index_stats();
            $stats['lite_stats'] = $lite_stats;
            $stats['upgrade_suggestions'] = $connection_manager->get_upgrade_suggestions();
            $stats['avg_search_time'] = get_option('aivesese_lite_avg_search_time', 0);
        }

        return $stats;
    }
}

// Initialize the AJAX handlers
AIVectorSearch_Lite_Mode_Ajax::instance();
