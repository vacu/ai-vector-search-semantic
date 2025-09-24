<?php
/**
 * Search Handler with Woodmart Integration and Analytics
 * File: includes/class-search-handler.php
 */
class AIVectorSearch_Search_Handler {

    private static $instance = null;
    private $connection_manager;
    private $analytics;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance();
        $this->analytics = AIVectorSearch_Analytics::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        if (!$this->is_search_enabled()) {
            return;
        }

        // Regular WordPress search interception
        add_action('pre_get_posts', [$this, 'intercept_product_search'], 9999);

        // Woodmart AJAX search integration
        $this->init_woodmart_integration();

        // Track clicks on search results
        add_action('template_redirect', [$this, 'maybe_track_click']);
    }

    /**
     * Initialize Woodmart AJAX search integration
     */
    private function init_woodmart_integration() {
        // Only initialize if Woodmart integration is enabled
        if (get_option('aivesese_enable_woodmart_integration', '0') !== '1') {
            return;
        }

        // Register AJAX handlers directly
        add_action('wp_ajax_woodmart_ajax_search', [$this, 'handle_woodmart_ajax_search']);
        add_action('wp_ajax_nopriv_woodmart_ajax_search', [$this, 'handle_woodmart_ajax_search']);

        // Remove original Woodmart handlers
        add_action('wp_loaded', [$this, 'override_woodmart_ajax'], 99);

        // Fallback method using pre_get_posts
        add_action('pre_get_posts', [$this, 'intercept_woodmart_query'], 1);
    }

    /**
     * Override Woodmart's original AJAX search handlers
     */
    public function override_woodmart_ajax() {
        // Remove original Woodmart handlers if they exist
        remove_action('wp_ajax_woodmart_ajax_search', 'woodmart_ajax_search');
        remove_action('wp_ajax_nopriv_woodmart_ajax_search', 'woodmart_ajax_search');
    }

    /**
     * Handle Woodmart AJAX search with AI results and analytics
     */
    public function handle_woodmart_ajax_search() {
        if (!$this->is_search_enabled()) {
            wp_send_json(['suggestions' => []]);
            return;
        }

        $query = sanitize_text_field($_REQUEST['query'] ?? '');
        $number = intval($_REQUEST['number'] ?? 20);

        if (strlen($query) < 3) {
            wp_send_json(['suggestions' => []]);
            return;
        }

        // Get AI search results
        $product_ids = $this->search_products($query, $number);
        $product_ids = $this->normalize_product_ids($product_ids);

        // Track analytics
        $this->track_search_analytics($query, $product_ids);

        // Build suggestions for Woodmart
        $suggestions = [];

        if (!empty($product_ids)) {
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || $product->get_status() !== 'publish') {
                    continue;
                }

                $suggestions[] = [
                    'value' => $product->get_name(),
                    'permalink' => add_query_arg([
                        'from_search' => '1',
                        'search_term' => urlencode($query)
                    ], get_permalink($product_id)),
                    'price' => $product->get_price_html(),
                    'thumbnail' => $product->get_image(),
                    'sku' => $product->get_sku() ? 'SKU: ' . $product->get_sku() : '',
                    'group' => 'product'
                ];
            }
        }

        wp_send_json(['suggestions' => $suggestions]);
        exit;
    }

    /**
     * Intercept Woodmart query as fallback
     */
    public function intercept_woodmart_query($query) {
        // Only for AJAX requests
        if (!wp_doing_ajax() || is_admin()) {
            return;
        }

        // Only for Woodmart search
        if (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'woodmart_ajax_search') {
            return;
        }

        $search_term = sanitize_text_field($_REQUEST['query'] ?? '');

        if (strlen($search_term) < 3) {
            return;
        }

        // Get our AI search results
        $product_ids = $this->search_products($search_term, 20);
        $product_ids = $this->normalize_product_ids($product_ids);

        if (!empty($product_ids)) {
            // Override the query to use our results
            $query->set('post__in', $product_ids);
            $query->set('orderby', 'post__in');
            $query->set('post_type', 'product');
            $query->set('posts_per_page', 20);

            // Clear default search parameters
            $query->set('s', '');
            $query->set('meta_query', []);
            $query->set('tax_query', []);
        }
    }

    /**
     * Regular WordPress search interception
     */
    public function intercept_product_search(WP_Query $query) {
        if (is_admin() || ! $query->is_main_query()) {
            return;
        }

        $search_term = '';
        $is_ajax_search = false;

        // Regular WordPress search
        if ($query->is_search()) {
            if (!$query->get('post_type')) {
                $query->set('post_type', ['product']);
            }
            $search_term = $query->get('s');
        }

        // Generic AJAX search detection (not Woodmart specific)
        if (wp_doing_ajax() && !isset($_REQUEST['action'])) {
            $search_term = sanitize_text_field($_REQUEST['s'] ?? $_REQUEST['query'] ?? '');
            if ($search_term) {
                $is_ajax_search = true;
                $query->set('post_type', ['product']);
            }
        }

        if (!$search_term || strlen($search_term) < 3) {
            return;
        }

        $product_ids = $this->search_products($search_term);
        $product_ids = $this->normalize_product_ids($product_ids);

        $this->track_search_analytics($search_term, $product_ids);

        if (empty($product_ids)) {
            if ($is_ajax_search) {
                $query->set('post__in', [0]); // Force no results
            }
            return;
        }

        // Clear the search term to prevent WordPress from doing its own search
        $query->set('s', '');
        $query->set('post__in', $product_ids);
        $query->set('orderby', 'post__in');
        $query->set('posts_per_page', $is_ajax_search ? 10 : 20);
    }

    /**
     * Main search method
     */
    public function search_products(string $term, int $limit = 20): array {
        if (!$this->is_search_enabled()) {
            return [];
        }

        $use_semantic = $this->should_use_semantic_search($term);

        if ($use_semantic) {
            $ids = $this->search_semantic($term, $limit);

            if (count($ids) < 3) {
                $ids = array_unique(array_merge(
                    $ids,
                    $this->search_fulltext($term, $limit)
                ));
            }

            // If still no results, try SKU search as fallback
            if (empty($ids)) {
                $ids = $this->search_sku($term, $limit);
            }

            return $ids;
        }

        // For non-semantic search
        $ids = $this->search_fulltext($term, $limit);

        // If no results from full-text search, try SKU search as fallback
        if (empty($ids)) {
            $ids = $this->search_sku($term, $limit);
        }

        // Add fuzzy search as final fallback for non-semantic search too
        if (empty($ids)) {
            $ids = $this->search_fuzzy($term, $limit);
        }

        return $ids;
    }

    /**
     * Track search analytics
     */
    private function track_search_analytics(string $term, array $product_ids) {
        // Determine search type used
        $search_type = 'fts'; // default

        if ($this->should_use_semantic_search($term)) {
            $search_type = 'semantic';
        }

        // If no results from main search, check if SKU search would work
        if (empty($product_ids)) {
            $sku_results = $this->search_sku($term, 5);
            if (!empty($sku_results)) {
                $search_type = 'sku';
                $product_ids = $sku_results; // Update results for tracking
            }
        }

        // Track the search
        if (is_object($this->analytics)) {
            $this->analytics->track_search($term, $search_type, $product_ids);
        }
    }

    /**
     * Track clicks on search results
     */
    public function maybe_track_click() {
        // Check if this is a click from search results
        if (isset($_GET['from_search']) && isset($_GET['search_term']) && is_singular('product')) {
            $search_term = sanitize_text_field($_GET['search_term']);
            $product_id = get_the_ID();

            // Track the click in analytics
            if (is_object($this->analytics)) {
                $this->analytics->track_click($search_term, $product_id);
            }
        }
    }

    /**
     * Search using SKU - uses connection manager
     */
    private function search_sku(string $term, int $limit): array {
        return $this->connection_manager->search_products_sku($term, $limit);
    }

    /**
     * Search using semantic search - uses connection manager
     */
    private function search_semantic(string $term, int $limit): array {
        if ($this->connection_manager->is_api_mode()) {
            // API mode handles embedding generation internally
            return $this->connection_manager->search_products_semantic($term, [], $limit);
        } else {
            // Self-hosted mode needs to generate embedding first
            $embedding = $this->connection_manager->generate_embedding($term);
            if (!$embedding) {
                return [];
            }

            $results = $this->connection_manager->search_products_semantic($term, $embedding, $limit);
            return $results;
        }
    }

    /**
     * Search using full-text search - uses connection manager
     */
    private function search_fulltext(string $term, int $limit): array {
        return $this->connection_manager->search_products_fts($term, $limit);
    }

    /**
     * Fuzzy search fallback
     */
    private function search_fuzzy(string $term, int $limit): array {
        return $this->connection_manager->search_products_fuzzy($term, $limit);
    }

    /**
     * Determine if semantic search should be used
     */
    private function should_use_semantic_search(string $term): bool {
        return get_option('aivesese_semantic_toggle') === '1' &&
               strlen($term) >= 3 &&
               $this->connection_manager->is_semantic_search_available();
    }

    private function normalize_product_ids(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if (!$p) continue;

            // Map variations to parent
            if ($p->is_type('variation')) {
                $parent = $p->get_parent_id();
                if (!$parent) continue;
                $id = $parent;
                $p  = wc_get_product($id);
                if (!$p) continue;
            }

            // Keep only published products
            if ($p->get_status() !== 'publish') continue;

            // Preserve order while making unique
            if (!isset($out[$id])) $out[$id] = true;
        }
        return array_map('intval', array_keys($out));
    }

    /**
     * Get search suggestions for autocomplete
     */
    public function get_search_suggestions(string $term, int $limit = 5): array {
        if (strlen($term) < 2) {
            return [];
        }

        // Get popular search terms from analytics that start with the term
        $suggestions = $this->analytics->get_popular_terms(10, 30);

        $matching_suggestions = [];
        foreach ($suggestions as $suggestion) {
            if (stripos($suggestion->search_term, $term) === 0) {
                $matching_suggestions[] = $suggestion->search_term;
                if (count($matching_suggestions) >= $limit) {
                    break;
                }
            }
        }

        return $matching_suggestions;
    }

    /**
     * Get trending searches
     */
    public function get_trending_searches(int $limit = 5): array {
        return $this->analytics->get_popular_terms($limit, 7); // Last 7 days
    }

    /**
     * Preview search results (for admin/testing)
     */
    public function preview_search_results(string $term, int $limit = 10): array {
        $product_ids = $this->search_products($term, $limit);

        $results = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $results[] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price_html(),
                    'url' => get_permalink($product_id),
                    'image' => get_the_post_thumbnail_url($product_id, 'thumbnail')
                ];
            }
        }

        return $results;
    }

    /**
     * Public search API for external use
     */
    public function public_search(string $term, array $args = []): array {
        if (!$this->is_search_enabled()) {
            return [];
        }

        $defaults = [
            'limit' => 20,
            'track' => true,
            'include_data' => false
        ];

        $args = wp_parse_args($args, $defaults);

        $product_ids = $this->search_products($term, $args['limit']);

        // Track if enabled
        if ($args['track']) {
            $this->track_search_analytics($term, $product_ids);
        }

        // Return just IDs or include product data
        if (!$args['include_data']) {
            return $product_ids;
        }

        return $this->preview_search_results($term, $args['limit']);
    }

    /**
     * Debug method for testing
     */
    public function debug_search(string $term): array {
        if (!current_user_can('manage_options')) {
            return [];
        }

        $results = [
            'term' => $term,
            'fts_results' => $this->search_fulltext($term, 10),
            'sku_results' => $this->search_sku($term, 10),
            'final_results' => $this->search_products($term, 10),
            'semantic_available' => $this->should_use_semantic_search($term),
            'woodmart_integration' => get_option('aivesese_enable_woodmart_integration', '0') === '1',
            'analytics_enabled' => true
        ];

        if ($this->should_use_semantic_search($term)) {
            $results['semantic_results'] = $this->search_semantic($term, 10);
        }

        // Add analytics data
        $results['recent_searches'] = $this->analytics->get_popular_terms(5, 7);
        $results['zero_results'] = $this->analytics->get_zero_result_searches(3, 7);

        return $results;
    }

    /**
     * Determine if search features should be active
     */
    private function is_search_enabled(): bool {
        return get_option('aivesese_enable_search', '1') === '1';
    }
}
