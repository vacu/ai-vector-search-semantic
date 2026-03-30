<?php
/**
 * Recommendations class with Connection Manager support
 */
class AIVectorSearch_Recommendations {

    private static $instance = null;
    private $connection_manager;
    private $merchandising;
    private $cart_recommendations_rendered = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance();
        $this->merchandising = AIVectorSearch_Merchandising::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Cart recommendations
        add_filter('woocommerce_cart_item_name', [$this, 'trigger_cart_recommendations'], 10, 2);

        // Similar products on PDP
        if (get_option('aivesese_enable_pdp_similar', '1') === '1') {
            add_filter('woocommerce_related_products_args', [$this, 'preserve_related_order'], 20);
            add_filter('woocommerce_related_products', [$this, 'get_similar_products'], 10, 3);
        }
    }

    public function trigger_cart_recommendations($name, $cart_item) {
        if (get_option('aivesese_enable_cart_below', '1') !== '1') {
            return $name;
        }

        if (!$this->cart_recommendations_rendered) {
            add_action('woocommerce_after_cart', [$this, 'render_cart_recommendations']);
            $this->cart_recommendations_rendered = true;
        }

        return $name;
    }

    public function render_cart_recommendations() {
        $html = $this->get_cart_recommendations_html([
            'respect_setting' => true,
        ]);

        if ($html) {
            echo $html;
        }
    }

    public function preserve_related_order($args) {
        $args['orderby'] = 'post__in';
        $args['posts_per_page'] = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 8;
        return $args;
    }

    public function get_similar_products($related, $product_id, $args) {
        if (get_option('aivesese_enable_pdp_similar', '1') !== '1') {
            return $related;
        }

        $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 4;

        // Use connection manager for similar products
        $similar_products = $this->connection_manager->get_similar_products($product_id, $limit);

        $ids = wp_list_pluck((array) $similar_products, 'woocommerce_id');
        $ids = $this->merchandising->rank_product_ids($ids, 'recommendations', ['product_id' => $product_id]);
        $this->merchandising->track_recommendations('similar_products', $ids, $product_id);
        return !empty($ids) ? $ids : $related;
    }

    public function get_cart_recommendations_html(array $args = []): string {
        $defaults = [
            'title' => 'You might also like',
            'limit' => 4,
            'columns' => 4,
            'wrapper_class' => '',
            'respect_setting' => false,
        ];

        $args = array_merge($defaults, $args);

        if ($args['respect_setting'] && get_option('aivesese_enable_cart_below', '1') !== '1') {
            return '';
        }

        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        $cart_items = WC()->cart->get_cart_contents();
        $cart_ids = array_map(function($item) {
            return $item['product_id'];
        }, $cart_items);

        if (empty($cart_ids)) {
            return '';
        }

        $limit = max(1, (int) $args['limit']);
        $columns = max(1, (int) $args['columns']);
        $wrapper_class = (string) $args['wrapper_class'];

        // Use connection manager for recommendations.
        // Local bundle candidates (mined from WP events) are only used in lite mode;
        // in supabase/api mode the connection manager already calls the bundle_recommendations RPC.
        $connection_mode = get_option('aivesese_connection_mode', 'lite');
        // Use local bundle candidates for both lite and supabase-direct modes.
        // In API mode the server manages bundles (once the write path exists).
        // In supabase-direct mode the Supabase bundle_candidates table has no write
        // path yet, so local mining is the only working source.
        $use_local_bundles = $connection_mode !== 'api'
            && get_option('aivesese_enable_bundle_recommendations', '1') === '1';
        $bundle_candidates = $use_local_bundles
            ? $this->merchandising->get_bundle_candidates_for_products($cart_ids, $limit)
            : [];
        $recommendations = !empty($bundle_candidates)
            ? $bundle_candidates
            : $this->connection_manager->get_recommendations($cart_ids, $limit);

        if (empty($recommendations)) {
            return '';
        }

        $ranked_ids = $this->merchandising->rank_product_ids(wp_list_pluck((array) $recommendations, 'woocommerce_id'), 'recommendations', ['cart_ids' => $cart_ids]);
        $map = [];
        foreach ((array) $recommendations as $recommendation) {
            if (!empty($recommendation['woocommerce_id'])) {
                $map[(int) $recommendation['woocommerce_id']] = $recommendation;
            }
        }
        $recommendations = [];
        foreach ($ranked_ids as $product_id) {
            if (isset($map[$product_id])) {
                $recommendations[] = $map[$product_id];
            } else {
                $recommendations[] = ['woocommerce_id' => $product_id];
            }
        }
        $this->merchandising->track_recommendations('cart_recommendations', $ranked_ids, !empty($cart_ids) ? (int) $cart_ids[0] : 0);

        ob_start();
        $this->render_recommendations_html($recommendations, (string) $args['title'], $columns, $wrapper_class, !empty($cart_ids) ? (int) $cart_ids[0] : 0);
        return ob_get_clean();
    }

    private function render_recommendations_html(
        array $recommendations,
        string $title = '',
        int $columns = 4,
        string $wrapper_class = '',
        int $anchor_product_id = 0
    ) {
        if ($title) {
            echo '<h3>' . esc_html($title) . '</h3>';
        }

        $columns = max(1, $columns);
        $classes = trim('products supabase-recs columns-' . $columns . ' ' . $wrapper_class);
        echo '<ul class="' . esc_attr($classes) . '">';

        foreach ($recommendations as $recommendation) {
            $product_id = $recommendation['woocommerce_id'] ?? 0;
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $this->render_product_item($product, $wrapper_class !== '' ? $wrapper_class : 'recommendation', $anchor_product_id);
        }

        echo '</ul>';
    }

    private function render_product_item(WC_Product $product, string $surface = 'recommendation', int $anchor_product_id = 0) {
        $permalink = add_query_arg([
            'from_recommendation' => '1',
            'recommendation_surface' => $surface,
            'anchor_product_id' => $anchor_product_id,
        ], get_permalink($product->get_id()));
        $image = $product->get_image();
        $title = $product->get_name();
        $price = $product->get_price_html();

        echo '<li class="product">';
        echo '<a href="' . esc_url($permalink) . '">';
        echo wp_kses_post($image);
        echo '<h2 class="woocommerce-loop-product__title">' . esc_html($title) . '</h2>';
        echo wp_kses_post($price);
        echo '</a>';
        echo '</li>';
    }
}
