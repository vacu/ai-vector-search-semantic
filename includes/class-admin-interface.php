<?php
/**
 * Handles all admin interface functionality
 */
class AIVectorSearch_Admin_Interface {

    private static $instance = null;
    private $supabase_client;
    private $api_client;
    private $product_sync;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
        $this->api_client = AIVectorSearch_API_Client::instance();
        $this->product_sync = AIVectorSearch_Product_Sync::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'show_services_banner']);
        add_action('admin_notices', [$this, 'show_sql_update_notice']);
        add_action('admin_init', [$this, 'handle_sql_update_dismiss']);
        add_action('wp_ajax_aivesese_toggle_help', [$this, 'handle_help_toggle']);
        add_action('wp_ajax_aivesese_activate_license', [$this, 'handle_license_activation']);
        add_action('wp_ajax_aivesese_test_connection', [$this, 'handle_test_connection']);
    }

    public function register_settings() {
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

            // Feature toggles
            'semantic_toggle' => 'Enable semantic (vector) search',
            'auto_sync' => 'Auto-sync products on save',
            'enable_pdp_similar' => 'PDP "Similar products"',
            'enable_cart_below' => 'Below-cart recommendations',
            'enable_woodmart_integration' => 'Woodmart live search integration',
        ];

        foreach ($settings as $id => $label) {
            $this->register_setting($id);
        }

        add_settings_section('aivesese_section', 'AI Search Configuration', '__return_false', 'aivesese');
        $this->add_settings_fields();
    }

    private function register_setting(string $id) {
        $sanitizers = [
            'connection_mode' => 'sanitize_text_field',
            'license_key' => 'aivesese_passthru',
            'url' => 'esc_url_raw',
            'key' => 'aivesese_passthru',
            'store' => 'sanitize_text_field',
            'openai' => 'aivesese_passthru',
        ];

        $config = [
            'type' => 'string',
            'sanitize_callback' => $sanitizers[$id] ?? 'sanitize_text_field',
            'default' => '',
        ];

        // Special handling for connection mode
        if ($id === 'connection_mode') {
            $config['default'] = 'self_hosted';
        }

        // Special handling for checkboxes
        if (in_array($id, ['semantic_toggle', 'auto_sync', 'enable_pdp_similar', 'enable_cart_below', 'enable_woodmart_integration'])) {
            $config['sanitize_callback'] = function($v) { return $v === '1' ? '1' : '0'; };
            $config['default'] = $id === 'enable_woodmart_integration' ? '0' : '1';
        }

        register_setting('aivesese_settings', "aivesese_{$id}", $config);
    }

    private function add_settings_fields() {
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
        $self_hosted_fields = ['url', 'key', 'store', 'openai'];
        foreach ($self_hosted_fields as $id) {
            add_settings_field(
                "aivesese_{$id}",
                ucfirst(str_replace('_', ' ', $id)),
                [$this, 'render_text_field'],
                'aivesese',
                'aivesese_section',
                ['field_id' => $id, 'conditional' => 'self_hosted']
            );
        }

        // Feature toggles
        $checkbox_fields = [
            'semantic_toggle' => 'Enable semantic (vector) search - Better relevance',
            'auto_sync' => 'Auto-sync products - Automatically sync products when saved/updated',
            'enable_pdp_similar' => 'PDP "Similar products" - Show similar products on product pages',
            'enable_cart_below' => 'Below-cart recommendations - Show recommendations under cart',
            'enable_woodmart_integration' => 'Woodmart live search integration - Enable AI search for Woodmart AJAX search',
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

    public function render_connection_mode_field() {
        $value = get_option('aivesese_connection_mode', 'self_hosted');
        ?>
        <div class="connection-mode-selector">
            <label class="connection-option">
                <input type="radio" name="aivesese_connection_mode" value="api" <?php checked($value, 'api'); ?>>
                <div class="option-card">
                    <h4>🚀 Managed API Service</h4>
                    <p>Use our hosted service with your license key. No setup required!</p>
                    <ul>
                        <li>✅ No database setup needed</li>
                        <li>✅ Automatic updates and maintenance</li>
                        <li>✅ Professional support included</li>
                        <li>✅ Guaranteed uptime and performance</li>
                    </ul>
                    <small><strong>Starts at $29/month</strong></small>
                </div>
            </label>

            <label class="connection-option">
                <input type="radio" name="aivesese_connection_mode" value="self_hosted" <?php checked($value, 'self_hosted'); ?>>
                <div class="option-card">
                    <h4>⚙️ Self-Hosted (Bring Your Own Keys)</h4>
                    <p>Use your own Supabase and OpenAI accounts. Full control!</p>
                    <ul>
                        <li>🔧 Requires Supabase project setup</li>
                        <li>🔧 Manual SQL installation needed</li>
                        <li>🔧 You manage infrastructure</li>
                        <li>💰 Pay only for API usage</li>
                    </ul>
                    <small><strong>Free plugin + your API costs</strong></small>
                </div>
            </label>
        </div>

        <style>
        .connection-mode-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        .connection-option {
            cursor: pointer;
        }
        .connection-option input[type="radio"] {
            display: none;
        }
        .option-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s ease;
            height: 100%;
        }
        .connection-option input[type="radio"]:checked + .option-card {
            border-color: #0073aa;
            background-color: #f0f8ff;
        }
        .option-card h4 {
            margin-top: 0;
            color: #0073aa;
        }
        .option-card ul {
            list-style: none;
            padding-left: 0;
        }
        .option-card li {
            margin: 5px 0;
            font-size: 14px;
        }
        .option-card small {
            color: #666;
            font-weight: bold;
        }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const radios = document.querySelectorAll('input[name="aivesese_connection_mode"]');
            const toggleFields = function() {
                const mode = document.querySelector('input[name="aivesese_connection_mode"]:checked').value;

                // Toggle license key field
                const licenseField = document.querySelector('[data-field="license_key"]');
                if (licenseField) {
                    licenseField.style.display = mode === 'api' ? 'table-row' : 'none';
                }

                // Toggle self-hosted fields
                const selfHostedFields = document.querySelectorAll('[data-conditional="self_hosted"]');
                selfHostedFields.forEach(field => {
                    field.style.display = mode === 'self_hosted' ? 'table-row' : 'none';
                });

                // Show/hide help sections
                const sqlSection = document.querySelector('.sql-help-section');
                if (sqlSection) {
                    sqlSection.style.display = mode === 'self_hosted' ? 'block' : 'none';
                }
            };

            radios.forEach(radio => radio.addEventListener('change', toggleFields));
            toggleFields(); // Initial toggle
        });
        </script>
        <?php
    }

    public function render_license_key_field() {
        $value = get_option('aivesese_license_key');
        $is_activated = !empty($value) && get_option('aivesese_api_activated') === '1';
        ?>
        <tr data-field="license_key">
            <th scope="row">License Key</th>
            <td>
                <div class="license-key-section">
                    <?php if ($is_activated): ?>
                        <div class="license-status activated">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <strong>License Active</strong>
                            <p>Your API service is connected and ready!</p>
                            <button type="button" class="button" onclick="revokeLicense()">Change License</button>
                        </div>
                    <?php else: ?>
                        <input type="text"
                               id="aivesese_license_key"
                               name="aivesese_license_key"
                               value="<?php echo esc_attr($value); ?>"
                               class="regular-text"
                               placeholder="Enter your license key from zzzsolutions.ro">

                        <button type="button"
                                id="activate-license"
                                class="button button-secondary"
                                onclick="activateLicense()">
                            Activate License
                        </button>

                        <div id="license-status" style="margin-top: 10px;"></div>

                        <p class="description">
                            Don't have a license?
                            <a href="https://zzzsolutions.ro/ai-search-service" target="_blank">Get one here</a>
                        </p>
                    <?php endif; ?>
                </div>

                <style>
                .license-key-section {
                    max-width: 500px;
                }
                .license-status {
                    padding: 15px;
                    border-radius: 4px;
                    border-left: 4px solid;
                }
                .license-status.activated {
                    background: #f0f9ff;
                    border-left-color: #10b981;
                    color: #065f46;
                }
                .license-status .dashicons {
                    color: #10b981;
                    margin-right: 5px;
                }
                .license-loading {
                    color: #f59e0b;
                }
                .license-error {
                    color: #dc2626;
                    background: #fef2f2;
                    border-left-color: #dc2626;
                    padding: 10px;
                    margin-top: 10px;
                }
                .license-success {
                    color: #065f46;
                    background: #f0f9ff;
                    border-left-color: #10b981;
                    padding: 10px;
                    margin-top: 10px;
                }
                </style>
            </td>
        </tr>

        <script>
        function activateLicense() {
            const key = document.getElementById('aivesese_license_key').value;
            const button = document.getElementById('activate-license');
            const status = document.getElementById('license-status');

            if (!key) {
                status.innerHTML = '<div class="license-error">Please enter a license key</div>';
                return;
            }

            button.disabled = true;
            button.textContent = 'Activating...';
            status.innerHTML = '<div class="license-loading">🔄 Activating license...</div>';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'aivesese_activate_license',
                    license_key: key,
                    nonce: '<?php echo wp_create_nonce('aivesese_license_nonce'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = '<div class="license-success">✅ License activated successfully! Refreshing page...</div>';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    status.innerHTML = '<div class="license-error">❌ ' + data.data.message + '</div>';
                    button.disabled = false;
                    button.textContent = 'Activate License';
                }
            })
            .catch(error => {
                status.innerHTML = '<div class="license-error">❌ Connection error. Please try again.</div>';
                button.disabled = false;
                button.textContent = 'Activate License';
            });
        }

        function revokeLicense() {
            if (confirm('Are you sure you want to deactivate your license? This will switch back to self-hosted mode.')) {
                // Clear license data
                document.getElementById('aivesese_license_key').value = '';
                // Submit form to save changes
                document.querySelector('form').submit();
            }
        }
        </script>
        <?php
    }

    public function render_text_field($args) {
        $field_id = $args['field_id'];
        $conditional = $args['conditional'] ?? null;
        $value = get_option("aivesese_{$field_id}");

        $data_attr = $conditional ? 'data-conditional="' . esc_attr($conditional) . '"' : '';

        printf(
            '<tr %s><th scope="row">%s</th><td><input type="text" class="regular-text" name="aivesese_%s" value="%s" /></td></tr>',
            $data_attr,
            ucfirst(str_replace('_', ' ', $field_id)),
            esc_attr($field_id),
            esc_attr($value)
        );
    }

    public function render_checkbox_field($args) {
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

    /**
     * Handle license activation AJAX
     */
    public function handle_license_activation() {
        check_ajax_referer('aivesese_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $license_key = sanitize_text_field($_POST['license_key']);

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
     * Enhanced settings page with conditional fields
     */
    public function render_settings_page() {
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI Vector Search Settings', 'ai-vector-search-semantic') . '</h1>';

        // Show different descriptions based on mode
        if ($connection_mode === 'api') {
            echo '<p>You are using our managed API service. No additional setup required!</p>';
        } else {
            echo '<p>Configure your own Supabase project and optionally enable semantic search using OpenAI.</p>';
            $this->render_help_section();
        }

        echo '<form method="post" action="options.php">';
        settings_fields('aivesese_settings');
        echo '<table class="form-table">';
        do_settings_sections('aivesese');
        echo '</table>';
        settings_errors();
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Enhanced status page with API/self-hosted detection
     */
    public function render_status_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI Vector Search Status', 'ai-vector-search-semantic') . '</h1>';

        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

        if ($connection_mode === 'api') {
            $this->render_api_status();
        } else {
            $this->render_self_hosted_status();
        }

        echo '</div>';
    }

    private function render_api_status() {
        $license_key = get_option('aivesese_license_key');

        if (empty($license_key)) {
            echo '<div class="notice notice-error"><p>No license key configured. Please go to Settings to activate your license.</p></div>';
            return;
        }

        // Get status from API
        $status = $this->api_client->get_status();

        if (!$status) {
            echo '<div class="notice notice-error"><p>Unable to connect to API service. Please check your license key.</p></div>';
            return;
        }

        echo '<div class="notice notice-success"><p>✅ Connected to ZZZ Solutions API Service</p></div>';

        // Show subscription info
        echo '<h2>Subscription Status</h2>';
        echo '<table class="widefat striped">';
        echo '<tbody>';
        echo '<tr><td><strong>Plan</strong></td><td>' . esc_html(ucfirst($status['plan'])) . '</td></tr>';
        echo '<tr><td><strong>Status</strong></td><td><span class="status-' . esc_attr($status['status']) . '">' . esc_html(ucfirst($status['status'])) . '</span></td></tr>';
        echo '<tr><td><strong>Products Synced</strong></td><td>' . number_format($status['products_count']) . '</td></tr>';
        echo '<tr><td><strong>Searches This Month</strong></td><td>' . number_format($status['usage']['searches_this_month']) . '</td></tr>';
        echo '<tr><td><strong>API Calls Today</strong></td><td>' . number_format($status['usage']['api_calls_today']) . '</td></tr>';

        if (!empty($status['expires_at'])) {
            echo '<tr><td><strong>Next Payment</strong></td><td>' . esc_html(date('M j, Y', strtotime($status['expires_at']))) . '</td></tr>';
        }

        echo '</tbody></table>';

        // Show usage limits
        if (!empty($status['limits'])) {
            echo '<h2>Usage Limits</h2>';
            echo '<div class="usage-bars">';

            $this->render_usage_bar(
                'Products',
                $status['products_count'],
                $status['limits']['products_limit'],
                'products'
            );

            $this->render_usage_bar(
                'Monthly Searches',
                $status['usage']['searches_this_month'],
                $status['limits']['searches_limit'],
                'searches'
            );

            echo '</div>';
        }
    }

    private function render_usage_bar($label, $current, $limit, $type) {
        $percentage = $limit > 0 ? min(($current / $limit) * 100, 100) : 0;
        $color = $percentage > 90 ? '#dc2626' : ($percentage > 70 ? '#f59e0b' : '#10b981');

        echo '<div class="usage-bar-container">';
        echo '<div class="usage-bar-header">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<span>' . number_format($current) . ($limit > 0 ? ' / ' . number_format($limit) : '') . '</span>';
        echo '</div>';
        echo '<div class="usage-bar">';
        echo '<div class="usage-bar-fill" style="width: ' . $percentage . '%; background-color: ' . $color . '"></div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_self_hosted_status() {
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

    public function add_admin_pages() {
        add_options_page(
            'AI Supabase',
            'AI Supabase',
            'manage_options',
            'aivesese',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'options-general.php',
            'AI Supabase Status',
            'Supabase Status',
            'manage_options',
            'aivesese-status',
            [$this, 'render_status_page']
        );

        add_submenu_page(
            'options-general.php',
            'Sync Products to Supabase',
            'Sync Products',
            'manage_options',
            'aivesese-sync',
            [$this, 'render_sync_page']
        );
    }

    public function render_sync_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Sync Products to Supabase', 'ai-vector-search-semantic') . '</h1>';

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

    private function is_configured(): bool {
        return get_option('aivesese_store') &&
               get_option('aivesese_url') &&
               get_option('aivesese_key');
    }

    private function render_configuration_error() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Configuration incomplete! Please configure your Supabase settings first.', 'ai-vector-search-semantic');
        echo ' <a href="' . esc_url(admin_url('options-general.php?page=aivesese')) . '">';
        echo esc_html__('Go to Settings', 'ai-vector-search-semantic') . '</a>';
        echo '</p></div>';
    }

    private function render_connection_error() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Unable to connect to Supabase or no data found. Check your configuration and ensure the SQL has been installed.', 'ai-vector-search-semantic');
        echo '</p></div>';
    }

    private function render_health_overview(array $data) {
        echo '<div class="notice notice-success"><p>' . esc_html__('✅ Successfully connected to Supabase!', 'ai-vector-search-semantic') . '</p></div>';

        echo '<h2>' . esc_html__('Store Health Overview', 'ai-vector-search-semantic') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Metric</th><th>Count</th><th>Status</th></tr></thead>';
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

    private function render_health_row(string $label, int $count, bool $is_good) {
        echo '<tr>';
        echo '<td>' . esc_html($label) . '</td>';
        echo '<td>' . number_format($count) . '</td>';
        echo '<td>' . ($is_good ? '✅' : '⚠️') . '</td>';
        echo '</tr>';
    }

    private function render_embeddings_status_row(int $with_embeddings, int $published) {
        echo '<tr>';
        echo '<td>With Embeddings</td>';
        echo '<td>' . number_format($with_embeddings) . '</td>';
        echo '<td>';

        if ($with_embeddings == 0) {
            echo '❌ No embeddings found';
        } elseif ($with_embeddings == $published) {
            echo '✅ All products have embeddings';
        } else {
            $percent = round(($with_embeddings / $published) * 100, 1);
            printf(esc_html__('⚠️ %s%% coverage', 'ai-vector-search-semantic'), esc_html($percent));
        }

        echo '</td></tr>';
    }

    private function render_configuration_summary() {
        echo '<h2>' . esc_html__('Configuration Summary', 'ai-vector-search-semantic') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<tbody>';

        $config_items = [
            'Store ID' => get_option('aivesese_store'),
            'Supabase URL' => get_option('aivesese_url'),
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

    private function get_woodmart_status(): string {
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

    private function render_quick_actions() {
        echo '<h2>' . esc_html__('Quick Actions', 'ai-vector-search-semantic') . '</h2>';
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('options-general.php?page=aivesese')) . '" class="button">' . esc_html__('Configure Settings', 'ai-vector-search-semantic') . '</a> ';
        echo '<a href="' . esc_url(admin_url('options-general.php?page=aivesese-status')) . '" class="button">' . esc_html__('Refresh Status', 'ai-vector-search-semantic') . '</a>';
        echo '</p>';
    }

    private function handle_sync_actions() {
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

    private function handle_sync_all() {
        $result = $this->product_sync->sync_all_products();

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Successfully synced ' .
                 esc_attr($result['synced']) . '/' . esc_attr($result['total']) .
                 ' products to Supabase!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Sync failed: ' .
                 esc_html($result['message']) . '</p></div>';
        }
    }

    private function handle_sync_batch() {
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

    private function handle_generate_embeddings() {
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

    private function render_next_batch_form(int $batch_size, int $next_offset) {
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

    private function render_sync_overview() {
        $total_products = wp_count_posts('product')->publish;
        $synced_count = $this->supabase_client->get_synced_count();

        echo '<h2>' . esc_html__('Sync Overview', 'ai-vector-search-semantic') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<tbody>';
        echo '<tr><td><strong>WooCommerce Products</strong></td><td>' . number_format($total_products) . '</td></tr>';
        echo '<tr><td><strong>Synced to Supabase</strong></td><td>' . number_format($synced_count) . '</td></tr>';
        echo '<tr><td><strong>Sync Status</strong></td><td>';

        if ($synced_count == 0) {
            echo '❌ No products synced';
        } elseif ($synced_count >= $total_products) {
            echo '✅ All products synced';
        } else {
            $percent = round(($synced_count / $total_products) * 100, 1);
            printf(
                esc_html__('⚠️ %1$s%% synced (%2$d/%3$d)', 'ai-vector-search-semantic'),
                esc_html($percent),
                absint($synced_count),
                absint($total_products)
            );
        }

        echo '</td></tr>';
        echo '</tbody></table>';
    }

    private function render_sync_actions() {
        echo '<h2>' . esc_html__('Sync Actions', 'ai-vector-search-semantic') . '</h2>';

        // Full sync
        echo '<div class="card" style="max-width: 600px;">';
        echo '<h3>🔄 Full Sync</h3>';
        echo '<p>Sync all WooCommerce products to Supabase. This may take a while for large catalogs.</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_all">';
        echo '<button type="submit" class="button button-primary" onclick="return confirm(\'This will sync all products. Continue?\')">Sync All Products</button>';
        echo '</form>';
        echo '</div>';

        // Batch sync
        echo '<div class="card" style="max-width: 600px; margin-top: 20px;">';
        echo '<h3>⚡ Batch Sync</h3>';
        echo '<p>Sync products in smaller batches to avoid timeouts.</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_batch">';
        echo '<label>Batch Size: <input type="number" name="batch_size" value="50" min="1" max="200" style="width:60px;"></label> ';
        echo '<label>Offset: <input type="number" name="offset" value="0" min="0" style="width:60px;"></label> ';
        echo '<button type="submit" class="button">Sync Batch</button>';
        echo '</form>';
        echo '</div>';

        // Embeddings generation
        if (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai')) {
            echo '<div class="card" style="max-width: 600px; margin-top: 20px;">';
            echo '<h3>🧠 Generate Embeddings</h3>';
            echo '<p>Generate or update OpenAI embeddings for products that don\'t have them.</p>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('aivesese_sync');
            echo '<input type="hidden" name="action" value="generate_embeddings">';
            echo '<button type="submit" class="button button-secondary">Generate Missing Embeddings</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '<div id="sync-status" style="margin-top: 20px;"></div>';
    }

    private function render_help_section() {
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

    private function render_setup_instructions() {
        echo '<div class="notice notice-info inline" style="margin: 10px 0; padding: 10px;">';
        echo '<p><strong>🆕 SQL Updated!</strong> New version includes partial SKU search and Woodmart integration support.</p>';
        echo '</div>';

        echo '<h2>' . esc_html__('How to find your Supabase credentials:', 'ai-vector-search-semantic') . '</h2>';
        echo '<ol>';
        echo '<li>' . sprintf(
            esc_html__('Go to your Supabase project dashboard at %s.', 'ai-vector-search-semantic'),
            '<a href="https://app.supabase.io/" target="_blank">https://app.supabase.io/</a>'
        ) . '</li>';
        echo '<li>' . esc_html__('Navigate to "Project Settings" > "API".', 'ai-vector-search-semantic') . '</li>';
        echo '<li>' . esc_html__('Your Supabase URL is the "URL" value (e.g., https://xyz.supabase.co).', 'ai-vector-search-semantic') . '</li>';
        echo '<li>' . esc_html__('Your Supabase service role key or anon key can be found under "Project API keys".', 'ai-vector-search-semantic') . '</li>';
        echo '</ol>';

        echo '<h2>' . esc_html__('How to find your OpenAI API key:', 'ai-vector-search-semantic') . '</h2>';
        echo '<p>' . sprintf(
            esc_html__('If you enable semantic search, you will need an OpenAI API key from %s.', 'ai-vector-search-semantic'),
            '<a href="https://beta.openai.com/account/api-keys" target="_blank">OpenAI website</a>'
        ) . '</p>';
    }

    private function render_sql_section() {
        $sql_content = $this->get_sql_content();

        echo '<hr>';
        echo '<div class="notice notice-success inline" style="margin: 15px 0; padding: 12px; border-left: 4px solid #46b450;">';
        echo '<h3 style="margin-top: 0;">🎉 Enhanced SQL Schema (v2.0)</h3>';
        echo '<p><strong>New in this version:</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>✨ <code>sku_search()</code> function for partial SKU matching</li>';
        echo '<li>🔍 Enhanced <code>fts_search()</code> with better ranking</li>';
        echo '<li>🚀 Optimized indexes for faster search performance</li>';
        echo '</ul>';
        echo '</div>';

        echo '<h2>' . esc_html__('Install/Update the SQL in Supabase', 'ai-vector-search-semantic') . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__('Open your Supabase project → SQL Editor → New query.', 'ai-vector-search-semantic') . '</li>';
        echo '<li>' . esc_html__('Click "Copy SQL" below and paste it into the editor.', 'ai-vector-search-semantic') . '</li>';
        echo '<li>' . esc_html__('Press RUN and wait for success.', 'ai-vector-search-semantic') . '</li>';
        echo '<li><strong>' . esc_html__('✅ Safe to re-run: This SQL uses CREATE OR REPLACE and IF NOT EXISTS.', 'ai-vector-search-semantic') . '</strong></li>';
        echo '</ol>';

        if (!$sql_content) {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Could not find supabase.sql. Place it at assets/sql/supabase.sql (recommended).', 'ai-vector-search-semantic') .
                '</p></div>';
        } else {
            echo '<p><button class="button button-primary" id="ai-copy-sql">📋 Copy Updated SQL</button> ';
            echo '<small style="opacity:.75;margin-left:.5rem">' .
                esc_html__('Paste into Supabase → SQL Editor and run it.', 'ai-vector-search-semantic') .
                '</small></p>';
            echo '<textarea id="ai-sql" rows="22" style="width:100%;font-family:Menlo,Consolas,monospace;border:2px solid #46b450;" readonly>' .
                esc_textarea($sql_content) .
                '</textarea>';
            echo '<p id="ai-copy-status" style="display:none;margin-top:.5rem;"></p>';
        }
    }

    private function get_sql_content(): string {
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

    public function enqueue_admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, ['aivesese', 'aivesese-status', 'aivesese-sync'], true)) {
            return;
        }

        $this->enqueue_help_script();
        $this->enqueue_admin_styles();
        $this->enqueue_admin_scripts();
    }

    private function enqueue_help_script() {
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

    private function enqueue_admin_styles() {
        wp_register_style('aivesese-admin', false, [], AIVESESE_PLUGIN_VERSION);
        wp_enqueue_style('aivesese-admin');

        $admin_css = $this->get_admin_css();
        wp_add_inline_style('aivesese-admin', $admin_css);
    }

    private function enqueue_admin_scripts() {
        wp_register_script('aivesese-admin', false, [], AIVESESE_PLUGIN_VERSION, true);
        wp_enqueue_script('aivesese-admin');

        $copy_script = $this->get_copy_sql_script();
        $submit_script = $this->get_submit_spinner_script();

        wp_add_inline_script('aivesese-admin', $copy_script);
        wp_add_inline_script('aivesese-admin', $submit_script);
    }

    public function show_services_banner() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed_screens = [
            'settings_page_aivesese',
            'settings_page_aivesese-status',
            'settings_page_aivesese-sync'
        ];

        if (!$screen || !in_array($screen->id, $allowed_screens, true)) {
            return;
        }

        echo '<div class="notice notice-success aivesese-services-banner" style="
            display:flex;align-items:center;gap:16px;
            border-left:6px solid #673ab7;
            background:#f5f3ff;padding:14px 18px;margin-top:16px;">
                <div style="font-size:24px;">🚀</div>
                <div style="flex:1 1 auto;">
                    <strong>Need a hand with AI search or Supabase?</strong><br>
                    Our team at <em>ZZZ Solutions</em> can install, customise and tune everything for you.
                </div>
                <a href="https://zzzsolutions.ro" target="_blank" rel="noopener noreferrer"
                   class="button button-primary">See Services</a>
            </div>';
    }

    public function handle_help_toggle() {
        check_ajax_referer('aivesese_help_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        $open = (isset($_POST['open']) && wp_unslash($_POST['open']) === '1') ? '1' : '0';
        update_user_meta(get_current_user_id(), '_aivesese_help_open', $open);

        wp_send_json_success(['open' => $open]);
    }

    // Asset content methods
    private function get_admin_css(): string {
        return trim("
            .ai-supabase-help details { border:1px solid #dcdcde; border-radius:6px; background:#fff; }
            .ai-supabase-help__summary { padding:12px 14px; cursor:pointer; list-style:none; }
            .ai-supabase-help__summary::-webkit-details-marker { display:none; }
            .ai-supabase-help__summary:after { content:'▾'; float:right; transition:transform .2s ease; }
            .ai-supabase-help details[open] .ai-supabase-help__summary:after { transform:rotate(180deg); }
            .ai-supabase-help details > *:not(.ai-supabase-help__summary) { padding:0 14px 14px; }
            .ai-supabase-help__hint { color:#646970; font-weight:400; margin-left:8px; }
        ");
    }

    private function get_help_toggle_script(): string {
        return "(function(){
            var el = document.getElementById('ai-supabase-help-details');
            if (!el) return;
            el.addEventListener('toggle', function(){
                var body = new FormData();
                body.append('action', 'aivesese_toggle_help');
                body.append('open', el.open ? '1' : '0');
                body.append('nonce', window.AISupabaseHelp.nonce);
                fetch(window.AISupabaseHelp.ajax_url, { method:'POST', credentials:'same-origin', body: body });
            }, { passive: true });
        })();";
    }

    private function get_copy_sql_script(): string {
        return "(function(){
            var btn = document.getElementById('ai-copy-sql');
            var ta = document.getElementById('ai-sql');
            var out = document.getElementById('ai-copy-status');
            if (!btn || !ta) return;
            btn.addEventListener('click', async function(){
                try {
                    await navigator.clipboard.writeText(ta.value);
                    if(out){ out.textContent = 'SQL copied to clipboard.'; out.style.display='block'; out.style.color='green'; }
                } catch(e){
                    ta.select(); document.execCommand('copy');
                    if(out){ out.textContent = 'Copied using fallback.'; out.style.display='block'; out.style.color='green'; }
                }
            }, { passive:true });
        })();";
    }

    private function get_submit_spinner_script(): string {
        return "document.addEventListener('DOMContentLoaded', function(){
            var forms = document.querySelectorAll('form');
            forms.forEach(function(form){
                form.addEventListener('submit', function(){
                    var button = form.querySelector('button[type=submit]');
                    if (button) { button.innerHTML = 'Processing...'; button.disabled = true; }

                    var statusDiv = document.getElementById('sync-status');
                    if (statusDiv) {
                        statusDiv.innerHTML = '<div class=\"notice notice-info\"><p>⏳ Processing... Please wait.</p></div>';
                    }
                });
            });
        });";
    }

    public function show_sql_update_notice() {
        if (!current_user_can('manage_options') || get_option('aivesese_sql_v2_dismissed')) {
            return;
        }

        // Only show on relevant admin pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed_screens = [
            'settings_page_aivesese',
            'settings_page_aivesese-status',
            'settings_page_aivesese-sync',
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
        echo '<li>Go to <a href="' . esc_url(admin_url('options-general.php?page=aivesese')) . '"><strong>Settings → AI Supabase</strong></a></li>';
        echo '<li>Expand the <strong>"Setup Guide"</strong> section</li>';
        echo '<li>Copy the updated SQL and run it in <strong>Supabase → SQL Editor</strong></li>';
        echo '<li>The new functions will be added/updated automatically</li>';
        echo '</ol>';
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('options-general.php?page=aivesese')) . '" class="button button-primary">Update SQL Now</a> ';
        echo '<a href="' .
            esc_url(wp_nonce_url(add_query_arg('aivesese_sql_v2_dismiss', 1), 'aivesese_sql_v2_nonce')) .
            '" class="button">I\'ve Updated It</a>';
        echo '</p>';
        echo '</div>';
    }

    public function handle_sql_update_dismiss() {
        if (isset($_GET['aivesese_sql_v2_dismiss']) && check_admin_referer('aivesese_sql_v2_nonce')) {
            update_option('aivesese_sql_v2_dismissed', time());
            wp_safe_redirect(remove_query_arg(['aivesese_sql_v2_dismiss', '_wpnonce']));
            exit;
        }
    }
}
