<?php
/**
 * File: includes/class-analytics.php
 * Clean Analytics Class - Properly Integrated with track_click method
 */
class AIVectorSearch_Analytics {

    private static $instance = null;
    private $table_name;
    private $table_name_escaped;
    private $db_version = '1.0';
    private $cache_group = 'aivs_search_analytics';
    private $cache_ttl;
    private $recent_search_cache_ttl;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aivs_search_analytics';
        // Use wpdb->_escape() for table names with backticks for extra safety
        // Since table name is constructed from wpdb->prefix + hardcoded string, it's safe
        // But we validate prefix contains only safe characters
        if (preg_match('/^[a-zA-Z0-9_]+$/', $wpdb->prefix)) {
            $this->table_name_escaped = $this->table_name;
        } else {
            // Fallback: strip unsafe characters
            $safe_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $wpdb->prefix);
            $this->table_name_escaped = $safe_prefix . 'aivs_search_analytics';
        }

        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $this->cache_ttl = 5 * $minute;
        $this->recent_search_cache_ttl = $minute;

        $this->init_hooks();
    }

    private function init_hooks() {
        // Create table on activation
        register_activation_hook(AIVESESE_PLUGIN_PATH . 'ai-supabase-search.php', [$this, 'create_table']);

        add_action('admin_init', [$this, 'check_database_version']);
        add_action('admin_init', [$this, 'handle_clear_analytics_data']);

        // Add admin page
        add_action('admin_menu', [$this, 'add_analytics_page']);

        // Cleanup old data daily
        add_action('aivs_cleanup_analytics', [$this, 'cleanup_old_data']);
        if (!wp_next_scheduled('aivs_cleanup_analytics')) {
            wp_schedule_event(time(), 'daily', 'aivs_cleanup_analytics');
        }

        // AJAX handlers
        add_action('wp_ajax_aivs_preview_search', [$this, 'handle_preview_search']);
        add_action('wp_ajax_aivs_get_live_stats', [$this, 'handle_get_live_stats']);
        add_action('wp_ajax_aivs_track_event', [$this, 'handle_track_event']);
        add_action('wp_ajax_nopriv_aivs_track_event', [$this, 'handle_track_event']);
    }

    public function check_database_version() {
        $installed_version = get_option('aivesese_analytics_db_version', '0');

        if (version_compare($installed_version, $this->db_version, '<')) {
            $this->maybe_update_database();
        }
    }

    private function maybe_update_database() {
        $installed_version = get_option('aivesese_analytics_db_version', '0');

        // If no version recorded or version is old, update database
        if (version_compare($installed_version, $this->db_version, '<')) {
            $this->create_table();
            update_option('aivesese_analytics_db_version', $this->db_version);
        }
    }

    /**
     * Create analytics table
     * @return bool True on success, false on failure
     */
    public function create_table(): bool {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_name_escaped}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            search_term varchar(500) NOT NULL,
            results_found tinyint(1) NOT NULL DEFAULT 0,
            results_count int(11) NOT NULL DEFAULT 0,
            search_type varchar(20) NOT NULL DEFAULT 'fts',
            clicked_result_id bigint(20) NULL,
            user_ip varchar(45) NULL,
            user_agent text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_search_term (search_term),
            KEY idx_created_at (created_at),
            KEY idx_results_found (results_found)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Suppress errors temporarily to check if table creation succeeded
        $wpdb->suppress_errors();
        dbDelta($sql);
        $wpdb->suppress_errors(false);

        // Verify table was created
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));

        if ($table_exists) {
            update_option('aivesese_analytics_db_version', $this->db_version);
            $this->invalidate_cache();
            return true;
        } else {
            // Log error for debugging
            if (function_exists('error_log')) {
                error_log('AIVectorSearch: Failed to create analytics table - ' . $wpdb->last_error);
            }
            return false;
        }
    }

    /**
     * Track a search query
     */
    public function track_search(string $term, string $type, array $results, ?int $clicked_id = null) {
        $normalized_term = sanitize_text_field($term);
        if (strlen($normalized_term) < 2) {
            return; // Skip very short searches
        }

        global $wpdb;

        $user_ip = $this->get_user_ip();
        $user_agent_raw = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        $user_agent = substr(sanitize_text_field($user_agent_raw), 0, 500);

        $data = [
            'search_term' => $normalized_term,
            'results_found' => !empty($results) ? 1 : 0,
            'results_count' => count($results),
            'search_type' => sanitize_text_field($type),
            'clicked_result_id' => $clicked_id !== null ? (int) $clicked_id : null,
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'created_at' => current_time('mysql')
        ];

        $inserted = $wpdb->insert($this->table_name, $data);

        if ($inserted !== false) {
            $this->invalidate_cache();

            if (!empty($wpdb->insert_id)) {
                $this->set_recent_search_cache($normalized_term, $user_ip, (int) $wpdb->insert_id);
            }
        }
    }

    /**
     * Track a click on search results
     */
    public function track_click(string $search_term, int $product_id) {
        $normalized_term = sanitize_text_field($search_term);
        if (strlen($normalized_term) < 2 || !$product_id) {
            return;
        }

        global $wpdb;

        $user_ip = $this->get_user_ip();
        $recent_id = $this->get_recent_search_id($normalized_term, $user_ip);

        if ($recent_id === null) {
            $query = $this->inject_table_name('SELECT id FROM {table}
                WHERE search_term = %s
                AND user_ip = %s
                AND clicked_result_id IS NULL
                ORDER BY created_at DESC
                LIMIT 1');
            $prepared_sql = $wpdb->prepare($query, $normalized_term, $user_ip);

            if ($prepared_sql !== false) {
                $recent_id = (int) $wpdb->get_var($prepared_sql);
                $this->set_recent_search_cache($normalized_term, $user_ip, $recent_id);
            }
        }

        if ($recent_id > 0) {
            $updated = $wpdb->update(
                $this->table_name,
                ['clicked_result_id' => (int) $product_id],
                ['id' => $recent_id]
            );

            if ($updated !== false) {
                $this->invalidate_cache();
                $this->set_recent_search_cache($normalized_term, $user_ip, $recent_id);
            }
        } else {
            // Create new record for the click if no matching search found
            $this->track_search($normalized_term, 'click', [$product_id], $product_id);
        }
    }

    /**
     * Delete all analytics rows.
     */
    public function clear_all_data(): bool {
        global $wpdb;

        $query = $this->inject_table_name('DELETE FROM {table}');
        $deleted = $wpdb->query($query);

        if ($deleted === false) {
            return false;
        }

        $this->invalidate_cache();
        return true;
    }

    /**
     * Get search statistics
     */
    public function get_search_stats(int $days = 30, array $filters = []): array {
        global $wpdb;

        $filters = $this->normalize_period_filters($filters);
        $days = max(1, (int) $days);
        $cache_key = $this->build_cache_key('search_stats', [$days, $filters]);
        $found = false;
        $cached_stats = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_stats;
        }

        $query = $this->inject_table_name('SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms,
                SUM(results_found) as successful_searches,
                AVG(results_count) as avg_results_per_search,
                COUNT(clicked_result_id) as total_clicks
            FROM {table}
            WHERE 1=1');
        $prepared_sql = $this->prepare_period_query($query, $filters, $days);
        $stats = $prepared_sql !== false ? $wpdb->get_row($prepared_sql) : null;

        if (!$stats) {
            $result = [
                'total_searches' => 0,
                'unique_terms' => 0,
                'successful_searches' => 0,
                'success_rate' => 0,
                'avg_results_per_search' => 0,
                'click_through_rate' => 0
            ];
        } else {
            $result = [
                'total_searches' => (int) $stats->total_searches,
                'unique_terms' => (int) $stats->unique_terms,
                'successful_searches' => (int) $stats->successful_searches,
                'success_rate' => $stats->total_searches > 0 ?
                    round(($stats->successful_searches / $stats->total_searches) * 100, 1) : 0,
                'avg_results_per_search' => round((float) $stats->avg_results_per_search, 1),
                'click_through_rate' => $stats->total_searches > 0 ?
                    round(($stats->total_clicks / $stats->total_searches) * 100, 1) : 0
            ];
        }

        wp_cache_set($cache_key, $result, $this->cache_group, $this->cache_ttl);
        return $result;
    }

    /**
     * Get popular search terms
     */
    public function get_popular_terms(int $limit = 10, int $days = 30, array $filters = []): array {
        global $wpdb;

        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $filters = $this->normalize_period_filters($filters);
        $cache_key = $this->build_cache_key('popular_terms', [$limit, $days, $filters]);
        $found = false;
        $cached_terms = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_terms;
        }

        $query = $this->inject_table_name('SELECT
                search_term,
                COUNT(*) as search_count,
                SUM(results_found) as found_count,
                AVG(results_count) as avg_results,
                COUNT(clicked_result_id) as click_count,
                ROUND((COUNT(clicked_result_id) / COUNT(*)) * 100, 1) as ctr_percent
            FROM {table}
            WHERE 1=1
            GROUP BY search_term
            ORDER BY search_count DESC
            LIMIT %d');
        $prepared_sql = $this->prepare_period_query($query, $filters, $days, [$limit]);
        $results = $prepared_sql !== false ? $wpdb->get_results($prepared_sql) : [];

        $results = $results ?: [];
        wp_cache_set($cache_key, $results, $this->cache_group, $this->cache_ttl);
        return $results;
    }

    /**
     * Get searches with no results (opportunity finder)
     */
    public function get_zero_result_searches(int $limit = 10, int $days = 30, array $filters = []): array {
        global $wpdb;

        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $filters = $this->normalize_period_filters($filters);
        $cache_key = $this->build_cache_key('zero_result_searches', [$limit, $days, $filters]);
        $found = false;
        $cached_results = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_results;
        }

        $query = $this->inject_table_name('SELECT
                search_term,
                COUNT(*) as search_count,
                MAX(created_at) as last_searched
            FROM {table}
            WHERE 1=1
            AND results_found = 0
            GROUP BY search_term
            HAVING search_count >= 2
            ORDER BY search_count DESC
            LIMIT %d');
        $prepared_sql = $this->prepare_period_query($query, $filters, $days, [$limit]);
        $results = $prepared_sql !== false ? $wpdb->get_results($prepared_sql) : [];

        $results = $results ?: [];
        wp_cache_set($cache_key, $results, $this->cache_group, $this->cache_ttl);
        return $results;
    }

    /**
     * Generate actionable business insights
     */
    public function get_business_insights(array $filters = []): array {
        $insights = [];
        $stats = $this->get_search_stats(30, $filters);
        $zero_results = $this->get_zero_result_searches(5, 30, $filters);

        // Success rate insights
        if ($stats['success_rate'] < 80) {
            $insights[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Low Search Success Rate',
                'message' => "Only {$stats['success_rate']}% of searches return results. Consider improving product titles and descriptions.",
                'action' => 'Review zero-result searches below',
                'priority' => 'high'
            ];
        } elseif ($stats['success_rate'] > 90) {
            $insights[] = [
                'type' => 'success',
                'icon' => '✅',
                'title' => 'Excellent Search Performance',
                'message' => "Your search is performing great with {$stats['success_rate']}% success rate!",
                'action' => 'Consider enabling semantic search for even better results',
                'priority' => 'low'
            ];
        }

        // Zero results opportunities
        if (!empty($zero_results)) {
            $count = count($zero_results);
            $total_missed = array_sum(array_column($zero_results, 'search_count'));
            $insights[] = [
                'type' => 'opportunity',
                'icon' => '💡',
                'title' => 'Product Opportunities Found',
                'message' => "{$count} different search terms with {$total_missed} total searches returned no results. These represent potential revenue opportunities!",
                'action' => 'Add products for these search terms',
                'priority' => 'high'
            ];
        }

        return $insights;
    }

    /**
     * Export analytics data (basic CSV for free version)
     */
    public function export_search_data(string $format = 'csv', array $filters = []): string {
        $format = strtolower($format);
        if ($format !== 'csv') {
            return '';
        }

        $filters = $this->normalize_period_filters($filters);
        $cache_key = $this->build_cache_key('export_search_data', [$format, $filters]);
        $found = false;
        $cached_export = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_export;
        }

        global $wpdb;

        $query = $this->inject_table_name('SELECT
                search_term,
                search_type,
                results_found,
                results_count,
                DATE(created_at) as search_date,
                TIME(created_at) as search_time
            FROM {table}
            WHERE 1=1
            ORDER BY created_at DESC
            LIMIT 1000');

        $prepared_sql = $this->prepare_period_query($query, $filters, 30);
        $data = $prepared_sql !== false ? $wpdb->get_results($prepared_sql) : [];

        $data = $data ?: [];

        $headers = ['Search Term', 'Search Type', 'Found Results', 'Result Count', 'Date', 'Time'];
        $output = implode(',', array_map([$this, 'escape_csv_value'], $headers)) . "\n";
        foreach ($data as $row) {
            $values = [
                $row->search_term,
                $row->search_type,
                $row->results_found ? 'Yes' : 'No',
                $row->results_count,
                $row->search_date,
                $row->search_time
            ];
            $output .= implode(',', array_map([$this, 'escape_csv_value'], $values)) . "\n";
        }

        wp_cache_set($cache_key, $output, $this->cache_group, $this->cache_ttl);
        return $output;
    }

    /**
     * Handle analytics search preview (admin only).
     */
    public function handle_preview_search() {
        check_ajax_referer('aivs_preview_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        if ($term === '') {
            wp_send_json_error(['message' => 'Search term is required']);
            return;
        }

        $handler = AIVectorSearch_Search_Handler::instance();
        $results = $handler->preview_search_results($term, 10);

        foreach ($results as &$result) {
            $result['search_term'] = $term;
        }

        wp_send_json_success($results);
    }

    /**
     * Handle live stats refresh (admin only).
     */
    public function handle_get_live_stats() {
        check_ajax_referer('aivs_stats_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $stats = $this->get_search_stats(30);
        wp_send_json_success($stats);
    }

    /**
     * Handle frontend analytics events.
     */
    public function handle_track_event() {
        check_ajax_referer('aivs_tracking_nonce', 'nonce');

        $event_type = sanitize_text_field(wp_unslash($_POST['event_type'] ?? ''));
        $raw_data = wp_unslash($_POST['event_data'] ?? '');
        $data = json_decode($raw_data, true);
        if (!is_array($data)) {
            $data = [];
        }

        switch ($event_type) {
            case 'search_performed':
                $term = sanitize_text_field($data['query'] ?? '');
                $results_count = isset($data['results']) ? max(0, (int) $data['results']) : 0;
                $type = sanitize_text_field($data['search_type'] ?? 'ajax');
                $results = $results_count > 0 ? array_fill(0, $results_count, 0) : [];
                $this->track_search($term, $type, $results);
                break;
            case 'search_submitted':
                $term = sanitize_text_field($data['query'] ?? '');
                $this->track_search($term, 'submit', []);
                break;
            case 'search_result_click':
                $term = sanitize_text_field($data['query'] ?? '');
                $product_id = isset($data['product_id']) ? (int) $data['product_id'] : 0;
                if ($term !== '' && $product_id > 0) {
                    $this->track_click($term, $product_id);
                }
                break;
            default:
                wp_send_json_error(['message' => 'Unknown event type']);
                return;
        }

        wp_send_json_success(['ok' => true]);
    }

    /**
     * Add analytics admin page - NOTE: Analytics page is now managed by Admin_Interface class
     */
    public function add_analytics_page() {
        // Analytics page is now handled by the main Admin_Interface class
        // This method is kept for backward compatibility but no longer used
    }

    /**
     * Render analytics dashboard (templated)
     */
    public function render_analytics_page_template() {
        $days = 30;
        $available_years = $this->get_available_years();
        $filters = $this->get_period_filters_from_request($available_years);
        $period_label = $this->get_period_label($filters, $days);

        $stats = $this->get_search_stats($days, $filters);
        $popular_terms = $this->get_popular_terms(10, $days, $filters);
        $zero_results = $this->get_zero_result_searches(10, $days, $filters);
        $insights = $this->get_business_insights($filters);

        // Handle export
        if (isset($_GET['export'])) {
            $export = sanitize_text_field(wp_unslash($_GET['export']));
            if ($export === 'csv') {
                $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
                if (!wp_verify_nonce($nonce, 'aivesese_export_analytics')) {
                    wp_die(esc_html__('Security check failed.', 'aivesese'));
                }

                $csv_data = $this->export_search_data('csv', $filters);
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="search-analytics-' . gmdate('Y-m-d') . '.csv"');
                echo $csv_data;
                exit;
            }
        }

        // Load template
        $template = AIVESESE_PLUGIN_PATH . 'assets/templates/analytics-dashboard.php';
        if (file_exists($template)) {
            include $template; // Uses $stats, $popular_terms, $zero_results, $insights
            return;
        }

        // Fallback minimal output if template missing
        echo '<div class="wrap"><h1>Search Analytics</h1><p>Template not found.</p></div>';
    }

    /**
     * Render analytics dashboard
     */
    public function render_analytics_page() {
        return $this->render_analytics_page_template();
    }

    /**
     * Clean up old data (keep last 90 days in free version)
     */
    public function cleanup_old_data() {
        global $wpdb;

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $cutoff_date = gmdate('Y-m-d H:i:s', time() - (90 * $seconds_per_day));

        $query = $this->inject_table_name('DELETE FROM {table}
            WHERE created_at < %s');
        $prepared_sql = $wpdb->prepare($query, $cutoff_date);
        $deleted = $prepared_sql !== false ? $wpdb->query($prepared_sql) : false;

        if ($deleted !== false) {
            $this->invalidate_cache();
        }
    }

    /**
     * Retrieve the current cache version, creating one if needed.
     */
    private function get_cache_version(): string {
        $found = false;
        $version = wp_cache_get('version', $this->cache_group, false, $found);

        if (!$found || !is_string($version) || $version === '') {
            $version = (string) microtime(true);
            wp_cache_set('version', $version, $this->cache_group);
        }

        return $version;
    }

    /**
     * Build a namespaced cache key so we can invalidate via version bumps.
     */
    private function build_cache_key(string $context, array $args = []): string {
        $version = $this->get_cache_version();

        if (!empty($args)) {
            $serializer = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
            $context .= ':' . md5((string) $serializer($args));
        }

        return $version . ':' . $context;
    }

    /**
     * Invalidate analytics caches.
     */
    private function invalidate_cache(): void {
        wp_cache_delete('version', $this->cache_group);
    }

    /**
     * Cache the most recent search ID for a term/user combination.
     */
    private function set_recent_search_cache(string $search_term, string $user_hash, int $search_id): void {
        $cache_key = $this->build_cache_key('recent_search', [$search_term, $user_hash]);
        wp_cache_set($cache_key, $search_id, $this->cache_group, $this->recent_search_cache_ttl);
    }

    /**
     * Attempt to fetch a cached recent search ID.
     */
    private function get_recent_search_id(string $search_term, string $user_hash): ?int {
        $cache_key = $this->build_cache_key('recent_search', [$search_term, $user_hash]);
        $found = false;
        $cached_id = wp_cache_get($cache_key, $this->cache_group, false, $found);

        if (!$found) {
            return null;
        }

        return (int) $cached_id;
    }

    /**
     * Replace the table placeholder with the escaped table name for raw SQL fragments.
     * Table names are wrapped in backticks for additional safety.
     */
    private function inject_table_name(string $sql_template): string {
        // Wrap table name in backticks for MySQL identifier safety
        return str_replace('{table}', '`' . $this->table_name_escaped . '`', $sql_template);
    }

    /**
     * Parse month/year filters from the request.
     */
    private function get_period_filters_from_request(array $available_years = []): array {
        $current_year = (int) gmdate('Y', current_time('timestamp'));
        $default_year = $current_year;
        if (!empty($available_years) && !in_array($current_year, $available_years, true)) {
            $default_year = (int) $available_years[0];
        }

        $year = isset($_GET['year']) ? absint(wp_unslash($_GET['year'])) : $default_year;
        $month = isset($_GET['month']) ? absint(wp_unslash($_GET['month'])) : 0;

        return $this->normalize_period_filters([
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Normalize month/year filter values.
     */
    private function normalize_period_filters(array $filters): array {
        $year = isset($filters['year']) ? (int) $filters['year'] : 0;
        $month = isset($filters['month']) ? (int) $filters['month'] : 0;

        if ($year < 2000 || $year > 2100) {
            $year = 0;
        }

        if ($month < 1 || $month > 12) {
            $month = 0;
        }

        if ($year === 0) {
            $month = 0;
        }

        return [
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * Build the period WHERE clause and parameter list.
     */
    private function build_period_where(array $filters, int $days = 30): array {
        $filters = $this->normalize_period_filters($filters);
        $where = '';
        $args = [];

        if ($filters['year'] > 0) {
            $where .= ' AND YEAR(created_at) = %d';
            $args[] = $filters['year'];

            if ($filters['month'] > 0) {
                $where .= ' AND MONTH(created_at) = %d';
                $args[] = $filters['month'];
            }

            return [$where, $args];
        }

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $date_limit = gmdate('Y-m-d H:i:s', time() - ($days * $seconds_per_day));
        $where .= ' AND created_at >= %s';
        $args[] = $date_limit;

        return [$where, $args];
    }

    /**
     * Prepare a query with optional period filters.
     */
    private function prepare_period_query(string $sql, array $filters, int $days = 30, array $tail_args = []) {
        global $wpdb;

        [$where, $args] = $this->build_period_where($filters, $days);
        $sql .= $where;
        $args = array_merge($args, $tail_args);

        return empty($args) ? $sql : $wpdb->prepare($sql, ...$args);
    }

    /**
     * Return years present in the analytics table.
     */
    private function get_available_years(): array {
        global $wpdb;

        $query = $this->inject_table_name('SELECT DISTINCT YEAR(created_at) AS year
            FROM {table}
            ORDER BY year DESC');
        $rows = $wpdb->get_col($query);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $rows)));
    }

    /**
     * Build a human-readable label for the active period.
     */
    private function get_period_label(array $filters, int $days = 30): string {
        $filters = $this->normalize_period_filters($filters);

        if ($filters['year'] > 0 && $filters['month'] > 0) {
            return gmdate('F Y', gmmktime(0, 0, 0, $filters['month'], 1, $filters['year']));
        }

        if ($filters['year'] > 0) {
            return (string) $filters['year'];
        }

        return sprintf('Last %d days', $days);
    }

    /**
     * Handle the dashboard clear-data action.
     */
    public function handle_clear_analytics_data() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $action = isset($_POST['aivs_analytics_action']) ? sanitize_key(wp_unslash($_POST['aivs_analytics_action'])) : '';

        if ($page !== 'aivesese-analytics' || $action !== 'clear_data') {
            return;
        }

        check_admin_referer('aivs_clear_analytics_data', 'aivs_clear_analytics_nonce');

        $cleared = $this->clear_all_data();

        $redirect_url = add_query_arg(
            ['page' => 'aivesese-analytics', 'aivs_cleared' => $cleared ? '1' : '0'],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Get user IP (GDPR-friendly - just for deduplication)
     */
    private function get_user_ip(): string {
        // Hash IP for privacy
        $raw_ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash((string) $_SERVER['REMOTE_ADDR']) : '';
        $ip = filter_var($raw_ip, FILTER_VALIDATE_IP);
        if (!$ip) {
            $ip = '127.0.0.1';
        }
        return substr(md5($ip . wp_salt()), 0, 10);
    }

    /**
     * Escape CSV values and prevent spreadsheet formula injection.
     */
    private function escape_csv_value($value): string {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }
        $value = str_replace('"', '""', $value);
        return '"' . $value . '"';
    }
}
