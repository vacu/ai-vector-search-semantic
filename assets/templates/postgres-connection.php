<?php
/**
 * PostgreSQL Connection Template
 * File: assets/templates/postgres-connection.php
 *
 * Variables available:
 * - $connection_mode: Current connection mode
 * - $value: Current connection string value
 * - $has_value: Boolean if connection string exists
 */

defined('ABSPATH') || exit;
?>

<div class="postgres-connection-field">
    <?php if ($has_value): ?>
        <div class="connection-status configured">
            <span class="dashicons dashicons-yes-alt"></span>
            <strong>PostgreSQL Connection Configured</strong>
            <p>Connection string is securely stored and ready for WP-CLI commands.</p>
            <button type="button" class="button" onclick="toggleConnectionString()">
                Update Connection String
            </button>
        </div>
    <?php endif; ?>

    <div id="connection-string-input" style="<?php echo $has_value ? 'display: none;' : ''; ?>">
        <label for="aivesese_postgres_connection_string">
            <strong>PostgreSQL Connection String</strong>
        </label>
        <input
            type="password"
            id="aivesese_postgres_connection_string"
            name="aivesese_postgres_connection_string"
            value="<?php echo esc_attr($value); ?>"
            class="large-text code"
            placeholder="postgresql://username:password@host:port/database"
            autocomplete="off"
        />

        <div class="postgres-connection-help">
            <h4>🔗 How to get your connection string:</h4>

            <div class="connection-string-examples">
                <p><strong>Supabase Format:</strong></p>
                <code>postgresql://postgres.PROJECT_ID:PASSWORD@aws-0-REGION.pooler.supabase.com:6543/postgres</code>

                <p><strong>Standard PostgreSQL:</strong></p>
                <code>postgresql://username:password@localhost:5432/database</code>
            </div>

            <p><strong>Where to find it:</strong></p>
            <ol>
                <li>Go to your Supabase project → <strong>Settings</strong> → <strong>Database</strong></li>
                <li>Scroll to <strong>"Connection string"</strong> section</li>
                <li>Select <strong>"Pooler"</strong> mode (recommended for better performance)</li>
                <li>Copy the connection string and replace [YOUR-PASSWORD] with your actual database password</li>
                <li>Paste it in the field above</li>
            </ol>

            <div class="security-note">
                <p><strong>🔒 Security:</strong> Connection strings are encrypted and stored securely. Only you can see and modify them.</p>
            </div>
        </div>
    </div>

    <?php if ($connection_mode === 'self_hosted'): ?>
        <div class="wp-cli-info">
            <h4>💻 Recommended: Use WP-CLI for Schema Installation</h4>
            <p>WP-CLI provides the most reliable way to install your database schema:</p>

            <div class="cli-command-box">
                <code>wp aivs install-schema</code>
                <button type="button" class="button" onclick="copyCliCommand()">
                    <span class="dashicons dashicons-clipboard"></span>
                    Copy
                </button>
            </div>

            <p><strong>Available WP-CLI commands:</strong></p>
            <ul>
                <li><code>wp aivs install-schema</code> - Install database schema</li>
                <li><code>wp aivs check-schema</code> - Check installation status</li>
                <li><code>wp aivs test-connection</code> - Test database connection</li>
                <li><code>wp aivs sync-products</code> - Sync WooCommerce products</li>
            </ul>

            <p><em>💡 WP-CLI provides a reliable, professional way to manage database schema installations.</em></p>
        </div>
    <?php endif; ?>
</div>
