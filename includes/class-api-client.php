<?php
/**
 * API Client for ZZZ Solutions Managed Service
 */
class AIVectorSearch_API_Client {

    private static $instance = null;
    private $api_base_url = 'https://api.zzzsolutions.ro/api/v1';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Check if API mode is active
     */
    public function is_api_mode(): bool {
        return get_option('aivesese_connection_mode') === 'api' &&
               !empty(get_option('aivesese_license_key'));
    }

    /**
     * Activate license key
     */
    public function activate_license(string $license_key): array {
        $response = $this->request('POST', '/activate', [
            'license_key' => $license_key,
            'site_url' => home_url(),
            'site_name' => get_bloginfo('name')
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 && !empty($data['success'])) {
            return [
                'success' => true,
                'store_id' => $data['data']['store_id'],
                'plan' => $data['data']['plan'],
                'message' => 'License activated successfully!'
            ];
        }

        return [
            'success' => false,
            'message' => $data['error'] ?? 'Invalid license key or activation failed'
        ];
    }

    /**
     * Get service status and usage statistics
     */
    public function get_status(): ?array {
        if (!$this->is_api_mode()) {
            return null;
        }

        $response = $this->request('GET', '/store/health');

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['data'] ?? null;
    }

    /**
     * Full-text search via API
     */
    public function search_fts(string $term, int $limit = 20): array {
        $response = $this->request('POST', '/search/fts', [
            'term' => $term,
            'limit' => $limit
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * Semantic search via API
     */
    public function search_semantic(string $term, int $limit = 20, float $threshold = 0.5): array {
        $response = $this->request('POST', '/search/semantic', [
            'term' => $term,
            'limit' => $limit,
            'threshold' => $threshold
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * SKU search via API
     */
    public function search_sku(string $term, int $limit = 20): array {
        $response = $this->request('POST', '/search/sku', [
            'term' => $term,
            'limit' => $limit
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * Sync products to API
     */
    public function sync_products(array $products): array {
        $response = $this->request('POST', '/products/sync', [
            'products' => $products
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data ?? ['success' => false, 'message' => 'Unknown error'];
    }

    /**
     * Batch sync products
     */
    public function sync_products_batch(array $products): array {
        $response = $this->request('POST', '/products/batch', [
            'products' => $products
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data ?? ['success' => false, 'message' => 'Unknown error'];
    }

    /**
     * Generate embeddings for products
     */
    public function generate_embeddings(int $batch_size = 25, int $max_batches = 10): array {
        $response = $this->request('POST', '/products/embeddings', [
            'batch_size' => $batch_size,
            'max_batches' => $max_batches
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data ?? ['success' => false, 'message' => 'Unknown error'];
    }

    /**
     * Get cart recommendations
     */
    public function get_cart_recommendations(array $cart_ids, int $limit = 4): array {
        $response = $this->request('POST', '/recommendations/cart', [
            'cart_ids' => $cart_ids,
            'limit' => $limit
        ]);

        return $this->parse_recommendations_response($response);
    }

    /**
     * Get similar products
     */
    public function get_similar_products(int $product_id, int $limit = 4): array {
        $response = $this->request('POST', '/recommendations/similar', [
            'product_id' => $product_id,
            'limit' => $limit
        ]);

        return $this->parse_recommendations_response($response);
    }

    /**
     * Make API request with authentication
     */
    private function request(string $method, string $endpoint, array $body = null): array|WP_Error {
        $store_id = get_option('aivesese_store');
        $license_key = get_option('aivesese_license_key');

        if (empty($license_key)) {
            return new WP_Error('no_license', 'No license key configured');
        }

        $url = $this->api_base_url . $endpoint;

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Store-ID' => $store_id ?: '',
                'X-Store-Token' => $license_key,
            ],
        ];

        if ($body) {
            $args['body'] = wp_json_encode($body);
        }

        return wp_remote_request($url, $args);
    }

    /**
     * Parse search response and extract product IDs
     */
    private function parse_search_response($response): array {
        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['success']) || empty($data['data'])) {
            return [];
        }

        // Extract woocommerce_id from response
        return array_map(function($item) {
            return isset($item['woocommerce_id']) ? (int)$item['woocommerce_id'] : 0;
        }, $data['data']);
    }

    /**
     * Parse recommendations response
     */
    private function parse_recommendations_response($response): array {
        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['success']) || empty($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Test connection to API
     */
    public function test_connection(): array {
        $response = $this->request('GET', '/store/health');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200 && !empty($data['success'])) {
            return [
                'success' => true,
                'message' => 'Connection successful!',
                'data' => $data['data']
            ];
        }

        return [
            'success' => false,
            'message' => $data['error'] ?? 'API connection failed'
        ];
    }

    /**
     * Get usage statistics for display
     */
    public function get_usage_stats(): array {
        $status = $this->get_status();

        if (!$status) {
            return [];
        }

        return [
            'plan' => $status['subscription']['plan'] ?? 'unknown',
            'status' => $status['subscription']['status'] ?? 'unknown',
            'products_synced' => $status['total_products'] ?? 0,
            'searches_month' => $status['usage']['searches_this_month'] ?? 0,
            'api_calls_today' => $status['usage']['api_calls_today'] ?? 0,
            'limits' => [
                'products_limit' => $status['limits']['products'] ?? -1,
                'searches_limit' => $status['limits']['searches'] ?? -1,
                'api_calls_limit' => $status['limits']['api_calls'] ?? -1,
            ]
        ];
    }
}
