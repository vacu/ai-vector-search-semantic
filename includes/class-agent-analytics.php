<?php

/**
 * Tracks dedicated analytics for the product/order agent.
 */
class AIVectorSearch_Agent_Analytics
{
    private static $instance = null;
    private string $table_name;
    private string $table_name_escaped;
    private string $db_version = '1.0';

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;

        $this->table_name = $wpdb->prefix . 'aivs_agent_analytics';
        $safe_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $wpdb->prefix);
        $this->table_name_escaped = $safe_prefix . 'aivs_agent_analytics';

        add_action('admin_init', [$this, 'check_database_version']);
    }

    public function check_database_version(): void
    {
        $installed_version = get_option('aivesese_agent_analytics_db_version', '0');
        if (version_compare($installed_version, $this->db_version, '<')) {
            $this->create_table();
        }
    }

    public function create_table(): bool
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_name_escaped}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            event_type varchar(50) NOT NULL,
            intent varchar(50) NULL,
            model_name varchar(100) NULL,
            product_id bigint(20) NULL,
            order_id bigint(20) NULL,
            success tinyint(1) NOT NULL DEFAULT 0,
            user_id bigint(20) NULL,
            metadata longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session_id (session_id),
            KEY idx_event_type (event_type),
            KEY idx_created_at (created_at),
            KEY idx_intent (intent)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $wpdb->suppress_errors();
        dbDelta($sql);
        $wpdb->suppress_errors(false);

        $table_exists = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $this->table_name
        ));

        if ($table_exists) {
            update_option('aivesese_agent_analytics_db_version', $this->db_version);
            return true;
        }

        return false;
    }

    public function track_event(array $event): void
    {
        global $wpdb;

        $session_id = sanitize_text_field((string) ($event['session_id'] ?? ''));
        $event_type = sanitize_key((string) ($event['event_type'] ?? ''));

        if ($session_id === '' || $event_type === '') {
            return;
        }

        $metadata = $event['metadata'] ?? null;
        if (is_array($metadata)) {
            $metadata = wp_json_encode($metadata);
        }

        $wpdb->insert($this->table_name, [
            'session_id' => $session_id,
            'event_type' => $event_type,
            'intent' => !empty($event['intent']) ? sanitize_key((string) $event['intent']) : null,
            'model_name' => !empty($event['model_name']) ? sanitize_text_field((string) $event['model_name']) : null,
            'product_id' => isset($event['product_id']) ? (int) $event['product_id'] : null,
            'order_id' => isset($event['order_id']) ? (int) $event['order_id'] : null,
            'success' => !empty($event['success']) ? 1 : 0,
            'user_id' => isset($event['user_id']) ? (int) $event['user_id'] : null,
            'metadata' => is_string($metadata) ? $metadata : null,
            'created_at' => current_time('mysql'),
        ]);
    }

    public function get_summary(int $days = 30): array
    {
        global $wpdb;

        $days = max(1, $days);
        $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $query = "SELECT
                COUNT(*) AS total_events,
                COUNT(DISTINCT session_id) AS total_sessions,
                SUM(CASE WHEN event_type = 'turn' THEN 1 ELSE 0 END) AS total_turns,
                SUM(CASE WHEN event_type = 'product_impression' THEN 1 ELSE 0 END) AS product_impressions,
                SUM(CASE WHEN event_type = 'add_to_cart_click' THEN 1 ELSE 0 END) AS add_to_cart_clicks,
                SUM(CASE WHEN event_type = 'order_request' THEN 1 ELSE 0 END) AS order_requests,
                SUM(CASE WHEN event_type = 'order_verified' THEN 1 ELSE 0 END) AS order_verified,
                SUM(CASE WHEN event_type = 'order_blocked' THEN 1 ELSE 0 END) AS order_blocked
            FROM {$this->table_name_escaped}
            WHERE created_at >= %s";

        $row = $wpdb->get_row($wpdb->prepare($query, $since), ARRAY_A);

        return is_array($row) ? $row : [];
    }

    public function get_top_intents(int $days = 30, int $limit = 10): array
    {
        global $wpdb;

        $days = max(1, $days);
        $limit = max(1, $limit);
        $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $query = "SELECT intent, COUNT(*) AS event_count
            FROM {$this->table_name_escaped}
            WHERE created_at >= %s
              AND intent IS NOT NULL
              AND intent <> ''
            GROUP BY intent
            ORDER BY event_count DESC
            LIMIT %d";

        $prepared = $wpdb->prepare($query, $since, $limit);
        return $prepared ? (array) $wpdb->get_results($prepared) : [];
    }

    public function get_recent_events(int $limit = 25): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $query = $wpdb->prepare(
            "SELECT session_id, event_type, intent, model_name, product_id, order_id, success, user_id, created_at
             FROM {$this->table_name_escaped}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        );

        return $query ? (array) $wpdb->get_results($query) : [];
    }

    public function render_analytics_page(): void
    {
        $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
        $summary = $this->get_summary($days);
        $top_intents = $this->get_top_intents($days);
        $recent_events = $this->get_recent_events();

        echo '<div class="wrap aivs-analytics-dashboard">';
        echo '<h1>Agent Analytics</h1>';
        echo '<form method="get" class="aivs-analytics-filter-form" style="margin:16px 0;">';
        echo '<input type="hidden" name="page" value="aivesese-agent-analytics" />';
        echo '<label for="aivs-agent-days" style="margin-right:8px;">Window</label>';
        echo '<select id="aivs-agent-days" name="days">';
        foreach ([7, 30, 90] as $value) {
            echo '<option value="' . esc_attr((string) $value) . '"' . selected($days, $value, false) . '>' . esc_html($value . ' days') . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-secondary">Apply</button>';
        echo '</form>';

        echo '<div class="aivs-stats-grid">';
        $cards = [
            'Sessions' => (int) ($summary['total_sessions'] ?? 0),
            'Turns' => (int) ($summary['total_turns'] ?? 0),
            'Product Impressions' => (int) ($summary['product_impressions'] ?? 0),
            'Add To Cart Clicks' => (int) ($summary['add_to_cart_clicks'] ?? 0),
            'Order Requests' => (int) ($summary['order_requests'] ?? 0),
            'Verified Orders' => (int) ($summary['order_verified'] ?? 0),
            'Blocked Orders' => (int) ($summary['order_blocked'] ?? 0),
        ];

        foreach ($cards as $label => $value) {
            echo '<div class="aivs-stat-card">';
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<div class="aivs-stat-number">' . number_format_i18n($value) . '</div>';
            echo '<small>Last ' . esc_html((string) $days) . ' days</small>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="aivs-analytics-section">';
        echo '<h2>Top Intents</h2>';
        if (!empty($top_intents)) {
            echo '<table class="wp-list-table widefat fixed striped aivs-data-table"><thead><tr><th>Intent</th><th class="numeric">Events</th></tr></thead><tbody>';
            foreach ($top_intents as $row) {
                echo '<tr><td>' . esc_html((string) ($row->intent ?? 'unknown')) . '</td><td class="numeric">' . number_format_i18n((int) ($row->event_count ?? 0)) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No agent intent data recorded yet.</p>';
        }
        echo '</div>';

        echo '<div class="aivs-analytics-section">';
        echo '<h2>Recent Events</h2>';
        if (!empty($recent_events)) {
            echo '<table class="wp-list-table widefat fixed striped aivs-data-table"><thead><tr><th>When</th><th>Session</th><th>Event</th><th>Intent</th><th>Model</th><th>Status</th></tr></thead><tbody>';
            foreach ($recent_events as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row->created_at ?? '')) . '</td>';
                echo '<td><code>' . esc_html((string) ($row->session_id ?? '')) . '</code></td>';
                echo '<td>' . esc_html((string) ($row->event_type ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row->intent ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row->model_name ?? '')) . '</td>';
                echo '<td>' . (!empty($row->success) ? 'Success' : 'Info') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No recent agent events.</p>';
        }
        echo '</div>';
        echo '</div>';
    }
}
