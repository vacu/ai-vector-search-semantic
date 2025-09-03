<?php
/**
 * Connection Manager - Routes requests to API or Self-hosted based on settings
 */
class AIVectorSearch_Connection_Manager {

    private static $instance = null;
    private $api_client;
    private $supabase_client;
    private $openai_client;

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
    }

    /**
     * Check if API mode is active
     */
    public function is_api_mode(): bool {
        return get_option('aivesese_connection_mode') === 'api' &&
               !empty(get_option('aivesese_license_key')) &&
               get_option('aivesese_api_activated') === '1';
    }

    /**
     * Get connection status and health info
     */
    public function get_health_status(): array {
        if ($this->is_api_mode()) {
            return $this->api_client->get_status() ?: [];
        } else {
            $health = $this->supabase_client->get_store_health();
            return $health ? $health[0] : [];
        }
    }

    /**
     * Search products using full-text search
     */
    public function search_products_fts(string $term, int $limit = 20): array {
        if ($this->is_api_mode()) {
            return $this->api_client->search_fts($term, $limit);
        } else {
            return $this->supabase_client->search_products_fts($term, $limit);
        }
    }

    /**
     * Search products using semantic search
     */
    public function search_products_semantic(string $term, array $embedding, int $limit = 20): array {
        if ($this->is_api_mode()) {
            return $this->api_client->search_semantic($term, $limit);
        } else {
            return $this->supabase_client->search_products_semantic($term, $embedding, $limit);
        }
    }

    /**
     * Search products by SKU
     */
    public function search_products_sku(string $term, int $limit = 20): array {
        if ($this->is_api_mode()) {
            return $this->api_client->search_sku($term, $limit);
        } else {
            return $this->supabase_client->search_products_sku($term, $limit);
        }
    }

    /**
     * Sync products batch
     */
    public function sync_products_batch(array $products): bool {
        if ($this->is_api_mode()) {
            $result = $this->api_client->sync_products_batch($products);
            return $result['success'] ?? false;
        } else {
            return $this->supabase_client->sync_products_batch($products);
        }
    }

    /**
     * Generate embeddings for products without them
     */
    public function generate_missing_embeddings(): array {
        if ($this->is_api_mode()) {
            return $this->api_client->generate_embeddings();
        } else {
            // Use the existing product sync class for self-hosted
            $product_sync = AIVectorSearch_Product_Sync::instance();
            return $product_sync->generate_missing_embeddings();
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
        if ($this->is_api_mode()) {
            // API handles embedding generation internally for semantic search
            return null; // Not needed in API mode for single embeddings
        } else {
            return $this->openai_client->embed_single($text);
        }
    }

    /**
     * Check if semantic search is available
     */
    public function is_semantic_search_available(): bool {
        if ($this->is_api_mode()) {
            // Always available in API mode (handled by service)
            return true;
        } else {
            // For self-hosted, check if OpenAI key is configured
            return get_option('aivesese_semantic_toggle') === '1' &&
                   $this->openai_client->is_configured();
        }
    }

    /**
     * Get configuration summary for admin display
     */
    public function get_config_summary(): array {
        $mode = $this->is_api_mode() ? 'api' : 'self_hosted';

        $summary = [
            'mode' => $mode,
            'mode_label' => $mode === 'api' ? 'Managed API Service' : 'Self-Hosted',
        ];

        if ($mode === 'api') {
            $summary['license_key'] = get_option('aivesese_license_key');
            $summary['api_status'] = get_option('aivesese_api_activated') === '1' ? 'active' : 'inactive';
        } else {
            $summary['supabase_url'] = get_option('aivesese_url');
            $summary['supabase_configured'] = !empty(get_option('aivesese_url')) && !empty(get_option('aivesese_key'));
            $summary['openai_configured'] = !empty(get_option('aivesese_openai'));
        }

        $summary['semantic_enabled'] = $this->is_semantic_search_available();
        $summary['store_id'] = get_option('aivesese_store');

        return $summary;
    }

    /**
     * Test connection for current mode
     */
    public function test_connection(): array {
        if ($this->is_api_mode()) {
            return $this->api_client->test_connection();
        } else {
            // Test Supabase connection
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
        }
    }

    /**
     * Switch connection mode
     */
    public function switch_mode(string $new_mode): array {
        $current_mode = get_option('aivesese_connection_mode', 'self_hosted');

        if ($current_mode === $new_mode) {
            return ['success' => true, 'message' => 'Already in ' . $new_mode . ' mode'];
        }

        if ($new_mode === 'api') {
            $license_key = get_option('aivesese_license_key');

            if (empty($license_key)) {
                return ['success' => false, 'message' => 'License key required for API mode'];
            }

            // Test license key
            $activation_result = $this->api_client->activate_license($license_key);

            if (!$activation_result['success']) {
                return $activation_result;
            }

            update_option('aivesese_connection_mode', 'api');
            update_option('aivesese_api_activated', '1');

            return ['success' => true, 'message' => 'Switched to API mode successfully'];

        } else if ($new_mode === 'self_hosted') {

            // Check if self-hosted requirements are met
            if (empty(get_option('aivesese_url')) || empty(get_option('aivesese_key'))) {
                return [
                    'success' => false,
                    'message' => 'Supabase URL and key are required for self-hosted mode'
                ];
            }

            update_option('aivesese_connection_mode', 'self_hosted');
            update_option('aivesese_api_activated', '0');

            return ['success' => true, 'message' => 'Switched to self-hosted mode successfully'];
        }

        return ['success' => false, 'message' => 'Invalid mode specified'];
    }

    /**
     * Get synced product count
     */
    public function get_synced_count(): int {
        if ($this->is_api_mode()) {
            $status = $this->api_client->get_status();
            return $status['total_products'] ?? 0;
        } else {
            return $this->supabase_client->get_synced_count();
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
}
