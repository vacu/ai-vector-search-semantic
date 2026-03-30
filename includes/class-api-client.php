<?php

/**
 * API Client for ZZZ Solutions Managed Service
 */
class AIVectorSearch_API_Client
{

    private static $instance = null;
    private $api_base_url = 'https://api.zzzsolutions.ro/api/v1';

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Check if API mode is active
     */
    public function is_api_mode(): bool
    {
        return get_option('aivesese_connection_mode') === 'api' &&
            !empty(get_option('aivesese_license_key'));
    }

    /**
     * Activate license key
     */
    public function activate_license(string $license_key): array
    {
        $response = $this->request('POST', '/activate', [
            'license_key' => $license_key,
            'site_url' => home_url(),
            'site_name' => get_bloginfo('name')
        ], $license_key);

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
    public function get_status(): ?array
    {
        if (!$this->is_api_mode()) {
            return null;
        }

        $response = $this->request('GET', '/store/health');

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }

        return $this->normalize_status_payload($data);
    }

    public function get_agent_status(bool $force_refresh = false): array
    {
        $cache_key = 'aivesese_api_agent_status';
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $status = $this->get_status();
        $features = is_array($status['features'] ?? null) ? $status['features'] : [];
        $models = is_array($status['agent_models'] ?? null) ? $status['agent_models'] : [];

        $agent_status = [
            'enabled' => !empty($features['agent_assistant']),
            'features' => $features,
            'models' => $models,
            'reason' => !empty($features['agent_assistant']) ? '' : 'Your current managed API plan does not include the assistant.',
        ];

        set_transient($cache_key, $agent_status, 5 * MINUTE_IN_SECONDS);
        return $agent_status;
    }

    public function get_agent_models(): array
    {
        $response = $this->request('GET', '/agent/models');
        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success']) || !is_array($data['data'] ?? null)) {
            return [];
        }

        return $data['data'];
    }

    public function send_agent_message(array $payload): array
    {
        $response = $this->request('POST', '/agent/chat', $payload);
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : [
            'success' => false,
            'error' => 'Invalid agent response',
        ];
    }

    /**
     * Full-text search via API
     */
    public function search_fts(string $term, int $limit = 0): array
    {
        // Use configured limit if none provided
        if ($limit === 0) {
            $limit = aivesese_get_search_results_limit();
        }
        $response = $this->request('POST', '/search/fts', [
            'term' => $term,
            'limit' => $limit
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * Fuzzy search via API
     */
    public function search_fuzzy(string $term, int $limit = 0): array
    {
        // Use configured limit if none provided
        if ($limit === 0) {
            $limit = aivesese_get_search_results_limit();
        }
        $response = $this->request('POST', '/search/fuzzy', [
            'term' => $term,
            'limit' => $limit
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * Semantic search via API
     */
    public function search_semantic(string $term, int $limit = 0, float $threshold = 0.5): array
    {
        // Use configured limit if none provided
        if ($limit === 0) {
            $limit = aivesese_get_search_results_limit();
        }
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
    public function search_sku(string $term, int $limit = 0): array
    {
        // Use configured limit if none provided
        if ($limit === 0) {
            $limit = aivesese_get_search_results_limit();
        }
        $response = $this->request('POST', '/search/sku', [
            'term' => $term,
            'limit' => $limit
        ]);

        return $this->parse_search_response($response);
    }

    /**
     * Sync products to API
     */
    public function sync_products(array $products): array
    {
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
    public function sync_products_batch(array $products): array
    {
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
     * Update a single field for a batch of already-synced products
     */
    public function update_products_field(array $data, string $field): array
    {
        $response = $this->request('POST', '/products/batch/field', [
            'field'    => $field,
            'products' => $data,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        return $result ?? ['success' => false, 'message' => 'Unknown error'];
    }

    /**
     * Generate embeddings for products
     */
    public function generate_embeddings(int $batch_size = 25, int $max_batches = 10): array
    {
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
     * Push locally-aggregated daily metrics to the API so Supabase RPCs
     * have a demand signal. Sends in chunks of 500 rows.
     */
    public function push_merchandising_metrics(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $response = $this->request('POST', '/merchandising/metrics', ['metrics' => $chunk]);
            if (is_wp_error($response)) {
                return false;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['success'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Push locally-mined bundle candidates to the API so the
     * bundle_recommendations Supabase RPC has data to return.
     */
    public function push_bundle_candidates(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $response = $this->request('POST', '/merchandising/bundles', ['bundles' => $chunk]);
            if (is_wp_error($response)) {
                return false;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['success'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cart recommendations
     */
    public function get_cart_recommendations(array $cart_ids, int $limit = 4): array
    {
        $response = $this->request('POST', '/recommendations/cart', [
            'cart_ids' => $cart_ids,
            'limit' => $limit
        ]);

        return $this->parse_recommendations_response($response);
    }

    /**
     * Get similar products
     */
    public function get_similar_products(int $product_id, int $limit = 4): array
    {
        $response = $this->request('POST', '/recommendations/similar', [
            'product_id' => $product_id,
            'limit' => $limit
        ]);

        return $this->parse_recommendations_response($response);
    }

    /**
     * Make API request with authentication
     */
    private function request(string $method, string $endpoint, array $body = null, ?string $license_override = null): array|WP_Error
    {
        $store_id = get_option('aivesese_store');
        $license_key = $license_override ?: get_option('aivesese_license_key');

        if (empty($license_key) && $endpoint !== '/activate') {
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
    private function parse_search_response($response): array
    {
        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['success']) || empty($data['data'])) {
            return [];
        }

        // Extract woocommerce_id from response
        return array_map(function ($item) {
            return isset($item['woocommerce_id']) ? (int)$item['woocommerce_id'] : 0;
        }, $data['data']);
    }

    /**
     * Parse recommendations response
     */
    private function parse_recommendations_response($response): array
    {
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
    public function test_connection(): array
    {
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
                'data' => $this->normalize_status_payload($data)
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
    public function get_usage_stats(): array
    {
        $status = $this->get_status();

        if (!$status) {
            return [];
        }

        return [
            'plan' => $status['subscription']['plan'] ?? 'unknown',
            'status' => $status['subscription']['status'] ?? 'unknown',
            'products_synced' => $status['products_synced']
                ?? $status['products_count']
                ?? $status['total_products']
                ?? ($status['usage_tracking']['products_synced'] ?? 0)
                ?? ($status['usage_tracking']['productsSynced'] ?? 0)
                ?? ($status['usageTracking']['products_synced'] ?? 0)
                ?? ($status['usageTracking']['productsSynced'] ?? 0)
                ?? ($status['usage']['products_synced'] ?? 0),
            'searches_month' => $status['searches_this_month']
                ?? ($status['usage']['searches_this_month'] ?? null)
                ?? ($status['usage_tracking']['searches_this_month'] ?? null)
                ?? ($status['usage_tracking']['searchesThisMonth'] ?? null)
                ?? ($status['usageTracking']['searches_this_month'] ?? null)
                ?? ($status['usageTracking']['searchesThisMonth'] ?? 0),
            'api_calls_today' => $status['api_calls_today']
                ?? ($status['usage']['api_calls_today'] ?? null)
                ?? ($status['usage_tracking']['api_calls_today'] ?? null)
                ?? ($status['usage_tracking']['apiCallsToday'] ?? null)
                ?? ($status['usageTracking']['api_calls_today'] ?? null)
                ?? ($status['usageTracking']['apiCallsToday'] ?? 0),
            'limits' => [
                'products_limit' => $status['limits']['products'] ?? -1,
                'searches_limit' => $status['limits']['searches'] ?? -1,
                'api_calls_limit' => $status['limits']['api_calls'] ?? -1,
            ]
        ];
    }

    private function normalize_status_payload(array $data): array
    {
        $status = is_array($data['data'] ?? null) ? $data['data'] : $data;

        if (!isset($status['usage_tracking']) && isset($data['usage_tracking'])) {
            $status['usage_tracking'] = $data['usage_tracking'];
        }

        if (!isset($status['usageTracking']) && isset($data['usageTracking'])) {
            $status['usageTracking'] = $data['usageTracking'];
        }

        if (!isset($status['products_synced']) && isset($data['products_synced'])) {
            $status['products_synced'] = $data['products_synced'];
        }

        if (!isset($status['searches_this_month']) && isset($data['searches_this_month'])) {
            $status['searches_this_month'] = $data['searches_this_month'];
        }

        if (!isset($status['api_calls_today']) && isset($data['api_calls_today'])) {
            $status['api_calls_today'] = $data['api_calls_today'];
        }

        if (!isset($status['features']) && isset($data['features']) && is_array($data['features'])) {
            $status['features'] = $data['features'];
        }

        if (!isset($status['agent_models']) && isset($data['agent_models']) && is_array($data['agent_models'])) {
            $status['agent_models'] = $data['agent_models'];
        }

        return $status;
    }
}
