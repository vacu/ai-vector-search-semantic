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
        add_action('wp_ajax_aivesese_postgres_install_schema', [$this, 'handle_postgres_install_schema']);
        add_action('wp_ajax_aivesese_postgres_check_status', [$this, 'handle_postgres_check_status']);
    }

    /**
     * Enhanced register_settings with PostgreSQL connection string
     */
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

            // NEW: PostgreSQL connection string for WP-CLI
            'postgres_connection_string' => 'PostgreSQL Connection String (for WP-CLI schema installation)',

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
            'postgres_connection_string' => 'aivesese_passthru', // Will be encrypted
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
                <div class="api-service-preview">
                    <h5>🚀 Managed API Service (Coming Soon!)</h5>
                    <p><em>We're working on a hosted service that will eliminate setup complexity.
                    <!-- <a href="https://zzzsolutions.ro" target="_blank">Join our waitlist</a> to be notified when it's ready!</em></p> -->
                </div>
                <!-- <div class="option-card">
                    <h4>🚀 Managed API Service</h4>
                    <p>Use our hosted service with your license key. No setup required!</p>
                    <ul>
                        <li>✅ No database setup needed</li>
                        <li>✅ Automatic updates and maintenance</li>
                        <li>✅ Professional support included</li>
                        <li>✅ Guaranteed uptime and performance</li>
                    </ul>
                    <small><strong>Starts at $29/month</strong></small>
                </div> -->
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

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const radios = document.querySelectorAll('input[name="aivesese_connection_mode"]');
            const toggleFields = function() {
                const mode = document.querySelector('input[name="aivesese_connection_mode"]:checked').value;

                // Toggle license key field
                const licenseRow = document.querySelector('#aivesese_license_key').closest('tr');
                if (licenseRow) {
                    licenseRow.style.display = mode === 'api' ? 'table-row' : 'none';
                }

                // Toggle self-hosted fields
                const selfHostedFields = ['aivesese_url', 'aivesese_key', 'aivesese_store', 'aivesese_openai'];
                selfHostedFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        const row = field.closest('tr');
                        if (row) {
                            row.style.display = mode === 'self_hosted' ? 'table-row' : 'none';
                        }
                    }
                });

                // Show/hide help sections
                const helpSections = document.querySelectorAll('.ai-supabase-help');
                helpSections.forEach(section => {
                    section.style.display = mode === 'self_hosted' ? 'block' : 'none';
                });
            };

            radios.forEach(radio => radio.addEventListener('change', toggleFields));
            setTimeout(toggleFields, 100); // Initial toggle with delay
        });
        </script>
        <?php
    }

    public function render_license_key_field() {
        $value = get_option('aivesese_license_key');
        $is_activated = !empty($value) && get_option('aivesese_api_activated') === '1';
        ?>
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
                document.getElementById('aivesese_license_key').value = '';
                document.querySelector('form').submit();
            }
        }
        </script>
        <?php
    }

    public function render_text_field($args) {
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
     * Enhanced settings page with custom field rendering
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
        }

        // Show help section only for self-hosted mode
        if ($connection_mode === 'self_hosted') {
            $this->render_help_section();
        }

        echo '<form method="post" action="options.php">';
        settings_fields('aivesese_settings');
        do_settings_sections('aivesese');
        submit_button();
        echo '</form>';

        echo '</div>';

        // Add styles
        $this->add_admin_styles();
    }

    private function add_admin_styles() {
        ?>
        <style>
            .api-service-preview {
                background: rgba(255,255,255,0.7);
                border: 1px dashed #666;
                border-radius: 6px;
                padding: 15px;
                margin-top: 20px;
            }
            .api-service-preview h5 {
                margin: 0 0 8px 0;
                color: #666;
            }
            .api-service-preview p {
                margin: 0;
                color: #666;
                font-style: italic;
            }
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

        /* License field styles */
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
        <?php
    }

    /**
     * Add JavaScript for showing/hiding conditional fields
     */
    private function add_conditional_field_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const radios = document.querySelectorAll('input[name="aivesese_connection_mode"]');

            function toggleFields() {
                const mode = document.querySelector('input[name="aivesese_connection_mode"]:checked');
                if (!mode) return;

                const selectedMode = mode.value;

                // Show/hide API fields
                const apiFields = document.querySelectorAll('.api-field');
                apiFields.forEach(field => {
                    field.style.display = selectedMode === 'api' ? 'table-row' : 'none';
                });

                // Show/hide self-hosted fields
                const selfHostedFields = document.querySelectorAll('.self-hosted-field');
                selfHostedFields.forEach(field => {
                    field.style.display = selectedMode === 'self_hosted' ? 'table-row' : 'none';
                });

                // Show/hide help sections
                const helpSections = document.querySelectorAll('.ai-supabase-help');
                helpSections.forEach(section => {
                    section.style.display = selectedMode === 'self_hosted' ? 'block' : 'none';
                });
            }

            // Initial toggle
            setTimeout(toggleFields, 100); // Small delay to ensure DOM is ready

            // Toggle on change
            radios.forEach(radio => {
                radio.addEventListener('change', toggleFields);
            });
        });
        </script>

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
        <?php
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
        echo '<div class="setup-flow">';

        // Step 1: Supabase Setup
        echo '<div class="setup-step">';
        echo '<h3>🚀 Step 1: Create Your Supabase Project</h3>';
        echo '<ol>';
        echo '<li>Go to <a href="https://app.supabase.io/" target="_blank" rel="noopener">supabase.com</a> and create a free account</li>';
        echo '<li>Click "New Project" and choose your organization</li>';
        echo '<li>Set a project name and strong database password</li>';
        echo '<li>Choose a region close to your users</li>';
        echo '<li>Wait 2-3 minutes for project setup to complete</li>';
        echo '</ol>';
        echo '</div>';

        // Step 2: Get Credentials
        echo '<div class="setup-step">';
        echo '<h3>🔑 Step 2: Get Your API Credentials</h3>';
        echo '<ol>';
        echo '<li>In your Supabase project, go to <strong>Settings → API</strong></li>';
        echo '<li>Copy your <strong>Project URL</strong> (looks like: <code>https://xyz.supabase.co</code>)</li>';
        echo '<li>Copy your <strong>service_role</strong> key from "Project API keys" section</li>';
        echo '<li>Paste both values in the configuration form above ⬆️</li>';
        echo '</ol>';
        echo '</div>';

        // Step 3: PostgreSQL Connection (NEW)
        echo '<div class="setup-step">';
        echo '<h3>🔗 Step 3: Get PostgreSQL Connection String (for WP-CLI)</h3>';
        echo '<ol>';
        echo '<li>In your Supabase project, go to <strong>Settings → Database</strong></li>';
        echo '<li>Scroll down to <strong>"Connection parameters"</strong></li>';
        echo '<li>Copy the <strong>"Connection string"</strong> in URI format</li>';
        echo '<li>Paste it in the PostgreSQL Connection String field above ⬆️</li>';
        echo '</ol>';
        echo '<div class="notice notice-info inline" style="margin: 15px 0;">';
        echo '<p><strong>💡 Why PostgreSQL connection?</strong> This enables professional WP-CLI commands for reliable schema installation:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><code>wp aivs install-schema</code> - One-command schema installation</li>';
        echo '<li><code>wp aivs sync-products</code> - Bulk product synchronization</li>';
        echo '<li><code>wp aivs check-schema</code> - Comprehensive status checking</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';

        // Step 4: OpenAI (Optional)
        echo '<div class="setup-step">';
        echo '<h3>🤖 Step 4: OpenAI Setup (Optional)</h3>';
        echo '<p>For AI semantic search, you\'ll need an OpenAI API key:</p>';
        echo '<ol>';
        echo '<li>Visit <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI API Keys</a></li>';
        echo '<li>Create a new API key</li>';
        echo '<li>Add billing information (required for API usage)</li>';
        echo '<li>Paste the key in the OpenAI field above ⬆️</li>';
        echo '</ol>';
        echo '<div class="notice notice-warning inline" style="margin: 15px 0;">';
        echo '<p><strong>💰 Cost:</strong> Embeddings cost ~$0.05-$1.00 per 1,000 products (one-time setup cost)</p>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // close setup-flow

        // Add some styling
        echo '<style>
        .setup-flow {
            margin: 20px 0;
        }
        .setup-step {
            margin: 25px 0;
            padding: 20px;
            background: #fafafa;
            border-left: 4px solid #0073aa;
            border-radius: 0 8px 8px 0;
        }
        .setup-step h3 {
            margin-top: 0;
            color: #0073aa;
        }
        .setup-step code {
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid #ddd;
        }
        </style>';
    }

    private function render_sql_section() {
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');

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

        $this->add_postgres_installation_scripts();
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

    /**
     * Render PostgreSQL connection string field
     */
    public function render_postgres_connection_field() {
        $connection_mode = get_option('aivesese_connection_mode', 'self_hosted');
        $value = get_option('aivesese_postgres_connection_string');
        $has_value = !empty($value);

        if ($connection_mode !== 'self_hosted') {
            echo '<p><em>PostgreSQL connection is only needed for self-hosted mode.</em></p>';
            return;
        }

        echo '<div class="postgres-connection-field">';

        if ($has_value) {
            echo '<div class="connection-status configured">';
            echo '<span class="dashicons dashicons-yes-alt"></span>';
            echo '<strong>PostgreSQL Connection Configured</strong>';
            echo '<p>Connection string is securely stored and ready for WP-CLI commands.</p>';
            echo '<button type="button" class="button" onclick="toggleConnectionString()">Update Connection String</button>';
            echo '</div>';
        }

        echo '<div id="connection-string-input" style="' . ($has_value ? 'display: none;' : '') . '">';
        echo '<textarea id="aivesese_postgres_connection_string" name="aivesese_postgres_connection_string" ';
        echo 'rows="3" cols="80" class="large-text" placeholder="postgresql://username:password@hostname:port/database">';
        echo esc_textarea($value);
        echo '</textarea>';

        echo '<div class="postgres-connection-help">';
        echo '<h4>🔗 How to get your PostgreSQL connection string:</h4>';
        echo '<ol>';
        echo '<li>Go to your Supabase project → <strong>Settings</strong> → <strong>Database</strong></li>';
        echo '<li>Scroll down to <strong>"Connection parameters"</strong> or <strong>"Connection pooling"</strong></li>';
        echo '<li>Copy the <strong>"Connection string"</strong> (URI format)</li>';
        echo '<li>Make sure to use the <strong>direct connection</strong> (not pooled) for schema operations</li>';
        echo '</ol>';

        echo '<div class="connection-string-examples">';
        echo '<p><strong>📝 Example format:</strong></p>';
        echo '<code>postgresql://postgres.abcdefgh:[YOUR-PASSWORD]@aws-0-us-east-1.pooler.supabase.com:5432/postgres</code>';
        echo '</div>';

        echo '<div class="security-note">';
        echo '<p><strong>🔒 Security:</strong> This connection string will be encrypted and stored securely in your WordPress database.</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // WP-CLI Command Info
        echo '<div class="wp-cli-info">';
        echo '<h4>⚡ WP-CLI Schema Installation</h4>';
        echo '<p>Once configured, you can install/update your schema with one command:</p>';
        echo '<div class="cli-command-box">';
        echo '<code>wp aivs install-schema</code>';
        echo '<button type="button" class="button button-small" onclick="copyCliCommand()">Copy Command</button>';
        echo '</div>';

        echo '<p><strong>Available WP-CLI commands:</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li><code>wp aivs install-schema</code> - Install/update database schema</li>';
        echo '<li><code>wp aivs check-schema</code> - Check schema status</li>';
        echo '<li><code>wp aivs test-connection</code> - Test database connection</li>';
        echo '<li><code>wp aivs sync-products</code> - Sync WooCommerce products</li>';
        echo '</ul>';

        echo '<p><em>💡 WP-CLI provides a reliable, professional way to manage database schema installations.</em></p>';
        echo '</div>';

        echo '</div>'; // close postgres-connection-field

        $this->add_postgres_connection_scripts();
    }

    /**
     * Add JavaScript for PostgreSQL connection field
     */
    private function add_postgres_connection_scripts() {
        ?>
        <script>
        function toggleConnectionString() {
            const input = document.getElementById('connection-string-input');
            const status = document.querySelector('.connection-status');

            if (input.style.display === 'none') {
                input.style.display = 'block';
                status.style.display = 'none';
            } else {
                input.style.display = 'none';
                status.style.display = 'block';
            }
        }

        function copyCliCommand() {
            const command = 'wp aivs install-schema';
            navigator.clipboard.writeText(command).then(function() {
                alert('✅ WP-CLI command copied to clipboard!');
            }).catch(function() {
                // Fallback
                const textArea = document.createElement('textarea');
                textArea.value = command;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('✅ WP-CLI command copied to clipboard!');
            });
        }
        </script>

        <style>
        .postgres-connection-field {
            max-width: 800px;
            margin: 20px 0;
        }

        .connection-status {
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #46b450;
            background: #f0f9ff;
            margin-bottom: 15px;
        }

        .connection-status .dashicons {
            color: #46b450;
            margin-right: 8px;
        }

        .postgres-connection-help {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
        }

        .postgres-connection-help h4 {
            margin-top: 0;
            color: #0073aa;
        }

        .connection-string-examples {
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border-left: 3px solid #0073aa;
        }

        .connection-string-examples code {
            display: block;
            word-break: break-all;
            font-size: 12px;
            color: #d63638;
        }

        .security-note {
            background: #fff3cd;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            border-left: 3px solid #ffc107;
        }

        .wp-cli-info {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 6px;
            margin-top: 20px;
            border-left: 4px solid #0073aa;
        }

        .wp-cli-info h4 {
            margin-top: 0;
            color: #0073aa;
        }

        .cli-command-box {
            background: #23282d;
            color: #f1f1f1;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .cli-command-box code {
            background: none;
            color: #46b450;
            font-size: 16px;
            flex: 1;
            font-weight: bold;
        }

        .wp-cli-info ul {
            background: rgba(255,255,255,0.7);
            padding: 15px 20px;
            border-radius: 4px;
        }

        .wp-cli-info li {
            margin: 8px 0;
            font-family: monospace;
        }
        </style>
        <?php
    }

    /**
     * Handle PostgreSQL schema installation via AJAX
     */
    public function handle_postgres_install_schema() {
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
    public function handle_postgres_check_status() {
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
     * Render installation options (PostgreSQL + Manual)
     */
    private function render_installation_options(array $migration_status) {
        echo '<h2>🗄️ Database Schema Installation</h2>';

        // Show current status if already installed
        $installed_time = get_option('aivesese_schema_installed');
        $install_method = get_option('aivesese_schema_install_method', 'unknown');

        if ($installed_time) {
            echo '<div class="notice notice-success inline">';
            echo '<h3>✅ Schema Already Installed</h3>';
            echo '<p>Installed on <strong>' . date('M j, Y \a\t g:i A', $installed_time) . '</strong>';
            if ($install_method) {
                echo ' via <strong>' . esc_html($install_method) . '</strong>';
            }
            echo '</p>';
            echo '<p>';
            echo '<button type="button" class="button" id="postgres-reinstall-btn">Update Schema</button> ';
            echo '<button type="button" class="button button-small" id="postgres-check-status-btn">Check Status</button>';
            echo '</p>';
            echo '</div>';
        }

        // Installation options
        echo '<div class="installation-options">';

        // Option 1: PostgreSQL Direct Installation
        if ($migration_status['can_run']) {
            $this->render_postgres_installation_option();
        } else {
            $this->render_postgres_installation_unavailable($migration_status);
        }

        // Option 2: Manual Installation
        $this->render_manual_installation_option();

        echo '</div>';
    }

    /**
     * Render PostgreSQL installation option (available)
     */
    private function render_postgres_installation_option() {
        echo '<div class="installation-option postgres-option">';
        echo '<h3>🚀 Direct PostgreSQL Installation (Recommended)</h3>';
        echo '<p>Install schema directly via PostgreSQL connection - fastest and most reliable method.</p>';

        echo '<div class="postgres-benefits">';
        echo '<ul>';
        echo '<li>✅ <strong>One-click installation</strong> - No copy/paste needed</li>';
        echo '<li>✅ <strong>Transactional safety</strong> - Automatic rollback on errors</li>';
        echo '<li>✅ <strong>Real-time feedback</strong> - See exactly what happens</li>';
        echo '<li>✅ <strong>Professional grade</strong> - Same method used by WP-CLI</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="postgres-action">';
        echo '<button type="button" class="button button-primary button-large" id="postgres-install-btn">';
        echo '<span class="dashicons dashicons-database" style="margin-right: 8px;"></span>';
        echo 'Install Schema via PostgreSQL';
        echo '</button>';
        echo '</div>';

        echo '<div id="postgres-installation-progress" style="display: none; margin: 20px 0;">';
        echo '<div class="progress-bar" style="background: #f0f0f0; height: 20px; border-radius: 10px; overflow: hidden; margin: 10px 0;">';
        echo '<div class="progress-fill" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease;"></div>';
        echo '</div>';
        echo '<div class="progress-text" style="font-style: italic; color: #666;">Preparing installation...</div>';
        echo '</div>';

        echo '<div id="postgres-installation-result" style="margin-top: 20px;"></div>';

        echo '</div>'; // close postgres-option
    }

    /**
     * Render PostgreSQL installation unavailable notice
     */
    private function render_postgres_installation_unavailable(array $status) {
        echo '<div class="installation-option postgres-unavailable">';
        echo '<h3>🚀 Direct PostgreSQL Installation</h3>';
        echo '<div class="notice notice-warning inline">';
        echo '<p><strong>⚠️ PostgreSQL installation not available</strong></p>';

        echo '<div class="requirements-check">';
        echo '<h4>Requirements Status:</h4>';
        echo '<ul>';

        foreach ($status['requirements'] as $requirement => $met) {
            $icon = $met ? '✅' : '❌';
            $req_name = ucwords(str_replace('_', ' ', $requirement));
            echo "<li>{$icon} {$req_name}</li>";
        }

        echo '</ul>';
        echo '</div>';

        if (!$status['requirements']['psql_command']) {
            echo '<div class="psql-install-help">';
            echo '<p><strong>To enable PostgreSQL installation:</strong></p>';
            echo '<ol>';
            echo '<li>Install PostgreSQL client on your server:</li>';
            echo '<ul style="margin-left: 20px;">';
            echo '<li><strong>Ubuntu/Debian:</strong> <code>sudo apt-get install postgresql-client</code></li>';
            echo '<li><strong>CentOS/RHEL:</strong> <code>sudo yum install postgresql</code></li>';
            echo '<li><strong>Alpine:</strong> <code>apk add postgresql-client</code></li>';
            echo '</ul>';
            echo '<li>Configure PostgreSQL connection string above</li>';
            echo '<li>Refresh this page</li>';
            echo '</ol>';
            echo '</div>';
        }

        if (!$status['requirements']['connection_string']) {
            echo '<p><strong>Missing:</strong> Configure your PostgreSQL connection string in the field above.</p>';
        }

        echo '</div>';
        echo '</div>'; // close postgres-unavailable
    }

    /**
     * Render manual installation option
     */
    private function render_manual_installation_option() {
        echo '<div class="installation-option manual-option">';
        echo '<h3>📝 Manual Installation</h3>';
        echo '<p>Copy the SQL and run it manually in Supabase SQL Editor - always available as fallback.</p>';

        echo '<div class="manual-benefits">';
        echo '<ul>';
        echo '<li>✅ <strong>Always works</strong> - No server requirements</li>';
        echo '<li>✅ <strong>Full control</strong> - See exactly what gets executed</li>';
        echo '<li>✅ <strong>Educational</strong> - Learn the database structure</li>';
        echo '<li>✅ <strong>Universal</strong> - Works on any hosting environment</li>';
        echo '</ul>';
        echo '</div>';

        echo '<details>';
        echo '<summary class="manual-toggle"><strong>Show Manual Installation</strong></summary>';
        echo '<div class="manual-content" style="margin-top: 15px;">';

        $this->render_manual_installation_steps();

        echo '</div>';
        echo '</details>';

        echo '</div>'; // close manual-option
    }

    /**
     * Render manual installation steps
     */
    private function render_manual_installation_steps() {
        echo '<div class="manual-steps">';
        echo '<h4>📋 Manual Installation Steps:</h4>';
        echo '<ol>';
        echo '<li>Open your Supabase project → <strong>SQL Editor</strong> → <strong>New query</strong></li>';
        echo '<li>Click "Copy SQL" below and paste it into the editor</li>';
        echo '<li>Press <strong>RUN</strong> and wait for success</li>';
        echo '<li>✅ Safe to re-run: Uses CREATE OR REPLACE and IF NOT EXISTS</li>';
        echo '</ol>';
        echo '</div>';

        $sql_content = $this->get_sql_content();
        if ($sql_content) {
            echo '<p style="margin: 20px 0;">';
            echo '<button class="button button-secondary" id="copy-manual-sql-btn">';
            echo '<span class="dashicons dashicons-clipboard" style="margin-right: 5px;"></span>';
            echo 'Copy SQL for Manual Installation';
            echo '</button>';
            echo '</p>';

            echo '<textarea id="manual-sql-content" rows="12" style="width:100%; font-family:Consolas,Monaco,monospace; font-size:12px; border:2px solid #ddd; background:#f9f9f9;" readonly>' .
                esc_textarea($sql_content) . '</textarea>';
            echo '<p id="manual-copy-status" style="display:none; margin-top: 10px;"></p>';
        } else {
            echo '<div class="notice notice-error inline">';
            echo '<p><strong>❌ SQL file not found</strong></p>';
            echo '<p>Expected location: <code>' . AIVESESE_PLUGIN_PATH . 'supabase.sql</code></p>';
            echo '</div>';
        }
    }

    /**
     * Add PostgreSQL installation JavaScript
     */
    private function add_postgres_installation_scripts() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const installBtn = document.getElementById('postgres-install-btn');
            const reinstallBtn = document.getElementById('postgres-reinstall-btn');
            const checkStatusBtn = document.getElementById('postgres-check-status-btn');
            const progressDiv = document.getElementById('postgres-installation-progress');
            const progressFill = document.querySelector('#postgres-installation-progress .progress-fill');
            const progressText = document.querySelector('#postgres-installation-progress .progress-text');
            const resultDiv = document.getElementById('postgres-installation-result');
            const copyManualBtn = document.getElementById('copy-manual-sql-btn');

            // PostgreSQL Installation Handler
            function handlePostgresInstallation(isReinstall = false) {
                const btn = isReinstall ? reinstallBtn : installBtn;
                if (!btn) return;

                const originalHTML = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> ' + (isReinstall ? 'Updating...' : 'Installing...');

                // Show progress
                if (progressDiv) {
                    progressDiv.style.display = 'block';
                    if (progressFill) progressFill.style.width = '20%';
                    if (progressText) progressText.textContent = 'Connecting to PostgreSQL database...';
                }

                if (resultDiv) {
                    resultDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Installing schema via PostgreSQL connection...</p></div>';
                }

                // Progress simulation
                let progress = 20;
                const progressInterval = setInterval(() => {
                    if (progress < 90) {
                        progress += Math.random() * 15;
                        if (progressFill) progressFill.style.width = Math.min(progress, 90) + '%';

                        if (progress > 40 && progress < 60 && progressText) {
                            progressText.textContent = 'Executing SQL schema...';
                        } else if (progress > 60 && progress < 80 && progressText) {
                            progressText.textContent = 'Creating functions and triggers...';
                        }
                    }
                }, 800);

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'aivesese_postgres_install_schema',
                        nonce: '<?php echo wp_create_nonce('aivesese_postgres_install_nonce'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    clearInterval(progressInterval);

                    if (progressFill) {
                        progressFill.style.width = '100%';
                        progressFill.style.background = data.success ? '#46b450' : '#dc3232';
                    }

                    if (progressText) {
                        progressText.textContent = data.success ? 'Installation completed!' : 'Installation failed';
                    }

                    if (data.success) {
                        handleSuccessfulInstallation(data.data);
                    } else {
                        handleFailedInstallation(data.data);
                    }
                })
                .catch(error => {
                    clearInterval(progressInterval);
                    handleInstallationError(error);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;

                    setTimeout(() => {
                        if (progressDiv) progressDiv.style.display = 'none';
                    }, 5000);
                });
            }

            // Success handler
            function handleSuccessfulInstallation(data) {
                if (!resultDiv) return;

                let message = '<div class="notice notice-success">';
                message += '<h4>✅ ' + data.message + '</h4>';

                if (data.details && data.details.stdout) {
                    message += '<details style="margin: 15px 0;"><summary><strong>Installation Details</strong></summary>';
                    message += '<pre style="background: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;">';
                    message += data.details.stdout;
                    message += '</pre></details>';
                }

                message += '<div style="margin: 15px 0; padding: 15px; background: #e8f4fd; border-left: 4px solid #0073aa; border-radius: 0 4px 4px 0;">';
                message += '<h4>🎉 Next Steps:</h4>';
                message += '<ol>';
                message += '<li>✅ Database schema is ready</li>';
                message += '<li>📦 <a href="<?php echo admin_url('options-general.php?page=aivesese-sync'); ?>">Sync your products</a></li>';
                message += '<li>🔍 Test search functionality on your store</li>';
                message += '</ol>';
                message += '</div>';

                message += '</div>';
                resultDiv.innerHTML = message;

                // Refresh page after delay
                setTimeout(() => {
                    window.location.reload();
                }, 8000);
            }

            // Error handler
            function handleFailedInstallation(data) {
                if (!resultDiv) return;

                let message = '<div class="notice notice-error">';
                message += '<h4>❌ Installation Failed</h4>';
                message += '<p><strong>Error:</strong> ' + data.message + '</p>';

                if (data.details) {
                    if (data.details.errors && data.details.errors.length > 0) {
                        message += '<details style="margin: 15px 0;"><summary><strong>Error Details</strong></summary>';
                        message += '<div style="background: #fef2f2; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                        data.details.errors.forEach(error => {
                            message += '<div style="color: #dc3232; margin: 5px 0;">' + error + '</div>';
                        });
                        message += '</div></details>';
                    }

                    if (data.details.suggestions && data.details.suggestions.length > 0) {
                        message += '<div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 0 4px 4px 0;">';
                        message += '<h4>💡 Suggestions:</h4>';
                        message += '<ul>';
                        data.details.suggestions.forEach(suggestion => {
                            message += '<li>' + suggestion + '</li>';
                        });
                        message += '</ul>';
                        message += '</div>';
                    }
                }

                message += '<div style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-radius: 4px;">';
                message += '<h4>🛠️ What to do:</h4>';
                message += '<ol>';
                message += '<li>Check the error details above for specific issues</li>';
                message += '<li>Verify your PostgreSQL connection string is correct</li>';
                message += '<li>Try the manual installation method below</li>';
                message += '<li>Contact support if issues persist</li>';
                message += '</ol>';
                message += '</div>';

                message += '</div>';
                resultDiv.innerHTML = message;
            }

            // Network error handler
            function handleInstallationError(error) {
                if (!resultDiv) return;

                resultDiv.innerHTML = '<div class="notice notice-error">' +
                    '<h4>❌ Connection Error</h4>' +
                    '<p>Unable to communicate with the installation service.</p>' +
                    '<p><strong>Error:</strong> ' + error.message + '</p>' +
                    '<p><strong>Try:</strong> Refresh the page and try again, or use manual installation.</p>' +
                    '</div>';
            }

            // Status check handler
            function handleStatusCheck() {
                const btn = checkStatusBtn;
                const originalHTML = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Checking...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'aivesese_postgres_check_status',
                        nonce: '<?php echo wp_create_nonce('aivesese_postgres_status_nonce'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayStatusResults(data.data);
                    } else {
                        if (resultDiv) {
                            resultDiv.innerHTML = '<div class="notice notice-error"><p>Status check failed: ' + data.data.message + '</p></div>';
                        }
                    }
                })
                .catch(error => {
                    if (resultDiv) {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>Status check failed: ' + error.message + '</p></div>';
                    }
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                });
            }

            // Display status results
            function displayStatusResults(status) {
                if (!resultDiv) return;

                let message = '<div class="notice notice-info">';
                message += '<h4>📊 PostgreSQL Installation Status</h4>';

                message += '<div style="margin: 15px 0;">';
                message += '<p><strong>Installation Ready:</strong> ' + (status.can_run ? '✅ Yes' : '❌ No') + '</p>';

                message += '<h4>Requirements Check:</h4>';
                message += '<ul style="margin-left: 20px;">';
                Object.entries(status.requirements).forEach(([req, met]) => {
                    const icon = met ? '✅' : '❌';
                    const name = req.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    message += '<li>' + icon + ' ' + name + '</li>';
                });
                message += '</ul>';
                message += '</div>';

                if (!status.can_run) {
                    message += '<div style="background: #fff3cd; padding: 15px; border-radius: 4px; margin: 15px 0;">';
                    message += '<p><strong>💡 To enable PostgreSQL installation:</strong></p>';

                    if (!status.requirements.psql_command) {
                        message += '<p>Install PostgreSQL client on your server</p>';
                    }
                    if (!status.requirements.connection_string) {
                        message += '<p>Configure PostgreSQL connection string above</p>';
                    }
                    if (!status.requirements.sql_file) {
                        message += '<p>Ensure supabase.sql file exists in plugin directory</p>';
                    }

                    message += '</div>';
                }

                message += '</div>';
                resultDiv.innerHTML = message;
            }

            // Manual SQL copy handler
            function handleManualCopy() {
                const textarea = document.getElementById('manual-sql-content');
                const statusEl = document.getElementById('manual-copy-status');

                if (!textarea || !statusEl) return;

                navigator.clipboard.writeText(textarea.value).then(() => {
                    statusEl.innerHTML = '<div style="color: #00a32a; background: #f0f9ff; padding: 10px; border-radius: 4px;">' +
                        '✅ SQL copied to clipboard! Paste it in Supabase → SQL Editor and run it.</div>';
                    statusEl.style.display = 'block';
                }).catch(() => {
                    // Fallback
                    textarea.select();
                    document.execCommand('copy');
                    statusEl.innerHTML = '<div style="color: #00a32a; background: #f0f9ff; padding: 10px; border-radius: 4px;">' +
                        '✅ SQL copied to clipboard! Paste it in Supabase → SQL Editor and run it.</div>';
                    statusEl.style.display = 'block';
                });

                setTimeout(() => {
                    statusEl.style.display = 'none';
                }, 8000);
            }

            // Event listeners
            if (installBtn) {
                installBtn.addEventListener('click', () => handlePostgresInstallation(false));
            }

            if (reinstallBtn) {
                reinstallBtn.addEventListener('click', () => handlePostgresInstallation(true));
            }

            if (checkStatusBtn) {
                checkStatusBtn.addEventListener('click', handleStatusCheck);
            }

            if (copyManualBtn) {
                copyManualBtn.addEventListener('click', handleManualCopy);
            }

            // Add spin animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .spin {
                    animation: spin 1s linear infinite;
                    display: inline-block;
                }
            `;
            document.head.appendChild(style);
        });
        </script>

        <style>
        .aivesese-schema-section {
            margin: 20px 0;
        }

        .installation-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin: 30px 0;
        }

        .installation-option {
            border: 2px solid #ddd;
            border-radius: 12px;
            padding: 25px;
            background: #fff;
            transition: all 0.3s ease;
        }

        .installation-option:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .postgres-option {
            border-color: #0073aa;
            background: linear-gradient(135deg, #f0f8ff 0%, #e8f4fd 100%);
        }

        .postgres-unavailable {
            border-color: #ffc107;
            background: #fffbf0;
        }

        .manual-option {
            border-color: #666;
            background: #f9f9f9;
        }

        .installation-option h3 {
            margin-top: 0;
            color: #23282d;
            font-size: 20px;
        }

        .postgres-benefits ul,
        .manual-benefits ul {
            background: rgba(255,255,255,0.8);
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .postgres-benefits li,
        .manual-benefits li {
            margin: 8px 0;
            font-size: 14px;
        }

        .postgres-action {
            text-align: center;
            margin: 25px 0;
        }

        .button-large {
            font-size: 16px;
            padding: 12px 24px;
            height: auto;
        }

        .requirements-check ul {
            background: rgba(255,255,255,0.8);
            padding: 15px 20px;
            border-radius: 6px;
            margin: 15px 0;
        }

        .psql-install-help {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .manual-toggle {
            cursor: pointer;
            padding: 12px 16px;
            background: #f0f0f0;
            border-radius: 6px;
            display: block;
            margin: 15px 0;
            transition: background-color 0.3s ease;
        }

        .manual-toggle:hover {
            background: #e0e0e0;
        }

        .manual-content {
            border-left: 4px solid #666;
            padding-left: 20px;
            margin: 20px 0;
        }

        .manual-steps {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
        }

        #postgres-installation-progress .progress-bar {
            background: #f0f0f0;
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
            margin: 15px 0;
            border: 1px solid #ddd;
        }

        #postgres-installation-progress .progress-fill {
            height: 100%;
            background: linear-gradient(45deg, #0073aa 25%, transparent 25%, transparent 50%, #0073aa 50%, #0073aa 75%, transparent 75%);
            background-size: 20px 20px;
            animation: progress-stripes 1s linear infinite;
            transition: width 0.3s ease;
        }

        @keyframes progress-stripes {
            0% { background-position: 0 0; }
            100% { background-position: 20px 0; }
        }

        #postgres-installation-progress .progress-text {
            font-style: italic;
            color: #666;
            text-align: center;
            margin-top: 10px;
        }
        </style>
        <?php
    }

    /**
     * Validate Supabase connection configuration
     */
    private function validate_supabase_connection(): bool {
        $url = trim(get_option('aivesese_url', ''));
        $key = trim(get_option('aivesese_key', ''));
        $store_id = trim(get_option('aivesese_store', ''));

        return !empty($url) && !empty($key) && !empty($store_id);
    }
}
