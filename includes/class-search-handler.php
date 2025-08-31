<?php
/**
 * Handles search functionality and query interception
 */
class AIVectorSearch_Search_Handler {

    private static $instance = null;
    private $supabase_client;
    private $openai_client;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->supabase_client = AIVectorSearch_Supabase_Client::instance();
        $this->openai_client = AIVectorSearch_OpenAI_Client::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('pre_get_posts', [$this, 'intercept_product_search'], 20);
    }

    public function intercept_product_search(WP_Query $query) {
        if (is_admin() || 'product' !== $query->get('post_type')) {
            return;
        }

        $search_term = $query->get('s');
        if (!$search_term || strlen($search_term) < 3) {
            return;
        }

        $product_ids = $this->search_products($search_term);
        if (!$product_ids) {
            return;
        }

        $query->set('post__in', $product_ids);
        $query->set('orderby', 'post__in');
        $query->set('posts_per_page', 20);
    }

    public function search_products(string $term, int $limit = 20): array {
        $use_semantic = $this->should_use_semantic_search($term);

        if ($use_semantic) {
            $ids = $this->search_semantic($term, $limit);
            if ($ids) {
                return $ids;
            }
        }

        return $this->search_fulltext($term, $limit);
    }

    private function search_semantic(string $term, int $limit): array {
        $embedding = $this->openai_client->embed_single($term);
        if (!$embedding) {
            return [];
        }

        return $this->supabase_client->search_products_semantic($term, $embedding, $limit);
    }

    private function search_fulltext(string $term, int $limit): array {
        return $this->supabase_client->search_products_fts($term, $limit);
    }

    private function should_use_semantic_search(string $term): bool {
        return get_option('aivesese_semantic_toggle') === '1' &&
               strlen($term) >= 3 &&
               $this->openai_client->is_configured();
    }
}
