<?php
/**
 * PostgreSQL Help Section Template
 * File: assets/templates/postgres-help-section.php
 *
 * This template displays help information for PostgreSQL connection setup
 */

defined('ABSPATH') || exit;
?>

<div class="postgres-connection-help">
    <h4>🔗 How to get your PostgreSQL connection string:</h4>
    <ol>
        <li>Go to your Supabase project → <strong>Settings</strong> → <strong>Database</strong></li>
        <li>Scroll down to <strong>"Connection parameters"</strong> or <strong>"Connection pooling"</strong></li>
        <li>Copy the <strong>"Connection string"</strong> (URI format)</li>
        <li>Make sure to use the <strong>direct connection</strong> (not pooled) for schema operations</li>
    </ol>

    <div class="connection-string-examples">
        <p><strong>📝 Example format:</strong></p>
        <code>postgresql://postgres.abcdefgh:[YOUR-PASSWORD]@aws-0-us-east-1.pooler.supabase.com:5432/postgres</code>
    </div>

    <div class="security-note">
        <p><strong>🔒 Security:</strong> This connection string will be encrypted and stored securely in your WordPress database.</p>
    </div>
</div>

<div class="wp-cli-info">
    <h4>⚡ WP-CLI Schema Installation</h4>
    <p>Once configured, you can install/update your schema with one command:</p>
    <div class="cli-command-box">
        <code>wp aivs install-schema</code>
        <button type="button" class="button button-small" onclick="copyCliCommand()">Copy Command</button>
    </div>

    <p><strong>Available WP-CLI commands:</strong></p>
    <ul style="margin-left: 20px;">
        <li><code>wp aivs install-schema</code> - Install/update database schema</li>
        <li><code>wp aivs check-schema</code> - Check schema status</li>
        <li><code>wp aivs test-connection</code> - Test database connection</li>
        <li><code>wp aivs sync-products</code> - Sync WooCommerce products</li>
    </ul>

    <p><em>💡 WP-CLI provides a reliable, professional way to manage database schema installations.</em></p>
</div>
