<?php
/**
 * Lite Search Engine - Local WordPress TF-IDF based search
 * File: includes/class-lite-engine.php
 */
class AIVectorSearch_Lite_Engine {

    private static $instance = null;
    private $stopwords = [];
    private $synonyms = [];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_stopwords();
        $this->load_synonyms();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Rebuild index when products change
        add_action('save_post', [$this, 'maybe_rebuild_index'], 10, 2);
        add_action('woocommerce_update_product', [$this, 'maybe_rebuild_index_product']);
        add_action('delete_post', [$this, 'maybe_rebuild_index_delete'], 10, 2);

        // Cron job for periodic index rebuilds
        add_action('aivesese_rebuild_lite_index', [$this, 'rebuild_full_index']);

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('aivesese_rebuild_lite_index')) {
            wp_schedule_event(time(), 'daily', 'aivesese_rebuild_lite_index');
        }
    }

    /**
     * Main search method
     */
    public function search_products(string $term, int $limit = 20): array {
        if (strlen(trim($term)) < 2) {
            return [];
        }

        // Get or build search index
        $index = $this->get_search_index();
        if (empty($index)) {
            return [];
        }

        // Tokenize and expand search term
        $search_tokens = $this->tokenize_and_expand($term);
        if (empty($search_tokens)) {
            return [];
        }

        // Calculate scores for all products
        $scores = $this->calculate_scores($search_tokens, $index);

        // Sort by score and return top results
        arsort($scores);
        $product_ids = array_keys(array_slice($scores, 0, $limit, true));

        return array_map('intval', $product_ids);
    }

    /**
     * Get or build the search index
     */
    private function get_search_index(): array {
        $index_limit = get_option('aivesese_lite_index_limit', '500');
        $cache_key = "aivesese_lite_index_{$index_limit}";

        $index = get_transient($cache_key);
        if ($index !== false) {
            return $index;
        }

        // Build new index
        $index = $this->build_search_index();

        // Cache for 12 hours
        set_transient($cache_key, $index, 12 * HOUR_IN_SECONDS);

        return $index;
    }

    /**
     * Build the search index
     */
    private function build_search_index(): array {
        $limit = intval(get_option('aivesese_lite_index_limit', '500'));
        $products = $this->get_products_for_indexing($limit);

        if (empty($products)) {
            return [];
        }

        $index = [
            'documents' => [],
            'terms' => [],
            'metadata' => []
        ];

        foreach ($products as $product) {
            $doc_id = $product->get_id();
            $text_content = $this->extract_product_text($product);

            // Store document metadata
            $index['metadata'][$doc_id] = [
                'id' => $doc_id,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'brand' => $this->get_product_brand($product),
                'categories' => $this->get_product_categories($product),
                'price' => $product->get_price(),
                'stock_status' => $product->get_stock_status(),
                'created' => $product->get_date_created()->getTimestamp()
            ];

            // Tokenize content
            $tokens = $this->tokenize_text($text_content);
            $index['documents'][$doc_id] = array_count_values($tokens);

            // Build term frequency index
            foreach ($tokens as $token) {
                if (!isset($index['terms'][$token])) {
                    $index['terms'][$token] = [];
                }
                if (!isset($index['terms'][$token][$doc_id])) {
                    $index['terms'][$token][$doc_id] = 0;
                }
                $index['terms'][$token][$doc_id]++;
            }
        }

        // Calculate TF-IDF scores
        $total_docs = count($index['documents']);
        foreach ($index['terms'] as $term => $docs) {
            $df = count($docs); // Document frequency
            $idf = log($total_docs / $df); // Inverse document frequency

            foreach ($docs as $doc_id => $tf) {
                $index['terms'][$term][$doc_id] = $tf * $idf;
            }
        }

        return $index;
    }

    /**
     * Get products for indexing based on limit setting
     */
    private function get_products_for_indexing(int $limit): array {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'meta_query' => [
                [
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '='
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        // If limit is 0, get all products
        if ($limit === 0) {
            unset($args['posts_per_page']);
            unset($args['meta_query']); // Include out of stock products too
        }

        $posts = get_posts($args);
        $products = [];

        foreach ($posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product && $product->is_visible()) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Extract searchable text from product
     */
    private function extract_product_text(WC_Product $product): string {
        $text_parts = [
            $product->get_name(),
            $product->get_sku(),
            wp_strip_all_tags($product->get_description()),
            wp_strip_all_tags($product->get_short_description())
        ];

        // Add brand
        $brand = $this->get_product_brand($product);
        if ($brand) {
            $text_parts[] = $brand;
        }

        // Add categories
        $categories = $this->get_product_categories($product);
        if (!empty($categories)) {
            $text_parts = array_merge($text_parts, $categories);
        }

        // Add tags
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        if (!empty($tags) && !is_wp_error($tags)) {
            $text_parts = array_merge($text_parts, $tags);
        }

        // Add attributes
        $attributes = $product->get_attributes();
        foreach ($attributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    $text_parts = array_merge($text_parts, $terms);
                }
            } else {
                $text_parts[] = $attribute->get_options()[0] ?? '';
            }
        }

        return implode(' ', array_filter($text_parts));
    }

    /**
     * Tokenize and expand search term with synonyms
     */
    private function tokenize_and_expand(string $term): array {
        $tokens = $this->tokenize_text($term);
        $expanded = [];

        foreach ($tokens as $token) {
            $expanded[] = $token;

            // Add synonyms
            if (isset($this->synonyms[$token])) {
                $expanded = array_merge($expanded, $this->synonyms[$token]);
            }
        }

        return array_unique($expanded);
    }

    /**
     * Tokenize text into searchable terms
     */
    private function tokenize_text(string $text): array {
        // Convert to lowercase and remove HTML
        $text = strtolower(wp_strip_all_tags($text));

        // Handle Romanian diacritics
        $text = $this->normalize_romanian_text($text);

        // Split on non-alphanumeric characters
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter out stopwords and short terms
        $filtered = [];
        foreach ($tokens as $token) {
            if (strlen($token) >= 2 && !in_array($token, $this->stopwords)) {
                $filtered[] = $token;
            }
        }

        return $filtered;
    }

    /**
     * Calculate TF-IDF scores for search terms
     */
    private function calculate_scores(array $search_tokens, array $index): array {
        $scores = [];

        foreach ($search_tokens as $token) {
            $matching_terms = $this->find_matching_index_terms($token, $index['terms']);

            if (empty($matching_terms)) {
                continue;
            }

            foreach ($matching_terms as $matched_term => $weight) {
                foreach ($index['terms'][$matched_term] as $doc_id => $tfidf_score) {
                    if (!isset($scores[$doc_id])) {
                        $scores[$doc_id] = 0;
                    }
                    $scores[$doc_id] += $tfidf_score * $weight;
                }
            }
        }

        // Apply category and brand boosts
        foreach ($scores as $doc_id => $score) {
            if (!isset($index['metadata'][$doc_id])) {
                continue;
            }

            $metadata = $index['metadata'][$doc_id];
            $boost = 1.0;

            // Brand boost - if search term matches brand
            $search_text = strtolower(implode(' ', $search_tokens));
            if (!empty($metadata['brand']) && strpos($search_text, strtolower($metadata['brand'])) !== false) {
                $boost *= 1.5;
            }

            // Category boost - if search term matches category
            foreach ($metadata['categories'] as $category) {
                if (strpos($search_text, strtolower($category)) !== false) {
                    $boost *= 1.3;
                    break;
                }
            }

            // Stock status boost
            if ($metadata['stock_status'] === 'instock') {
                $boost *= 1.2;
            }

            // Recent products boost
            $age_days = (time() - $metadata['created']) / DAY_IN_SECONDS;
            if ($age_days < 30) {
                $boost *= 1.1;
            }

            $scores[$doc_id] = $score * $boost;
        }

        return $scores;
    }

    /**
     * Find index terms that match (exactly or partially) a search token
     */
    private function find_matching_index_terms(string $token, array $index_terms): array {
        $matches = [];

        if (isset($index_terms[$token])) {
            $matches[$token] = 1.0;
        }

        // Support prefix matching for longer tokens when exact matches are missing.
        if (strlen($token) >= 3) {
            foreach ($index_terms as $term => $documents) {
                if ($term === $token) {
                    continue;
                }

                if (strpos($term, $token) === 0) {
                    // Down-rank partial matches so exact hits stay ahead.
                    $length_ratio = strlen($token) / max(strlen($term), 1);
                    $matches[$term] = max(0.5, $length_ratio);
                }
            }
        }

        return $matches;
    }

    /**
     * Get product brand (from various sources)
     */
    private function get_product_brand(WC_Product $product): string {
        // Try different brand taxonomies/attributes
        $brand_sources = ['product_brand', 'brand', 'pa_brand'];

        foreach ($brand_sources as $source) {
            if (taxonomy_exists($source)) {
                $terms = wp_get_post_terms($product->get_id(), $source, ['fields' => 'names']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    return $terms[0];
                }
            }
        }

        // Try custom fields
        $brand_fields = ['_brand', 'brand', '_product_brand'];
        foreach ($brand_fields as $field) {
            $brand = get_post_meta($product->get_id(), $field, true);
            if (!empty($brand)) {
                return $brand;
            }
        }

        return '';
    }

    /**
     * Get product categories
     */
    private function get_product_categories(WC_Product $product): array {
        $terms = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        return (!empty($terms) && !is_wp_error($terms)) ? $terms : [];
    }

    /**
     * Normalize Romanian text (handle diacritics)
     */
    private function normalize_romanian_text(string $text): string {
        $replacements = [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ț' => 't'
        ];

        return strtr($text, $replacements);
    }

    /**
     * Load stopwords
     */
    private function load_stopwords() {
        $this->stopwords = [
            // English stopwords
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
            'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the',
            'to', 'was', 'will', 'with', 'or', 'but', 'not', 'this', 'can',
            // Romanian stopwords
            'si', 'sau', 'cu', 'de', 'la', 'in', 'pe', 'ca', 'ce', 'se',
            'un', 'una', 'ale', 'lui', 'din', 'pentru', 'mai', 'dar', 'nu'
        ];
    }

    /**
     * Load synonyms
     */
    private function load_synonyms() {
        $this->synonyms = [
            // English synonyms
            'phone' => ['mobile', 'smartphone', 'cell'],
            'laptop' => ['notebook', 'computer'],
            'tv' => ['television', 'monitor'],
            'shoes' => ['footwear', 'sneakers'],

            // Romanian synonyms
            'telefon' => ['mobil', 'smartphone'],
            'laptop' => ['notebook', 'calculator'],
            'televizor' => ['tv', 'monitor'],
            'pantofi' => ['incaltaminte', 'adidasi']
        ];
    }

    /**
     * Rebuild index when product changes
     */
    public function maybe_rebuild_index($post_id, $post) {
        if ($post->post_type === 'product' && $post->post_status === 'publish') {
            $this->clear_index_cache();
        }
    }

    /**
     * Rebuild index when product is updated via WooCommerce
     */
    public function maybe_rebuild_index_product($product_id) {
        $this->clear_index_cache();
    }

    /**
     * Rebuild index when product is deleted
     */
    public function maybe_rebuild_index_delete($post_id, $post) {
        if ($post->post_type === 'product') {
            $this->clear_index_cache();
        }
    }

    /**
     * Clear index cache
     */
    private function clear_index_cache() {
        $limits = ['200', '500', '1000', '0'];
        foreach ($limits as $limit) {
            delete_transient("aivesese_lite_index_{$limit}");
        }
    }

    /**
     * Rebuild full index (cron job)
     */
    public function rebuild_full_index() {
        $this->clear_index_cache();
        // Force rebuild by calling get_search_index
        $this->get_search_index();
    }

    /**
     * Get index statistics
     */
    public function get_index_stats(): array {
        $limit = intval(get_option('aivesese_lite_index_limit', '500'));
        $cache_key = "aivesese_lite_index_{$limit}";

        $index = get_transient($cache_key);
        if ($index === false) {
            return ['indexed_products' => 0, 'total_terms' => 0, 'last_built' => 0];
        }

        return [
            'indexed_products' => count($index['documents']),
            'total_terms' => count($index['terms']),
            'last_built' => time() // Approximate, could store actual time
        ];
    }

    /**
     * Force rebuild index
     */
    public function force_rebuild_index(): array {
        $this->clear_index_cache();

        $start_time = microtime(true);
        $index = $this->build_search_index();
        $build_time = microtime(true) - $start_time;

        if (empty($index)) {
            return [
                'success' => false,
                'message' => 'Failed to build search index - no products found',
                'stats' => []
            ];
        }

        // Cache the new index
        $limit = intval(get_option('aivesese_lite_index_limit', '500'));
        $cache_key = "aivesese_lite_index_{$limit}";
        set_transient($cache_key, $index, 12 * HOUR_IN_SECONDS);

        return [
            'success' => true,
            'message' => 'Search index rebuilt successfully',
            'stats' => [
                'indexed_products' => count($index['documents']),
                'total_terms' => count($index['terms']),
                'build_time' => round($build_time, 2)
            ]
        ];
    }
}
