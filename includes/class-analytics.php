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
        $this->table_name_escaped = esc_sql($this->table_name);

        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $this->cache_ttl = 5 * $minute;
        $this->recent_search_cache_ttl = $minute;

        $this->init_hooks();
    }

    private function init_hooks() {
        // Create table on activation
        register_activation_hook(AIVESESE_PLUGIN_PATH . 'ai-supabase-search.php', [$this, 'create_table']);

        add_action('admin_init', [$this, 'check_database_version']);

        // Add admin page
        add_action('admin_menu', [$this, 'add_analytics_page']);

        // Cleanup old data daily
        add_action('aivs_cleanup_analytics', [$this, 'cleanup_old_data']);
        if (!wp_next_scheduled('aivs_cleanup_analytics')) {
            wp_schedule_event(time(), 'daily', 'aivs_cleanup_analytics');
        }
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
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
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
        dbDelta($sql);

        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'")) {
            update_option('aivesese_analytics_db_version', $this->db_version);
        }

        $this->invalidate_cache();
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
     * Get search statistics
     */
    public function get_search_stats(int $days = 30): array {
        global $wpdb;

        $days = max(1, (int) $days);
        $cache_key = $this->build_cache_key('search_stats', [$days]);
        $found = false;
        $cached_stats = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_stats;
        }

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $date_limit = gmdate('Y-m-d H:i:s', time() - ($days * $seconds_per_day));

        $query = $this->inject_table_name('SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms,
                SUM(results_found) as successful_searches,
                AVG(results_count) as avg_results_per_search,
                COUNT(clicked_result_id) as total_clicks
            FROM {table}
            WHERE created_at >= %s');
        $prepared_sql = $wpdb->prepare($query, $date_limit);
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
    public function get_popular_terms(int $limit = 10, int $days = 30): array {
        global $wpdb;

        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $cache_key = $this->build_cache_key('popular_terms', [$limit, $days]);
        $found = false;
        $cached_terms = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_terms;
        }

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $date_limit = gmdate('Y-m-d H:i:s', time() - ($days * $seconds_per_day));

        $query = $this->inject_table_name('SELECT
                search_term,
                COUNT(*) as search_count,
                SUM(results_found) as found_count,
                AVG(results_count) as avg_results,
                COUNT(clicked_result_id) as click_count,
                ROUND((COUNT(clicked_result_id) / COUNT(*)) * 100, 1) as ctr_percent
            FROM {table}
            WHERE created_at >= %s
            GROUP BY search_term
            ORDER BY search_count DESC
            LIMIT %d');
        $prepared_sql = $wpdb->prepare($query, $date_limit, $limit);
        $results = $prepared_sql !== false ? $wpdb->get_results($prepared_sql) : [];

        $results = $results ?: [];
        wp_cache_set($cache_key, $results, $this->cache_group, $this->cache_ttl);
        return $results;
    }

    /**
     * Get searches with no results (opportunity finder)
     */
    public function get_zero_result_searches(int $limit = 10, int $days = 30): array {
        global $wpdb;

        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $cache_key = $this->build_cache_key('zero_result_searches', [$limit, $days]);
        $found = false;
        $cached_results = wp_cache_get($cache_key, $this->cache_group, false, $found);
        if ($found) {
            return $cached_results;
        }

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $date_limit = gmdate('Y-m-d H:i:s', time() - ($days * $seconds_per_day));

        $query = $this->inject_table_name('SELECT
                search_term,
                COUNT(*) as search_count,
                MAX(created_at) as last_searched
            FROM {table}
            WHERE created_at >= %s
            AND results_found = 0
            GROUP BY search_term
            HAVING search_count >= 2
            ORDER BY search_count DESC
            LIMIT %d');
        $prepared_sql = $wpdb->prepare($query, $date_limit, $limit);
        $results = $prepared_sql !== false ? $wpdb->get_results($prepared_sql) : [];

        $results = $results ?: [];
        wp_cache_set($cache_key, $results, $this->cache_group, $this->cache_ttl);
        return $results;
    }

    /**
     * Generate actionable business insights
     */
    public function get_business_insights(): array {
        $insights = [];
        $stats = $this->get_search_stats();
        $zero_results = $this->get_zero_result_searches(5);

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
    public function export_search_data(string $format = 'csv'): string {
        $format = strtolower($format);
        if ($format !== 'csv') {
            return '';
        }

        $cache_key = $this->build_cache_key('export_search_data', [$format]);
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
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at DESC
            LIMIT 1000');

        $data = $wpdb->get_results($query);

        $data = $data ?: [];

        $output = "Search Term,Search Type,Found Results,Result Count,Date,Time\n";
        foreach ($data as $row) {
            $output .= sprintf('"%s","%s","%s","%s","%s","%s"' . "\n",
                $row->search_term,
                $row->search_type,
                $row->results_found ? 'Yes' : 'No',
                $row->results_count,
                $row->search_date,
                $row->search_time
            );
        }

        wp_cache_set($cache_key, $output, $this->cache_group, $this->cache_ttl);
        return $output;
    }

    /**
     * Add analytics admin page
     */
    public function add_analytics_page() {
        add_submenu_page(
            'options-general.php',
            'Search Analytics',
            'Search Analytics',
            'manage_options',
            'aivesese-analytics',
            [$this, 'render_analytics_page_template']
        );
    }

    /**
     * Render analytics dashboard (templated)
     */
    public function render_analytics_page_template() {
        // Optional timeframe via query param (e.g., 7, 30, 90 or '7d')
        $days = 30;
        if (isset($_GET['timeframe'])) {
            $tf = sanitize_text_field(wp_unslash($_GET['timeframe']));
            if (preg_match('/^(\d+)/', $tf, $m)) {
                $days = max(1, (int) $m[1]);
            }
        }

        $stats = $this->get_search_stats($days);
        $popular_terms = $this->get_popular_terms(10, $days);
        $zero_results = $this->get_zero_result_searches(10, $days);
        $insights = $this->get_business_insights();

        // Handle export
        if (isset($_GET['export'])) {
            $export = sanitize_text_field(wp_unslash($_GET['export']));
            if ($export === 'csv') {
                $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
                if (!wp_verify_nonce($nonce, 'aivesese_export_analytics')) {
                    wp_die(esc_html__('Security check failed.', 'aivesese'));
                }

                $csv_data = $this->export_search_data('csv');
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
     */
    private function inject_table_name(string $sql_template): string {
        return str_replace('{table}', $this->table_name_escaped, $sql_template);
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
}
