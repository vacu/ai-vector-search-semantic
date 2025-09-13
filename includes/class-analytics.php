<?php
/**
 * File: includes/class-analytics.php
 * Clean Analytics Class - Properly Integrated with track_click method
 */
class AIVectorSearch_Analytics {

    private static $instance = null;
    private $table_name;
    private $db_version = '1.0';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aivs_search_analytics';
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
    }

    /**
     * Track a search query
     */
    public function track_search(string $term, string $type, array $results, int $clicked_id = null) {
        if (strlen($term) < 2) {
            return; // Skip very short searches
        }

        global $wpdb;

        $data = [
            'search_term' => sanitize_text_field($term),
            'results_found' => !empty($results) ? 1 : 0,
            'results_count' => count($results),
            'search_type' => sanitize_text_field($type),
            'clicked_result_id' => $clicked_id,
            'user_ip' => $this->get_user_ip(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'created_at' => current_time('mysql')
        ];

        $wpdb->insert($this->table_name, $data);
    }

    /**
     * Track a click on search results
     */
    public function track_click(string $search_term, int $product_id) {
        if (strlen($search_term) < 2 || !$product_id) {
            return;
        }

        global $wpdb;

        // Find the most recent search for this term and update it with the click
        $recent_search = $wpdb->get_row($wpdb->prepare("
            SELECT id FROM {$this->table_name}
            WHERE search_term = %s
            AND user_ip = %s
            AND clicked_result_id IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ", $search_term, $this->get_user_ip()));

        if ($recent_search) {
            // Update existing search record with click
            $wpdb->update(
                $this->table_name,
                ['clicked_result_id' => $product_id],
                ['id' => $recent_search->id]
            );
        } else {
            // Create new record for the click if no matching search found
            $this->track_search($search_term, 'click', [$product_id], $product_id);
        }
    }

    /**
     * Get search statistics
     */
    public function get_search_stats(int $days = 30): array {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms,
                SUM(results_found) as successful_searches,
                AVG(results_count) as avg_results_per_search,
                COUNT(clicked_result_id) as total_clicks
            FROM {$this->table_name}
            WHERE created_at >= %s
        ", $date_limit));

        if (!$stats) {
            return [
                'total_searches' => 0,
                'unique_terms' => 0,
                'successful_searches' => 0,
                'success_rate' => 0,
                'avg_results_per_search' => 0,
                'click_through_rate' => 0
            ];
        }

        return [
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

    /**
     * Get popular search terms
     */
    public function get_popular_terms(int $limit = 10, int $days = 30): array {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                search_term,
                COUNT(*) as search_count,
                SUM(results_found) as found_count,
                AVG(results_count) as avg_results,
                COUNT(clicked_result_id) as click_count,
                ROUND((COUNT(clicked_result_id) / COUNT(*)) * 100, 1) as ctr_percent
            FROM {$this->table_name}
            WHERE created_at >= %s
            GROUP BY search_term
            ORDER BY search_count DESC
            LIMIT %d
        ", $date_limit, $limit));

        return $results ?: [];
    }

    /**
     * Get searches with no results (opportunity finder)
     */
    public function get_zero_result_searches(int $limit = 10, int $days = 30): array {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT
                search_term,
                COUNT(*) as search_count,
                MAX(created_at) as last_searched
            FROM {$this->table_name}
            WHERE created_at >= %s
            AND results_found = 0
            GROUP BY search_term
            HAVING search_count >= 2
            ORDER BY search_count DESC
            LIMIT %d
        ", $date_limit, $limit));

        return $results ?: [];
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
        global $wpdb;

        $data = $wpdb->get_results("
            SELECT
                search_term,
                search_type,
                results_found,
                results_count,
                DATE(created_at) as search_date,
                TIME(created_at) as search_time
            FROM {$this->table_name}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at DESC
            LIMIT 1000
        ");

        if ($format === 'csv') {
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
            return $output;
        }

        return '';
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
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $csv_data = $this->export_search_data('csv');
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="search-analytics-' . date('Y-m-d') . '.csv"');
            echo $csv_data;
            exit;
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

        $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));

        $wpdb->query($wpdb->prepare("
            DELETE FROM {$this->table_name}
            WHERE created_at < %s
        ", $cutoff_date));
    }

    /**
     * Get user IP (GDPR-friendly - just for deduplication)
     */
    private function get_user_ip(): string {
        // Hash IP for privacy
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return substr(md5($ip . wp_salt()), 0, 10);
    }
}
