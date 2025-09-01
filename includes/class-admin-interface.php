<?php
/**
 * Handles all admin interface functionality
 */
class AIVectorSearch_Admin_Interface {

    private static $instance = null;
    private $supabase_client;
    private $product_sync;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
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
    }

    public function register_settings() {
        $settings = [
            'url' => 'Supabase URL (https://xyz.supabase.co)',
            'key' => 'Supabase service / anon key',
            'store' => 'Store ID (UUID)',
            'openai' => 'OpenAI API key (only if semantic search is enabled)',
            'semantic_toggle' => 'Enable semantic (vector) search',
            'auto_sync' => 'Auto-sync products on save',
            'enable_pdp_similar' => 'PDP "Similar products"',
            'enable_cart_below' => 'Below-cart recommendations',
            'enable_woodmart_integration' => 'Woodmart live search integration',
        ];

        foreach ($settings as $id => $label) {
            $this->register_setting($id);
        }

        add_settings_section('aivesese_section', 'Supabase connection', '__return_false', 'aivesese');
        $this->add_settings_fields();
    }

    private function register_setting(string $id) {
        $sanitizers = [
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

        // Special handling for checkboxes
        if (in_array($id, ['semantic_toggle', 'auto_sync', 'enable_pdp_similar', 'enable_cart_below', 'enable_woodmart_integration'])) {
            $config['sanitize_callback'] = function($v) { return $v === '1' ? '1' : '0'; };
            $config['default'] = $id === 'enable_woodmart_integration' ? '0' : '1'; // Default OFF for Woodmart
        }

        register_setting('aivesese_settings', "aivesese_{$id}", $config);
    }

    private function add_settings_fields() {
        $text_fields = ['url', 'key', 'store', 'openai'];
        $checkbox_fields = [
            'semantic_toggle' => 'Enable semantic (vector) search - Better relevance (needs OpenAI key)',
            'auto_sync' => 'Auto-sync products - Automatically sync products when saved/updated',
            'enable_pdp_similar' => 'PDP "Similar products" - Show similar products on product pages',
            'enable_cart_below' => 'Below-cart recommendations - Show recommendations under cart',
            'enable_woodmart_integration' => 'Woodmart live search integration - Enable AI search for Woodmart AJAX search',
        ];

        foreach ($text_fields as $id) {
            add_settings_field(
                "aivesese_{$id}",
                ucfirst(str_replace('_', ' ', $id)),
                [$this, 'render_text_field'],
                'aivesese',
                'aivesese_section',
                ['field_id' => $id]
            );
        }

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

    public function render_text_field($args) {
        $field_id = $args['field_id'];
        $value = get_option("aivesese_{$field_id}");

        printf(
            '<input type="text" class="regular-text" name="aivesese_%s" value="%s" />',
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

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI Supabase Settings', 'ai-vector-search-semantic') . '</h1>';
        echo '<p>' . esc_html__('Configure the connection to your Supabase project and optionally enable semantic search using OpenAI.', 'ai-vector-search-semantic') . '</p>';

        $this->render_help_section();

        echo '<form method="post" action="options.php">';
        settings_fields('aivesese_settings');
        do_settings_sections('aivesese');
        settings_errors();
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function render_status_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI Supabase Status', 'ai-vector-search-semantic') . '</h1>';

        if (!$this->is_configured()) {
            $this->render_configuration_error();
            echo '</div>';
            return;
        }

        $health = $this->supabase_client->get_store_health();

        if (empty($health)) {
            $this->render_connection_error();
            echo '</div>';
            return;
        }

        $this->render_health_overview($health[0]);
        $this->render_configuration_summary();
        $this->render_quick_actions();
        echo '</div>';
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
