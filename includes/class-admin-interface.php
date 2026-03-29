<?php

/**
 * Handles all admin interface functionality
 */
class AIVectorSearch_Admin_Interface
{

    private static $instance = null;
    private $supabase_client;
    private $api_client;
    private $connection_manager;
    private $product_sync;
    private $lite_engine;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
        $this->api_client = AIVectorSearch_API_Client::instance();
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance();
        $this->product_sync = AIVectorSearch_Product_Sync::instance();
        $this->lite_engine = AIVectorSearch_Lite_Engine::instance();
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'show_services_banner']);
        add_action('admin_notices', [$this, 'show_sql_update_notice']);
        add_action('admin_init', [$this, 'handle_sql_update_dismiss']);
        add_action('wp_ajax_aivesese_toggle_help', [$this, 'handle_help_toggle']);
        add_action('wp_ajax_aivesese_activate_license', [$this, 'handle_license_activation']);
        add_action('wp_ajax_aivesese_postgres_install_schema', [$this, 'handle_postgres_install_schema']);
        add_action('wp_ajax_aivesese_postgres_check_status', [$this, 'handle_postgres_check_status']);
        add_action('wp_ajax_aivesese_update_sold_counts', [$this, 'handle_update_sold_counts']);
        add_action('wp_ajax_aivesese_sync_products_batch', [$this, 'handle_sync_products_batch_ajax']);
        add_action('wp_ajax_aivesese_sync_field_batch', [$this, 'handle_sync_field_batch_ajax']);

        $this->init_admin_body_classes();
    }

    /**
     * Enhanced register_settings with PostgreSQL connection string
     */
    public function register_settings()
    {
        $settings = [
            // Connection mode
            'connection_mode' => 'Connection Type',

            // API mode settings
            'license_key' => 'License Key (for API mode)',

            // Self-hosted mode settings (existing)
            'url' => 'Supabase URL (https://xyz.supabase.co)',
            'key' => 'Supabase service / anon key',
            'store' => 'Store ID (UUID)',
            'openai' => 'OpenAI API key (only if semantic search is enabled)',

            // NEW: PostgreSQL connection string for WP-CLI
            'postgres_connection_string' => 'PostgreSQL Connection String (for WP-CLI schema installation)',

            // Feature toggles
            'enable_search' => 'Enable AI search',
            'semantic_toggle' => 'Enable semantic (vector) search',
            'auto_sync' => 'Auto-sync products on save',
            'enable_pdp_similar' => 'PDP "Similar products"',
            'enable_cart_below' => 'Below-cart recommendations',
            'enable_woodmart_integration' => 'Woodmart live search integration',
            'enable_search_autocomplete' => 'Search autocomplete',
            'enable_agent' => 'Enable Agent Assistant',
            'agent_model' => 'Agent Model',
            'agent_disclaimer' => 'Agent Disclaimer',
            'search_results_limit' => 'Search Results Limit',
            'lite_index_limit' => 'Lite Mode Index Limit',
            'lite_stopwords' => 'Lite Mode Stopwords',
            'lite_synonyms' => 'Lite Mode Synonyms',
        ];

        foreach ($settings as $id => $label) {
            $this->register_setting($id);
        }

        add_settings_section('aivesese_section', 'AI Search Configuration', '__return_false', 'aivesese');
        $this->add_settings_fields();
    }

    private function register_setting(string $id)
    {
        $sanitizers = [
            'connection_mode' => 'sanitize_text_field',
            'license_key' => 'aivesese_passthru',
            'url' => 'esc_url_raw',
            'key' => 'aivesese_passthru',
            'store' => 'sanitize_text_field',
            'openai' => 'aivesese_passthru',
            'postgres_connection_string' => 'aivesese_passthru', // Will be encrypted
            'search_results_limit' => [$this, 'sanitize_search_results_limit'],
            'agent_model' => 'sanitize_text_field',
            'agent_disclaimer' => [$this, 'sanitize_agent_disclaimer'],
            'lite_index_limit' => 'absint',
            'lite_stopwords' => [$this, 'sanitize_lite_stopwords'],
            'lite_synonyms' => [$this, 'sanitize_lite_synonyms'],
        ];

        $config = [
            'type' => 'string',
            'sanitize_callback' => $sanitizers[$id] ?? 'sanitize_text_field',
            'default' => '',
        ];

        // Special handling for connection mode
        if ($id === 'connection_mode') {
            $config['default'] = 'lite';
        }

        if ($id === 'search_results_limit') {
            $config['type'] = 'integer';
            $config['default'] = 20;
        }

        if ($id === 'lite_index_limit') {
            $config['type'] = 'integer';
            $config['default'] = 500;
        }

        // Special handling for checkboxes
        if (in_array($id, ['enable_search', 'semantic_toggle', 'auto_sync', 'enable_pdp_similar', 'enable_cart_below', 'enable_woodmart_integration', 'enable_search_autocomplete', 'enable_agent'], true)) {
            $config['sanitize_callback'] = function ($v) {
                return $v === '1' ? '1' : '0';
            };
            $config['default'] = in_array($id, ['enable_woodmart_integration', 'enable_search_autocomplete', 'enable_agent'], true) ? '0' : '1';
        }

        register_setting('aivesese_settings', "aivesese_{$id}", $config);
    }

    public function sanitize_lite_stopwords($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_textarea_field(wp_unslash($value));
    }

    public function sanitize_lite_synonyms($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_textarea_field(wp_unslash($value));
    }

    public function sanitize_search_results_limit($value): int
    {
        $limit = absint($value);

        if ($limit < 1) {
            return 20;
        }

        if ($limit > 100) {
            return 100;
        }

        return $limit;
    }

    public function sanitize_agent_disclaimer($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_textarea_field(wp_unslash($value));
    }

    private function add_settings_fields()
    {
        // Connection mode selector
        add_settings_field(
            'aivesese_connection_mode',
            'Connection Type',
            [$this, 'render_connection_mode_field'],
            'aivesese',
            'aivesese_section'
        );

        // API mode fields
        add_settings_field(
            'aivesese_license_key',
            'License Key',
            [$this, 'render_license_key_field'],
            'aivesese',
            'aivesese_section'
        );

        // Self-hosted fields
        $self_hosted_fields = [
            'url' => 'Supabase URL',
            'key' => 'Supabase Service Key',
            'store' => 'Store ID (UUID)',
            'openai' => 'OpenAI API Key'
        ];

        foreach ($self_hosted_fields as $id => $label) {
            add_settings_field(
                "aivesese_{$id}",
                $label,
                [$this, 'render_text_field'],
                'aivesese',
                'aivesese_section',
                ['field_id' => $id, 'conditional' => 'self_hosted']
            );
        }

        // PostgreSQL connection string (NEW)
        add_settings_field(
            'aivesese_postgres_connection_string',
            'PostgreSQL Connection String',
            [$this, 'render_postgres_connection_field'],
            'aivesese',
            'aivesese_section'
        );

        // Search limit field
        add_settings_field(
            'aivesese_search_results_limit',
            'Search Results Limit',
            [$this, 'render_search_limit_field'],
            'aivesese',
            'aivesese_section'
        );

        add_settings_field(
            'aivesese_enable_agent',
            'Enable Agent Assistant',
            [$this, 'render_agent_enable_field'],
            'aivesese',
            'aivesese_section'
        );

        add_settings_field(
            'aivesese_agent_model',
            'Agent Model',
            [$this, 'render_agent_model_field'],
            'aivesese',
            'aivesese_section'
        );

        add_settings_field(
            'aivesese_agent_disclaimer',
            'Agent Disclaimer',
            [$this, 'render_agent_disclaimer_field'],
            'aivesese',
            'aivesese_section'
        );

        // Feature toggles
        $checkbox_fields = [
            'enable_search' => 'Enable AI search - Use AI-powered results for store search',
            'semantic_toggle' => 'Enable semantic (vector) search - Better relevance',
            'auto_sync' => 'Auto-sync products - Automatically sync products when saved/updated',
            'enable_pdp_similar' => 'PDP "Similar products" - Show similar products on product pages',
            'enable_cart_below' => 'Below-cart recommendations - Show recommendations under cart',
            'enable_woodmart_integration' => 'Live search integration - Replace theme AJAX search with AI-powered product results (supports Woodmart &amp; Storefront)',
            'enable_search_autocomplete' => 'Search autocomplete - Enhance the dropdown with term suggestions and category links in addition to products',
        ];

        foreach ($checkbox_fields as $id => $label) {
            add_settings_field(
                "aivesese_{$id}",
                $label,
                [$this, 'render_checkbox_field'],
                'aivesese',
                'aivesese_section',
                ['field_id' => $id, 'label' => $label]
            );
        }
    }

    public function render_connection_mode_field()
    {
        // Use templated selector instead of inline HTML
        $current_mode = get_option('aivesese_connection_mode', 'lite');
        $api_available = true;
        $this->load_template('connection-mode-selector-with-lite', compact('current_mode', 'api_available'));
        return;
    }

    public function render_license_key_field()
    {
        // Use templated license activation instead of inline HTML
        $license_key = get_option('aivesese_license_key');
        $is_activated = !empty($license_key) && get_option('aivesese_api_activated') === '1';
        $activation_data = [];
        if ($is_activated && method_exists($this->api_client, 'get_status')) {
            $status = $this->api_client->get_status();
            if (is_array($status)) {
                $activation_data = $status;
            }
        }
        $this->load_template('license-activation', compact('license_key', 'is_activated', 'activation_data'));
        return;
    }

    public function render_text_field($args)
    {
        $field_id = $args['field_id'];
        $value = get_option("aivesese_{$field_id}");

        printf(
            '<input type="text" id="aivesese_%s" name="aivesese_%s" value="%s" class="regular-text" />',
            esc_attr($field_id),
            esc_attr($field_id),
            esc_attr($value)
        );

        if ($field_id === 'openai') {
            echo '<p class="description">Only required if semantic search is enabled</p>';
        }
    }

    public function render_search_limit_field()
    {
        $value = aivesese_get_search_results_limit();

        printf(
            '<input type="number" id="aivesese_search_results_limit" name="aivesese_search_results_limit" value="%d" min="1" max="100" class="small-text" />',
            esc_attr($value)
        );

        echo '<p class="description">Maximum number of products to return in search results (1-100). Default: 20</p>';
    }

    public function render_checkbox_field($args)
    {
        $field_id = $args['field_id'];
        $label = $args['label'];
        $value = get_option("aivesese_{$field_id}");

        printf(
            '<label><input type="checkbox" name="aivesese_%s" value="1"%s> %s</label>',
            esc_attr($field_id),
            checked($value, '1', false),
            esc_html($label)
        );
    }

    public function render_agent_enable_field()
    {
        $status = class_exists('AIVectorSearch_Agent') ? AIVectorSearch_Agent::instance()->get_agent_status() : [];
        $is_api_mode = get_option('aivesese_connection_mode', 'lite') === 'api';
        $available = $is_api_mode && !empty($status['enabled']);
        $value = get_option('aivesese_enable_agent', '0');

        echo '<input type="hidden" name="aivesese_enable_agent" value="0" />';
        printf(
            '<label><input type="checkbox" name="aivesese_enable_agent" value="1"%s%s> %s</label>',
            checked($value, '1', false),
            disabled($available, false, false),
            esc_html__('Enable the managed storefront assistant for products and order status.', 'ai-vector-search-semantic')
        );

        if (!$is_api_mode) {
            echo '<p class="description">The agent is available only in managed API mode.</p>';
            return;
        }

        if (!$available) {
            $reason = !empty($status['reason']) ? $status['reason'] : 'Your current API plan does not include the assistant.';
            echo '<p class="description">' . esc_html($reason) . '</p>';
            return;
        }

        echo '<p class="description">The agent uses separate analytics and only returns products that exist in the synced catalog.</p>';
    }

    public function render_agent_model_field()
    {
        $status = class_exists('AIVectorSearch_Agent') ? AIVectorSearch_Agent::instance()->get_agent_status() : [];
        $models = is_array($status['models'] ?? null) ? $status['models'] : [];
        $current = (string) get_option('aivesese_agent_model', 'gpt-4.1-mini');
        $is_api_mode = get_option('aivesese_connection_mode', 'lite') === 'api';
        $available = $is_api_mode && !empty($status['enabled']) && !empty($models);

        if (!$available) {
            echo '<p><em>Agent models are available after API activation on a plan with assistant access.</em></p>';
            return;
        }

        echo '<select id="aivesese_agent_model" name="aivesese_agent_model">';
        foreach ($models as $model) {
            $model_id = sanitize_text_field((string) ($model['id'] ?? ''));
            $label = sanitize_text_field((string) ($model['label'] ?? $model_id));
            if ($model_id === '') {
                continue;
            }

            echo '<option value="' . esc_attr($model_id) . '"' . selected($current, $model_id, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Curated models come from the managed API so pricing and compatibility stay controlled.</p>';
    }

    public function render_agent_disclaimer_field()
    {
        $value = (string) get_option('aivesese_agent_disclaimer', 'AI-generated responses use your synced catalog and verified customer order data. Review product details and checkout information before purchasing.');

        printf(
            '<textarea id="aivesese_agent_disclaimer" name="aivesese_agent_disclaimer" rows="4" class="large-text">%s</textarea>',
            esc_textarea($value)
        );

        echo '<p class="description">Shown at the start of every assistant chat. Use it for compliance, disclosure, or store-specific guidance.</p>';
    }

    /**
     * Handle license activation AJAX
     */
    public function handle_license_activation()
    {
        check_ajax_referer('aivesese_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $license_key = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));

        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key is required']);
            return;
        }

        // Test the license key with the API
        $result = $this->api_client->activate_license($license_key);

        if ($result['success']) {
            // Save license and switch to API mode
            update_option('aivesese_license_key', $license_key);
            update_option('aivesese_connection_mode', 'api');
            update_option('aivesese_api_activated', '1');
            update_option('aivesese_store', $result['store_id']);

            wp_send_json_success([
                'message' => 'License activated successfully!',
                'store_id' => $result['store_id'],
                'plan' => $result['plan']
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Enhanced settings page with custom field rendering
     */
    public function render_settings_page()
    {
        $connection_mode = get_option('aivesese_connection_mode', 'lite');

        echo '<div class="wrap aivesese-admin aivesese-mode-' . esc_attr($connection_mode) . '">';
        echo '<h1>' . esc_html__('AI Vector Search Settings', 'ai-vector-search-semantic') . '</h1>';

        // Show different descriptions based on mode
        if ($connection_mode === 'api') {
            echo '<p>' . esc_html__('You are using our managed API service. No additional setup required!', 'ai-vector-search-semantic') . '</p>';
        } elseif ($connection_mode === 'lite') {
            echo '<p>' . esc_html__('Lite mode runs locally. Configure the search engine options below.', 'ai-vector-search-semantic') . '</p>';
        } else {
            echo '<p>' . esc_html__('Configure your own Supabase project and optionally enable semantic search using OpenAI.', 'ai-vector-search-semantic') . '</p>';
        }

        // Show help section only for self-hosted mode
        if ($connection_mode === 'self_hosted') {
            $this->render_help_section();
            $this->render_sold_count_section();
        }

        echo '<form method="post" action="options.php">';
        settings_fields('aivesese_settings');
        do_settings_sections('aivesese');

        if ($connection_mode === 'lite') {
            echo '<div class="lite-mode-section">';
            $this->load_template('lite-mode-config');
            echo '</div>';
        }

        submit_button();
        echo '</form>';

        echo '</div>';
    }

    /**
     * Updated enqueue_admin_assets method - Now properly organized
     */
    public function enqueue_admin_assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, ['aivesese', 'aivesese-status', 'aivesese-sync', 'aivesese-analytics', 'aivesese-agent-analytics'], true)) {
            return;
        }

        // Enqueue styles first
        $this->enqueue_admin_styles();

        // Then enqueue scripts (which depend on styles being loaded)
        $this->enqueue_admin_scripts();
    }

    /**
     * Enhanced status page with API/self-hosted detection
     */
    public function render_status_page()
    {
        echo '<div class="wrap aivesese-admin">';
        echo '<h1>' . esc_html__('AI Vector Search Status', 'ai-vector-search-semantic') . '</h1>';

        $connection_mode = get_option('aivesese_connection_mode', 'lite');

        if ($connection_mode === 'api') {
            $this->render_api_status();
        } elseif ($connection_mode === 'lite') {
            $this->render_lite_status();
        } else {
            $this->render_self_hosted_status();
        }

        // Add status page footer action hook
        do_action('aivesese_status_page_footer');

        echo '</div>';
    }

    private function render_api_status()
    {
        $license_key = get_option('aivesese_license_key');

        if (empty($license_key)) {
            echo '<div class="notice notice-error"><p>No license key configured. Please go to Settings to activate your license.</p></div>';
            return;
        }

        if (get_option('aivesese_api_activated') !== '1') {
            echo '<div class="notice notice-warning"><p>License key saved but not activated. Please activate it in Settings to connect the API service.</p></div>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=aivesese')) . '">Go to Settings</a></p>';
            return;
        }

        // Get status from API with a helpful error message
        $connection = $this->api_client->test_connection();

        if (empty($connection['success'])) {
            $message = !empty($connection['message']) ? $connection['message'] : 'Unable to connect to API service. Please check your license key.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        $status = is_array($connection['data'] ?? null) ? $connection['data'] : [];
        if (empty($status)) {
            echo '<div class="notice notice-error"><p>API connection succeeded but returned no status data.</p></div>';
            return;
        }

        echo '<div class="notice notice-success"><p>Connected to ZZZ Solutions API Service.</p></div>';

        // Show subscription info
        $plan = $status['plan'] ?? ($status['subscription']['plan'] ?? 'unknown');
        $subscription_status = $status['status'] ?? ($status['subscription']['status'] ?? 'unknown');
        $usage = $status['usage']
            ?? ($status['usage_tracking'] ?? ($status['usageTracking'] ?? []));
        $usage_products = $usage['products_synced'] ?? 0;
        $products_count = $status['products_synced']
            ?? max(
                (int) $usage_products,
                (int) ($status['products_count'] ?? 0),
                (int) ($status['total_products'] ?? 0)
            );
        $searches_this_month = $usage['searches_this_month'] ?? 0;
        $api_calls_today = $usage['api_calls_today'] ?? 0;
        $expires_at = $status['expires_at'] ?? ($status['subscription']['expires_at'] ?? '');
        $limits = $status['limits'] ?? [];
        $products_limit = $limits['products_limit'] ?? ($limits['products'] ?? -1);
        $searches_limit = $limits['searches_limit'] ?? ($limits['searches'] ?? -1);

        echo '<h2>Subscription Status</h2>';
        echo '<table class="widefat striped">';
        echo '<tbody>';
        echo '<tr><td><strong>Plan</strong></td><td>' . esc_html(ucfirst($plan)) . '</td></tr>';
        echo '<tr><td><strong>Status</strong></td><td><span class="status-' . esc_attr($subscription_status) . '">' . esc_html(ucfirst($subscription_status)) . '</span></td></tr>';
        $store_id = get_option('aivesese_store', '');
        $status_store_id = $status['store_id'] ?? '';
        if (empty($store_id) && !empty($status_store_id)) {
            $store_id = $status_store_id;
            update_option('aivesese_store', $store_id);
        }

        if (!empty($store_id)) {
            echo '<tr><td><strong>Store ID</strong></td><td><code>' . esc_html($store_id) . '</code></td></tr>';
        }

        echo '<tr><td><strong>Products Synced</strong></td><td>' . number_format($products_count) . '</td></tr>';
        echo '<tr><td><strong>Searches This Month</strong></td><td>' . number_format($searches_this_month) . '</td></tr>';
        echo '<tr><td><strong>API Calls Today</strong></td><td>' . number_format($api_calls_today) . '</td></tr>';

        if (!empty($expires_at)) {
            echo '<tr><td><strong>Next Payment</strong></td><td>' . esc_html(gmdate('M j, Y', strtotime($expires_at))) . '</td></tr>';
        }

        echo '</tbody></table>';

        // Show usage limits
        if (!empty($limits)) {
            echo '<h2>Usage Limits</h2>';
            echo '<div class="usage-bars">';

            $this->render_usage_bar(
                'Products',
                $products_count,
                $products_limit,
                'products'
            );

            $this->render_usage_bar(
                'Monthly Searches',
                $searches_this_month,
                $searches_limit,
                'searches'
            );

            echo '</div>';
        }
    }

    private function render_usage_bar($label, $current, $limit, $type)
    {
        $percentage = $limit > 0 ? min(($current / $limit) * 100, 100) : 0;
        $bar_class = $percentage > 90 ? 'usage-critical' : ($percentage > 70 ? 'usage-warning' : 'usage-good');

        echo '<div class="usage-bar-container">';
        echo '<div class="usage-bar-header">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<span>' . number_format($current) . ($limit > 0 ? ' / ' . number_format($limit) : '') . '</span>';
        echo '</div>';
        echo '<div class="usage-bar">';
        echo '<div class="usage-bar-fill ' . esc_attr($bar_class) . '" style="width: ' . $percentage . '%"></div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_self_hosted_status()
    {
        if (!$this->is_configured()) {
            $this->render_configuration_error();
            return;
        }

        $health = $this->supabase_client->get_store_health();

        if (empty($health)) {
            $this->render_connection_error();
            return;
        }

        $this->render_health_overview($health[0]);
        $this->render_configuration_summary();
        $this->render_quick_actions();
    }

    private function render_lite_status()
    {
        $stats = $this->lite_engine ? $this->lite_engine->get_index_stats() : ['indexed_products' => 0, 'total_terms' => 0, 'last_built' => 0];
        $limit_option = get_option('aivesese_lite_index_limit', '500');
        $limit_value = is_numeric($limit_option) ? (int) $limit_option : 0;

        if ($limit_value <= 0) {
            $limit_label = esc_html__('All products', 'ai-vector-search-semantic');
        } else {
            $limit_label = sprintf(
                esc_html__('Latest %s products', 'ai-vector-search-semantic'),
                number_format_i18n($limit_value)
            );
        }

        $last_built = !empty($stats['last_built'])
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $stats['last_built'])
            : esc_html__('Not built yet', 'ai-vector-search-semantic');

        echo '<div class="notice notice-info"><p>' . esc_html__('Lite mode is active. Your search index runs locally without Supabase or OpenAI configuration.', 'ai-vector-search-semantic') . '</p></div>';

        echo '<table class="widefat striped aivs-data-table">';
        echo '<tbody>';
        echo '<tr><th>' . esc_html__('Products Indexed', 'ai-vector-search-semantic') . '</th><td>' . number_format_i18n((int) ($stats['indexed_products'] ?? 0)) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Unique Terms', 'ai-vector-search-semantic') . '</th><td>' . number_format_i18n((int) ($stats['total_terms'] ?? 0)) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Index Limit', 'ai-vector-search-semantic') . '</th><td>' . esc_html($limit_label) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Last Rebuild', 'ai-vector-search-semantic') . '</th><td>' . esc_html($last_built) . '</td></tr>';
        echo '</tbody></table>';

        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=aivesese')) . '">' . esc_html__('Manage Lite settings', 'ai-vector-search-semantic') . '</a></p>';
    }


    public function add_admin_pages()
    {
        // Add top-level menu page
        add_menu_page(
            'AI Vector Search',                    // Page title
            'AI Vector Search',                    // Menu title
            'manage_options',                      // Capability
            'aivesese',                           // Menu slug
            [$this, 'render_settings_page'],     // Callback function
            'dashicons-search',                   // Icon
            30                                    // Position
        );

        // Add submenu pages under the main menu
        add_submenu_page(
            'aivesese',                           // Parent slug
            'AI Vector Search Settings',          // Page title
            'Settings',                           // Menu title
            'manage_options',                     // Capability
            'aivesese',                          // Menu slug (same as parent for first submenu)
            [$this, 'render_settings_page']     // Callback function
        );

        add_submenu_page(
            'aivesese',
            'AI Vector Search Status',
            'Status',
            'manage_options',
            'aivesese-status',
            [$this, 'render_status_page']
        );

        add_submenu_page(
            'aivesese',
            'Sync Products',
            'Sync Products',
            'manage_options',
            'aivesese-sync',
            [$this, 'render_sync_page']
        );

        // Add Analytics page if analytics is available
        if (class_exists('AIVectorSearch_Analytics')) {
            add_submenu_page(
                'aivesese',
                'Search Analytics',
                'Analytics',
                'manage_options',
                'aivesese-analytics',
                [AIVectorSearch_Analytics::instance(), 'render_analytics_page_template']
            );
        }

        if (class_exists('AIVectorSearch_Agent_Analytics')) {
            add_submenu_page(
                'aivesese',
                'Agent Analytics',
                'Agent Analytics',
                'manage_options',
                'aivesese-agent-analytics',
                [AIVectorSearch_Agent_Analytics::instance(), 'render_analytics_page']
            );
        }
    }


    public function render_sync_page()
    {
        echo '<div class="wrap">';
        $connection_mode = get_option('aivesese_connection_mode', 'lite');
        $heading = $connection_mode === 'api'
            ? __('Sync Products to Managed API', 'ai-vector-search-semantic')
            : __('Sync Products to Supabase', 'ai-vector-search-semantic');

        echo '<h1>' . esc_html($heading) . '</h1>';

        if ($connection_mode === 'lite') {
            echo '<div class="notice notice-info"><p>' . esc_html__('Lite mode manages its search index automatically. No Supabase sync is required.', 'ai-vector-search-semantic') . '</p></div>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=aivesese')) . '">' . esc_html__('Manage Lite settings', 'ai-vector-search-semantic') . '</a></p>';
            echo '</div>';
            return;
        }

        if (!$this->is_configured()) {
            $this->render_configuration_error();
            echo '</div>';
            return;
        }

        $this->handle_sync_actions();
        $this->render_sync_overview();
        $this->render_sync_actions();
        echo '</div>';
    }

    private function is_configured(): bool
    {
        $mode = get_option('aivesese_connection_mode', 'lite');
        if ($mode === 'lite') {
            return true;
        }

        if ($mode === 'api') {
            return !empty(get_option('aivesese_license_key')) &&
                get_option('aivesese_api_activated') === '1' &&
                !empty(get_option('aivesese_store'));
        }

        return get_option('aivesese_store') &&
            get_option('aivesese_url') &&
            get_option('aivesese_key');
    }

    private function render_configuration_error()
    {
        $mode = get_option('aivesese_connection_mode', 'lite');
        $message = $mode === 'api'
            ? __('Configuration incomplete! Please activate your license in Settings first.', 'ai-vector-search-semantic')
            : __('Configuration incomplete! Please configure your Supabase settings first.', 'ai-vector-search-semantic');

        echo '<div class="notice notice-error"><p>';
        echo esc_html($message);
        echo ' <a href="' . esc_url(admin_url('admin.php?page=aivesese')) . '">';
        echo esc_html__('Go to Settings', 'ai-vector-search-semantic') . '</a>';
        echo '</p></div>';
    }

    private function render_connection_error()
    {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Unable to connect to Supabase or no data found. Check your configuration and ensure the SQL has been installed.', 'ai-vector-search-semantic');
        echo '</p></div>';
    }

    private function render_health_overview(array $data)
    {
        echo '<div class="notice notice-success"><p>✅ Successfully connected to Supabase!</p></div>';

        echo '<h2>Store Health Overview</h2>';
        echo '<table class="widefat striped aivs-data-table">';
        echo '<thead><tr><th>Metric</th><th class="numeric">Count</th><th>Status</th></tr></thead>';
        echo '<tbody>';

        $total = intval($data['total_products']);
        $published = intval($data['published_products']);
        $in_stock = intval($data['in_stock_products']);
        $with_embeddings = intval($data['with_embeddings']);

        $this->render_health_row('Total Products', $total, $total > 0);
        $this->render_health_row('Published Products', $published, $published > 0);
        $this->render_health_row('In Stock Products', $in_stock, $in_stock > 0);
        $this->render_embeddings_status_row($with_embeddings, $published);

        echo '</tbody></table>';
    }

    private function render_health_row(string $label, int $count, bool $is_good)
    {
        echo '<tr>';
        echo '<td>' . esc_html($label) . '</td>';
        echo '<td class="numeric">' . number_format($count) . '</td>';
        echo '<td>' . ($is_good ? '✅' : '⚠️') . '</td>';
        echo '</tr>';
    }

    private function render_embeddings_status_row(int $with_embeddings, int $published)
    {
        echo '<tr>';
        echo '<td>With Embeddings</td>';
        echo '<td class="numeric">' . number_format($with_embeddings) . '</td>';
        echo '<td>';

        if ($with_embeddings == 0) {
            echo '❌ No embeddings found';
        } elseif ($with_embeddings == $published) {
            echo '✅ All products have embeddings';
        } else {
            $percent = round(($with_embeddings / $published) * 100, 1);
            echo '⚠️ ' . esc_html($percent) . '% coverage';
        }

        echo '</td></tr>';
    }

    private function render_configuration_summary()
    {
        echo '<h2>' . esc_html__('Configuration Summary', 'ai-vector-search-semantic') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<tbody>';

        $config_items = [
            'Store ID' => get_option('aivesese_store'),
            'Supabase URL' => get_option('aivesese_url'),
            'AI Search' => get_option('aivesese_enable_search', '1') === '1' ? '✅ Enabled' : '❌ Disabled',
            'Semantic Search' => get_option('aivesese_semantic_toggle') === '1' ? '✅ Enabled' : '❌ Disabled',
            'OpenAI Key' => get_option('aivesese_openai') ? '✅ Configured' : '❌ Not set',
            'Woodmart Integration' => $this->get_woodmart_status(),
        ];

        foreach ($config_items as $label => $value) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td>';
            if (in_array($label, ['Store ID', 'Supabase URL'])) {
                echo '<code>' . esc_html($value) . '</code>';
            } else {
                echo wp_kses_post($value);
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function get_woodmart_status(): string
    {
        $is_enabled = get_option('aivesese_enable_woodmart_integration', '0') === '1';
        $is_woodmart_active = defined('WOODMART_THEME_DIR') || wp_get_theme()->get('Name') === 'Woodmart';

        if (!$is_woodmart_active) {
            return '⚪ Woodmart not detected';
        }

        if ($is_enabled) {
            return '✅ Enabled (Woodmart detected)';
        }

        return '❌ Disabled (Woodmart available)';
    }

    private function render_quick_actions()
    {
        echo '<h2>' . esc_html__('Quick Actions', 'ai-vector-search-semantic') . '</h2>';
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=aivesese')) . '" class="button">' . esc_html__('Configure Settings', 'ai-vector-search-semantic') . '</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=aivesese-status')) . '" class="button">' . esc_html__('Refresh Status', 'ai-vector-search-semantic') . '</a>';
        echo '</p>';
    }

    private function handle_sync_actions()
    {
        if (!isset($_POST['action']) || !check_admin_referer('aivesese_sync')) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['action']));

        switch ($action) {
            case 'sync_all':
                $this->handle_sync_all();
                break;
            case 'sync_batch':
                $this->handle_sync_batch();
                break;
            case 'generate_embeddings':
                $this->handle_generate_embeddings();
                break;
        }
    }

    private function handle_sync_all()
    {
        echo '<div class="notice notice-info"><p>' .
            esc_html__('Batch sync starts in your browser and runs one request per chunk to avoid timeouts.', 'ai-vector-search-semantic') .
            '</p></div>';
    }

    private function handle_sync_batch()
    {
        $batch_size = isset($_POST['batch_size']) ? absint(wp_unslash($_POST['batch_size'])) : 50;
        $offset = isset($_POST['offset']) ? absint(wp_unslash($_POST['offset'])) : 0;

        $result = $this->product_sync->sync_products_batch($batch_size, $offset);

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Successfully synced batch: ' .
                esc_attr($result['synced']) . '/' . esc_attr($result['total']) .
                ' products (offset: ' . esc_attr($offset) . ')</p></div>';

            $this->render_next_batch_form($batch_size, $offset + $batch_size);
        } else {
            echo '<div class="notice notice-error"><p>Batch sync failed: ' .
                esc_html($result['message']) . '</p></div>';
        }
    }

    private function handle_generate_embeddings()
    {
        $result = $this->product_sync->generate_missing_embeddings();

        if ($result['success']) {
            if ($result['updated'] === 0) {
                echo '<div class="notice notice-info"><p>No products without embeddings found in Supabase.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Generated embeddings for ' .
                    esc_html($result['updated']) . ' products. Skipped ' .
                    esc_html($result['skipped']) . '.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
        }
    }

    private function render_next_batch_form(int $batch_size, int $next_offset)
    {
        echo '<div class="notice notice-info">';
        echo '<p>Continue with next batch:</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_batch">';
        echo '<input type="hidden" name="batch_size" value="' . esc_attr($batch_size) . '">';
        echo '<input type="hidden" name="offset" value="' . esc_attr($next_offset) . '">';
        echo '<button type="submit" class="button">Sync Next Batch (offset: ' . esc_html($next_offset) . ')</button>';
        echo '</form>';
        echo '</div>';
    }

    private function render_sync_overview()
    {
        $total_products = $this->product_sync->get_syncable_products_count();
        $synced_count = $this->connection_manager->get_synced_count();
        $destination_label = get_option('aivesese_connection_mode', 'lite') === 'api'
            ? __('Synced to Managed API', 'ai-vector-search-semantic')
            : __('Synced to Supabase', 'ai-vector-search-semantic');

        echo '<h2>Sync Overview</h2>';
        echo '<table class="widefat striped aivs-data-table">';
        echo '<tbody>';
        echo '<tr><td><strong>WooCommerce Products</strong></td><td class="numeric">' . number_format($total_products) . '</td></tr>';
        echo '<tr><td><strong>' . esc_html($destination_label) . '</strong></td><td class="numeric">' . number_format($synced_count) . '</td></tr>';
        echo '<tr><td><strong>Sync Status</strong></td><td>';

        if ($synced_count == 0) {
            echo '<span class="status-indicator status-error">❌ No products synced</span>';
        } elseif ($synced_count >= $total_products) {
            echo '<span class="status-indicator status-success">✅ All products synced</span>';
        } else {
            $percent = round(($synced_count / $total_products) * 100, 1);
            echo '<span class="status-indicator status-warning">⚠️ ' . esc_html($percent) . '% synced (' .
                absint($synced_count) . '/' . absint($total_products) . ')</span>';
        }

        echo '</td></tr>';
        echo '</tbody></table>';
    }

    private function render_sync_actions()
    {
        echo '<h2>Sync Actions</h2>';

        // Full sync
        echo '<div class="sync-action-card">';
        echo '<h3>🔄 Full Sync</h3>';
        echo '<p>Sync the full catalog in browser-driven batches so large catalogs do not hit a page timeout.</p>';
        echo '<form method="post" class="sync-form" data-aivesese-sync-all-form="1">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_all">';
        echo '<div class="sync-form-controls">';
        echo '<label>Batch Size: <input type="number" name="batch_size" value="50" min="1" max="200" class="small-text"></label> ';
        echo '<button type="button" class="button button-primary" data-aivesese-sync-all-btn="1">Sync All Products</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Batch sync
        echo '<div class="sync-action-card">';
        echo '<h3>⚡ Batch Sync</h3>';
        echo '<p>Sync products in smaller batches to avoid timeouts.</p>';
        echo '<form method="post" class="sync-form">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_batch">';
        echo '<div class="sync-form-controls">';
        echo '<label>Batch Size: <input type="number" name="batch_size" value="50" min="1" max="200" class="small-text"></label> ';
        echo '<label>Offset: <input type="number" name="offset" value="0" min="0" class="small-text"></label> ';
        echo '<button type="submit" class="button">Sync Batch</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Embeddings generation
        if (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai')) {
            echo '<div class="sync-action-card">';
            echo '<h3>🧠 Generate Embeddings</h3>';
            echo '<p>Generate or update OpenAI embeddings for products that don\'t have them.</p>';
            echo '<form method="post" class="sync-form">';
            wp_nonce_field('aivesese_sync');
            echo '<input type="hidden" name="action" value="generate_embeddings">';
            echo '<button type="submit" class="button button-secondary">Generate Missing Embeddings</button>';
            echo '</form>';
            echo '</div>';
        }

        // Field sync
        echo '<div class="sync-action-card">';
        echo '<h3>🎯 Field Sync</h3>';
        echo '<p>Update a single field across all synced products without re-syncing the full catalog. Useful for refreshing Cost of Goods, prices, or stock after a bulk change.</p>';
        echo '<form method="post" class="sync-form" data-aivesese-field-sync-form="1">';
        wp_nonce_field('aivesese_sync');
        echo '<div class="sync-form-controls">';
        echo '<label>Field: <select name="field">';
        foreach ($this->product_sync->get_syncable_fields() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>Batch Size: <input type="number" name="batch_size" value="50" min="1" max="200" class="small-text"></label> ';
        echo '<button type="button" class="button button-secondary" data-aivesese-field-sync-btn="1">Sync Field for All Products</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div id="sync-status"></div>';
    }

    public function handle_sync_products_batch_ajax()
    {
        check_ajax_referer('aivesese_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access'], 403);
            return;
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = min(max($batch_size, 1), 200);
        $total_products = $this->product_sync->get_syncable_products_count();

        if ($offset >= $total_products) {
            wp_send_json_success([
                'message' => 'Sync already complete.',
                'synced' => 0,
                'processed' => $total_products,
                'total_products' => $total_products,
                'next_offset' => $offset,
                'done' => true,
            ]);
            return;
        }

        $result = $this->product_sync->sync_products_batch($batch_size, $offset);

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? 'Batch sync failed.',
                'synced' => (int) ($result['synced'] ?? 0),
                'processed' => min($offset, $total_products),
                'total_products' => $total_products,
                'next_offset' => $offset,
            ]);
            return;
        }

        $processed = min($offset + (int) $result['total'], $total_products);

        wp_send_json_success([
            'message' => sprintf(
                'Synced %d of %d products in this batch.',
                (int) $result['synced'],
                (int) $result['total']
            ),
            'synced' => (int) $result['synced'],
            'processed' => $processed,
            'total_products' => $total_products,
            'next_offset' => $offset + $batch_size,
            'done' => $processed >= $total_products,
        ]);
    }

    public function handle_sync_field_batch_ajax()
    {
        check_ajax_referer('aivesese_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access'], 403);
            return;
        }

        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = min(max($batch_size, 1), 200);
        $total_products = $this->product_sync->get_syncable_products_count();

        if ($offset >= $total_products) {
            wp_send_json_success([
                'message'        => 'Field sync complete.',
                'synced'         => 0,
                'processed'      => $total_products,
                'total_products' => $total_products,
                'next_offset'    => $offset,
                'done'           => true,
            ]);
            return;
        }

        $result = $this->product_sync->sync_field_batch($batch_size, $offset, $field);

        if (empty($result['success'])) {
            wp_send_json_error([
                'message'        => $result['message'] ?? 'Field sync failed.',
                'synced'         => (int) ($result['synced'] ?? 0),
                'processed'      => min($offset, $total_products),
                'total_products' => $total_products,
                'next_offset'    => $offset,
            ]);
            return;
        }

        $processed = min($offset + (int) $result['total'], $total_products);

        wp_send_json_success([
            'message'        => sprintf('Updated %d of %d products in this batch.', (int) $result['synced'], (int) $result['total']),
            'synced'         => (int) $result['synced'],
            'processed'      => $processed,
            'total_products' => $total_products,
            'next_offset'    => $offset + $batch_size,
            'done'           => $processed >= $total_products,
        ]);
    }

    private function render_help_section()
    {
        $user_id = get_current_user_id();
        $is_open = get_user_meta($user_id, '_aivesese_help_open', true);
        $is_open = ($is_open === '' ? '1' : $is_open);
        $open_attr = ($is_open === '1') ? ' open' : '';

        echo '<div class="ai-supabase-help">';
        echo '<details id="ai-supabase-help-details"' . esc_attr($open_attr) . '>';
        echo '<summary class="ai-supabase-help__summary"><strong>Setup Guide</strong> <span class="ai-supabase-help__hint">click to expand/collapse</span></summary>';

        $this->render_setup_instructions();
        $this->render_sql_section();

        echo '</details></div>';
    }

    private function render_setup_instructions()
    {
        $template = AIVESESE_PLUGIN_PATH . 'assets/templates/setup-instructions.php';

        if (! file_exists($template)) {
            return;
        }

        include $template;
    }
    private function render_sql_section()
    {
        $connection_mode = get_option('aivesese_connection_mode', 'lite');

        if ($connection_mode !== 'self_hosted') {
            return; // Don't show SQL section for API mode
        }

        echo '<hr>';
        echo '<div class="aivesese-schema-section">';

        if (!$this->validate_supabase_connection()) {
            $this->render_connection_required_notice();
            echo '</div>';
            return;
        }

        // Load migration runner for status
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';
        $migration_status = \ZZZSolutions\VectorSearch\Migrations\Runner::getStatus();

        $this->render_installation_options($migration_status);

        echo '</div>';
    }

    private function get_sql_content(): string
    {
        $base = plugin_dir_path(__FILE__);
        $candidates = [
            $base . '../assets/sql/supabase.sql',
            $base . '../admin/sql/supabase.sql',
            $base . '../supabase.sql',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);

                // Add version header if not present
                if (strpos($content, '-- AI Vector Search SQL v2.0') === false) {
                    $version_header = "-- AI Vector Search SQL v2.0 - Updated with SKU search and enhanced FTS\n" .
                        "-- Run this entire script in Supabase SQL Editor\n" .
                        "-- New features: Partial SKU search, Better ranking, Woodmart integration\n\n";
                    $content = $version_header . $content;
                }

                return $content;
            }
        }

        return '';
    }

    private function enqueue_help_script()
    {
        wp_register_script('aivesese-help', false, [], AIVESESE_PLUGIN_VERSION, true);
        wp_enqueue_script('aivesese-help');

        $data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aivesese_help_nonce'),
        ];
        wp_add_inline_script('aivesese-help', 'window.AISupabaseHelp=' . wp_json_encode($data) . ';', 'before');

        $help_script = $this->get_help_toggle_script();
        wp_add_inline_script('aivesese-help', $help_script);
    }

    private function enqueue_admin_styles()
    {
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        // Main admin interface styles (always load on plugin pages)
        wp_enqueue_style(
            'aivesese-admin-interface',
            AIVESESE_PLUGIN_URL . 'assets/css/admin-interface.css',
            [],
            AIVESESE_PLUGIN_VERSION
        );

        // PostgreSQL installation styles (only on settings page)
        if ($current_page === 'aivesese') {
            wp_enqueue_style(
                'aivesese-postgres-install',
                AIVESESE_PLUGIN_URL . 'assets/css/postgres-installation.css',
                ['aivesese-admin-interface'],
                AIVESESE_PLUGIN_VERSION
            );
        }

        // Analytics dashboard styles (only on analytics page)
        if (in_array($current_page, ['aivesese-analytics', 'aivesese-agent-analytics'], true)) {
            wp_enqueue_style(
                'aivesese-analytics-dashboard',
                AIVESESE_PLUGIN_URL . 'assets/css/analytics-dashboard.css',
                ['aivesese-admin-interface'],
                AIVESESE_PLUGIN_VERSION
            );
        }

        // Add any conditional inline styles if needed
        $this->add_conditional_styles($current_page);
    }

    /**
     * Add conditional inline styles (minimal, only when necessary)
     */
    private function add_conditional_styles($current_page)
    {
        $connection_mode = get_option('aivesese_connection_mode', 'lite');

        // Add body class-based styles for connection mode
        $inline_css = "
            body.aivesese-mode-{$connection_mode} .{$connection_mode}-only { display: block !important; }
            body.aivesese-mode-{$connection_mode} .hide-in-{$connection_mode} { display: none !important; }
        ";

        // Only add if we have conditional styles to add
        if (!empty($inline_css)) {
            wp_add_inline_style('aivesese-admin-interface', $inline_css);
        }
    }

    private function enqueue_admin_scripts()
    {
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        // Main admin interface script (always load on plugin pages)
        wp_enqueue_script(
            'aivesese-admin-interface',
            AIVESESE_PLUGIN_URL . 'assets/js/admin-interface.js',
            ['jquery'],
            AIVESESE_PLUGIN_VERSION,
            true
        );

        // Localize script with necessary data
        wp_localize_script('aivesese-admin-interface', 'aivesese_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aivesese_admin_nonce'),
            'license_nonce' => wp_create_nonce('aivesese_license_nonce'),
            'help_nonce' => wp_create_nonce('aivesese_help_nonce'),
            'analytics_nonce' => wp_create_nonce('aivs_analytics_nonce'),
            'strings' => [
                'activating' => __('Activating...', 'ai-vector-search-semantic'),
                'processing' => __('Processing...', 'ai-vector-search-semantic'),
                'license_copied' => __('License key copied to clipboard!', 'ai-vector-search-semantic'),
                'sql_copied' => __('SQL copied to clipboard.', 'ai-vector-search-semantic'),
            ]
        ]);

        // PostgreSQL installation script (only on settings page)
        if ($current_page === 'aivesese') {
            wp_enqueue_script(
                'aivesese-postgres-install',
                AIVESESE_PLUGIN_URL . 'assets/js/postgres-installation.js',
                ['jquery', 'aivesese-admin-interface'],
                AIVESESE_PLUGIN_VERSION,
                true
            );

            wp_localize_script('aivesese-postgres-install', 'aivesese_postgres', [
                'install_nonce' => wp_create_nonce('aivesese_postgres_install_nonce'),
                'status_nonce' => wp_create_nonce('aivesese_postgres_status_nonce'),
                'admin_url' => admin_url(),
            ]);
        }

        // Analytics dashboard script (only on analytics page)
        if ($current_page === 'aivesese-analytics') {
            wp_enqueue_script(
                'aivesese-analytics-dashboard',
                AIVESESE_PLUGIN_URL . 'assets/js/analytics-dashboard.js',
                ['jquery'],
                AIVESESE_PLUGIN_VERSION,
                true
            );

            wp_localize_script('aivesese-analytics-dashboard', 'aivesese_analytics', [
                'preview_nonce' => wp_create_nonce('aivs_preview_nonce'),
                'stats_nonce' => wp_create_nonce('aivs_stats_nonce'),
                'tracking_nonce' => wp_create_nonce('aivs_tracking_nonce'),
                'analytics_nonce' => wp_create_nonce('aivs_analytics_nonce'),
                'export_nonce' => wp_create_nonce('aivesese_export_analytics'),
            ]);
        }

    }

    public function show_services_banner()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed_screens = [
            'toplevel_page_aivesese',
            'aivesese_page_aivesese',
            'aivesese_page_aivesese-status',
            'aivesese_page_aivesese-sync',
            'aivesese_page_aivesese-analytics',
            'aivesese_page_aivesese-agent-analytics'
        ];

        if (!$screen || !in_array($screen->id, $allowed_screens, true)) {
            return;
        }

        echo '<div class="notice notice-success aivesese-services-banner">';
        echo '<div>🚀</div>';
        echo '<div>';
        echo '<strong>Need a hand with AI search or Supabase?</strong><br>';
        echo 'Our team at <em>ZZZ Solutions</em> can install, customise and tune everything for you.';
        echo '</div>';
        echo '<a href="https://zzzsolutions.ro" target="_blank" rel="noopener noreferrer" class="button button-primary">See Services</a>';
        echo '</div>';
    }

    public function handle_help_toggle()
    {
        check_ajax_referer('aivesese_help_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $open = (isset($_POST['open']) && wp_unslash($_POST['open']) === '1') ? '1' : '0';
        update_user_meta(get_current_user_id(), '_aivesese_help_open', $open);

        wp_send_json_success(['open' => $open]);
    }

    public function show_sql_update_notice()
    {
        if (!current_user_can('manage_options') || get_option('aivesese_sql_v2_dismissed')) {
            return;
        }

        // Only show on relevant admin pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed_screens = [
            'toplevel_page_aivesese',
            'aivesese_page_aivesese',
            'aivesese_page_aivesese-status',
            'aivesese_page_aivesese-sync',
            'aivesese_page_aivesese-analytics',
            'aivesese_page_aivesese-agent-analytics',
            'plugins'
        ];

        if (!$screen || !in_array($screen->id, $allowed_screens, true)) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<h3>🔄 AI Vector Search - SQL Update Required</h3>';
        echo '<p><strong>New features added!</strong> We\'ve enhanced the search with:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>✨ <strong>Partial SKU search</strong> - Find products by typing part of the SKU</li>';
        echo '<li>🎯 <strong>Better search ranking</strong> - More relevant results</li>';
        echo '<li>🚀 <strong>Woodmart live search integration</strong> - Enable in settings</li>';
        echo '</ul>';
        echo '<p><strong>Action required:</strong> Please update your Supabase SQL to get these features:</p>';
        echo '<ol style="margin-left: 20px;">';
        echo '<li>Go to <a href="' . esc_url(admin_url('admin.php?page=aivesese')) . '"><strong>Settings - AI Supabase</strong></a></li>';
        echo '<li>Expand the <strong>"Setup Guide"</strong> section</li>';
        echo '<li>Copy the updated SQL and run it in <strong>Supabase - SQL Editor</strong></li>';
        echo '<li>The new functions will be added/updated automatically</li>';
        echo '</ol>';
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=aivesese')) . '" class="button button-primary">Update SQL Now</a> ';
        echo '<a href="' .
            esc_url(wp_nonce_url(add_query_arg('aivesese_sql_v2_dismiss', 1), 'aivesese_sql_v2_nonce')) .
            '" class="button">I\'ve Updated It</a>';
        echo '</p>';
        echo '</div>';
    }

    public function handle_sql_update_dismiss()
    {
        if (isset($_GET['aivesese_sql_v2_dismiss']) && check_admin_referer('aivesese_sql_v2_nonce')) {
            update_option('aivesese_sql_v2_dismissed', time());
            wp_safe_redirect(remove_query_arg(['aivesese_sql_v2_dismiss', '_wpnonce']));
            exit;
        }
    }

    /**
     * Render PostgreSQL connection string field
     */
    public function render_postgres_connection_field()
    {
        $connection_mode = get_option('aivesese_connection_mode', 'lite');
        $value = get_option('aivesese_postgres_connection_string');
        $has_value = !empty($value);

        if ($connection_mode !== 'self_hosted') {
            echo '<p><em>PostgreSQL connection is only needed for self-hosted mode.</em></p>';
            return;
        }

        // Use template file instead of inline HTML
        $template_vars = compact('connection_mode', 'value', 'has_value');
        $this->load_template('postgres-connection', $template_vars);
    }

    /**
     * Render sold count update section (self-hosted only).
     */
    private function render_sold_count_section()
    {
        $ranges = [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];

        $template_vars = compact('ranges');
        $this->load_template('sold-count-update', $template_vars);
    }

    /**
     * Render PostgreSQL help section (extracted from inline HTML)
     */
    private function render_postgres_help_section()
    {
        include AIVESESE_PLUGIN_PATH . 'assets/templates/postgres-help-section.php';
    }

    /**
     * Handle PostgreSQL schema installation via AJAX
     */
    public function handle_postgres_install_schema()
    {
        check_ajax_referer('aivesese_postgres_install_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access']);
            return;
        }

        // Load the migration runner
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';

        // Run the migration
        $result = \ZZZSolutions\VectorSearch\Migrations\Runner::run();

        if ($result['ok']) {
            wp_send_json_success([
                'message' => $result['msg'],
                'details' => $result['details'] ?? []
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['msg'],
                'details' => $result['details'] ?? []
            ]);
        }
    }

    /**
     * Handle PostgreSQL status check via AJAX
     */
    public function handle_postgres_check_status()
    {
        check_ajax_referer('aivesese_postgres_status_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access']);
            return;
        }

        // Load the migration runner
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';

        $status = \ZZZSolutions\VectorSearch\Migrations\Runner::getStatus();
        wp_send_json_success($status);
    }

    /**
     * Handle sold count update via AJAX.
     */
    public function handle_update_sold_counts()
    {
        check_ajax_referer('aivesese_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized access']);
            return;
        }

        $days = isset($_POST['days']) ? absint($_POST['days']) : 30;
        if ($days < 1) {
            $days = 30;
        }

        $result = $this->product_sync->update_sold_counts($days);

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }

    /**
     * Render installation options (PostgreSQL + Manual)
     */
    private function render_installation_options(array $migration_status)
    {
        echo '<h2>🗄️ Database Schema Installation</h2>';

        // Show current status if already installed
        $installed_time = get_option('aivesese_schema_installed');
        $install_method = get_option('aivesese_schema_install_method', 'unknown');

        if ($installed_time) {
            echo '<div class="notice notice-success inline">';
            echo '<h3>✅ Schema Already Installed</h3>';
            echo '<p>Installed on <strong>' . gmdate('M j, Y \a\t g:i A', $installed_time) . '</strong>';
            if ($install_method) {
                echo ' via <strong>' . esc_html($install_method) . '</strong>';
            }
            echo '</p>';
            echo '<div class="installation-actions">';
            echo '<button type="button" class="button" id="postgres-reinstall-btn">Update Schema</button> ';
            echo '<button type="button" class="button button-small" id="postgres-check-status-btn">Check Status</button>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="installation-options">';

        // PostgreSQL installation option
        if ($migration_status['can_run']) {
            $this->render_postgres_installation_option();
        } else {
            $this->render_postgres_installation_unavailable($migration_status);
        }

        // Manual installation option
        $this->render_manual_installation_option();

        echo '</div>';
    }

    /**
     * Render PostgreSQL installation option (available)
     */
    private function render_postgres_installation_option()
    {
        include AIVESESE_PLUGIN_PATH . 'assets/templates/postgres-installation-option.php';
    }

    /**
     * Render PostgreSQL installation unavailable notice
     */
    private function render_postgres_installation_unavailable(array $status)
    {
        include AIVESESE_PLUGIN_PATH . 'assets/templates/postgres-installation-unavailable.php';
    }

    /**
     * Render manual installation option
     */
    private function render_manual_installation_option()
    {
        $sql_content = $this->get_sql_content();
        include AIVESESE_PLUGIN_PATH . 'assets/templates/manual-installation-option.php';
    }

    /**
     * Render manual installation steps
     */
    private function render_manual_installation_steps()
    {
        $sql_content = $this->get_sql_content();

        // Use template file instead of inline HTML
        $template_vars = compact('sql_content');
        $this->load_template('manual-installation', $template_vars);
    }

    /**
     * Validate Supabase connection configuration
     */
    private function validate_supabase_connection(): bool
    {
        $url = trim(get_option('aivesese_url', ''));
        $key = trim(get_option('aivesese_key', ''));
        $store_id = trim(get_option('aivesese_store', ''));

        return !empty($url) && !empty($key) && !empty($store_id);
    }

    /**
     * Render notice when Supabase connection is not configured
     */
    private function render_connection_required_notice()
    {
        echo '<div class="notice notice-warning inline">';
        echo '<h3>⚙️ Configuration Required</h3>';
        echo '<p>Please configure your Supabase connection settings above to use the schema installation features.</p>';
        echo '<p>You need to provide:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>Supabase URL</li>';
        echo '<li>Supabase API Key (anon)</li>';
        echo '<li>Store ID</li>';
        echo '</ul>';
        echo '<p><strong>Scroll up</strong> and fill in the required fields, then click "Save Changes".</p>';
        echo '</div>';
    }

    /**
     * Add body classes for better CSS targeting
     */
    public function add_admin_body_class($classes)
    {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'aivesese') !== false) {
            $connection_mode = get_option('aivesese_connection_mode', 'lite');
            $classes .= ' aivesese-admin aivesese-mode-' . $connection_mode;
        }
        return $classes;
    }

    /**
     * Register the admin body class filter
     */
    private function init_admin_body_classes()
    {
        add_filter('admin_body_class', [$this, 'add_admin_body_class']);
    }

    /**
     * Load template file helper method (NEW)
     */
    private function load_template($template_name, $vars = [])
    {
        $template_name = basename((string) $template_name);
        $template_path = AIVESESE_PLUGIN_PATH . "assets/templates/{$template_name}.php";

        if (file_exists($template_path)) {
            // Extract variables for template
            extract($vars);
            include $template_path;
        } else {
            error_log("AI Vector Search: Template not found - {$template_path}");
            echo "<div class='notice notice-error'><p>Template missing: {$template_name}.php</p></div>";
        }
    }

    /**
     * Enhanced template loading with error handling (NEW)
     */
    private function load_template_with_fallback($template_name, $vars = [], $fallback_content = '')
    {
        $template_name = basename((string) $template_name);
        $template_path = AIVESESE_PLUGIN_PATH . "assets/templates/{$template_name}.php";

        if (file_exists($template_path)) {
            extract($vars);
            ob_start();
            include $template_path;
            return ob_get_clean();
        }

        // Log error and return fallback
        error_log("AI Vector Search: Template not found - {$template_path}");
        return $fallback_content;
    }
}
