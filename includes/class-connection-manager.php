<?php
/**
 * Connection Manager - Routes requests to API or Self-hosted based on settings
 */
class AIVectorSearch_Connection_Manager {

    private static $instance = null;
    private $api_client;
    private $supabase_client;
    private $openai_client;
    private $lite_engine;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_client = AIVectorSearch_API_Client::instance();
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
        $this->openai_client = AIVectorSearch_OpenAI_Client::instance();
        $this->lite_engine = AIVectorSearch_Lite_Engine::instance();
    }

    /**
     * Get current connection mode
     */
    public function get_mode(): string {
        $mode = get_option('aivesese_connection_mode', '');

        // Auto-detect mode if not set
        if (empty($mode)) {
            $mode = $this->detect_optimal_mode();
            update_option('aivesese_connection_mode', $mode);
        }

        return $mode;
    }

    /**
     * Auto-detect the best mode based on current configuration
     */
    private function detect_optimal_mode(): string {
        // Check if API mode is configured
        if (!empty(get_option('aivesese_license_key')) &&
            get_option('aivesese_api_activated') === '1') {
            return 'api';
        }

        // Check if self-hosted mode is configured
        if (!empty(get_option('aivesese_url')) &&
            !empty(get_option('aivesese_key')) &&
            !empty(get_option('aivesese_store'))) {
            return 'self_hosted';
        }

        // Default to lite mode for immediate value
        return 'lite';
    }

    /**
     * Check if API mode is active
     */
    public function is_api_mode(): bool {
        return $this->get_mode() === 'api' &&
               !empty(get_option('aivesese_license_key')) &&
               get_option('aivesese_api_activated') === '1';
    }

    /**
     * Check if lite mode is active
     */
    public function is_lite_mode(): bool {
        return $this->get_mode() === 'lite';
    }

    /**
     * Check if self-hosted mode is active
     */
    public function is_self_hosted_mode(): bool {
        return $this->get_mode() === 'self_hosted';
    }

    /**
     * Get connection status and health info
     */
    public function get_health_status(): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                $stats = $this->lite_engine->get_index_stats();
                return [
                    'mode' => 'lite',
                    'status' => 'healthy',
                    'indexed_products' => $stats['indexed_products'],
                    'total_terms' => $stats['total_terms'],
                    'last_indexed' => $stats['last_built']
                ];

            case 'api':
                return $this->api_client->get_status() ?: [];

            case 'self_hosted':
                $health = $this->supabase_client->get_store_health();
                return $health ? $health[0] : [];

            default:
                return ['mode' => 'unknown', 'status' => 'error'];
        }
    }

    /**
     * Search products using full-text search
     */
    public function search_products_fts(string $term, int $limit = 20): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                return $this->lite_engine->search_products($term, $limit);

            case 'api':
                return $this->api_client->search_fts($term, $limit) ?: [];

            case 'self_hosted':
                return $this->supabase_client->search_products_fts($term, $limit) ?: [];

            default:
                // Fallback to lite mode
                return $this->lite_engine->search_products($term, $limit);
        }
    }

    /**
     * Search products using semantic search
     */
    public function search_products_semantic(string $term, array $embedding, int $limit = 20): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                // Fallback to lite search for semantic requests
                return $this->lite_engine->search_products($term, $limit);

            case 'api':
                return $this->api_client->search_semantic($term, $limit) ?: [];

            case 'self_hosted':
                if (empty($embedding)) {
                    return [];
                }
                return $this->supabase_client->search_products_semantic($term, $embedding, $limit) ?: [];

            default:
                return $this->lite_engine->search_products($term, $limit);
        }
    }

    /**
     * Search products by SKU
     */
    public function search_products_sku(string $term, int $limit = 20): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                // For lite mode, we'll implement a simple SKU filter on the lite search results
                return $this->search_lite_sku($term, $limit);

            case 'api':
                return $this->api_client->search_sku($term, $limit) ?: [];

            case 'self_hosted':
                return $this->supabase_client->search_products_sku($term, $limit) ?: [];

            default:
                return $this->search_lite_sku($term, $limit);
        }
    }

    public function search_products_fuzzy(string $term, int $limit = 20): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                // Lite mode uses its own fuzzy-like search with TF-IDF
                return $this->lite_engine->search_products($term, $limit);

            case 'api':
                return $this->api_client->search_fuzzy($term, $limit) ?: [];

            case 'self_hosted':
                return $this->supabase_client->search_products_fuzzy($term, $limit) ?: [];

            default:
                return $this->lite_engine->search_products($term, $limit);
        }
    }

    /**
     * Simple SKU search for lite mode
     */
    private function search_lite_sku(string $term, int $limit): array {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_sku',
                    'value' => $term,
                    'compare' => 'LIKE'
                ]
            ]
        ];

        $posts = get_posts($args);
        return array_map(function($post) { return $post->ID; }, $posts);
    }

    /**
     * Sync products batch
     */
    public function sync_products_batch(array $products): bool {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                // Lite mode doesn't need external sync, it indexes automatically
                // Force rebuild to include any new products
                $this->lite_engine->force_rebuild_index();
                return true;

            case 'api':
                $result = $this->api_client->sync_products_batch($products);
                return $result['success'] ?? false;

            case 'self_hosted':
                return $this->supabase_client->sync_products_batch($products);

            default:
                return false;
        }
    }

    /**
     * Generate embeddings for products without them
     */
    public function generate_missing_embeddings(): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                // No embeddings in lite mode
                return ['success' => true, 'message' => 'Lite mode doesn\'t use embeddings'];

            case 'api':
                // API mode handles embeddings automatically
                return $this->api_client->generate_embeddings();

            case 'self_hosted':
                $product_sync = AIVectorSearch_Product_Sync::instance();
                return $product_sync->generate_missing_embeddings();

            default:
                return ['success' => false, 'message' => 'Unknown mode'];
        }
    }

    /**
     * Get cart recommendations
     */
    public function get_recommendations(array $cart_ids, int $limit = 4): array {
        if ($this->is_api_mode()) {
            return $this->api_client->get_cart_recommendations($cart_ids, $limit);
        } else {
            return $this->supabase_client->get_recommendations($cart_ids, $limit);
        }
    }

    /**
     * Get similar products
     */
    public function get_similar_products(int $product_id, int $limit = 4): array {
        if ($this->is_api_mode()) {
            return $this->api_client->get_similar_products($product_id, $limit);
        } else {
            return $this->supabase_client->get_similar_products($product_id, $limit);
        }
    }

    /**
     * Generate embedding for a single text
     */
    public function generate_embedding(string $text): ?array {
        if ($this->get_mode() !== 'self_hosted') {
            return null; // Not needed in API mode or lite mode
        }

        return $this->openai_client->embed_single($text);
    }

    /**
     * Check if semantic search is available
     */
    public function is_semantic_search_available(): bool {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                return false; // No semantic search in lite mode

            case 'api':
                // Always available in API mode (handled by service)
                return true;

            case 'self_hosted':
                return get_option('aivesese_semantic_toggle') === '1' &&
                       !empty(get_option('aivesese_openai'));

            default:
                return false;
        }
    }

    /**
     * Get configuration summary for admin display
     */
    public function get_config_summary(): array {
        $mode = $this->get_mode();

        $summary = [
            'mode' => $mode,
            'mode_label' => $this->get_mode_label($mode),
        ];

        switch ($mode) {
            case 'lite':
                $limit = get_option('aivesese_lite_index_limit', '500');
                $summary['index_limit'] = $limit;
                $summary['index_limit_label'] = $this->get_limit_label($limit);
                $summary['upgrade_available'] = true;
                break;

            case 'api':
                $summary['license_key'] = get_option('aivesese_license_key');
                $summary['api_status'] = get_option('aivesese_api_activated') === '1' ? 'active' : 'inactive';
                break;

            case 'self_hosted':
                $summary['supabase_url'] = get_option('aivesese_url');
                $summary['supabase_configured'] = !empty(get_option('aivesese_url')) && !empty(get_option('aivesese_key'));
                $summary['openai_configured'] = !empty(get_option('aivesese_openai'));
                break;
        }

        $summary['semantic_enabled'] = $this->is_semantic_search_available();
        $summary['store_id'] = get_option('aivesese_store');

        return $summary;
    }

    /**
     * Get human-readable mode label
     */
    private function get_mode_label(string $mode): string {
        switch ($mode) {
            case 'lite': return 'Lite Mode (Local Search)';
            case 'api': return 'Managed API Service';
            case 'self_hosted': return 'Self-Hosted (Supabase)';
            default: return 'Unknown Mode';
        }
    }

    /**
     * Get human-readable limit label
     */
    private function get_limit_label(string $limit): string {
        switch ($limit) {
            case '200': return 'Recent 200 products (fastest)';
            case '500': return 'Recent 500 products (balanced)';
            case '1000': return 'Recent 1000 products (comprehensive)';
            case '0': return 'All products (may be slower)';
            default: return "Recent {$limit} products";
        }
    }

    /**
     * Test connection for current mode
     */
    public function test_connection(): array {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                $stats = $this->lite_engine->get_index_stats();
                return [
                    'success' => true,
                    'message' => 'Lite mode is working perfectly!',
                    'data' => [
                        'indexed_products' => $stats['indexed_products'],
                        'mode' => 'lite'
                    ]
                ];

            case 'api':
                return $this->api_client->test_connection();

            case 'self_hosted':
                $health = $this->supabase_client->get_store_health();
                if (empty($health)) {
                    return [
                        'success' => false,
                        'message' => 'Unable to connect to Supabase. Check your URL and API key.'
                    ];
                }
                return [
                    'success' => true,
                    'message' => 'Self-hosted connection successful!',
                    'data' => $health[0]
                ];

            default:
                return [
                    'success' => false,
                    'message' => 'Unknown connection mode'
                ];
        }
    }

    /**
     * Switch connection mode
     */
    public function switch_mode(string $new_mode): array {
        $current_mode = $this->get_mode();

        if ($current_mode === $new_mode) {
            return ['success' => true, 'message' => "Already in {$new_mode} mode"];
        }

        // Validate new mode requirements
        $validation = $this->validate_mode_requirements($new_mode);
        if (!$validation['success']) {
            return $validation;
        }

        // Update mode
        update_option('aivesese_connection_mode', $new_mode);

        // Handle mode-specific setup
        switch ($new_mode) {
            case 'lite':
                // Force rebuild of lite index
                $this->lite_engine->force_rebuild_index();
                $message = 'Switched to Lite mode successfully! Your products are being indexed locally.';
                break;

            case 'api':
                update_option('aivesese_api_activated', '1');
                $message = 'Switched to API mode successfully!';
                break;

            case 'self_hosted':
                update_option('aivesese_api_activated', '0');
                $message = 'Switched to self-hosted mode successfully!';
                break;

            default:
                return ['success' => false, 'message' => 'Invalid mode specified'];
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * Validate requirements for switching to a mode
     */
    private function validate_mode_requirements(string $mode): array {
        switch ($mode) {
            case 'lite':
                // No special requirements for lite mode
                return ['success' => true];

            case 'api':
                $license_key = get_option('aivesese_license_key');
                if (empty($license_key)) {
                    return ['success' => false, 'message' => 'License key required for API mode'];
                }

                // Test license key
                $activation_result = $this->api_client->activate_license($license_key);
                if (!$activation_result['success']) {
                    return $activation_result;
                }

                return ['success' => true];

            case 'self_hosted':
                if (empty(get_option('aivesese_url')) || empty(get_option('aivesese_key'))) {
                    return [
                        'success' => false,
                        'message' => 'Supabase URL and key are required for self-hosted mode'
                    ];
                }
                return ['success' => true];

            default:
                return ['success' => false, 'message' => 'Invalid mode specified'];
        }
    }

    /**
     * Get synced product count
     */
    public function get_synced_count(): int {
        $mode = $this->get_mode();

        switch ($mode) {
            case 'lite':
                $stats = $this->lite_engine->get_index_stats();
                return $stats['indexed_products'];

            case 'api':
                $status = $this->api_client->get_status();
                return $status['total_products'] ?? 0;

            case 'self_hosted':
                return $this->supabase_client->get_synced_count();

            default:
                return 0;
        }
    }

    /**
     * Migrate from self-hosted to API (and vice versa)
     */
    public function migrate_data(string $from_mode, string $to_mode): array {
        if ($from_mode === $to_mode) {
            return ['success' => true, 'message' => 'No migration needed'];
        }

        // For now, we don't automatically migrate data between modes
        // Users would need to re-sync their products after switching
        return [
            'success' => true,
            'message' => 'Mode switched. You may need to re-sync your products.',
            'requires_resync' => true
        ];
    }

    /**
     * Get upgrade suggestions based on current usage
     */
    public function get_upgrade_suggestions(): array {
        if ($this->get_mode() !== 'lite') {
            return []; // Only suggest upgrades from lite mode
        }

        $suggestions = [];
        $stats = $this->lite_engine->get_index_stats();
        $total_products = wp_count_posts('product')->publish;

        // Suggest upgrade based on product count
        if ($total_products > 1000) {
            $suggestions[] = [
                'type' => 'performance',
                'title' => 'Large Product Catalog Detected',
                'message' => "Your store has {$total_products} products. Supabase would provide faster search performance.",
                'cta' => 'Upgrade for Better Performance'
            ];
        }

        // Suggest semantic search benefits
        $suggestions[] = [
            'type' => 'feature',
            'title' => 'Unlock Semantic Search',
            'message' => 'Find products by meaning, not just keywords. "comfortable shoes" finds "cozy sneakers".',
            'cta' => 'Enable AI-Powered Search'
        ];

        // Performance-based suggestion (would need to track search times)
        $search_time = get_option('aivesese_lite_avg_search_time', 0);
        if ($search_time > 500) { // milliseconds
            $suggestions[] = [
                'type' => 'speed',
                'title' => 'Speed Up Your Search',
                'message' => "Current search time: {$search_time}ms. Supabase typically delivers <50ms response times.",
                'cta' => 'Get Lightning Fast Search'
            ];
        }

        return $suggestions;
    }

    /**
     * Migrate from lite mode to full mode
     */
    public function migrate_from_lite(string $to_mode): array {
        if ($this->get_mode() !== 'lite') {
            return ['success' => false, 'message' => 'Not currently in lite mode'];
        }

        // Switch to new mode
        $switch_result = $this->switch_mode($to_mode);
        if (!$switch_result['success']) {
            return $switch_result;
        }

        // For API/self-hosted modes, we'd need to sync products
        if (in_array($to_mode, ['api', 'self_hosted'])) {
            return [
                'success' => true,
                'message' => "Successfully switched to {$to_mode} mode! Please sync your products to enable full search capabilities.",
                'requires_sync' => true
            ];
        }

        return $switch_result;
    }

    /**
     * Check if current mode supports advanced features
     */
    public function supports_advanced_features(): array {
        $mode = $this->get_mode();

        return [
            'semantic_search' => in_array($mode, ['api', 'self_hosted']) && $this->is_semantic_search_available(),
            'unlimited_products' => in_array($mode, ['api', 'self_hosted']),
            'advanced_analytics' => in_array($mode, ['api', 'self_hosted']),
            'real_time_sync' => in_array($mode, ['api', 'self_hosted']),
            'multi_language' => in_array($mode, ['api', 'self_hosted']),
            'custom_attributes' => in_array($mode, ['api', 'self_hosted']),
            'performance_optimization' => in_array($mode, ['api', 'self_hosted'])
        ];
    }
}
