<?php
/**
 * Product Sync class with Connection Manager support
 */
class AIVectorSearch_Product_Sync {

    private static $instance = null;
    private $connection_manager;
    private $openai_client; // Keep for self-hosted embedding text building
    private $syncing_products = [];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->connection_manager = AIVectorSearch_Connection_Manager::instance();
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

        $current_time = time();

        // Check if we synced this product recently (within 10 seconds)
        if (isset($this->syncing_products[$product_id])) {
            $last_sync_time = $this->syncing_products[$product_id];
            $time_diff = $current_time - $last_sync_time;
            if ($time_diff < 10) {
                return;
            }
        }

        // Record the sync time
        $this->syncing_products[$product_id] = $current_time;

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

            // Only generate embeddings for self-hosted mode
            if ($with_embeddings && !$this->connection_manager->is_api_mode()) {
                $text = $this->openai_client->build_embedding_text_from_product($product);
                $texts_for_embedding[] = $text;
            }
        }

        // Generate embeddings if requested and in self-hosted mode
        if ($with_embeddings && !$this->connection_manager->is_api_mode() && !empty($texts_for_embedding)) {
            $embeddings = $this->openai_client->embed_batch($texts_for_embedding);

            if (!empty($embeddings)) {
                foreach ($transformed_products as $i => $product) {
                    if (isset($embeddings[$i])) {
                        $transformed_products[$i]['embedding'] = $embeddings[$i];
                    }
                }
            }
        }

        // Sync using connection manager (handles both API and self-hosted)
        $success_count = 0;
        $batches = array_chunk($transformed_products, 100);

        foreach ($batches as $batch) {
            if ($this->connection_manager->sync_products_batch($batch)) {
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
        // Use connection manager to handle both API and self-hosted modes
        return $this->connection_manager->generate_missing_embeddings();
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
               $this->connection_manager->is_semantic_search_available();
    }

    // Product data extraction methods (unchanged)
    private function get_product_gtin(WC_Product $product): string {
        // Define priority order of GTIN/barcode meta keys
        $gtin_meta_keys = [
            '_gtin',                    // Generic GTIN field
            '_global_unique_id',        // WooCommerce core field
            '_ean',                     // European Article Number
            '_upc',                     // Universal Product Code
            '_isbn',                    // International Standard Book Number
            '_mpn',                     // Manufacturer Part Number
            '_barcode',                 // Generic barcode field
            '_product_gtin',            // Some GTIN plugins
            '_woocommerce_gtin',        // WooCommerce GTIN extensions
            '_yith_barcode',            // YITH Barcode plugin
            '_atum_barcode',            // ATUM Inventory plugin
            '_wc_barcode',              // WC Barcode plugins
            '_product_barcode',         // Generic product barcode
            '_gs1_gtin',               // GS1 standard GTIN
            '_gtin14',                  // GTIN-14 format
            '_gtin13',                  // GTIN-13 format (EAN-13)
            '_gtin12',                  // GTIN-12 format (UPC-A)
            '_gtin8',                   // GTIN-8 format (EAN-8)
            '_article_number',          // Article/item number
            '_manufacturer_sku',        // Manufacturer SKU (sometimes contains GTIN)
        ];

        // Try each meta key in priority order
        foreach ($gtin_meta_keys as $meta_key) {
            $value = get_post_meta($product->get_id(), $meta_key, true);
            if ($value && is_string($value)) {
                $cleaned_value = $this->clean_gtin_value($value);
                if ($this->is_valid_gtin($cleaned_value)) {
                    return $cleaned_value;
                }
            }
        }

        // For variable products, try to get GTIN from variations
        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();

            foreach ($variation_ids as $variation_id) {
                foreach ($gtin_meta_keys as $meta_key) {
                    $value = get_post_meta($variation_id, $meta_key, true);
                    if ($value && is_string($value)) {
                        $cleaned_value = $this->clean_gtin_value($value);
                        if ($this->is_valid_gtin($cleaned_value)) {
                            return $cleaned_value;
                        }
                    }
                }
            }
        }

        // Check product attributes for GTIN-like values
        $attributes = $product->get_attributes();
        $gtin_attribute_names = ['gtin', 'ean', 'upc', 'isbn', 'barcode', 'mpn'];

        foreach ($attributes as $attribute) {
            $attribute_name = strtolower($attribute->get_name());

            foreach ($gtin_attribute_names as $gtin_name) {
                if (strpos($attribute_name, $gtin_name) !== false) {
                    if ($attribute->is_taxonomy()) {
                        $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                        if (!is_wp_error($terms) && !empty($terms)) {
                            $cleaned_value = $this->clean_gtin_value($terms[0]);
                            if ($this->is_valid_gtin($cleaned_value)) {
                                return $cleaned_value;
                            }
                        }
                    } else {
                        $values = $attribute->get_options();
                        if (!empty($values)) {
                            $cleaned_value = $this->clean_gtin_value($values[0]);
                            if ($this->is_valid_gtin($cleaned_value)) {
                                return $cleaned_value;
                            }
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Clean and normalize GTIN value
     */
    private function clean_gtin_value(string $value): string {
        // Remove whitespace and common separators
        $cleaned = preg_replace('/[\s\-_\.]+/', '', trim($value));

        // Remove non-numeric characters (GTINs should be numeric)
        $cleaned = preg_replace('/[^0-9]/', '', $cleaned);

        return $cleaned;
    }

    /**
     * Validate if a string is a valid GTIN format
     */
    private function is_valid_gtin(string $value): bool {
        if (empty($value)) {
            return false;
        }

        // Check if it's numeric and has valid GTIN length
        if (!ctype_digit($value)) {
            return false;
        }

        $length = strlen($value);
        $valid_lengths = [8, 12, 13, 14]; // GTIN-8, GTIN-12 (UPC), GTIN-13 (EAN), GTIN-14

        if (!in_array($length, $valid_lengths)) {
            return false;
        }

        // Optional: Add check digit validation for more robust validation
        // This is a simplified version - you could implement full GTIN check digit validation

        return true;
    }

    /**
     * Validate GTIN check digit (optional enhanced validation)
     */
    private function validate_gtin_check_digit(string $gtin): bool {
        $length = strlen($gtin);

        if (!in_array($length, [8, 12, 13, 14])) {
            return false;
        }

        // Pad to 14 digits for uniform calculation
        $padded_gtin = str_pad($gtin, 14, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $digit = (int)$padded_gtin[$i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }

        $check_digit = (10 - ($sum % 10)) % 10;
        $actual_check_digit = (int)$padded_gtin[13];

        return $check_digit === $actual_check_digit;
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
        // Try COGS total value first (your current implementation)
        $cogs_cost = get_post_meta($product->get_id(), '_cogs_total_value', true);
        if ($cogs_cost && is_numeric($cogs_cost)) {
            return floatval($cogs_cost);
        }

        // Fallback to generic cost price meta
        $generic_cost = get_post_meta($product->get_id(), '_cost_price', true);
        if ($generic_cost && is_numeric($generic_cost)) {
            return floatval($generic_cost);
        }

        // Additional fallbacks for common COGS plugin meta keys
        $fallback_keys = [
            '_wc_cog_cost',           // WooCommerce Cost of Goods plugin
            '_purchase_price',        // Some accounting plugins
            '_product_cost',          // Generic cost field
            '_wholesale_price',       // Sometimes used as cost basis
        ];

        foreach ($fallback_keys as $meta_key) {
            $cost = get_post_meta($product->get_id(), $meta_key, true);
            if ($cost && is_numeric($cost) && floatval($cost) > 0) {
                return floatval($cost);
            }
        }

        // For variable products, try to get cost from variations
        if ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            $costs = [];

            foreach ($variation_ids as $variation_id) {
                $variation_cost = get_post_meta($variation_id, '_cogs_total_value', true);
                if ($variation_cost && is_numeric($variation_cost)) {
                    $costs[] = floatval($variation_cost);
                }
            }

            if (!empty($costs)) {
                // Return average cost of variations
                return array_sum($costs) / count($costs);
            }
        }

        return null;
    }
}
