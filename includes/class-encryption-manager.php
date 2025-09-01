<?php
/**
 * Handles secure storage and encryption of sensitive data
 */
class AIVectorSearch_Encryption_Manager {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Transparent encryption on update
        add_filter('pre_update_option_aivesese_key', [$this, 'encrypt_option'], 10, 3);
        add_filter('pre_update_option_aivesese_openai', [$this, 'encrypt_option'], 10, 3);

        // Transparent decryption on read
        add_filter('option_aivesese_key', [$this, 'decrypt_option']);
        add_filter('option_aivesese_openai', [$this, 'decrypt_option']);

        // One-time migration
        add_action('admin_init', [$this, 'migrate_legacy_options']);

        // Admin notice for missing master key
        add_action('admin_notices', [$this, 'master_key_notice']);
    }

    public function get_master_key(): string {
        if (defined('AIVESESE_MASTER_KEY_B64') && AIVESESE_MASTER_KEY_B64) {
            $bin = base64_decode(AIVESESE_MASTER_KEY_B64, true);
            if ($bin !== false && strlen($bin) === 32) {
                return $bin;
            }
        }

        $material = (defined('AUTH_SALT') ? AUTH_SALT : 'no-auth-salt') . '|' . site_url();
        return hash_hkdf('sha256', $material, 32, 'aivesese_plugin', wp_salt());
    }

    public function encrypt(string $plaintext): array {
        $key = $this->get_master_key();

        if (function_exists('sodium_crypto_secretbox')) {
            return $this->encrypt_sodium($plaintext, $key);
        }

        return $this->encrypt_openssl($plaintext, $key);
    }

    private function encrypt_sodium(string $plaintext, string $key): array {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return [
            'v' => 1,
            'alg' => 'secretbox',
            'nonce' => base64_encode($nonce),
            'cipher' => base64_encode($cipher),
        ];
    }

    private function encrypt_openssl(string $plaintext, string $key): array {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            return [
                'v' => 1,
                'alg' => 'plain',
                'cipher' => base64_encode($plaintext)
            ];
        }

        return [
            'v' => 1,
            'alg' => 'aes-256-gcm',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'cipher' => base64_encode($cipher),
        ];
    }

    public function decrypt($stored): ?string {
        if (!is_array($stored) || empty($stored['alg'])) {
            return is_string($stored) ? $stored : null;
        }

        $key = $this->get_master_key();

        switch ($stored['alg']) {
            case 'secretbox':
                return $this->decrypt_sodium($stored, $key);
            case 'aes-256-gcm':
                return $this->decrypt_openssl($stored, $key);
            case 'plain':
                return base64_decode($stored['cipher']);
            default:
                return null;
        }
    }

    private function decrypt_sodium(array $stored, string $key): ?string {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        $nonce = base64_decode($stored['nonce']);
        $cipher = base64_decode($stored['cipher']);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        return $plain === false ? null : $plain;
    }

    private function decrypt_openssl(array $stored, string $key): ?string {
        $iv = base64_decode($stored['iv']);
        $tag = base64_decode($stored['tag']);
        $cipher = base64_decode($stored['cipher']);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    public function encrypt_option($value, $old_value, $option) {
        return (is_string($value) && $value !== '')
            ? wp_json_encode($this->encrypt($value))
            : $value;
    }

    public function decrypt_option($value, $option = null) {
        $arr = json_decode($value, true);
        return is_array($arr) ? $this->decrypt($arr) : $value;
    }

    public function migrate_legacy_options() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $targets = ['aivesese_key', 'aivesese_openai'];
        foreach ($targets as $opt) {
            $val = get_option($opt, null);
            if (is_string($val) && $val !== '') {
                update_option($opt, $val, false); // pre_update filter will encrypt
            }
        }
    }

    public function master_key_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (defined('AIVESESE_MASTER_KEY_B64') && AIVESESE_MASTER_KEY_B64) {
            return;
        }

        // Only show on relevant admin pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed = ['plugins', 'plugins-network', 'settings_page_aivesese'];
        if (!$screen || !in_array($screen->id, $allowed, true)) {
            return;
        }

        try {
            $key = base64_encode(random_bytes(32));
        } catch (Exception $e) {
            $key = '';
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>AI Supabase Search:</strong> No master key defined for secret encryption.<br>';
        echo 'Add the following line to your <code>wp-config.php</code> above <code>/* That\'s all, stop editing! */</code>:';
        echo '<pre>define(\'AIVESESE_MASTER_KEY_B64\', \'' . esc_html($key) . '\');</pre>';
        echo '</p></div>';
    }
}
