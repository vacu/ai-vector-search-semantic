<?php
/**
 * Handles all Supabase API communication
 */
class AIVectorSearch_Supabase_Client {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function request(string $method, string $path, $body = null, array $query = [], ?string $cache_key = null, int $cache_ttl = 30) {
        if ($cache_key) {
            $hit = get_transient($cache_key);
            if (false !== $hit) {
                return $hit;
            }
        }

        $url = $this->build_url($path, $query);
        $args = $this->build_request_args($method, $body);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('Request failed', $response->get_error_message());
            return [];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->log_error("HTTP {$response_code}", wp_remote_retrieve_body($response));
            return [];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        if ($cache_key) {
            set_transient($cache_key, $result, $cache_ttl);
        }

        return $result;
    }

    private function build_url(string $path, array $query = []): string {
        $base = rtrim(get_option('aivesese_url'), '/') . '/';
        $url = $base . ltrim($path, '/');

        if ($query) {
            $url = add_query_arg($query, $url);
        }

        return $url;
    }

    private function build_request_args(string $method, $body = null): array {
        $args = [
            'method' => $method,
            'headers' => [
                'apikey' => get_option('aivesese_key'),
                'Authorization' => 'Bearer ' . get_option('aivesese_key'),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
        ];

        if ($body) {
            $args['body'] = wp_json_encode($body);
        }

        return $args;
    }

    private function log_error(string $context, string $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AI Supabase API error ({$context}): {$message}");
        }
    }

    public function get_store_health(): array {
        $store_id = get_option('aivesese_store');
        if (!$store_id) {
            return [];
        }

        return $this->request('POST', '/rest/v1/rpc/store_health_check', [
            'check_store_id' => $store_id
        ]);
    }

    public function get_synced_count(): int {
        $store_id = get_option('aivesese_store');
        if (!$store_id) {
            return 0;
        }

        $result = $this->request('GET', '/rest/v1/products', null, [
            'select' => 'id',
            'store_id' => 'eq.' . $store_id,
        ]);

        return is_array($result) ? count($result) : 0;
    }

    public function sync_products_batch(array $products): bool {
        if (empty($products)) {
            return false;
        }

        $result = $this->request('POST', '/rest/v1/products', $products, [
            'on_conflict' => 'store_id,woocommerce_id'
        ]);

        return !empty($result) || !is_wp_error($result);
    }

    public function get_products_without_embeddings(int $limit = 25): array {
        $store_id = get_option('aivesese_store');
        if (!$store_id) {
            return [];
        }

        return $this->request('GET', '/rest/v1/products', null, [
            'select' => 'id,woocommerce_id',
            'store_id' => 'eq.' . $store_id,
            'embedding' => 'is.null',
            'limit' => $limit,
        ]);
    }

    public function update_product_embedding(string $product_id, array $embedding): bool {
        $result = $this->request(
            'PATCH',
            '/rest/v1/products',
            ['embedding' => $embedding],
            ['id' => 'eq.' . $product_id]
        );

        return !is_wp_error($result);
    }

    public function search_products_fts(string $term, int $limit = 20): array {
        $store = get_option('aivesese_store');
        if (!$store || mb_strlen($term) < 3) {
            return [];
        }

        $params = [
            'search_store_id' => $store,
            'search_term' => $term,
            'search_limit' => $limit
        ];

        $cache_key = 'fts_' . $store . '_' . $limit . '_' . md5($term);
        $rows = $this->request('POST', '/rest/v1/rpc/fts_search', $params, [], $cache_key, 20);

        error_log(print_r($rows, true));

        return wp_list_pluck((array) $rows, 'woocommerce_id');
    }

    public function search_products_sku(string $term, int $limit = 20): array {
        $store = get_option('aivesese_store');
        if (!$store || mb_strlen($term) < 2) {
            return [];
        }

        $params = [
            'search_store_id' => $store,
            'search_term' => $term,
            'search_limit' => $limit
        ];

        $cache_key = 'sku_' . $store . '_' . $limit . '_' . md5($term);
        $rows = $this->request('POST', '/rest/v1/rpc/sku_search', $params, [], $cache_key, 20);

        return wp_list_pluck((array) $rows, 'woocommerce_id');
    }

    public function search_products_semantic(string $term, array $embedding, int $limit = 20): array {
        $store = get_option('aivesese_store');
        if (!$store || empty($embedding)) {
            return [];
        }

        $rows = $this->request('POST', '/rest/v1/rpc/semantic_search', [
            'store_id' => $store,
            'query_embedding' => $embedding,
            'match_threshold' => 0.5,
            'p_k' => $limit,
        ], [], 'sem_' . md5($term), 20);

        return wp_list_pluck($rows, 'woocommerce_id');
    }

    public function get_recommendations(array $cart_ids, int $limit = 4): array {
        $store = get_option('aivesese_store');
        if (!$store || empty($cart_ids)) {
            return [];
        }

        return $this->request('POST', '/rest/v1/rpc/get_recommendations', [
            'store_id' => $store,
            'cart' => $cart_ids,
            'p_k' => $limit,
        ], [], 'recs_' . md5(wp_json_encode($cart_ids)), 60);
    }

    public function get_similar_products(int $product_id, int $limit = 4): array {
        return $this->request('POST', '/rest/v1/rpc/similar_products', [
            'prod_wc_id' => $product_id,
            'k' => $limit,
        ], [], 'aivesese_sim_' . $product_id, 300);
    }

    public function encrypt_option($value, $old_value, $option) {
        return (is_string($value) && $value !== '')
            ? wp_json_encode($this->encrypt($value))
            : $value;
    }

    public function decrypt_option($value, $option = null) {
        $arr = json_decode($value, true);
        return is_array($arr) ? $this->decrypt($arr) : $value;
    }

    public function migrate_legacy_options() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $targets = ['aivesese_key', 'aivesese_openai'];
        foreach ($targets as $opt) {
            $val = get_option($opt, null);
            if (is_string($val) && $val !== '') {
                update_option($opt, $val, false);
            }
        }
    }

    public function master_key_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (defined('AIVESESE_MASTER_KEY_B64') && AIVESESE_MASTER_KEY_B64) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed = ['plugins', 'plugins-network', 'settings_page_aivesese'];
        if (!$screen || !in_array($screen->id, $allowed, true)) {
            return;
        }

        try {
            $key = base64_encode(random_bytes(32));
        } catch (Exception $e) {
            $key = '';
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>AI Supabase Search:</strong> No master key defined for secret encryption.<br>';
        echo 'Add the following line to your <code>wp-config.php</code> above <code>/* That\'s all, stop editing! */</code>:';
        echo '<pre>define(\'AIVESESE_MASTER_KEY_B64\', \'' . esc_html($key) . '\');</pre>';
        echo '</p></div>';
    }
}
