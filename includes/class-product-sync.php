<?php
/**
 * Handles product synchronization between WooCommerce and Supabase
 */
class AIVectorSearch_Product_Sync {

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
        add_action('woocommerce_update_product', [$this, 'auto_sync_product'], 10, 1);
        add_action('woocommerce_new_product', [$this, 'auto_sync_product'], 10, 1);
    }

    public function auto_sync_product($product_id) {
        if (get_option('aivesese_auto_sync') !== '1') {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $with_embeddings = $this->should_generate_embeddings();
        $this->sync_products([$product], $with_embeddings);
    }

    public function sync_all_products(): array {
        $products = $this->get_all_products();
        $with_embeddings = $this->should_generate_embeddings();

        return $this->sync_products($products, $with_embeddings);
    }

    public function sync_products_batch(int $batch_size, int $offset): array {
        $products = $this->get_products_batch($batch_size, $offset);

        if (empty($products)) {
            return [
                'success' => false,
                'message' => sprintf('No products found at offset %d', $offset),
                'synced' => 0,
                'total' => 0,
                'next_offset' => $offset + $batch_size
            ];
        }

        $with_embeddings = $this->should_generate_embeddings();
        return $this->sync_products($products, $with_embeddings);
    }

    public function sync_products(array $products, bool $with_embeddings = false): array {
        if (empty($products)) {
            return ['success' => false, 'message' => 'No products to sync'];
        }

        $transformed_products = [];
        $texts_for_embedding = [];

        foreach ($products as $product) {
            $transformed = $this->transform_product($product);
            $transformed_products[] = $transformed;

            if ($with_embeddings) {
                $text = $this->openai_client->build_embedding_text_from_product($product);
                $texts_for_embedding[] = $text;
            }
        }

        // Generate embeddings if requested
        if ($with_embeddings && !empty($texts_for_embedding)) {
            $embeddings = $this->openai_client->embed_batch($texts_for_embedding);

            if (!empty($embeddings)) {
                foreach ($transformed_products as $i => $product) {
                    if (isset($embeddings[$i])) {
                        $transformed_products[$i]['embedding'] = $embeddings[$i];
                    }
                }
            }
        }

        // Sync to Supabase in batches
        $success_count = 0;
        $batches = array_chunk($transformed_products, 100);

        foreach ($batches as $batch) {
            if ($this->supabase_client->sync_products_batch($batch)) {
                $success_count += count($batch);
            }
        }

        return [
            'success' => true,
            'synced' => $success_count,
            'total' => count($products)
        ];
    }

    public function generate_missing_embeddings(): array {
        $store_id = get_option('aivesese_store');
        if (!$store_id) {
            return ['success' => false, 'message' => 'Store ID missing'];
        }

        if (!$this->openai_client->is_configured()) {
            return ['success' => false, 'message' => 'OpenAI API key missing'];
        }

        $batch_size = 25;
        $max_loops = 400;
        $total_updated = 0;
        $total_skipped = 0;

        for ($loop = 0; $loop < $max_loops; $loop++) {
            $rows = $this->supabase_client->get_products_without_embeddings($batch_size);

            if (empty($rows)) {
                break;
            }

            $result = $this->process_embedding_batch($rows);
            $total_updated += $result['updated'];
            $total_skipped += $result['skipped'];
        }

        return [
            'success' => true,
            'updated' => $total_updated,
            'skipped' => $total_skipped,
            'completed' => empty($rows) // true if we broke out naturally
        ];
    }

    private function process_embedding_batch(array $rows): array {
        $embed_targets = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $sp_id = $row['id'] ?? null;
            $wc_id = isset($row['woocommerce_id']) ? intval($row['woocommerce_id']) : 0;

            if (!$sp_id || !$wc_id) {
                $skipped++;
                continue;
            }

            $product = wc_get_product($wc_id);
            if (!$product) {
                $skipped++;
                continue;
            }

            $text = $this->openai_client->build_embedding_text_from_product($product);
            if ($text === '') {
                $skipped++;
                continue;
            }

            $embed_targets[] = ['id' => $sp_id, 'text' => $text];
        }

        if (empty($embed_targets)) {
            return ['updated' => 0, 'skipped' => $skipped];
        }

        // Generate embeddings
        $texts = array_map(function($t) { return $t['text']; }, $embed_targets);
        $vectors = $this->openai_client->embed_batch($texts);

        if (!is_array($vectors) || count($vectors) !== count($embed_targets)) {
            return ['updated' => 0, 'skipped' => $skipped + count($embed_targets)];
        }

        // Update products with embeddings
        $updated = 0;
        foreach ($embed_targets as $i => $target) {
            if ($this->supabase_client->update_product_embedding($target['id'], $vectors[$i])) {
                $updated++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function transform_product(WC_Product $product): array {
        $store_id = get_option('aivesese_store');

        return [
            'id' => wp_generate_uuid4(),
            'store_id' => $store_id,
            'woocommerce_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'gtin' => $this->get_product_gtin($product),
            'name' => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_description()),
            'image_url' => $this->get_product_image_url($product),
            'brand' => $this->get_product_brand($product),
            'categories' => $this->get_product_categories($product),
            'tags' => $this->get_product_tags($product),
            'regular_price' => $this->get_product_price($product, 'regular'),
            'sale_price' => $this->get_product_price($product, 'sale'),
            'cost_price' => $this->get_product_cost_price($product),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status() === 'instock' ? 'in' : 'out',
            'attributes' => $this->get_product_attributes($product),
            'status' => $product->get_status(),
            'average_rating' => $product->get_average_rating() ? floatval($product->get_average_rating()) : null,
            'review_count' => $product->get_review_count() ? intval($product->get_review_count()) : 0,
        ];
    }

    private function get_all_products(): array {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'product_visibility',
                    'field' => 'name',
                    'terms' => ['exclude-from-search', 'exclude-from-catalog'],
                    'operator' => 'NOT IN',
                ],
            ]
        ];

        return $this->get_products_from_query($args);
    }

    private function get_products_batch(int $batch_size, int $offset): array {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'suppress_filters' => true,
            'tax_query' => [
                [
                    'taxonomy' => 'product_visibility',
                    'field' => 'name',
                    'terms' => ['exclude-from-search', 'exclude-from-catalog'],
                    'operator' => 'NOT IN',
                ],
            ],
        ];

        return $this->get_products_from_query($args);
    }

    private function get_products_from_query(array $args): array {
        $product_posts = get_posts($args);
        $products = [];

        foreach ($product_posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private function should_generate_embeddings(): bool {
        return get_option('aivesese_semantic_toggle') === '1' &&
               $this->openai_client->is_configured();
    }

    // Product data extraction methods
    private function get_product_gtin(WC_Product $product): string {
        $gtin = get_post_meta($product->get_id(), '_gtin', true);
        if ($gtin) {
            return $gtin;
        }
        return get_post_meta($product->get_id(), '_ean', true) ?: '';
    }

    private function get_product_image_url(WC_Product $product): string {
        $image_id = $product->get_image_id();
        if ($image_id) {
            return wp_get_attachment_image_url($image_id, 'full') ?: '';
        }
        return '';
    }

    private function get_product_brand(WC_Product $product): string {
        $brand_terms = wp_get_post_terms($product->get_id(), 'product_brand');
        if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
            return $brand_terms[0]->name;
        }
        return '';
    }

    private function get_product_categories(WC_Product $product): array {
        $categories = [];
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach ($terms as $term) {
            $categories[] = $term->name;
        }
        return $categories;
    }

    private function get_product_tags(WC_Product $product): array {
        $tags = [];
        $tag_terms = wp_get_post_terms($product->get_id(), 'product_tag');
        foreach ($tag_terms as $term) {
            $tags[] = $term->name;
        }
        return $tags;
    }

    private function get_product_attributes(WC_Product $product): array {
        $attributes = [];
        $product_attributes = $product->get_attributes();

        foreach ($product_attributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                if (!is_wp_error($terms) && !empty($terms)) {
                    $values = array_map(function($term) { return $term->name; }, $terms);
                    $attributes[$attribute->get_name()] = implode(', ', $values);
                }
            } else {
                $attributes[$attribute->get_name()] = $attribute->get_options()[0] ?? '';
            }
        }

        return $attributes;
    }

    private function get_product_price(WC_Product $product, string $type): ?float {
        switch ($type) {
            case 'regular':
                $price = $product->get_regular_price();
                break;
            case 'sale':
                $price = $product->get_sale_price();
                break;
            default:
                return null;
        }

        return $price ? floatval($price) : null;
    }

    private function get_product_cost_price(WC_Product $product): ?float {
        $cost = get_post_meta($product->get_id(), '_cost_price', true);
        return $cost ? floatval($cost) : null;
    }
}
