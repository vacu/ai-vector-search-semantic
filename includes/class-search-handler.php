<?php
/**
 * Search Handler with Connection Manager support
 */
class AIVectorSearch_Search_Handler {

    private static $instance = null;
    private $connection_manager;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('pre_get_posts', [$this, 'intercept_product_search'], 20);
    }

    public function intercept_product_search(WP_Query $query) {
        if (is_admin()) {
            return;
        }

        // Handle both regular search and AJAX search (including Woodmart)
        $search_term = '';

        // Regular WordPress search
        if ($query->is_search()) {
            if (!$query->get('post_type')) {
                $query->set('post_type', ['product']);
            }
            $search_term = $query->get('s');
        }

        // AJAX search (Woodmart and others) - only if integration is enabled
        if (wp_doing_ajax() && isset($_REQUEST['query'])) {
            // Check if Woodmart integration is enabled
            if (get_option('aivesese_enable_woodmart_integration', '0') !== '1') {
                return; // Skip AJAX integration if disabled
            }

            $search_term = sanitize_text_field(wp_unslash($_REQUEST['query']));

            // Ensure we're dealing with products
            if (!$query->get('post_type') || $query->get('post_type') === 'product') {
                $query->set('post_type', ['product']);
            }
        }

        if (!$search_term || strlen($search_term) < 3) {
            return;
        }

        $product_ids = $this->search_products($search_term);
        if (empty($product_ids)) {
            return;
        }

        // Clear the search term to prevent WordPress from doing its own search
        $query->set('s', '');
        $query->set('post__in', $product_ids);
        $query->set('orderby', 'post__in');
        $query->set('posts_per_page', 20);
    }

    public function search_products(string $term, int $limit = 20): array {
        $use_semantic = $this->should_use_semantic_search($term);

        if ($use_semantic) {
            $ids = $this->search_semantic($term, $limit);

            if (count($ids) < 5) {
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

        return $ids;
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
            return $this->connection_manager->search_products_semantic($term, $embedding, $limit);
        }
    }

    /**
     * Search using full-text search - uses connection manager
     */
    private function search_fulltext(string $term, int $limit): array {
        return $this->connection_manager->search_products_fts($term, $limit);
    }

    /**
     * Determine if semantic search should be used
     */
    private function should_use_semantic_search(string $term): bool {
        return get_option('aivesese_semantic_toggle') === '1' &&
               strlen($term) >= 3 &&
               $this->connection_manager->is_semantic_search_available();
    }
}
