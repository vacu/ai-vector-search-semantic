<?php
/**
 * Manual Installation Template
 * File: assets/templates/manual-installation.php
 *
 * Variables available:
 * - $sql_content: SQL schema content
 */

defined('ABSPATH') || exit;
?>

<div class="manual-installation-section">
    <h4>📋 Manual Installation Steps:</h4>

    <div class="installation-steps">
        <ol>
            <li>
                <strong>Open Supabase SQL Editor</strong>
                <p>Go to your Supabase project → <strong>SQL Editor</strong> → <strong>New query</strong></p>
            </li>
            <li>
                <strong>Copy & Paste SQL</strong>
                <p>Click "Copy SQL" below and paste it into the Supabase SQL Editor</p>
            </li>
            <li>
                <strong>Run the Query</strong>
                <p>Press <strong>RUN</strong> and wait for success confirmation</p>
            </li>
            <li>
                <strong>Verify Installation</strong>
                <p>✅ Safe to re-run: Uses CREATE OR REPLACE and IF NOT EXISTS</p>
            </li>
        </ol>
    </div>

    <?php if (!empty($sql_content)): ?>
        <!-- SQL Copy Section -->
        <div class="sql-copy-section">
            <div class="sql-copy-header">
                <h4>📄 Database Schema SQL</h4>
                <button class="button button-secondary" id="copy-manual-sql-btn">
                    <span class="dashicons dashicons-clipboard"></span>
                    Copy SQL for Manual Installation
                </button>
            </div>

            <div class="sql-editor-container">
                <textarea
                    id="manual-sql-content"
                    rows="15"
                    class="sql-editor"
                    readonly
                    aria-label="SQL Schema Content"
                ><?php echo esc_textarea($sql_content); ?></textarea>
            </div>

            <div id="manual-copy-status" class="copy-status-container" style="display:none;"></div>
        </div>

        <!-- SQL Information -->
        <div class="sql-info">
            <h4>📊 What this SQL does:</h4>
            <div class="sql-features">
                <div class="sql-feature">
                    <span class="feature-icon">🗃️</span>
                    <strong>Creates Tables:</strong> Products storage with full-text search support
                </div>
                <div class="sql-feature">
                    <span class="feature-icon">🔍</span>
                    <strong>Search Functions:</strong> FTS search, semantic search, SKU search
                </div>
                <div class="sql-feature">
                    <span class="feature-icon">🚀</span>
                    <strong>Performance:</strong> Optimized indexes and ranking algorithms
                </div>
                <div class="sql-feature">
                    <span class="feature-icon">🔒</span>
                    <strong>Security:</strong> RLS policies and proper permissions
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="notice notice-error inline">
            <p><strong>❌ SQL file not found</strong></p>
            <p>Expected location: <code><?php echo AIVESESE_PLUGIN_PATH . 'supabase.sql'; ?></code></p>
        </div>
    <?php endif; ?>
</div>
