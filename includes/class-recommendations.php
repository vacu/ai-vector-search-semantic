<?php
/**
 * Handles product recommendations (cart and similar products)
 */
class AIVectorSearch_Recommendations {

    private static $instance = null;
    private $supabase_client;
    private $cart_recommendations_rendered = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
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
        if (get_option('aivesese_enable_cart_below', '1') !== '1') {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $cart_items = WC()->cart->get_cart_contents();
        $cart_ids = array_map(function($item) {
            return $item['product_id'];
        }, $cart_items);

        if (empty($cart_ids)) {
            return;
        }

        $recommendations = $this->supabase_client->get_recommendations($cart_ids, 4);
        if (empty($recommendations)) {
            return;
        }

        $this->render_recommendations_html($recommendations, 'You might also like');
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
        $similar_products = $this->supabase_client->get_similar_products($product_id, $limit);

        $ids = wp_list_pluck((array) $similar_products, 'woocommerce_id');
        return !empty($ids) ? $ids : $related;
    }

    private function render_recommendations_html(array $recommendations, string $title = '') {
        if ($title) {
            echo '<h3>' . esc_html($title) . '</h3>';
        }

        echo '<ul class="products supabase-recs columns-4">';

        foreach ($recommendations as $recommendation) {
            $product_id = $recommendation['woocommerce_id'] ?? 0;
            $product = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            $this->render_product_item($product);
        }

        echo '</ul>';
    }

    private function render_product_item(WC_Product $product) {
        $permalink = get_permalink($product->get_id());
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
