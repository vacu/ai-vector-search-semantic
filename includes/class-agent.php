<?php

/**
 * Managed API-backed storefront assistant for products and order status.
 */
class AIVectorSearch_Agent
{
    private static $instance = null;
    private AIVectorSearch_API_Client $api_client;
    private AIVectorSearch_Agent_Analytics $analytics;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->api_client = AIVectorSearch_API_Client::instance();
        $this->analytics = AIVectorSearch_Agent_Analytics::instance();
        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_launcher']);
        add_shortcode('aivesese_agent', [$this, 'render_shortcode']);

        add_action('wp_ajax_aivesese_agent_chat', [$this, 'handle_chat']);
        add_action('wp_ajax_nopriv_aivesese_agent_chat', [$this, 'handle_chat']);
        add_action('wp_ajax_aivesese_agent_track', [$this, 'handle_track']);
        add_action('wp_ajax_nopriv_aivesese_agent_track', [$this, 'handle_track']);
    }

    public function is_enabled(): bool
    {
        if (get_option('aivesese_connection_mode', 'lite') !== 'api') {
            return false;
        }

        if (get_option('aivesese_api_activated') !== '1') {
            return false;
        }

        if (get_option('aivesese_enable_agent', '0') !== '1') {
            return false;
        }

        $status = $this->get_agent_status();
        return !empty($status['enabled']);
    }

    public function get_agent_status(bool $force_refresh = false): array
    {
        $status = $this->api_client->get_agent_status($force_refresh);
        if (!is_array($status)) {
            return [
                'enabled' => false,
                'models' => [],
                'reason' => 'Agent unavailable',
            ];
        }

        return $status;
    }

    public function enqueue_assets(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        wp_enqueue_style(
            'aivesese-agent-assistant',
            AIVESESE_PLUGIN_URL . 'assets/css/agent-assistant.css',
            [],
            AIVESESE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'aivesese-agent-assistant',
            AIVESESE_PLUGIN_URL . 'assets/js/agent-assistant.js',
            [],
            AIVESESE_PLUGIN_VERSION,
            true
        );

        $status = $this->get_agent_status();
        $default_disclaimer = 'AI-generated responses use your synced catalog and verified customer order data. Review product details and checkout information before purchasing.';
        wp_localize_script('aivesese-agent-assistant', 'aivesese_agent', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aivesese_agent_nonce'),
            'enabled' => '1',
            'model' => get_option('aivesese_agent_model', ''),
            'disclaimer' => (string) get_option('aivesese_agent_disclaimer', $default_disclaimer),
            'models' => $status['models'] ?? [],
            'strings' => [
                'title' => __('AI Agent', 'ai-vector-search-semantic'),
                'intro' => __('I am an AI Agent for this store. I can only help with product recommendations from the synced catalog and order information for verified customers.', 'ai-vector-search-semantic'),
                'placeholder' => __('Ask about products or your order status', 'ai-vector-search-semantic'),
                'send' => __('Send', 'ai-vector-search-semantic'),
                'thinking' => __('Checking the catalog…', 'ai-vector-search-semantic'),
                'error' => __('The assistant is unavailable right now.', 'ai-vector-search-semantic'),
            ],
        ]);
    }

    public function render_launcher(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        echo $this->get_markup();
    }

    public function render_shortcode(): string
    {
        if (!$this->is_enabled()) {
            return '';
        }

        return $this->get_markup(' is-shortcode');
    }

    private function get_markup(string $extra_class = ''): string
    {
        ob_start();
        ?>
        <div class="aivesese-agent<?php echo esc_attr($extra_class); ?>" data-aivesese-agent>
            <button type="button" class="aivesese-agent__launcher" data-agent-open>
                <?php esc_html_e('Ask AI Agent', 'ai-vector-search-semantic'); ?>
            </button>
            <div class="aivesese-agent__panel" hidden>
                <div class="aivesese-agent__header">
                    <strong><?php esc_html_e('AI Agent', 'ai-vector-search-semantic'); ?></strong>
                    <button type="button" class="aivesese-agent__close" data-agent-close aria-label="<?php esc_attr_e('Close', 'ai-vector-search-semantic'); ?>">×</button>
                </div>
                <div class="aivesese-agent__messages" data-agent-messages></div>
                <form class="aivesese-agent__composer" data-agent-form>
                    <textarea rows="2" data-agent-input placeholder="<?php esc_attr_e('Ask about products or your order status', 'ai-vector-search-semantic'); ?>"></textarea>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Send', 'ai-vector-search-semantic'); ?></button>
                </form>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function handle_chat(): void
    {
        check_ajax_referer('aivesese_agent_nonce', 'nonce');

        if (!$this->is_enabled()) {
            wp_send_json_error(['message' => 'Agent is disabled'], 403);
        }

        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));

        if ($message === '') {
            wp_send_json_error(['message' => 'Message is required'], 422);
        }

        if ($session_id === '') {
            $session_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(36, false);
        }

        $payload = [
            'message' => $message,
            'session_id' => $session_id,
            'model' => sanitize_text_field((string) get_option('aivesese_agent_model', '')),
            'customer_context' => $this->build_customer_context(),
        ];

        $response = $this->api_client->send_agent_message($payload);
        if (empty($response['success'])) {
            $this->analytics->track_event([
                'session_id' => $session_id,
                'event_type' => 'turn',
                'success' => 0,
                'user_id' => get_current_user_id(),
                'metadata' => ['message' => $message, 'error' => $response['error'] ?? 'Unknown error'],
            ]);

            wp_send_json_error(['message' => $response['error'] ?? 'Agent request failed'], 500);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $products = $this->enrich_products(is_array($data['products'] ?? null) ? $data['products'] : []);
        $data['products'] = $products;

        $intent = sanitize_key((string) ($data['intent'] ?? 'unknown'));
        $model_name = sanitize_text_field((string) ($data['model'] ?? ($payload['model'] ?? '')));
        $this->analytics->track_event([
            'session_id' => $session_id,
            'event_type' => 'turn',
            'intent' => $intent,
            'model_name' => $model_name,
            'success' => 1,
            'user_id' => get_current_user_id(),
            'metadata' => ['message' => $message],
        ]);

        foreach ($products as $product) {
            $this->analytics->track_event([
                'session_id' => $session_id,
                'event_type' => 'product_impression',
                'intent' => $intent,
                'model_name' => $model_name,
                'product_id' => (int) ($product['woocommerce_id'] ?? 0),
                'success' => 1,
                'user_id' => get_current_user_id(),
            ]);
        }

        if ($intent === 'order_status') {
            $order = is_array($data['order'] ?? null) ? $data['order'] : [];
            $verified = !empty($order['verified']);
            $this->analytics->track_event([
                'session_id' => $session_id,
                'event_type' => 'order_request',
                'intent' => $intent,
                'model_name' => $model_name,
                'order_id' => isset($order['id']) ? (int) $order['id'] : null,
                'success' => $verified ? 1 : 0,
                'user_id' => get_current_user_id(),
            ]);

            $this->analytics->track_event([
                'session_id' => $session_id,
                'event_type' => $verified ? 'order_verified' : 'order_blocked',
                'intent' => $intent,
                'model_name' => $model_name,
                'order_id' => isset($order['id']) ? (int) $order['id'] : null,
                'success' => $verified ? 1 : 0,
                'user_id' => get_current_user_id(),
            ]);
        }

        wp_send_json_success($data);
    }

    public function handle_track(): void
    {
        check_ajax_referer('aivesese_agent_nonce', 'nonce');

        if (!$this->is_enabled()) {
            wp_send_json_error(['message' => 'Agent is disabled'], 403);
        }

        $event_type = sanitize_key((string) ($_POST['event_type'] ?? ''));
        $allowed = ['session_start', 'add_to_cart_click'];
        if (!in_array($event_type, $allowed, true)) {
            wp_send_json_error(['message' => 'Unsupported event'], 422);
        }

        $this->analytics->track_event([
            'session_id' => sanitize_text_field((string) ($_POST['session_id'] ?? '')),
            'event_type' => $event_type,
            'intent' => sanitize_key((string) ($_POST['intent'] ?? '')),
            'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : null,
            'success' => 1,
            'user_id' => get_current_user_id(),
        ]);

        wp_send_json_success(['tracked' => true]);
    }

    private function build_customer_context(): array
    {
        $context = [
            'is_logged_in' => is_user_logged_in(),
            'verified_customer' => false,
        ];

        if (!is_user_logged_in() || !function_exists('wc_get_orders')) {
            return $context;
        }

        $user = wp_get_current_user();
        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);

        $order_payloads = [];
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $order_payloads[] = [
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'date_created' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
                'currency' => $order->get_currency(),
                'total' => (string) $order->get_total(),
                'item_count' => $order->get_item_count(),
            ];
        }

        $payload = [
            'user_id' => get_current_user_id(),
            'email' => $user->user_email,
            'orders' => $order_payloads,
            'verified_at' => gmdate('c'),
            'site_url' => home_url('/'),
        ];

        $secret = (string) get_option('aivesese_license_key', '');
        $signature = hash_hmac('sha256', wp_json_encode($payload), $secret);

        $context['verified_customer'] = true;
        $context['payload'] = $payload;
        $context['signature'] = $signature;

        return $context;
    }

    private function enrich_products(array $products): array
    {
        $enriched = [];

        foreach ($products as $product_row) {
            if (!is_array($product_row)) {
                continue;
            }

            $product_id = isset($product_row['woocommerce_id']) ? (int) $product_row['woocommerce_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }

            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                continue;
            }

            $product_row['woocommerce_id'] = $product_id;
            $product_row['product_url'] = get_permalink($product_id);
            $product_row['image_url'] = !empty($product_row['image_url']) ? $product_row['image_url'] : get_the_post_thumbnail_url($product_id, 'woocommerce_thumbnail');
            $product_row['price_html'] = $product->get_price_html();
            $product_row['can_add_to_cart'] = $product->is_purchasable() && $product->is_in_stock();
            $product_row['add_to_cart_url'] = $product->add_to_cart_url();
            $product_row['add_to_cart_text'] = $product->add_to_cart_text();
            $product_row['product_type'] = $product->get_type();
            $enriched[] = $product_row;
        }

        return $enriched;
    }
}
