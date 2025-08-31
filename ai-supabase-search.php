<?php
/**
 * Plugin Name: AI Vector Search (Semantic)
 * Description: Supabase‑powered WooCommerce search with optional semantic (OpenAI) matching, Woodmart live‑search support, and product recommendation
 * Version: 0.14.0
 * Author:      ZZZ Solutions
 * License:     GPLv2 or later
 * Text Domain: ai-vector-search-semantic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Stable Tag: 0.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIVESESE_PLUGIN_VERSION', '0.14.0' );

if ( ! function_exists( 'aivesese_passthru' ) ) {
    function aivesese_passthru( $value ) {
        return is_string( $value ) ? $value : '';
    }
}

// -----------------------------------------------------------------------------
// Secure secret storage: encrypt at rest using libsodium or AES-GCM.
// Add AIVESESE_MASTER_KEY_B64 to wp-config.php (base64 32 bytes) or we'll
// derive from AUTH_SALT + site URL via HKDF.
// -----------------------------------------------------------------------------

if (!function_exists('aivesese_get_master_key')) {
    function aivesese_get_master_key(): string {
        if (defined('AIVESESE_MASTER_KEY_B64') && AIVESESE_MASTER_KEY_B64) {
            $bin = base64_decode(AIVESESE_MASTER_KEY_B64, true);
            if ($bin !== false && strlen($bin) === 32) return $bin;
        }

        $material = (defined('AUTH_SALT') ? AUTH_SALT : 'no-auth-salt') . '|' . site_url();
        return hash_hkdf('sha256', $material, 32, 'aivesese_plugin', wp_salt());
    }
}

// -----------------------------------------------------------------------------
// Admin notice if no AIVESESE_MASTER_KEY_B64 constant is defined.
// Generates a random 32-byte Base64 key for the admin to paste in wp-config.php.
// -----------------------------------------------------------------------------
// Show a single admin notice if master key is missing.
if ( ! function_exists('aivesese_master_key_notice') ) {
    function aivesese_master_key_notice() {
        if ( ! current_user_can('manage_options') ) return;
        if ( defined('AIVESESE_MASTER_KEY_B64') && AIVESESE_MASTER_KEY_B64 ) return;

        // Only show on Plugins screen and our settings page
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed = array('plugins', 'plugins-network', 'settings_page_aivesese');
        if ( ! $screen || ! in_array($screen->id, $allowed, true) ) return;

        try {
            $key = base64_encode( random_bytes(32) );
        } catch ( Exception $e ) {
            $key = '';
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>AI Supabase Search:</strong> No master key defined for secret encryption.<br>';
        echo 'Add the following line to your <code>wp-config.php</code> above <code>/* That\'s all, stop editing! */</code>:';
        echo '<pre>define(\'AIVESESE_MASTER_KEY_B64\', \'' . esc_html( $key ) . '\');</pre>';
        echo '</p></div>';
    }
}

// Add the notice only once
if ( ! has_action('admin_notices', 'aivesese_master_key_notice') ) {
    add_action('admin_notices', 'aivesese_master_key_notice');
}

if (!function_exists('aivesese_encrypt')) {
    function aivesese_encrypt(string $plaintext): array {
        $key = aivesese_get_master_key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return [
                'v'      => 1,
                'alg'    => 'secretbox',
                'nonce'  => base64_encode($nonce),
                'cipher' => base64_encode($cipher),
            ];
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return ['v'=>1, 'alg'=>'plain', 'cipher'=>base64_encode($plaintext)];
        }
        return [
            'v'      => 1,
            'alg'    => 'aes-256-gcm',
            'iv'     => base64_encode($iv),
            'tag'    => base64_encode($tag),
            'cipher' => base64_encode($cipher),
        ];
    }
}

if (!function_exists('aivesese_decrypt')) {
    function aivesese_decrypt($stored): ?string {
        if (!is_array($stored) || empty($stored['alg'])) {
            return is_string($stored) ? $stored : null;
        }
        $key = aivesese_get_master_key();

        if ($stored['alg'] === 'secretbox' && function_exists('sodium_crypto_secretbox_open')) {
            $nonce  = base64_decode($stored['nonce']);
            $cipher = base64_decode($stored['cipher']);
            $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return $plain === false ? null : $plain;
        }

        if ($stored['alg'] === 'aes-256-gcm') {
            $iv     = base64_decode($stored['iv']);
            $tag    = base64_decode($stored['tag']);
            $cipher = base64_decode($stored['cipher']);
            $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? null : $plain;
        }

        if ($stored['alg'] === 'plain') {
            return base64_decode($stored['cipher']);
        }

        return null;
    }
}

// Transparent encryption on update
add_filter( 'pre_update_option_aivesese_key',
    function ( $value, $old_value, $option ) {
        return ( is_string( $value ) && $value !== '' )
            ? wp_json_encode( aivesese_encrypt( $value ) )
            : $value;
    },
10, 3 );

add_filter( 'pre_update_option_aivesese_openai',
    function ( $value, $old_value, $option ) {
        return ( is_string( $value ) && $value !== '' )
            ? wp_json_encode( aivesese_encrypt( $value ) )
            : $value;
    },
10, 3 );


// Transparent decryption on read
add_filter('option_aivesese_key', function($value, $option = null) {
    $arr = json_decode( $value, true );
    return is_array( $arr ) ? aivesese_decrypt( $arr ) : $value;
}, 10);
add_filter('option_aivesese_openai', function($value, $option = null) {
    $arr = json_decode( $value, true );
    return is_array( $arr ) ? aivesese_decrypt( $arr ) : $value;
}, 10);

// One-time migration: if legacy plaintext existed, re-save to trigger encryption
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    $targets = ['aivesese_key', 'aivesese_openai'];
    foreach ($targets as $opt) {
        $val = get_option($opt, null);
        if (is_string($val) && $val !== '') {
            update_option($opt, $val, false); // pre_update filter will encrypt
        }
    }
});

/**
 * Bootstrap: ensure aivesese_store (Store ID) exists.
 * Generates a UUID v4 once and stores it so the field is pre-populated.
 */
add_action( 'plugins_loaded', function () {
    $store = get_option( 'aivesese_store', '' );

    // Basic UUID v4 format check
    $is_uuid = preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        (string) $store
    );

    if ( ! $is_uuid ) {
        $new = function_exists( 'wp_generate_uuid4' )
            ? wp_generate_uuid4()
            : wp_generate_password( 36, false ); // fallback

        update_option( 'aivesese_store', $new, false ); // autoload = false
    }
}, 11 );

/* -------------------------------------------------------------------------
 * AUTO SYNC ON PRODUCT CHANGES
 * ------------------------------------------------------------------------- */

// Sync single product when it's updated
add_action('woocommerce_update_product', 'aivesese_auto_sync_product', 10, 1);
add_action('woocommerce_new_product', 'aivesese_auto_sync_product', 10, 1);

function aivesese_auto_sync_product($product_id) {
    if (get_option('aivesese_auto_sync') !== '1') {
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }

    $with_embeddings = (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai'));
    aivesese_sync_products([$product], $with_embeddings);
}

/* PRODUCT SYNC FUNCTIONS
 * ------------------------------------------------------------------------- */

function aivesese_get_synced_count() {
    $store_id = get_option('aivesese_store');
    if (!$store_id) return 0;

    $result = aivesese_request('GET', '/rest/v1/products', null, [
        'select' => 'id',
        'store_id' => 'eq.' . $store_id,
    ]);

    return is_array($result) ? count($result) : 0;
}

function aivesese_transform_product($product) {
    $store_id = get_option('aivesese_store');

    // Get categories
    $categories = [];
    $terms = wp_get_post_terms($product->get_id(), 'product_cat');
    foreach ($terms as $term) {
        $categories[] = $term->name;
    }

    // Get tags
    $tags = [];
    $tag_terms = wp_get_post_terms($product->get_id(), 'product_tag');
    foreach ($tag_terms as $term) {
        $tags[] = $term->name;
    }

    // Get brand (if using a brand plugin/taxonomy)
    $brand = '';
    $brand_terms = wp_get_post_terms($product->get_id(), 'product_brand');
    if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
        $brand = $brand_terms[0]->name;
    }

    // Get attributes
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

    // Get image URL
    $image_url = '';
    $image_id = $product->get_image_id();
    if ($image_id) {
        $image_url = wp_get_attachment_image_url($image_id, 'full');
    }

    return [
        'id' => wp_generate_uuid4(),
        'store_id' => $store_id,
        'woocommerce_id' => $product->get_id(),
        'sku' => $product->get_sku(),
        'gtin' => get_post_meta($product->get_id(), '_gtin', true) ?: get_post_meta($product->get_id(), '_ean', true),
        'name' => $product->get_name(),
        'description' => wp_strip_all_tags($product->get_description()),
        'image_url' => $image_url,
        'brand' => $brand,
        'categories' => $categories,
        'tags' => $tags,
        'regular_price' => $product->get_regular_price() ? floatval($product->get_regular_price()) : null,
        'sale_price' => $product->get_sale_price() ? floatval($product->get_sale_price()) : null,
        'cost_price' => get_post_meta($product->get_id(), '_cost_price', true) ? floatval(get_post_meta($product->get_id(), '_cost_price', true)) : null,
        'stock_quantity' => $product->get_stock_quantity(),
        'stock_status' => $product->get_stock_status() === 'instock' ? 'in' : 'out',
        'attributes' => $attributes,
        'status' => $product->get_status(),
        'average_rating' => $product->get_average_rating() ? floatval($product->get_average_rating()) : null,
        'review_count' => $product->get_review_count() ? intval($product->get_review_count()) : 0,
    ];
}

function aivesese_batch_embed($texts) {
    $key = trim(get_option('aivesese_openai'));
    if (!$key || empty($texts)) {
        return [];
    }

    $embeddings = [];
    $chunks = array_chunk($texts, 50);

    foreach ($chunks as $chunk) {
        $args = [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'text-embedding-3-small',
                'input' => $chunk,
            ]),
        ];

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', $args);

        if (is_wp_error($response)) {
            continue;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['data'])) {
            foreach ($data['data'] as $embedding_data) {
                $embeddings[] = $embedding_data['embedding'];
            }
        }

        // Small delay to respect rate limits
        usleep(100000); // 0.1 second
    }

    return $embeddings;
}

function aivesese_sync_products($products, $with_embeddings = false) {
    if (empty($products)) {
        return ['success' => false, 'message' => 'No products to sync'];
    }

    $transformed_products = [];
    $texts_for_embedding = [];

    foreach ($products as $product) {
        $transformed = aivesese_transform_product($product);
        $transformed_products[] = $transformed;

        if ($with_embeddings) {
            $text = $transformed['name'] . "\n" .
                   $transformed['description'] . "\n" .
                   implode(' ', $transformed['categories']) . ' ' .
                   implode(' ', $transformed['tags']) . ' ' .
                   $transformed['brand'];
            $texts_for_embedding[] = $text;
        }
    }

    // Generate embeddings if requested
    if ($with_embeddings && !empty($texts_for_embedding)) {
        $embeddings = aivesese_batch_embed($texts_for_embedding);

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
    $batches = array_chunk($transformed_products, 100); // Supabase batch limit

    foreach ($batches as $batch) {
        $result = aivesese_request('POST', '/rest/v1/products', $batch, [
            'on_conflict' => 'store_id,woocommerce_id'
        ]);

        if (!empty($result) || !is_wp_error($result)) {
            $success_count += count($batch);
        }
    }

    return [
        'success' => true,
        'synced' => $success_count,
        'total' => count($products)
    ];
}

function aivesese_handle_sync_all() {
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => [
            [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => [ 'exclude-from-search', 'exclude-from-catalog' ],
                'operator' => 'NOT IN',
            ],
        ]
    ];

    $product_posts = get_posts($args);
    $products = [];

    foreach ($product_posts as $post) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $products[] = $product;
        }
    }

    $with_embeddings = (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai'));
    $result = aivesese_sync_products($products, $with_embeddings);

    if ($result['success']) {
        echo '<div class="notice notice-success"><p>Successfully synced ' . esc_attr($result['synced']) . '/' . esc_attr($result['total']) . ' products to Supabase!</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Sync failed: ' . esc_html($result['message']) . '</p></div>';
    }
}

function aivesese_handle_sync_batch($batch_size, $offset) {
    $args = [
        'post_type'       => 'product',
        'post_status'     => 'publish',
        'posts_per_page'  => $batch_size,
        'offset'          => $offset,
        'suppress_filters'=> true,
        'tax_query'       => [
            [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => [ 'exclude-from-search', 'exclude-from-catalog' ],
            'operator' => 'NOT IN',
            ],
        ],
    ];

    $product_posts = get_posts($args);
    $products = [];

    foreach ($product_posts as $post) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $products[] = $product;
        }
    }

    if (empty($products)) {
        echo '<div class="notice notice-warning"><p>' .
            sprintf(
                /* translators: %d: numeric offset */
                esc_html__( 'No products found at offset %d', 'ai-vector-search-semantic' ),
                absint( $offset )
            ) .
            '</p></div>';
        return;
    }

    $with_embeddings = (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai'));
    $result = aivesese_sync_products($products, $with_embeddings);

    if ($result['success']) {
        echo '<div class="notice notice-success"><p>Successfully synced batch: ' . esc_attr($result['synced']) . '/' . esc_attr($result['total']) . ' products (offset: ' . esc_attr($offset) . ')</p></div>';

        // Suggest next batch
        $next_offset = $offset + $batch_size;
        echo '<div class="notice notice-info">';
        echo '<p>Continue with next batch:</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="sync_batch">';
        echo '<input type="hidden" name="batch_size" value="' . esc_attr( $batch_size ) . '">';
        echo '<input type="hidden" name="offset" value="' . esc_attr( $next_offset ) . '">';
        echo '<button type="submit" class="button">Sync Next Batch (offset: ' . esc_html( $next_offset ) . ')</button>';
        echo '</form>';
        echo '</div>';
    } else {
        echo '<div class="notice notice-error"><p>Batch sync failed: ' . esc_html($result['message']) . '</p></div>';
    }
}

// Build a clean embedding text from live WooCommerce fields.
if ( ! function_exists('aivesese_build_embedding_text_from_wc') ) {
    function aivesese_build_embedding_text_from_wc( WC_Product $product ): string {
        $name   = $product->get_name() ?: '';
        $short  = method_exists($product, 'get_short_description') ? $product->get_short_description() : '';
        $desc   = $product->get_description() ?: '';

        // Categories
        $cats = [];
        $cat_terms = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (!is_wp_error($cat_terms) && !empty($cat_terms)) $cats = $cat_terms;

        // Tags
        $tags = [];
        $tag_terms = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        if (!is_wp_error($tag_terms) && !empty($tag_terms)) $tags = $tag_terms;

        // Brand (if you use product_brand)
        $brand = '';
        $brand_terms = wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names']);
        if (!is_wp_error($brand_terms) && !empty($brand_terms)) $brand = $brand_terms[0];

        // Visible attributes (taxonomy + custom)
        $attr_parts = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute->get_visible()) continue;
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $attr_parts[] = implode(' ', $terms);
                }
            } else {
                $values = $attribute->get_options();
                if (is_array($values) && !empty($values)) {
                    $attr_parts[] = implode(' ', array_map('wp_kses_post', $values));
                }
            }
        }

        $parts = array_filter([
            $name,
            $short,
            $desc,
            implode(' ', $cats),
            implode(' ', $tags),
            $brand,
            implode(' ', $attr_parts),
        ]);

        // Trim + collapse whitespace
        $text = trim(preg_replace('/\s+/u', ' ', implode("\n", $parts)));
        return $text;
    }
}

if ( ! function_exists('aivesese_handle_generate_embeddings') ) :
function aivesese_handle_generate_embeddings() {
    $store_id = get_option('aivesese_store');
    if (!$store_id) { echo '<div class="notice notice-error"><p>Store ID missing.</p></div>'; return; }

    $openai_key = trim(get_option('aivesese_openai'));
    if (!$openai_key) { echo '<div class="notice notice-error"><p>OpenAI API key is missing (Settings → AI Vector Search).</p></div>'; return; }

    $BATCH = 25;
    $max_loops = 400; // safety guard
    $total_updated = 0;
    $total_skipped = 0;

    for ($loop = 0; $loop < $max_loops; $loop++) {
        // Fetch up to 25 that still lack embeddings
        $rows = aivesese_request('GET', '/rest/v1/products', null, [
            'select'    => 'id,woocommerce_id',
            'store_id'  => 'eq.' . $store_id,
            'embedding' => 'is.null',
            'limit'     => $BATCH,
        ]);

        // ✅ Only stop when none remain
        if (empty($rows)) {
            if ($total_updated === 0) {
                echo '<div class="notice notice-info"><p>No products without embeddings found in Supabase.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Done. Generated embeddings for ' . esc_html($total_updated) . ' products. Skipped ' . esc_html($total_skipped) . '.</p></div>';
            }
            return;
        }

        // Build batch from live Woo data
        $embed_targets = [];
        $skipped_this = 0;

        foreach ($rows as $row) {
            $sp_id = $row['id'] ?? null;
            $wc_id = isset($row['woocommerce_id']) ? intval($row['woocommerce_id']) : 0;
            if (!$sp_id || !$wc_id) { $skipped_this++; continue; }

            $product = wc_get_product($wc_id);
            if (!$product) { $skipped_this++; continue; }

            $text = aivesese_build_embedding_text_from_wc($product);
            if ($text === '') { $skipped_this++; continue; }

            $embed_targets[] = [ 'id' => $sp_id, 'text' => $text ];
        }

        if (empty($embed_targets)) {
            $total_skipped += $skipped_this;
            continue; // fetch next set; don’t stop yet
        }

        // Embed once per batch
        $texts = array_map(static fn($t) => $t['text'], $embed_targets);
        $vectors = aivesese_batch_embed($texts);

        if (!is_array($vectors) || count($vectors) !== count($embed_targets)) {
            echo '<div class="notice notice-error"><p>Embedding batch mismatch: expected ' . esc_html(count($embed_targets)) . ', got ' . esc_html(is_array($vectors) ? count($vectors) : 0) . '.</p></div>';
            return;
        }

        // PATCH back each row
        $updated_this = 0;
        foreach ($embed_targets as $i => $t) {
            $res = aivesese_request(
                'PATCH',
                '/rest/v1/products',
                [ 'embedding' => $vectors[$i] ],
                [ 'id' => 'eq.' . $t['id'] ]
            );
            if (!is_wp_error($res)) { $updated_this++; }
        }

        $total_updated += $updated_this;
        $total_skipped += $skipped_this;

        // 🔁 loop continues regardless of batch size, until a future fetch returns 0
    }

    echo '<div class="notice notice-warning"><p>Stopped after safety limit. Generated embeddings for ' . esc_html($total_updated) . ' products. Skipped ' . esc_html($total_skipped) . '.</p></div>';
}
endif;

/* -------------------------------------------------------------------------
 * 0. Validation
 * ------------------------------------------------------------------------- */
function aivesese_validate_url( $url ) {
 return esc_url_raw( $url );
}
function aivesese_validate_key( $key ) {
 return sanitize_text_field( $key );
}

/* -------------------------------------------------------------------------
 * 1. Settings — Settings ▸ AI Supabase
 * ------------------------------------------------------------------------- */
add_action( 'admin_init', function () {
    foreach ( [
        'url'              => 'Supabase URL (https://xyz.supabase.co)',
        'key'              => 'Supabase service / anon key',
        'store'            => 'Store ID (UUID)',
        'openai'           => 'OpenAI API key  (only if semantic search is enabled)',
        'semantic_toggle'  => 'Enable semantic (vector) search',
        'auto_sync'        => 'Auto-sync products on save',
    ] as $id => $label ) {
        // ── Sanitise each option ──────────────────────────────────────────────────────
        $sanitizers = [
            'url'   => 'esc_url_raw',
            'key'   => 'aivesese_passthru',
            'store' => 'sanitize_text_field',
            'openai'=> 'aivesese_passthru',
        ];
        register_setting(
            'aivesese_settings',
            "aivesese_$id",
            [
                'type'              => 'string',
                'sanitize_callback' => $sanitizers[ $id ] ?? 'sanitize_text_field',
                'default'           => '',
            ]
        );
        if (isset($sanitizers[$id]) && function_exists("aivesese_validate_$id")) {
            add_filter("option_update_aivesese_$id", "aivesese_validate_$id");
        }
    }

    add_settings_section( 'aivesese_section', 'Supabase connection', '__return_false', 'aivesese' );

    foreach ( [
        'url', 'key', 'store', 'openai'
    ] as $id ) {
        add_settings_field( "aivesese_$id", $label = ucfirst( str_replace( '_', ' ', $id ) ), function () use ( $id ) {
            printf( '<input type="text" class="regular-text" name="aivesese_%1$s" value="%2$s" />',
                esc_attr( $id ),
                esc_attr( get_option( "aivesese_$id" ) )
            );
        }, 'aivesese', 'aivesese_section' );
    }

    // Checkbox for semantic toggle
    add_settings_field( 'aivesese_semantic_toggle', 'Enable semantic (vector) search', function () {
        $val = get_option( 'aivesese_semantic_toggle' );
        echo '<label><input type="checkbox" name="aivesese_semantic_toggle" value="1"' . checked( $val, '1', false ) . '> Better relevance (needs OpenAI key)</label>';
    }, 'aivesese', 'aivesese_section' );

    // Checkbox for auto-sync
    add_settings_field( 'aivesese_auto_sync', 'Auto-sync products', function () {
        $val = get_option( 'aivesese_auto_sync' );
        echo '<label><input type="checkbox" name="aivesese_auto_sync" value="1"' . checked( $val, '1', false ) . '> Automatically sync products when saved/updated</label>';
    }, 'aivesese', 'aivesese_section' );

    // Register options (simple sanitize to keep values '1' or '0')
    register_setting('aivesese_settings', 'aivesese_enable_pdp_similar', [
        'type' => 'string',
        'sanitize_callback' => function($v){ return $v === '1' ? '1' : '0'; },
        'default' => '1',
    ]);
    register_setting('aivesese_settings', 'aivesese_enable_cart_below', [
        'type' => 'string',
        'sanitize_callback' => function($v){ return $v === '1' ? '1' : '0'; },
        'default' => '1',
    ]);

    // Checkbox: PDP "Similar products"
    add_settings_field('aivesese_enable_pdp_similar', 'PDP "Similar products"', function () {
        $val = get_option('aivesese_enable_pdp_similar', '1');
        echo '<label><input type="checkbox" name="aivesese_enable_pdp_similar" value="1" ' .
            checked($val, '1', false) . '> Show similar products on product pages</label>';
    }, 'aivesese', 'aivesese_section');

    // Checkbox: Below-cart recommendations
    add_settings_field('aivesese_enable_cart_below', 'Below-cart recommendations', function () {
        $val = get_option('aivesese_enable_cart_below', '1');
        echo '<label><input type="checkbox" name="aivesese_enable_cart_below" value="1" ' .
            checked($val, '1', false) . '> Show recommendations under cart</label>';
    }, 'aivesese', 'aivesese_section');
} );

add_action( 'admin_menu', function () {
    // Main settings page
    add_options_page( 'AI Supabase', 'AI Supabase', 'manage_options', 'aivesese', function () {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'AI Supabase Settings', 'ai-vector-search-semantic' ) . '</h1>';
        echo '<p>' . esc_html__( 'Configure the connection to your Supabase project and optionally enable semantic search using OpenAI.', 'ai-vector-search-semantic' ) . '</p>';

        $user_id   = get_current_user_id();
        $is_open   = get_user_meta($user_id, '_aivesese_help_open', true);
        $is_open   = ($is_open === '' ? '1' : $is_open);
        $open_attr = ($is_open === '1') ? ' open' : '';

        echo '<div class="ai-supabase-help">';
        echo '<details id="ai-supabase-help-details"' . esc_attr($open_attr) . '>';
        echo '<summary class="ai-supabase-help__summary"><strong>Setup Guide</strong> <span class="ai-supabase-help__hint">click to expand/collapse</span></summary>';

        echo '<h2>' . esc_html__( 'How to find your Supabase credentials:', 'ai-vector-search-semantic' ) . '</h2>';
        echo '<ol>';
        /* translators: %s: Supabase dashboard URL (e.g., https://app.supabase.com). */
        echo '<li>' . sprintf( esc_html__( 'Go to your Supabase project dashboard at %s.', 'ai-vector-search-semantic' ), '<a href="https://app.supabase.io/" target="_blank">https://app.supabase.io/</a>' ) . '</li>';
        echo '<li>' . esc_html__( 'Navigate to "Project Settings" > "API".', 'ai-vector-search-semantic' ) . '</li>';
        echo '<li>' . esc_html__( 'Your Supabase URL is the "URL" value (e.g., https://xyz.supabase.co).', 'ai-vector-search-semantic' ) . '</li>';
        echo '<li>' . esc_html__( 'Your Supabase service role key or anon key can be found under "Project API keys". Use the anon key for client-side requests and the service role key for server-side operations (like data syncing, if you implement it).', 'ai-vector-search-semantic' ) . '</li>';
        echo '</ol>';
        echo '<h2>' . esc_html__( 'How to find your Store ID:', 'ai-vector-search-semantic' ) . '</h2>';
        echo '<p>' . esc_html__( 'The Store ID is a UUID you define to identify your specific store within Supabase. If you are using a multi-store setup in Supabase, ensure this matches the ID associated with your WooCommerce store data.', 'ai-vector-search-semantic' ) . '</p>';
        echo '<h2>' . esc_html__( 'How to find your OpenAI API key:', 'ai-vector-search-semantic' ) . '</h2>';
        /* translators: %s: OpenAI website URL. */
        echo '<p>' . sprintf( esc_html__( 'If you enable semantic search, you will need an OpenAI API key. You can obtain one from the %s.', 'ai-vector-search-semantic' ), '<a href="https://beta.openai.com/account/api-keys" target="_blank">OpenAI website</a>' ) . '</p>';
        echo '<hr>';

        $__base = plugin_dir_path(__FILE__);
        $__candidates = [
            $__base . 'assets/sql/supabase.sql',
            $__base . 'admin/sql/supabase.sql',
            $__base . 'supabase.sql',
        ];
        $__sql = '';
        foreach ($__candidates as $__p) {
            if (file_exists($__p)) { $__sql = file_get_contents($__p); break; }
        }

        echo '<h2>' . esc_html__( 'Install the SQL in Supabase', 'ai-vector-search-semantic' ) . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__( 'Open your Supabase project → SQL Editor → New query.', 'ai-vector-search-semantic' ) . '</li>';
        echo '<li>' . esc_html__( 'Click "Copy SQL" below and paste it into the editor.', 'ai-vector-search-semantic' ) . '</li>';
        echo '<li>' . esc_html__( 'Press RUN and wait for success.', 'ai-vector-search-semantic' ) . '</li>';
        echo '<li>' . esc_html__( 'Verify tables/views, RPCs and extensions were created.', 'ai-vector-search-semantic' ) . '</li>';
        echo '</ol>';

        if (! $__sql) {
            echo '<div class="notice notice-error"><p>' .
                esc_html__( 'Could not find supabase.sql. Place it at assets/sql/supabase.sql (recommended).', 'ai-vector-search-semantic' ) .
                '</p></div>';
        } else {
            echo '<p><button class="button button-primary" id="ai-copy-sql">Copy SQL</button> ' .
                '<small style="opacity:.75;margin-left:.5rem">' .
                esc_html__( 'Paste into Supabase → SQL Editor.', 'ai-vector-search-semantic' ) .
                '</small></p>';

            echo '<textarea id="ai-sql" rows="22" style="width:100%;font-family:Menlo,Consolas,monospace" readonly>' .
                esc_textarea($__sql) .
                '</textarea>';

            echo '<p id="ai-copy-status" style="display:none;margin-top:.5rem;"></p>';
        }

        echo '</details></div>';
        echo '<form method="post" action="options.php">';
                settings_fields( 'aivesese_settings' );
                do_settings_sections( 'aivesese' );
                settings_errors();
                submit_button();
                echo '</form></div>';
    });

    add_submenu_page(
        'options-general.php',
        'AI Supabase Status',
        'Supabase Status',
        'manage_options',
        'aivesese-status',
        'aivesese_status_page'
    );

    add_submenu_page(
        'options-general.php',
        'Sync Products to Supabase',
        'Sync Products',
        'manage_options',
        'aivesese-sync',
        'aivesese_sync_page'
    );
} );

// STATUS PAGE FUNCTION
function aivesese_status_page() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'AI Supabase Status', 'ai-vector-search-semantic' ) . '</h1>';

    $store_id = get_option('aivesese_store');
    $url = get_option('aivesese_url');
    $key = get_option('aivesese_key');

    // Check if configuration is complete
    if (!$store_id || !$url || !$key) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Configuration incomplete! Please configure your Supabase settings first.', 'ai-vector-search-semantic');
        echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=aivesese' ) ) . '">'
            . esc_html__( 'Go to Settings', 'ai-vector-search-semantic' )
            . '</a>';
        echo '</p></div>';
        echo '</div>';
        return;
    }

    // Test connection and get health data
    $health = aivesese_request('POST', '/rest/v1/rpc/store_health_check', [
        'check_store_id' => $store_id
    ]);

    if (empty($health)) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Unable to connect to Supabase or no data found. Check your configuration and ensure the SQL has been installed.', 'ai-vector-search-semantic');
        echo '</p></div>';
        echo '</div>';
        return;
    }

    // Display health information
    $data = $health[0];
    echo '<div class="notice notice-success"><p>' . esc_html__('✅ Successfully connected to Supabase!', 'ai-vector-search-semantic') . '</p></div>';

    echo '<h2>' . esc_html__('Store Health Overview', 'ai-vector-search-semantic') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Metric</th><th>Count</th><th>Status</th></tr></thead>';
    echo '<tbody>';

    $total = intval($data['total_products']);
    $published = intval($data['published_products']);
    $in_stock = intval($data['in_stock_products']);
    $with_embeddings = intval($data['with_embeddings']);

    echo '<tr><td>Total Products</td><td>' . number_format($total) . '</td><td>' . ($total > 0 ? '✅' : '⚠️') . '</td></tr>';
    echo '<tr><td>Published Products</td><td>' . number_format($published) . '</td><td>' . ($published > 0 ? '✅' : '⚠️') . '</td></tr>';
    echo '<tr><td>In Stock Products</td><td>' . number_format($in_stock) . '</td><td>' . ($in_stock > 0 ? '✅' : '⚠️') . '</td></tr>';
    echo '<tr><td>With Embeddings</td><td>' . number_format($with_embeddings) . '</td><td>';

    if ($with_embeddings == 0) {
        echo '❌ No embeddings found';
    } elseif ($with_embeddings == $published) {
        echo '✅ All products have embeddings';
    } else {
        $percent = round( ( $with_embeddings / $published ) * 100, 1 );
        /* translators: %s: percentage (0–100) */
        printf(esc_html__( '⚠️ %s%% coverage', 'ai-vector-search-semantic' ), esc_html( $percent ));
    }
    echo '</td></tr>';
    echo '</tbody></table>';

    // Configuration summary
    echo '<h2>' . esc_html__('Configuration Summary', 'ai-vector-search-semantic') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<tbody>';
    echo '<tr><td><strong>Store ID</strong></td><td><code>' . esc_html($store_id) . '</code></td></tr>';
    echo '<tr><td><strong>Supabase URL</strong></td><td><code>' . esc_html($url) . '</code></td></tr>';
    echo '<tr><td><strong>Semantic Search</strong></td><td>' . (get_option('aivesese_semantic_toggle') === '1' ? '✅ Enabled' : '❌ Disabled') . '</td></tr>';
    echo '<tr><td><strong>OpenAI Key</strong></td><td>' . (get_option('aivesese_openai') ? '✅ Configured' : '❌ Not set') . '</td></tr>';
    echo '</tbody></table>';

    // Quick actions
    echo '<h2>' . esc_html__('Quick Actions', 'ai-vector-search-semantic') . '</h2>';
    echo '<p>';
    echo '<a href="' . esc_url( admin_url('options-general.php?page=aivesese') ) . '" class="button">' . esc_html__( 'Configure Settings', 'ai-vector-search-semantic' ) . '</a> ';
    echo '<a href="' . esc_url( admin_url( 'options-general.php?page=aivesese-status' ) ) . '" class="button">' . esc_html__( 'Refresh Status', 'ai-vector-search-semantic' ) . '</a>';
    echo '</p>';
    echo '</div>';
}

// PRODUCT SYNC PAGE FUNCTION
function aivesese_sync_page() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Sync Products to Supabase', 'ai-vector-search-semantic') . '</h1>';

    // Check configuration
    $store_id = get_option('aivesese_store');
    $url = get_option('aivesese_url');
    $key = get_option('aivesese_key');

    if (!$store_id || !$url || !$key) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Configuration incomplete! Please configure your Supabase settings first.', 'ai-vector-search-semantic');
        echo ' <a href="' . esc_url(admin_url('options-general.php?page=aivesese')) . '">Go to Settings</a>';
        echo '</p></div>';
        echo '</div>';
        return;
    }

    // Handle form submissions
    if (isset($_POST['action']) && check_admin_referer('aivesese_sync')) {
        $action_raw = wp_unslash( $_POST['action'] );
        $action     = sanitize_key( $action_raw );

        switch ($action) {
            case 'sync_all':
                aivesese_handle_sync_all();
                break;
            case 'sync_batch':
                $batch_size = isset( $_POST['batch_size'] )
                    ? absint( wp_unslash( $_POST['batch_size'] ) )
                    : 50;
                $offset     = isset( $_POST['offset'] )
                    ? absint( wp_unslash( $_POST['offset'] ) )
                    : 0;
                aivesese_handle_sync_batch($batch_size, $offset);
                break;
            case 'generate_embeddings':
                aivesese_handle_generate_embeddings();
                break;
        }
    }

    // Get product counts
    $total_products = wp_count_posts('product')->publish;
    $synced_count = aivesese_get_synced_count();

    echo '<h2>' . esc_html__('Sync Overview', 'ai-vector-search-semantic') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<tbody>';
    echo '<tr><td><strong>WooCommerce Products</strong></td><td>' . number_format($total_products) . '</td></tr>';
    echo '<tr><td><strong>Synced to Supabase</strong></td><td>' . number_format($synced_count) . '</td></tr>';
    echo '<tr><td><strong>Sync Status</strong></td><td>';

    if ($synced_count == 0) {
        echo '❌ No products synced';
    } elseif ($synced_count >= $total_products) {
        echo '✅ All products synced';
    } else {
        $percent = round( ( $synced_count / $total_products ) * 100, 1 );
        /* translators: 1: percentage, 2: synced, 3: total */
        printf( esc_html__( '⚠️ %1$s%% synced (%2$d/%3$d)', 'ai-vector-search-semantic' ), esc_html( $percent ), absint( $synced_count ), absint( $total_products ) );
    }
    echo '</td></tr>';
    echo '</tbody></table>';

    // Sync options
    echo '<h2>' . esc_html__('Sync Actions', 'ai-vector-search-semantic') . '</h2>';
    echo '<div class="card" style="max-width: 600px;">';
    echo '<h3>🔄 Full Sync</h3>';
    echo '<p>Sync all WooCommerce products to Supabase. This may take a while for large catalogs.</p>';
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('aivesese_sync');
    echo '<input type="hidden" name="action" value="sync_all">';
    echo '<button type="submit" class="button button-primary" onclick="return confirm(\'This will sync all products. Continue?\')">Sync All Products</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="card" style="max-width: 600px; margin-top: 20px;">';
    echo '<h3>⚡ Batch Sync</h3>';
    echo '<p>Sync products in smaller batches to avoid timeouts.</p>';
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('aivesese_sync');
    echo '<input type="hidden" name="action" value="sync_batch">';
    echo '<label>Batch Size: <input type="number" name="batch_size" value="50" min="1" max="200" style="width:60px;"></label> ';
    echo '<label>Offset: <input type="number" name="offset" value="0" min="0" style="width:60px;"></label> ';
    echo '<button type="submit" class="button">Sync Batch</button>';
    echo '</form>';
    echo '</div>';

    if (get_option('aivesese_semantic_toggle') === '1' && get_option('aivesese_openai')) {
        echo '<div class="card" style="max-width: 600px; margin-top: 20px;">';
        echo '<h3>🧠 Generate Embeddings</h3>';
        echo '<p>Generate or update OpenAI embeddings for products that don\'t have them.</p>';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('aivesese_sync');
        echo '<input type="hidden" name="action" value="generate_embeddings">';
        echo '<button type="submit" class="button button-secondary">Generate Missing Embeddings</button>';
        echo '</form>';
        echo '</div>';
    }

    // Progress/Status area
    echo '<div id="sync-status" style="margin-top: 20px;"></div>';
    echo '</div>';
}

/* -------------------------------------------------------------------------
 * 2. Utilities
 * ------------------------------------------------------------------------- */
function aivesese_request( string $method, string $path, $body = null, array $query = [], ?string $cache_key = null, int $cache_ttl = 30 ) {
    if ( $cache_key ) {
        $hit = get_transient( $cache_key );
        if ( false !== $hit ) {
            return $hit;
        }
    }
    $base = rtrim( get_option( 'aivesese_url' ), '/' ) . '/';
    $url  = $base . ltrim( $path, '/' );
    if ( $query ) {
        $url = add_query_arg( $query, $url );
    }
    $args = [
        'method'  => $method,
        'headers' => [
            'apikey'        => get_option( 'aivesese_key' ),
            'Authorization' => 'Bearer ' . get_option( 'aivesese_key' ),
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 12,
    ];
    if ( $body ) {
        $args['body'] = wp_json_encode( $body );
    }
    $res = wp_remote_request( $url, $args );
    if ( is_wp_error( $res ) ) {
        return [];
    }

    // Check for HTTP errors
    $response_code = wp_remote_retrieve_response_code( $res );
    if ( $response_code >= 400 ) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log( 'AI Supabase API error (' . $response_code . '): ' . wp_remote_retrieve_body( $res ) );
        }
        return [];
    }

    $out = json_decode( wp_remote_retrieve_body( $res ), true ) ?: [];
    if ( $cache_key ) {
        set_transient( $cache_key, $out, $cache_ttl );
    }
    return $out;
}

function ai_openai_embed( string $text ): ?array {
    $key = trim( get_option( 'aivesese_openai' ) );
    if ( ! $key ) {
        return null;
    }
    $args = [
        'method'  => 'POST',
        'timeout' => 12,
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ] ),
    ];
    $r = wp_remote_post( 'https://api.openai.com/v1/embeddings', $args );
    if ( is_wp_error( $r ) ) {
        return null;
    }
    $j = json_decode( wp_remote_retrieve_body( $r ), true );
    return $j['data'][0]['embedding'] ?? null;
}

/* -------------------------------------------------------------------------
 * 3. Search helpers
 * ------------------------------------------------------------------------- */
function aivesese_fts_ids(string $term, int $limit = 20): array {
    $term = trim($term);
    if ($term === '' || mb_strlen($term) < 3) {
        return [];
    }

    // keep it reasonable
    $limit = max(1, min($limit, 50));

    $store = get_option('aivesese_store');
    if (!$store) {
        return [];
    }

    $params = [
        'select'     => 'woocommerce_id',
        'store_id'   => 'eq.' . $store,
        // EITHER keep strict tsquery:
        // 'index_data' => 'fts.' . $term,
        // OR nicer, web-style search in 'simple' config:
        'index_data' => 'wfts.simple.' . $term,
        'order'      => 'fts.rank().desc',
        'limit'      => $limit,
    ];

    $cache_key = 'fts_' . $store . '_' . $limit . '_' . md5($term);
    $rows = aivesese_request('GET', '/rest/v1/search_indexes', null, $params, $cache_key, 20);

    return wp_list_pluck((array) $rows, 'woocommerce_id');
}

function aivesese_semantic_ids( string $term, int $limit = 20 ): array {
    $store     = get_option( 'aivesese_store' );
    $embedding = ai_openai_embed( $term );
    if ( ! $embedding ) {
        return [];
    }
    $rows = aivesese_request( 'POST', '/rest/v1/rpc/semantic_search', [
        'store_id'        => $store,
        'query_embedding' => $embedding,
        'match_threshold' => 0.35,
        'p_k'           => $limit,
    ], [], 'sem_' . md5( $term ), 20 );
    return wp_list_pluck( $rows, 'woocommerce_id' );
}

function aivesese_search_ids( string $term, int $limit = 20 ): array {
    $use_sem = get_option( 'aivesese_semantic_toggle' ) === '1' && strlen( $term ) >= 3;
    if ( $use_sem ) {
        $ids = aivesese_semantic_ids( $term, $limit );
        if ( $ids ) {
            return $ids;
        }
    }
    return aivesese_fts_ids( $term, $limit );
}

/* -------------------------------------------------------------------------
 * 4. Intercept product search (catalog + Woodmart live‑search)
 * ------------------------------------------------------------------------- */
add_action( 'pre_get_posts', function ( WP_Query $q ) {
    if ( is_admin() || 'product' !== $q->get( 'post_type' ) ) {
        return;
    }
    $s = $q->get( 's' );
    if ( ! $s || strlen( $s ) < 3 ) {
        return;
    }
    $ids = aivesese_search_ids( $s );
    if ( ! $ids ) {
        return;
    }
    $q->set( 'post__in', $ids );
    $q->set( 'orderby', 'post__in' );
    $q->set( 'posts_per_page', 20 );
}, 20 );

/* -------------------------------------------------------------------------
 * 5. Mini‑cart recommendations
 * ------------------------------------------------------------------------- */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item) {
    if (get_option('aivesese_enable_cart_below', '1') !== '1') {
        return $name;
    }
    if (! did_action('aivesese_recs_once')) {
        add_action('woocommerce_after_cart', 'aivesese_render_recs');
        do_action('aivesese_recs_once');
    }
    return $name;
}, 10, 2);

function aivesese_render_recs() {
    if (get_option('aivesese_enable_cart_below', '1') !== '1') {
        return;
    }
    $store = get_option('aivesese_store');
    if (!$store || !function_exists('WC')) {
        return;
    }
    $cart_items = WC()->cart->get_cart_contents();
    $cart_ids = array_map(function ($ci) { return $ci['product_id']; }, $cart_items);

    $rows = aivesese_request('POST', '/rest/v1/rpc/get_recommendations', [
        'store_id' => $store,
        'cart'     => $cart_ids,
        'p_k'      => 4,
    ], [], 'recs_' . md5(wp_json_encode($cart_ids)), 60);
    if (empty($rows)) {
        return;
    }
    echo '<h3>You might also like</h3><ul class="products supabase-recs columns-4">';
    foreach ($rows as $r) {
        $prod = wc_get_product($r['woocommerce_id']);
        if (!$prod) { continue; }
        echo '<li class="product"><a href="' . esc_url(get_permalink($prod->get_id())) . '">' .
             wp_kses_post( $prod->get_image() ) .
             '<h2 class="woocommerce-loop-product__title">' . esc_html($prod->get_name()) . '</h2>' .
             wp_kses_post( $prod->get_price_html() ) .
             '</a></li>';
    }
    echo '</ul>';
}

/* -------------------------------------------------------------------------
 * 6. PDP "Similar products"
 * ------------------------------------------------------------------------- */

if (get_option('aivesese_enable_pdp_similar', '1') === '1') {
    /* Preserve the order of our Supabase-ranked IDs */
    add_filter('woocommerce_related_products_args', function ($args) {
        $args['orderby'] = 'post__in';
        $args['posts_per_page'] = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 8;
        return $args;
    }, 20);

    /**
     * Replace Woo's related-products list with Supabase "similar_products".
     */
    add_filter('woocommerce_related_products', function ($related, $product_id, $args) {
        if (get_option('aivesese_enable_pdp_similar', '1') !== '1') {
            return $related;
        }
        $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 4;

        $rows = aivesese_request('POST', '/rest/v1/rpc/similar_products', [
            'prod_wc_id' => $product_id,
            'k'          => $limit,
        ], [], 'aivesese_sim_' . $product_id, 300);

        $ids = wp_list_pluck((array) $rows, 'woocommerce_id');
        return !empty($ids) ? $ids : $related;
    }, 10, 3);
}

// Save open/closed state per user (1=open, 0=closed)
add_action('wp_ajax_aivesese_toggle_help', function () {
    check_ajax_referer('aivesese_help_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'forbidden'), 403);
    }

    $open = (isset($_POST['open']) && wp_unslash($_POST['open']) === '1') ? '1' : '0';
    update_user_meta(get_current_user_id(), '_aivesese_help_open', $open);

    wp_send_json_success(array('open' => $open));
});

add_action('admin_enqueue_scripts', function($hook){
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ( ! in_array( $page, array('aivesese', 'aivesese-status', 'aivesese-sync'), true ) ) {
        return;
    }

    // Keep the small "help toggle" bootstrap in its own handle
    wp_register_script('aivesese-help', false, array(), defined('AIVESESE_PLUGIN_VERSION') ? AIVESESE_PLUGIN_VERSION : false, true);
    wp_enqueue_script('aivesese-help');

    $data = array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('aivesese_help_nonce'),
    );
    wp_add_inline_script('aivesese-help', 'window.AISupabaseHelp=' . wp_json_encode($data) . ';', 'before');

    wp_add_inline_script('aivesese-help', "(function(){
      var el = document.getElementById('ai-supabase-help-details');
      if (!el) return;
      el.addEventListener('toggle', function(){
        var body = new FormData();
        body.append('action', 'aivesese_toggle_help');
        body.append('open', el.open ? '1' : '0');
        body.append('nonce', window.AISupabaseHelp.nonce);
        fetch(window.AISupabaseHelp.ajax_url, { method:'POST', credentials:'same-origin', body: body });
      }, { passive: true });
    })();");

    // --- Admin CSS (migrated from inline <style>) -------------------------------
    wp_register_style('aivesese-admin', false, array(), AIVESESE_PLUGIN_VERSION);
    wp_enqueue_style('aivesese-admin');
    wp_add_inline_style('aivesese-admin', trim("
    .ai-supabase-help details { border:1px solid #dcdcde; border-radius:6px; background:#fff; }
    .ai-supabase-help__summary { padding:12px 14px; cursor:pointer; list-style:none; }
    .ai-supabase-help__summary::-webkit-details-marker { display:none; }
    .ai-supabase-help__summary:after { content:'▾'; float:right; transition:transform .2s ease; }
    .ai-supabase-help details[open] .ai-supabase-help__summary:after { transform:rotate(180deg); }
    .ai-supabase-help details > *:not(.ai-supabase-help__summary) { padding:0 14px 14px; }
    .ai-supabase-help__hint { color:#646970; font-weight:400; margin-left:8px; }
    "));

    // Ensure we use the prefixed handle for inline JS
    wp_register_script('aivesese-admin', false, array(), AIVESESE_PLUGIN_VERSION, true);
    wp_enqueue_script('aivesese-admin');

    // --- Copy SQL button logic (migrated from inline <script>) -------------------
    wp_add_inline_script('aivesese-admin', trim("(function(){
    var btn = document.getElementById('ai-copy-sql');
    var ta  = document.getElementById('ai-sql');
    var out = document.getElementById('ai-copy-status');
    if (!btn || !ta) return;
    btn.addEventListener('click', async function(){
        try {
        await navigator.clipboard.writeText(ta.value);
        if(out){ out.textContent = 'SQL copied to clipboard.'; out.style.display='block'; out.style.color='green'; }
        } catch(e){
        ta.select(); document.execCommand('copy');
        if(out){ out.textContent = 'Copied using fallback.'; out.style.display='block'; out.style.color='green'; }
        }
    }, { passive:true });
    })();"));

    // --- Submit spinner / status logic (migrated from inline <script>) ----------
    wp_add_inline_script('aivesese-admin', trim("document.addEventListener('DOMContentLoaded', function(){
    var forms = document.querySelectorAll('form');
    forms.forEach(function(form){
        form.addEventListener('submit', function(){
        var button = form.querySelector('button[type=submit]');
        if (button) { button.innerHTML = 'Processing...'; button.disabled = true; }

        var statusDiv = document.getElementById('sync-status');
        if (statusDiv) {
            statusDiv.innerHTML = '<div class=\"notice notice-info\"><p>⏳ Processing... Please wait.</p></div>';
        }
        });
    });
    });"));
});

// ─────────────────────────────────────────────────────────────────────────────
// Sales banner – shows on our Settings / Status / Sync admin pages only.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_notices', 'aivesese_services_banner' );
function aivesese_services_banner() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    // Limit the banner to our three pages so we don't spam the whole admin.
    if ( ! $screen || ! in_array(
            $screen->id,
            array(
                'settings_page_aivesese',
                'settings_page_aivesese-status',
                'settings_page_aivesese-sync'
            ),
            true
        ) ) {
        return;
    }

    echo '<div class="notice notice-success aivesese-services-banner" style="
        display:flex;align-items:center;gap:16px;
        border-left:6px solid #673ab7;
        background:#f5f3ff;padding:14px 18px;margin-top:16px;">
            <div style="font-size:24px;">🚀</div>
            <div style="flex:1 1 auto;">
                <strong>Need a hand with AI search or Supabase?</strong><br>
                Our team at <em>ZZZ Solutions</em> can install, customise and tune everything for you.
            </div>
            <a href="https://zzzsolutions.ro" target="_blank" rel="noopener noreferrer"
               class="button button-primary">See Services</a>
        </div>';
}

/**
 * Show a one-time “we screwed up” notice if store_health_check()
 * is still returning 0 published products.
 */
add_action( 'admin_notices', function () {

    // Only show to admins.
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Already dismissed?
    if ( get_option( 'aivesese_health_fix_dismissed' ) ) {
        return;
    }

    // Detect the bug: Woo says >0, Supabase says 0.
    $local  = (int) wp_count_posts( 'product' )->publish;
    $remote = 0;
    $resp   = aivesese_request(
        'POST',
        '/rest/v1/rpc/store_health_check',
        [ 'check_store_id' => get_option( 'aivesese_store_id' ) ]
    );
    if ( $resp && isset( $resp[0]['published_products'] ) ) {
        $remote = (int) $resp[0]['published_products'];
    }

    if ( $local === 0 || $remote > 0 ) {
        return; // nothing wrong, or already fixed
    }

    // Build the notice content.
    $sql = <<<SQL
-- Paste this in Supabase SQL Editor (SQL v0.14+)
DROP FUNCTION IF EXISTS store_health_check(uuid);

CREATE FUNCTION store_health_check(check_store_id uuid)
RETURNS TABLE (
    total_products         integer,
    published_products     integer,
    in_stock_products      integer,
    with_embeddings        integer,
    avg_embedding_quality  integer
) LANGUAGE sql STABLE AS
\$\$
SELECT
    COUNT(*)                                                  AS total_products,
    COUNT(*) FILTER (WHERE status = 'publish')                AS published_products,
    COUNT(*) FILTER (WHERE stock_status = 'in')               AS in_stock_products,
    COUNT(*) FILTER (WHERE embedding IS NOT NULL)             AS with_embeddings,
    COALESCE(AVG(vector_dims(embedding)),0)::int             AS avg_embedding_quality
FROM products
WHERE store_id = check_store_id;
\$\$;
SQL;

    // Render.
    ?>
    <div class="notice notice-error">
        <p><strong>AI Vector Search - Dashboard is broken ⚠️</strong></p>
        <p>
            Supabase is still reporting <code>0</code> published products while
            WooCommerce has <strong><?php echo esc_html( $local ); ?></strong>.
            We screwed up the SQL function. Run the snippet below once, then
            reload this page.
        </p>
        <textarea readonly rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $sql ); ?></textarea>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'aivesese_fix_dismiss', 1 ), 'aivesese_fix_nonce' ) ); ?>"
               class="button">Dismiss (I’ve fixed it)</a>
        </p>
    </div>
    <?php
});

/**
 * Mark the notice as dismissed.
 */
add_action( 'admin_init', function () {
    if ( isset( $_GET['aivesese_fix_dismiss'] ) &&
         check_admin_referer( 'aivesese_fix_nonce' ) ) {
        update_option( 'aivesese_health_fix_dismissed', time() );
        wp_safe_redirect( remove_query_arg( [ 'aivesese_fix_dismiss', '_wpnonce' ] ) );
        exit;
    }
});
