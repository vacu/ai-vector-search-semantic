<?php
/**
 * Shortcode, block, and Elementor integrations for cart recommendations.
 */
class AIVectorSearch_Recommendations_Integrations {

    private static $instance = null;
    private $recommendations;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->recommendations = AIVectorSearch_Recommendations::instance();

        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'register_block']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('elementor/widgets/register', [$this, 'register_elementor_widget']);
    }

    public function register_shortcode() {
        add_shortcode('aivesese_cart_recommendations', [$this, 'render_shortcode']);
    }

    public function render_shortcode($atts = []): string {
        $atts = shortcode_atts(
            [
                'title' => 'You might also like',
                'limit' => 4,
                'columns' => 4,
                'class' => '',
            ],
            $atts,
            'aivesese_cart_recommendations'
        );

        $limit = $this->clamp_int($atts['limit'], 1, 12, 4);
        $columns = $this->clamp_int($atts['columns'], 1, 6, 4);
        $class = $this->sanitize_class_list((string) $atts['class']);
        $title = sanitize_text_field((string) $atts['title']);

        return $this->recommendations->get_cart_recommendations_html([
            'title' => $title,
            'limit' => $limit,
            'columns' => $columns,
            'wrapper_class' => $class,
            'respect_setting' => false,
        ]);
    }

    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('aivesese/cart-recommendations', [
            'render_callback' => [$this, 'render_block'],
            'attributes' => [
                'title' => [
                    'type' => 'string',
                    'default' => 'You might also like',
                ],
                'limit' => [
                    'type' => 'number',
                    'default' => 4,
                ],
                'columns' => [
                    'type' => 'number',
                    'default' => 4,
                ],
                'className' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
            'editor_script' => 'aivesese-cart-recommendations-block',
        ]);
    }

    public function enqueue_block_editor_assets() {
        $handle = 'aivesese-cart-recommendations-block';

        if (wp_script_is($handle, 'registered')) {
            wp_enqueue_script($handle);
            return;
        }

        wp_register_script(
            $handle,
            AIVESESE_PLUGIN_URL . 'assets/js/cart-recommendations-block.js',
            ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor'],
            AIVESESE_PLUGIN_VERSION,
            true
        );

        wp_enqueue_script($handle);
    }

    public function render_block(array $attributes = []): string {
        $limit = $this->clamp_int($attributes['limit'] ?? 4, 1, 12, 4);
        $columns = $this->clamp_int($attributes['columns'] ?? 4, 1, 6, 4);
        $title = sanitize_text_field((string) ($attributes['title'] ?? 'You might also like'));
        $class = $this->sanitize_class_list((string) ($attributes['className'] ?? ''));
        $class = trim('wp-block-aivesese-cart-recommendations ' . $class);

        return $this->recommendations->get_cart_recommendations_html([
            'title' => $title,
            'limit' => $limit,
            'columns' => $columns,
            'wrapper_class' => $class,
            'respect_setting' => false,
        ]);
    }

    public function register_elementor_widget($widgets_manager) {
        if (!class_exists('\\Elementor\\Widget_Base')) {
            return;
        }

        require_once AIVESESE_PLUGIN_PATH . 'includes/elementor/class-cart-recommendations-widget.php';

        $widgets_manager->register(new AIVectorSearch_Cart_Recommendations_Widget());
    }

    private function clamp_int($value, int $min, int $max, int $fallback): int {
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            return $fallback;
        }
        return $value;
    }

    private function sanitize_class_list(string $value): string {
        $value = preg_replace('/[^A-Za-z0-9 _-]/', '', $value);
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));
        return $value ?: '';
    }
}
