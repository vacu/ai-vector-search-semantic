<?php
/**
 * Handles OpenAI API communication for embeddings
 */
class AIVectorSearch_OpenAI_Client {

    private static $instance = null;
    private const EMBEDDING_MODEL = 'text-embedding-3-small';
    private const BATCH_SIZE = 50;
    private const API_ENDPOINT = 'https://api.openai.com/v1/embeddings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function is_configured(): bool {
        return !empty(trim(get_option('aivesese_openai')));
    }

    public function embed_single(string $text): ?array {
        if (!$this->is_configured() || empty($text)) {
            return null;
        }

        $args = $this->build_request_args([$text]);
        $response = wp_remote_post(self::API_ENDPOINT, $args);

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['data'][0]['embedding'] ?? null;
    }

    public function embed_batch(array $texts): array {
        if (!$this->is_configured() || empty($texts)) {
            return [];
        }

        $embeddings = [];
        $chunks = array_chunk($texts, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $batch_embeddings = $this->process_embedding_batch($chunk);
            $embeddings = array_merge($embeddings, $batch_embeddings);

            // Rate limiting
            usleep(100000); // 0.1 second
        }

        return $embeddings;
    }

    private function process_embedding_batch(array $texts): array {
        $args = $this->build_request_args($texts);
        $response = wp_remote_post(self::API_ENDPOINT, $args);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($data['data'])) {
            return [];
        }

        $embeddings = [];
        foreach ($data['data'] as $embedding_data) {
            $embeddings[] = $embedding_data['embedding'];
        }

        return $embeddings;
    }

    private function build_request_args(array $texts): array {
        $key = trim(get_option('aivesese_openai'));

        return [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => self::EMBEDDING_MODEL,
                'input' => $texts,
            ]),
        ];
    }

    public function build_embedding_text_from_product(WC_Product $product): string {
        $components = [
            'name' => $product->get_name() ?: '',
            'short_description' => method_exists($product, 'get_short_description') ? $product->get_short_description() : '',
            'description' => $product->get_description() ?: '',
            'categories' => $this->get_product_categories($product),
            'tags' => $this->get_product_tags($product),
            'brand' => $this->get_product_brand($product),
            'attributes' => $this->get_product_attributes($product),
        ];

        $parts = array_filter([
            $components['name'],
            $components['short_description'],
            $components['description'],
            implode(' ', $components['categories']),
            implode(' ', $components['tags']),
            $components['brand'],
            implode(' ', $components['attributes']),
        ]);

        // Clean and normalize whitespace
        $text = trim(preg_replace('/\s+/u', ' ', implode("\n", $parts)));
        return $text;
    }

    private function get_product_categories(WC_Product $product): array {
        $terms = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        return (!is_wp_error($terms) && !empty($terms)) ? $terms : [];
    }

    private function get_product_tags(WC_Product $product): array {
        $terms = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        return (!is_wp_error($terms) && !empty($terms)) ? $terms : [];
    }

    private function get_product_brand(WC_Product $product): string {
        $terms = wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }
        return '';
    }

    private function get_product_attributes(WC_Product $product): array {
        $attributes = [];
        $product_attributes = $product->get_attributes();

        foreach ($product_attributes as $attribute) {
            if (!$attribute->get_visible()) {
                continue;
            }

            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $attributes[] = implode(' ', $terms);
                }
            } else {
                $values = $attribute->get_options();
                if (is_array($values) && !empty($values)) {
                    $attributes[] = implode(' ', array_map('wp_kses_post', $values));
                }
            }
        }

        return $attributes;
    }
}
