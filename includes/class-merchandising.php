<?php
/**
 * Advanced merchandising analytics, ranking, and bundle suggestions.
 */
class AIVectorSearch_Merchandising {

    private static $instance = null;
    private string $events_table;
    private string $events_table_escaped;
    private string $metrics_table;
    private string $metrics_table_escaped;
    private string $bundles_table;
    private string $bundles_table_escaped;
    private string $db_version = '1.0';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        global $wpdb;

        $safe_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $wpdb->prefix);
        $this->events_table = $wpdb->prefix . 'aivs_merch_events';
        $this->events_table_escaped = $safe_prefix . 'aivs_merch_events';
        $this->metrics_table = $wpdb->prefix . 'aivs_merch_daily_metrics';
        $this->metrics_table_escaped = $safe_prefix . 'aivs_merch_daily_metrics';
        $this->bundles_table = $wpdb->prefix . 'aivs_bundle_candidates';
        $this->bundles_table_escaped = $safe_prefix . 'aivs_bundle_candidates';

        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action('admin_init', [$this, 'check_database_version']);
        add_action('aivs_refresh_merchandising_metrics', [$this, 'refresh_daily_metrics']);
        add_action('aivs_refresh_merchandising_metrics', [$this, 'rebuild_bundle_candidates']);
        add_action('woocommerce_order_status_completed', [$this, 'capture_completed_order'], 10, 1);
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
        add_action('template_redirect', [$this, 'track_recommendation_click']);
        add_action('wp_ajax_aivs_merch_track', [$this, 'handle_track_event']);
        add_action('wp_ajax_nopriv_aivs_merch_track', [$this, 'handle_track_event']);
        add_action('wp_ajax_aivs_run_merchandising_refresh', [$this, 'handle_manual_refresh']);

        if (!wp_next_scheduled('aivs_refresh_merchandising_metrics')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'aivs_refresh_merchandising_metrics');
        }
    }

    public function check_database_version(): void {
        $installed_version = get_option('aivesese_merchandising_db_version', '0');
        if (version_compare($installed_version, $this->db_version, '<')) {
            $this->create_tables();
        }
    }

    public function create_tables(): bool {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = [];
        $sql[] = "CREATE TABLE IF NOT EXISTS `{$this->events_table_escaped}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            surface varchar(50) NOT NULL DEFAULT 'search',
            search_term varchar(500) NULL,
            product_id bigint(20) NULL,
            anchor_product_id bigint(20) NULL,
            session_id varchar(100) NULL,
            user_id bigint(20) NULL,
            order_id bigint(20) NULL,
            position_index int(11) NULL,
            revenue decimal(12,2) NULL,
            margin_value decimal(12,2) NULL,
            metadata longtext NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_surface (surface),
            KEY idx_product_date (product_id, created_at),
            KEY idx_order_id (order_id),
            KEY idx_session_id (session_id)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$this->metrics_table_escaped}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            metric_date date NOT NULL,
            product_id bigint(20) NOT NULL,
            surface varchar(50) NOT NULL DEFAULT 'all',
            impressions int(11) NOT NULL DEFAULT 0,
            clicks int(11) NOT NULL DEFAULT 0,
            add_to_carts int(11) NOT NULL DEFAULT 0,
            attributed_orders int(11) NOT NULL DEFAULT 0,
            attributed_revenue decimal(12,2) NOT NULL DEFAULT 0,
            attributed_margin decimal(12,2) NOT NULL DEFAULT 0,
            demand_score decimal(10,4) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_metric (metric_date, product_id, surface),
            KEY idx_product_surface (product_id, surface),
            KEY idx_metric_date (metric_date)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$this->bundles_table_escaped}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            anchor_product_id bigint(20) NOT NULL,
            bundled_product_id bigint(20) NOT NULL,
            source varchar(30) NOT NULL DEFAULT 'orders',
            support_count int(11) NOT NULL DEFAULT 0,
            confidence_score decimal(10,4) NOT NULL DEFAULT 0,
            attach_rate decimal(10,4) NOT NULL DEFAULT 0,
            bundle_score decimal(10,4) NOT NULL DEFAULT 0,
            ai_label varchar(255) NULL,
            metadata longtext NULL,
            last_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_bundle (anchor_product_id, bundled_product_id, source),
            KEY idx_anchor_score (anchor_product_id, bundle_score),
            KEY idx_source (source)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->suppress_errors();
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        $wpdb->suppress_errors(false);

        update_option('aivesese_merchandising_db_version', $this->db_version);
        return true;
    }

    public function handle_track_event(): void {
        check_ajax_referer('aivs_tracking_nonce', 'nonce');

        $event_type = sanitize_key((string) ($_POST['event_type'] ?? ''));
        $surface = sanitize_key((string) ($_POST['surface'] ?? 'search'));
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $anchor_product_id = isset($_POST['anchor_product_id']) ? absint($_POST['anchor_product_id']) : 0;
        $search_term = sanitize_text_field(wp_unslash($_POST['search_term'] ?? ''));
        $position_index = isset($_POST['position_index']) ? absint($_POST['position_index']) : null;
        $session_id = sanitize_text_field((string) ($_POST['session_id'] ?? ''));

        if ($event_type === '') {
            wp_send_json_error(['message' => 'Missing event type'], 400);
            return;
        }

        $this->track_event($event_type, [
            'surface' => $surface,
            'product_id' => $product_id,
            'anchor_product_id' => $anchor_product_id,
            'search_term' => $search_term,
            'position_index' => $position_index,
            'session_id' => $session_id,
        ]);

        wp_send_json_success(['tracked' => true]);
    }

    public function handle_manual_refresh(): void {
        check_ajax_referer('aivs_merchandising_refresh_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        $this->refresh_daily_metrics();
        $this->rebuild_bundle_candidates();

        $pushed = false;
        if (get_option('aivesese_connection_mode', 'lite') === 'api') {
            $metrics_rows = $this->get_metrics_for_push();
            $bundles_rows = $this->get_bundles_for_push();
            $pushed = AIVectorSearch_API_Client::instance()->push_merchandising_metrics($metrics_rows)
                   && AIVectorSearch_API_Client::instance()->push_bundle_candidates($bundles_rows);
        }

        $summary = $this->get_dashboard_summary();

        wp_send_json_success([
            'message'          => 'Merchandising data refreshed successfully.',
            'bundle_candidates' => (int) $summary['bundle_candidates'],
            'pushed_to_api'    => $pushed,
        ]);
    }

    public function track_event(string $event_type, array $context = []): void {
        global $wpdb;

        $session_id = !empty($context['session_id']) ? sanitize_text_field((string) $context['session_id']) : $this->get_session_id();
        $metadata = !empty($context['metadata']) && is_array($context['metadata']) ? wp_json_encode($context['metadata']) : null;

        $wpdb->insert($this->events_table, [
            'event_type' => sanitize_key($event_type),
            'surface' => sanitize_key((string) ($context['surface'] ?? 'search')),
            'search_term' => !empty($context['search_term']) ? sanitize_text_field((string) $context['search_term']) : null,
            'product_id' => !empty($context['product_id']) ? (int) $context['product_id'] : null,
            'anchor_product_id' => !empty($context['anchor_product_id']) ? (int) $context['anchor_product_id'] : null,
            'session_id' => $session_id,
            'user_id' => get_current_user_id() ?: null,
            'order_id' => !empty($context['order_id']) ? (int) $context['order_id'] : null,
            'position_index' => isset($context['position_index']) ? (int) $context['position_index'] : null,
            'revenue' => isset($context['revenue']) ? (float) $context['revenue'] : null,
            'margin_value' => isset($context['margin_value']) ? (float) $context['margin_value'] : null,
            'metadata' => $metadata,
            'created_at' => current_time('mysql'),
        ]);
    }

    public function track_search_results(string $term, array $product_ids, string $surface = 'search'): void {
        foreach (array_values($product_ids) as $index => $product_id) {
            $this->track_event('impression', [
                'surface' => $surface,
                'search_term' => $term,
                'product_id' => (int) $product_id,
                'position_index' => $index + 1,
            ]);
        }
    }

    public function track_recommendations(string $surface, array $product_ids, int $anchor_product_id = 0): void {
        foreach (array_values($product_ids) as $index => $product_id) {
            $this->track_event('impression', [
                'surface' => $surface,
                'product_id' => (int) $product_id,
                'anchor_product_id' => $anchor_product_id,
                'position_index' => $index + 1,
            ]);
        }
    }

    public function rank_product_ids(array $ids, string $surface, array $context = []): array {
        // In API mode the server already applies business ranking before returning results.
        // Running local re-ranking on top would double-rank and corrupt the order.
        $is_api_mode = get_option('aivesese_connection_mode', 'lite') === 'api';
        if ($is_api_mode || !$this->is_business_ranking_enabled($surface) || count($ids) < 2) {
            return array_values(array_unique(array_map('intval', $ids)));
        }

        $weights = $this->get_business_weights();
        $metrics = $this->get_product_metric_map($ids, $surface);
        $scored = [];

        // Prime the WC object cache for all IDs in one pass to avoid N+1 queries.
        $unique_ids = array_values(array_unique(array_map('intval', $ids)));
        _prime_post_caches($unique_ids, false, false);

        foreach ($unique_ids as $position => $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $base_relevance = 1 / ($position + 1);
            if ($base_relevance < $this->get_relevance_floor()) {
                $scored[$product_id] = $base_relevance;
                continue;
            }

            $sold = (float) $product->get_total_sales();
            $sales_score = min(1.0, $sold / 100.0);

            $regular_price = (float) $product->get_regular_price();
            $cost_price = (float) get_post_meta($product_id, '_cost_price', true);
            $margin_score = 0.0;
            if ($regular_price > 0 && $cost_price > 0 && $regular_price > $cost_price) {
                $margin_score = min(1.0, (($regular_price - $cost_price) / $regular_price));
            }

            $demand_score = (float) ($metrics[$product_id]['demand_score'] ?? 0.0);

            $score = ($base_relevance * $weights['relevance'])
                + ($sales_score * $weights['sales'])
                + ($margin_score * $weights['margin'])
                + ($demand_score * $weights['demand']);

            if ($product->is_featured()) {
                $score += 0.03;
            }

            if (!$product->is_in_stock()) {
                $score -= 0.10;
            }

            $scored[$product_id] = $score;
        }

        arsort($scored, SORT_NUMERIC);
        return array_values(array_map('intval', array_keys($scored)));
    }

    public function get_bundle_candidates_for_products(array $anchor_product_ids, int $limit = 4): array {
        global $wpdb;

        $anchor_product_ids = array_values(array_filter(array_map('intval', $anchor_product_ids)));
        if (empty($anchor_product_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($anchor_product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT anchor_product_id, bundled_product_id, source, support_count, confidence_score, attach_rate, bundle_score, ai_label
             FROM {$this->bundles_table_escaped}
             WHERE anchor_product_id IN ($placeholders)
             ORDER BY bundle_score DESC, support_count DESC
             LIMIT %d",
            array_merge($anchor_product_ids, [$limit * max(1, count($anchor_product_ids))])
        );

        if (!$sql) {
            return [];
        }

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $candidates = [];
        $seen = [];

        foreach ($rows as $row) {
            $product_id = (int) ($row['bundled_product_id'] ?? 0);
            if ($product_id <= 0 || in_array($product_id, $anchor_product_ids, true) || isset($seen[$product_id])) {
                continue;
            }

            $seen[$product_id] = true;
            $candidates[] = [
                'woocommerce_id' => $product_id,
                'source' => $row['source'] ?? 'orders',
                'bundle_score' => (float) ($row['bundle_score'] ?? 0),
                'confidence_score' => (float) ($row['confidence_score'] ?? 0),
                'attach_rate' => (float) ($row['attach_rate'] ?? 0),
                'ai_label' => (string) ($row['ai_label'] ?? ''),
            ];

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    public function refresh_daily_metrics(): void {
        global $wpdb;

        // Only aggregate the rolling window used for scoring; avoids full table scans
        // as the events table grows. We keep up to 90 days regardless of the attribution
        // window setting so historical data remains visible in the admin dashboard.
        $since = gmdate('Y-m-d', time() - (90 * DAY_IN_SECONDS));

        $query = $wpdb->prepare(
            "SELECT
                DATE(created_at) AS metric_date,
                COALESCE(product_id, 0) AS product_id,
                surface,
                SUM(CASE WHEN event_type = 'impression' THEN 1 ELSE 0 END) AS impressions,
                SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks,
                SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_carts,
                SUM(CASE WHEN event_type = 'attributed_order' THEN 1 ELSE 0 END) AS attributed_orders,
                SUM(CASE WHEN event_type = 'attributed_order' THEN COALESCE(revenue, 0) ELSE 0 END) AS attributed_revenue,
                SUM(CASE WHEN event_type = 'attributed_order' THEN COALESCE(margin_value, 0) ELSE 0 END) AS attributed_margin
            FROM {$this->events_table_escaped}
            WHERE product_id IS NOT NULL
              AND created_at >= %s
            GROUP BY DATE(created_at), product_id, surface",
            $since
        );

        $rows = $query ? (array) $wpdb->get_results($query, ARRAY_A) : [];
        foreach ($rows as $row) {
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $adds = (int) $row['add_to_carts'];
            $orders = (int) $row['attributed_orders'];
            $demand_score = min(1.0, (($clicks * 0.4) + ($adds * 0.8) + ($orders * 1.5)) / max(1, $impressions));

            $wpdb->replace($this->metrics_table, [
                'metric_date' => $row['metric_date'],
                'product_id' => (int) $row['product_id'],
                'surface' => sanitize_key((string) $row['surface']),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'add_to_carts' => $adds,
                'attributed_orders' => $orders,
                'attributed_revenue' => (float) $row['attributed_revenue'],
                'attributed_margin' => (float) $row['attributed_margin'],
                'demand_score' => $demand_score,
                'updated_at' => current_time('mysql'),
            ]);
        }

        // In API mode, push the refreshed metrics to the managed API so the
        // Supabase business_ranked_* RPCs have an up-to-date demand signal.
        if (get_option('aivesese_connection_mode', 'lite') === 'api') {
            $push_rows = $this->get_metrics_for_push();
            if (!empty($push_rows)) {
                AIVectorSearch_API_Client::instance()->push_merchandising_metrics($push_rows);
            }
        }
    }

    public function rebuild_bundle_candidates(): void {
        global $wpdb;

        $days = (int) $this->get_attribution_window_days();
        $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, product_id
             FROM {$this->events_table_escaped}
             WHERE event_type = 'attributed_order'
               AND order_id IS NOT NULL
               AND product_id IS NOT NULL
               AND created_at >= %s
             ORDER BY order_id ASC",
            $since
        ), ARRAY_A);

        $orders = [];
        foreach ($rows as $row) {
            $order_id = (int) $row['order_id'];
            $product_id = (int) $row['product_id'];
            if ($order_id <= 0 || $product_id <= 0) {
                continue;
            }

            if (!isset($orders[$order_id])) {
                $orders[$order_id] = [];
            }
            $orders[$order_id][$product_id] = true;
        }

        $counts = [];
        $anchor_totals = [];
        foreach ($orders as $product_map) {
            $products = array_keys($product_map);
            foreach ($products as $anchor) {
                if (!isset($anchor_totals[$anchor])) {
                    $anchor_totals[$anchor] = 0;
                }
                $anchor_totals[$anchor]++;
            }

            for ($i = 0; $i < count($products); $i++) {
                for ($j = 0; $j < count($products); $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    $anchor = (int) $products[$i];
                    $bundled = (int) $products[$j];
                    if (!isset($counts[$anchor])) {
                        $counts[$anchor] = [];
                    }
                    if (!isset($counts[$anchor][$bundled])) {
                        $counts[$anchor][$bundled] = 0;
                    }
                    $counts[$anchor][$bundled]++;
                }
            }
        }

        $thresholds = $this->get_bundle_thresholds();
        $rebuild_time = current_time('mysql');

        // Prime product object cache for all bundled product IDs in one pass.
        $all_bundled_ids = [];
        foreach ($counts as $bundled_items) {
            $all_bundled_ids = array_merge($all_bundled_ids, array_keys($bundled_items));
        }
        if (!empty($all_bundled_ids)) {
            _prime_post_caches(array_values(array_unique($all_bundled_ids)), false, false);
        }

        foreach ($counts as $anchor => $bundled_items) {
            foreach ($bundled_items as $bundled => $support_count) {
                if ($support_count < $thresholds['min_support']) {
                    continue;
                }

                $confidence = $support_count / max(1, (int) ($anchor_totals[$anchor] ?? 1));
                if ($confidence < $thresholds['min_confidence']) {
                    continue;
                }

                $product = wc_get_product($bundled);
                $margin_score = 0.0;
                if ($product) {
                    $regular_price = (float) $product->get_regular_price();
                    $cost_price = (float) get_post_meta($bundled, '_cost_price', true);
                    if ($regular_price > 0 && $cost_price > 0 && $regular_price > $cost_price) {
                        $margin_score = ($regular_price - $cost_price) / $regular_price;
                    }
                }

                $bundle_score = ($confidence * 0.7) + (min(1.0, $support_count / 10) * 0.2) + ($margin_score * 0.1);
                $wpdb->replace($this->bundles_table, [
                    'anchor_product_id' => $anchor,
                    'bundled_product_id' => $bundled,
                    'source' => 'orders',
                    'support_count' => $support_count,
                    'confidence_score' => $confidence,
                    'attach_rate' => $confidence,
                    'bundle_score' => $bundle_score,
                    'ai_label' => $this->build_bundle_label($anchor, $bundled, $confidence),
                    'last_seen_at' => $rebuild_time,
                    'updated_at' => $rebuild_time,
                ]);
            }
        }

        // Prune pairs that were not seen in this rebuild cycle. These are anchors
        // that appeared in the order data but whose pairs fell below thresholds, or
        // pairs belonging to anchors with no recent orders at all.
        if (!empty($counts)) {
            $anchor_placeholders = implode(',', array_fill(0, count($counts), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->bundles_table_escaped}
                 WHERE source = 'orders'
                   AND anchor_product_id IN ($anchor_placeholders)
                   AND last_seen_at < %s",
                array_merge(array_keys($counts), [$rebuild_time])
            ));
        }

        // In API mode, push rebuilt candidates to the managed API so the
        // bundle_recommendations Supabase RPC has data to return.
        if (get_option('aivesese_connection_mode', 'lite') === 'api') {
            $push_rows = $this->get_bundles_for_push();
            if (!empty($push_rows)) {
                AIVectorSearch_API_Client::instance()->push_bundle_candidates($push_rows);
            }
        }
    }

    public function capture_completed_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $product_ids = [];
        $line_items = $order->get_items('line_item');

        // Prime object cache before iterating to avoid one query per line item.
        $all_product_ids = array_filter(array_map(
            fn($item) => (int) $item->get_product_id(),
            iterator_to_array($line_items)
        ));
        if (!empty($all_product_ids)) {
            _prime_post_caches(array_values($all_product_ids), false, false);
        }

        foreach ($line_items as $item) {
            $product_id = (int) $item->get_product_id();
            if ($product_id <= 0) {
                continue;
            }

            $product_ids[] = $product_id;
            $product = wc_get_product($product_id);
            $revenue = (float) $item->get_total();
            $margin_value = null;
            if ($product) {
                $regular_price = (float) $product->get_regular_price();
                $cost_price = (float) get_post_meta($product_id, '_cost_price', true);
                if ($regular_price > 0 && $cost_price > 0) {
                    $margin_value = max(0, ($regular_price - $cost_price) * max(1, (int) $item->get_quantity()));
                }
            }

            $this->track_event('attributed_order', [
                'surface' => 'order',
                'product_id' => $product_id,
                'order_id' => $order_id,
                'revenue' => $revenue,
                'margin_value' => $margin_value,
            ]);
        }
    }

    public function track_recommendation_click(): void {
        if (!isset($_GET['from_recommendation']) || !is_singular('product')) {
            return;
        }

        $product_id = get_the_ID();
        $surface = sanitize_key((string) ($_GET['recommendation_surface'] ?? 'recommendation'));
        $anchor_product_id = isset($_GET['anchor_product_id']) ? absint($_GET['anchor_product_id']) : 0;
        $this->track_event('click', [
            'surface' => $surface,
            'product_id' => $product_id,
            'anchor_product_id' => $anchor_product_id,
        ]);
    }

    public function track_add_to_cart(string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data): void {
        $surface = !empty($_REQUEST['from_recommendation']) ? sanitize_key((string) $_REQUEST['from_recommendation']) : 'cart';
        $anchor_product_id = isset($_REQUEST['anchor_product_id']) ? absint($_REQUEST['anchor_product_id']) : 0;
        $this->track_event('add_to_cart', [
            'surface' => $surface === '1' ? 'recommendation' : $surface,
            'product_id' => $product_id,
            'anchor_product_id' => $anchor_product_id,
            'metadata' => [
                'quantity' => $quantity,
                'variation_id' => $variation_id,
            ],
        ]);
    }

    public function render_admin_page(): void {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $status = $this->get_managed_feature_status();
        $summary = $this->get_dashboard_summary();
        $top_products = $this->get_top_products();
        $top_bundles = $this->get_top_bundles();
        $base_url = admin_url('admin.php?page=aivesese-merchandising');

        echo '<div class="wrap aivs-analytics-dashboard">';
        echo '<h1>Merchandising Analytics</h1>';
        echo '<p>Business-aware ranking, product demand, and bundle intelligence across search and recommendations.</p>';

        if (!empty($status['notice'])) {
            echo '<div class="notice notice-info inline"><p>' . esc_html($status['notice']) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';
        foreach ([
            'overview' => 'Overview',
            'search' => 'Search Performance',
            'products' => 'Product Performance',
            'bundles' => 'Bundles',
            'settings' => 'Settings',
        ] as $slug => $label) {
            $class = $tab === $slug ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(add_query_arg('tab', $slug, $base_url)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ($tab === 'overview') {
            $refresh_nonce = wp_create_nonce('aivs_merchandising_refresh_nonce');
            $is_api_mode   = get_option('aivesese_connection_mode', 'lite') === 'api';
            echo '<div style="margin-top:16px;margin-bottom:8px;display:flex;align-items:center;gap:12px;">';
            echo '<button id="aivs-merch-refresh-btn" class="button button-primary">Refresh Now</button>';
            echo '<span id="aivs-merch-refresh-status" style="display:none;"></span>';
            echo '</div>';
            echo '<script>
(function($){
    $("#aivs-merch-refresh-btn").on("click", function(){
        var $btn = $(this);
        var $status = $("#aivs-merch-refresh-status");
        $btn.prop("disabled", true).text("Refreshing\u2026");
        $status.hide();
        $.post(' . wp_json_encode(admin_url('admin-ajax.php')) . ', {
            action: "aivs_run_merchandising_refresh",
            nonce:  ' . wp_json_encode($refresh_nonce) . '
        }, function(res){
            $btn.prop("disabled", false).text("Refresh Now");
            if (res.success) {
                var msg = res.data.message;
                if (' . ($is_api_mode ? 'true' : 'false') . ') {
                    msg += res.data.pushed_to_api ? " Data synced to API." : " API sync failed \u2014 check logs.";
                }
                msg += " Bundle candidates: " + res.data.bundle_candidates + ".";
                $status.text(msg).css("color","green").show();
            } else {
                $status.text(res.data.message || "Refresh failed.").css("color","red").show();
            }
        }).fail(function(){
            $btn.prop("disabled", false).text("Refresh Now");
            $status.text("Request failed. Please try again.").css("color","red").show();
        });
    });
}(jQuery));
</script>';

            echo '<div class="aivs-stats-grid">';
            $cards = [
                'Influenced Searches' => (int) $summary['influenced_searches'],
                'Search CTR' => round((float) $summary['ctr_delta'], 1) . '%',
                'Assisted Revenue' => wc_price((float) $summary['assisted_revenue']),
                'Bundle Candidates' => (int) $summary['bundle_candidates'],
            ];
            foreach ($cards as $label => $value) {
                echo '<div class="aivs-stat-card"><h3>' . esc_html($label) . '</h3><div class="aivs-stat-number">' . wp_kses_post((string) $value) . '</div></div>';
            }
            echo '</div>';
        } elseif ($tab === 'search' || $tab === 'products') {
            echo '<div class="aivs-analytics-section" style="margin-top:16px;">';
            echo '<h2>' . esc_html($tab === 'search' ? 'Top Boosted Products' : 'Product Performance') . '</h2>';
            echo '<table class="wp-list-table widefat fixed striped aivs-data-table"><thead><tr><th>Product</th><th class="numeric">Impressions</th><th class="numeric">Clicks</th><th class="numeric">Add To Cart</th><th class="numeric">Demand</th><th class="numeric">Assisted Revenue</th></tr></thead><tbody>';
            foreach ($top_products as $row) {
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link((int) $row['product_id'])) . '">' . esc_html(get_the_title((int) $row['product_id'])) . '</a></td>';
                echo '<td class="numeric">' . number_format_i18n((int) $row['impressions']) . '</td>';
                echo '<td class="numeric">' . number_format_i18n((int) $row['clicks']) . '</td>';
                echo '<td class="numeric">' . number_format_i18n((int) $row['add_to_carts']) . '</td>';
                echo '<td class="numeric">' . esc_html(number_format_i18n((float) $row['demand_score'], 2)) . '</td>';
                echo '<td class="numeric">' . wp_kses_post(wc_price((float) $row['attributed_revenue'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } elseif ($tab === 'bundles') {
            echo '<div class="aivs-analytics-section" style="margin-top:16px;">';
            echo '<h2>Top Bundle Candidates</h2>';
            echo '<table class="wp-list-table widefat fixed striped aivs-data-table"><thead><tr><th>Anchor</th><th>Bundle Product</th><th class="numeric">Support</th><th class="numeric">Confidence</th><th class="numeric">Score</th><th>AI Label</th></tr></thead><tbody>';
            foreach ($top_bundles as $row) {
                echo '<tr>';
                echo '<td>' . esc_html(get_the_title((int) $row['anchor_product_id'])) . '</td>';
                echo '<td>' . esc_html(get_the_title((int) $row['bundled_product_id'])) . '</td>';
                echo '<td class="numeric">' . number_format_i18n((int) $row['support_count']) . '</td>';
                echo '<td class="numeric">' . esc_html(number_format_i18n((float) $row['confidence_score'], 2)) . '</td>';
                echo '<td class="numeric">' . esc_html(number_format_i18n((float) $row['bundle_score'], 2)) . '</td>';
                echo '<td>' . esc_html((string) $row['ai_label']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="aivs-analytics-section" style="margin-top:16px;">';
            echo '<h2>Settings</h2>';
            echo '<p>Configure advanced ranking from the main Settings screen. Starter plans can see this page, but premium controls and managed metrics are plan-gated.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=aivesese')) . '">Open Settings</a></p>';
            echo '</div>';
        }

        echo '</div>';
    }

    public function get_managed_feature_status(): array {
        $mode = get_option('aivesese_connection_mode', 'lite');
        if ($mode !== 'api') {
            return ['notice' => 'Self-hosted and lite stores compute merchandising analytics locally.'];
        }

        $status = AIVectorSearch_API_Client::instance()->get_status();
        $features = is_array($status['features'] ?? null) ? $status['features'] : [];
        if (empty($features['advanced_analytics'])) {
            return ['notice' => 'Your current managed API plan does not include advanced merchandising analytics.'];
        }

        return ['notice' => 'Managed API merchandising features are available for this plan.'];
    }

    /**
     * Return daily metrics rows for the attribution window, ready to push to the API.
     */
    private function get_metrics_for_push(): array {
        global $wpdb;

        $days  = $this->get_attribution_window_days();
        $since = gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS));

        $sql = $wpdb->prepare(
            "SELECT metric_date, product_id, surface, impressions, clicks, add_to_carts,
                    attributed_orders, attributed_revenue, attributed_margin, demand_score
             FROM {$this->metrics_table_escaped}
             WHERE metric_date >= %s",
            $since
        );

        return $sql ? (array) $wpdb->get_results($sql, ARRAY_A) : [];
    }

    /**
     * Return all active bundle candidates, ready to push to the API.
     */
    private function get_bundles_for_push(): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            "SELECT anchor_product_id, bundled_product_id, source, support_count,
                    confidence_score, attach_rate, bundle_score, ai_label
             FROM {$this->bundles_table_escaped}
             ORDER BY bundle_score DESC",
            ARRAY_A
        );
    }

    private function get_dashboard_summary(): array {
        global $wpdb;

        $summary = [
            'influenced_searches' => 0,
            'ctr_delta' => 0,
            'assisted_revenue' => 0,
            'bundle_candidates' => 0,
        ];

        $row = $wpdb->get_row("SELECT
                SUM(CASE WHEN surface = 'search' AND event_type = 'impression' THEN 1 ELSE 0 END) AS influenced_searches,
                SUM(CASE WHEN event_type = 'attributed_order' THEN COALESCE(revenue, 0) ELSE 0 END) AS assisted_revenue
            FROM {$this->events_table_escaped}", ARRAY_A);

        if (is_array($row)) {
            $summary['influenced_searches'] = (int) ($row['influenced_searches'] ?? 0);
            $summary['assisted_revenue'] = (float) ($row['assisted_revenue'] ?? 0);
        }

        $summary['bundle_candidates'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->bundles_table_escaped}");
        $summary['ctr_delta'] = $this->calculate_search_ctr();
        return $summary;
    }

    private function get_top_products(int $limit = 20): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT product_id,
                    SUM(impressions) AS impressions,
                    SUM(clicks) AS clicks,
                    SUM(add_to_carts) AS add_to_carts,
                    AVG(demand_score) AS demand_score,
                    SUM(attributed_revenue) AS attributed_revenue
             FROM {$this->metrics_table_escaped}
             GROUP BY product_id
             ORDER BY demand_score DESC, attributed_revenue DESC
             LIMIT %d",
            $limit
        );

        return $sql ? (array) $wpdb->get_results($sql, ARRAY_A) : [];
    }

    private function get_top_bundles(int $limit = 20): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT anchor_product_id, bundled_product_id, support_count, confidence_score, bundle_score, ai_label
             FROM {$this->bundles_table_escaped}
             ORDER BY bundle_score DESC, support_count DESC
             LIMIT %d",
            $limit
        );

        return $sql ? (array) $wpdb->get_results($sql, ARRAY_A) : [];
    }

    private function calculate_search_ctr(): float {
        global $wpdb;
        // Aggregate from the pre-rolled metrics table (already windowed to 90 days).
        $row = $wpdb->get_row(
            "SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks
             FROM {$this->metrics_table_escaped}
             WHERE surface = 'search'",
            ARRAY_A
        );

        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        if ($impressions === 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    private function get_product_metric_map(array $ids, string $surface): array {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT product_id, AVG(demand_score) AS demand_score
             FROM {$this->metrics_table_escaped}
             WHERE product_id IN ($placeholders)
               AND (surface = %s OR surface = 'all')
             GROUP BY product_id",
            array_merge($ids, [$surface])
        );

        if (!$sql) {
            return [];
        }

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['product_id']] = [
                'demand_score' => (float) $row['demand_score'],
            ];
        }

        return $map;
    }

    private function get_business_weights(): array {
        $defaults = [
            'relevance' => 0.65,
            'sales' => 0.15,
            'margin' => 0.10,
            'demand' => 0.10,
        ];
        $raw = get_option('aivesese_business_ranking_weights', '');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($decoded, $defaults));
    }

    private function get_relevance_floor(): float {
        return max(0.0, min(1.0, (float) get_option('aivesese_business_ranking_relevance_floor', '0.35')));
    }

    private function get_bundle_thresholds(): array {
        $defaults = [
            'min_support' => 2,
            'min_confidence' => 0.15,
            'top_n' => 8,
        ];
        $raw = get_option('aivesese_bundle_thresholds', '');
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($decoded, $defaults));
    }

    private function get_attribution_window_days(): int {
        return max(1, min(90, (int) get_option('aivesese_merchandising_attribution_window_days', '7')));
    }

    private function is_business_ranking_enabled(string $surface): bool {
        if (get_option('aivesese_enable_business_ranking', '1') !== '1') {
            return false;
        }

        if ($surface === 'search') {
            return get_option('aivesese_enable_business_ranking_search', '1') === '1';
        }

        return get_option('aivesese_enable_business_ranking_recommendations', '1') === '1';
    }

    private function build_bundle_label(int $anchor_product_id, int $bundled_product_id, float $confidence): string {
        $anchor = get_the_title($anchor_product_id);
        $bundled = get_the_title($bundled_product_id);
        if ($anchor === '' || $bundled === '') {
            return '';
        }

        if ($confidence >= 0.5) {
            return sprintf('%s customers often add %s next.', $anchor, $bundled);
        }

        return sprintf('%s complements %s in recent orders.', $bundled, $anchor);
    }

    private function get_session_id(): string {
        // Use WooCommerce session when available (avoids native PHP sessions which
        // conflict with WP caching plugins and can cause headers-already-sent errors).
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get('aivs_merch_session');
            if (!$session_id) {
                $session_id = wp_generate_uuid4();
                WC()->session->set('aivs_merch_session', $session_id);
            }
            return (string) $session_id;
        }

        // Fallback: cookie-based session ID for non-WC pages (e.g. admin-ajax).
        $cookie_name = 'aivs_merch_sid';
        if (!empty($_COOKIE[$cookie_name])) {
            return sanitize_text_field(wp_unslash($_COOKIE[$cookie_name]));
        }

        $session_id = wp_generate_uuid4();
        if (!headers_sent()) {
            setcookie($cookie_name, $session_id, [
                'expires'  => time() + HOUR_IN_SECONDS * 2,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $session_id;
    }
}
