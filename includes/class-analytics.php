<?php
/**
 * File: includes/class-analytics.php
 * Clean Analytics Class - Properly Integrated
 */
class AIVectorSearch_Analytics {

    private static $instance = null;
    private $table_name;

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

        // Add admin page
        add_action('admin_menu', [$this, 'add_analytics_page']);

        // Cleanup old data daily
        add_action('aivs_cleanup_analytics', [$this, 'cleanup_old_data']);
        if (!wp_next_scheduled('aivs_cleanup_analytics')) {
            wp_schedule_event(time(), 'daily', 'aivs_cleanup_analytics');
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
            [$this, 'render_analytics_page']
        );
    }

    /**
     * Render analytics dashboard
     */
    public function render_analytics_page() {
        $stats = $this->get_search_stats();
        $popular_terms = $this->get_popular_terms();
        $zero_results = $this->get_zero_result_searches();
        $insights = $this->get_business_insights();

        // Handle export
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $csv_data = $this->export_search_data('csv');
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="search-analytics-' . date('Y-m-d') . '.csv"');
            echo $csv_data;
            exit;
        }

        ?>
        <div class="wrap">
            <h1>🔍 Search Analytics</h1>

            <!-- Business Insights Alert Section -->
            <?php if (!empty($insights)): ?>
            <div class="aivs-insights-section">
                <h2>💡 Business Insights</h2>
                <?php foreach ($insights as $insight): ?>
                <div class="aivs-insight aivs-insight-<?php echo $insight['type']; ?>">
                    <div class="aivs-insight-icon"><?php echo $insight['icon']; ?></div>
                    <div class="aivs-insight-content">
                        <h4><?php echo esc_html($insight['title']); ?></h4>
                        <p><?php echo esc_html($insight['message']); ?></p>
                        <small class="aivs-insight-action">💡 <?php echo esc_html($insight['action']); ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Overview Stats -->
            <div class="aivs-stats-grid">
                <div class="aivs-stat-card">
                    <h3>Total Searches</h3>
                    <div class="aivs-stat-number"><?php echo number_format($stats['total_searches']); ?></div>
                    <small>Last 30 days</small>
                </div>

                <div class="aivs-stat-card">
                    <h3>Success Rate</h3>
                    <div class="aivs-stat-number aivs-stat-<?php echo $stats['success_rate'] > 80 ? 'good' : ($stats['success_rate'] > 60 ? 'okay' : 'poor'); ?>">
                        <?php echo $stats['success_rate']; ?>%
                    </div>
                    <small><?php echo number_format($stats['successful_searches']); ?> found results</small>
                </div>

                <div class="aivs-stat-card">
                    <h3>Click-Through Rate</h3>
                    <div class="aivs-stat-number aivs-stat-<?php echo $stats['click_through_rate'] > 40 ? 'good' : ($stats['click_through_rate'] > 25 ? 'okay' : 'poor'); ?>">
                        <?php echo $stats['click_through_rate']; ?>%
                    </div>
                    <small>Users clicking results</small>
                </div>

                <div class="aivs-stat-card">
                    <h3>Unique Terms</h3>
                    <div class="aivs-stat-number"><?php echo number_format($stats['unique_terms']); ?></div>
                    <small>Different searches</small>
                </div>
            </div>

            <!-- Popular Terms Section -->
            <div class="aivs-analytics-section">
                <div class="aivs-section-header">
                    <h2>🔥 Popular Search Terms</h2>
                    <a href="<?php echo add_query_arg('export', 'csv'); ?>" class="button">📊 Export CSV</a>
                </div>

                <?php if (!empty($popular_terms)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Search Term</th>
                                <th>Searches</th>
                                <th>Success Rate</th>
                                <th>Click Rate</th>
                                <th>Avg Results</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popular_terms as $term): ?>
                            <?php
                            $success_rate = round(($term->found_count / $term->search_count) * 100, 1);
                            $performance_class = $success_rate > 80 ? 'good' : ($success_rate > 60 ? 'okay' : 'poor');
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($term->search_term); ?></strong></td>
                                <td><?php echo number_format($term->search_count); ?></td>
                                <td><span class="aivs-performance-badge aivs-<?php echo $performance_class; ?>"><?php echo $success_rate; ?>%</span></td>
                                <td><?php echo $term->ctr_percent; ?>%</td>
                                <td><?php echo round($term->avg_results, 1); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="aivs-empty-state">
                        <h3>🔍 No search data yet</h3>
                        <p>Start getting insights as soon as customers search your store!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Zero Results Section -->
            <div class="aivs-analytics-section">
                <h2>💡 Opportunity Finder - Zero Result Searches</h2>
                <?php if (!empty($zero_results)): ?>
                    <p class="description">These searches returned no results. Consider adding products or content for these terms:</p>
                    <div class="aivs-opportunity-grid">
                        <?php foreach ($zero_results as $term): ?>
                        <div class="aivs-opportunity-card">
                            <div class="aivs-opportunity-term">"<?php echo esc_html($term->search_term); ?>"</div>
                            <div class="aivs-opportunity-stats">
                                <span class="aivs-search-count"><?php echo $term->search_count; ?> searches</span>
                                <span class="aivs-last-search"><?php echo human_time_diff(strtotime($term->last_searched)); ?> ago</span>
                            </div>
                            <div class="aivs-opportunity-actions">
                                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-primary button-small">Add Product</a>
                                <a href="<?php echo admin_url('edit.php?post_type=product&s=' . urlencode($term->search_term)); ?>" class="button button-small">Search Existing</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>✅ Great! All searches are returning results. No opportunities found.</p>
                <?php endif; ?>
            </div>

            <!-- Upgrade Promotion -->
            <!-- <div class="aivs-upgrade-banner">
                <h3>🚀 Want More Insights?</h3>
                <p>Upgrade to our API service for unlimited analytics history, customer journey tracking, and advanced reports:</p>
                <ul>
                    <li>✅ Unlimited search history (vs 30 days)</li>
                    <li>✅ Customer journey tracking</li>
                    <li>✅ Export reports to CSV/PDF</li>
                    <li>✅ Real-time analytics dashboard</li>
                    <li>✅ Seasonal trend analysis</li>
                </ul>
                <a href="https://zzzsolutions.ro/ai-search-service" target="_blank" class="button button-primary">View Pricing</a>
                <a href="<?php //echo admin_url('options-general.php?page=aivesese'); ?>" class="button">Configure API</a>
            </div> -->
        </div>

        <style>
        .aivs-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .aivs-stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .aivs-stat-card h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
        }
        .aivs-stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #0073aa;
            margin: 10px 0;
        }
        .aivs-stat-good { color: #46b450; }
        .aivs-stat-okay { color: #ffb900; }
        .aivs-stat-poor { color: #dc3232; }

        /* Insights Section */
        .aivs-insights-section {
            background: #f8f9fa;
            border-left: 4px solid #0073aa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        .aivs-insight {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .aivs-insight-success { background: #f0f9ff; border-left-color: #46b450; }
        .aivs-insight-warning { background: #fffbf0; border-left-color: #ffb900; }
        .aivs-insight-opportunity { background: #f0fff4; border-left-color: #00a32a; }
        .aivs-insight-icon {
            font-size: 24px;
            line-height: 1;
        }
        .aivs-insight-content h4 {
            margin: 0 0 8px 0;
            color: #1d2327;
        }
        .aivs-insight-content p {
            margin: 0 0 8px 0;
            color: #50575e;
        }
        .aivs-insight-action {
            color: #646970;
            font-style: italic;
        }

        /* Analytics Sections */
        .aivs-analytics-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .aivs-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        /* Performance Badges */
        .aivs-performance-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .aivs-good { background: #d4edda; color: #155724; }
        .aivs-okay { background: #fff3cd; color: #856404; }
        .aivs-poor { background: #f8d7da; color: #721c24; }

        /* Opportunity Cards */
        .aivs-opportunity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .aivs-opportunity-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .aivs-opportunity-term {
            font-size: 18px;
            font-weight: bold;
            color: #1d2327;
            margin-bottom: 10px;
        }
        .aivs-opportunity-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        .aivs-search-count {
            background: #0073aa;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .aivs-last-search {
            color: #666;
            font-size: 12px;
        }
        .aivs-opportunity-actions {
            display: flex;
            gap: 10px;
        }

        /* Upgrade Banner */
        .aivs-upgrade-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin: 30px 0;
            text-align: center;
        }
        .aivs-upgrade-banner h3 {
            color: white;
            margin-top: 0;
            font-size: 24px;
        }
        .aivs-upgrade-banner ul {
            text-align: left;
            display: inline-block;
            margin: 20px 0;
        }
        .aivs-upgrade-banner .button {
            margin: 10px;
        }

        /* Empty State */
        .aivs-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .aivs-empty-state h3 {
            color: #1d2327;
            margin-bottom: 10px;
        }
        </style>
        <?php
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
