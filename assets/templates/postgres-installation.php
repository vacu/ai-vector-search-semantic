<?php
/**
 * PostgreSQL Installation Template
 * File: assets/templates/postgres-installation.php
 *
 * Variables available:
 * - $migration_status: Array with installation status and requirements
 * - $installed_time: Timestamp when schema was installed (if applicable)
 * - $install_method: Method used for installation
 * - $sql_content: SQL schema content for manual installation
 */

defined('ABSPATH') || exit;
?>

<div class="aivesese-schema-section">
    <h2>🗄️ Database Schema Installation</h2>

    <?php if ($installed_time): ?>
        <!-- Already Installed Status -->
        <div class="notice notice-success inline installation-status-notice">
            <h3>✅ Schema Already Installed</h3>
            <p>
                Installed on <strong><?php echo date('M j, Y \a\t g:i A', $installed_time); ?></strong>
                <?php if ($install_method): ?>
                    via <strong><?php echo esc_html($install_method); ?></strong>
                <?php endif; ?>
            </p>
            <div class="installation-actions">
                <button type="button" class="button" id="postgres-reinstall-btn">
                    <span class="dashicons dashicons-update"></span>
                    Update Schema
                </button>
                <button type="button" class="button button-small" id="postgres-check-status-btn">
                    <span class="dashicons dashicons-search"></span>
                    Check Status
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Installation Options -->
    <div class="installation-options">

        <!-- PostgreSQL Direct Installation -->
        <?php if ($migration_status['can_run']): ?>
            <div class="installation-option postgres-option">
                <h3>🚀 Direct PostgreSQL Installation (Recommended)</h3>
                <p>Install schema directly via PostgreSQL connection - fastest and most reliable method.</p>

                <div class="postgres-benefits">
                    <h4>Why choose this method?</h4>
                    <ul>
                        <li>✅ <strong>One-click installation</strong> - No copy/paste needed</li>
                        <li>✅ <strong>Transactional safety</strong> - Automatic rollback on errors</li>
                        <li>✅ <strong>Real-time feedback</strong> - See exactly what happens</li>
                        <li>✅ <strong>Professional grade</strong> - Same method used by WP-CLI</li>
                        <li>✅ <strong>Error handling</strong> - Clear troubleshooting guidance</li>
                    </ul>
                </div>

                <div class="postgres-action">
                    <button type="button" class="button button-primary button-large" id="postgres-install-btn">
                        <span class="dashicons dashicons-database"></span>
                        Install Schema via PostgreSQL
                    </button>
                </div>

                <!-- Progress Section -->
                <div id="postgres-installation-progress" style="display: none;">
                    <h4>Installation Progress</h4>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">Preparing installation...</div>
                </div>

                <!-- Results Section -->
                <div id="postgres-installation-result"></div>
            </div>

        <?php else: ?>
            <!-- PostgreSQL Unavailable -->
            <div class="installation-option postgres-unavailable">
                <h3>🚀 Direct PostgreSQL Installation</h3>
                <div class="notice notice-warning inline">
                    <p><strong>⚠️ PostgreSQL installation not available</strong></p>

                    <div class="requirements-check">
                        <h4>Requirements Status:</h4>
                        <ul class="requirements-list">
                            <?php foreach ($migration_status['requirements'] as $requirement => $met): ?>
                                <li class="requirement-item <?php echo $met ? 'requirement-met' : 'requirement-missing'; ?>">
                                    <span class="requirement-status"><?php echo $met ? '✅' : '❌'; ?></span>
                                    <span class="requirement-name"><?php echo esc_html(ucwords(str_replace('_', ' ', $requirement))); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if (!$migration_status['requirements']['psql_command']): ?>
                        <div class="psql-install-help">
                            <h4>🔧 Enable PostgreSQL Installation</h4>
                            <p><strong>Install PostgreSQL client on your server:</strong></p>
                            <div class="install-commands">
                                <div class="command-group">
                                    <strong>Ubuntu/Debian:</strong>
                                    <code>sudo apt-get install postgresql-client</code>
                                </div>
                                <div class="command-group">
                                    <strong>CentOS/RHEL:</strong>
                                    <code>sudo yum install postgresql</code>
                                </div>
                                <div class="command-group">
                                    <strong>Alpine Linux:</strong>
                                    <code>apk add postgresql-client</code>
                                </div>
                            </div>
                            <p>After installation, refresh this page to enable PostgreSQL installation.</p>
                        </div>
                    <?php endif; ?>

                    <?php if (!$migration_status['requirements']['connection_string']): ?>
                        <div class="connection-string-missing">
                            <p><strong>Missing:</strong> Configure your PostgreSQL connection string in the field above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Manual Installation -->
        <div class="installation-option manual-option">
            <h3>📝 Manual Installation</h3>
            <p>Copy the SQL and run it manually in Supabase SQL Editor - always available as fallback.</p>

            <div class="manual-benefits">
                <h4>Manual installation benefits:</h4>
                <ul>
                    <li>✅ <strong>Always works</strong> - No server requirements</li>
                    <li>✅ <strong>Full control</strong> - See exactly what gets executed</li>
                    <li>✅ <strong>Educational</strong> - Learn the database structure</li>
                    <li>✅ <strong>Universal</strong> - Works on any hosting environment</li>
                    <li>✅ <strong>Transparent</strong> - Review SQL before execution</li>
                </ul>
            </div>

            <details class="manual-installation-details">
                <summary class="manual-toggle">
                    <strong>Show Manual Installation</strong>
                    <span class="toggle-indicator"></span>
                </summary>

                <div class="manual-content">
                    <!-- Installation Steps -->
                    <div class="manual-steps">
                        <h4>📋 Manual Installation Steps:</h4>
                        <ol class="installation-steps">
                            <li>
                                <strong>Open Supabase SQL Editor</strong>
                                <p>Go to your Supabase project → <strong>SQL Editor</strong> → <strong>New query</strong></p>
                            </li>
                            <li>
                                <strong>Copy the SQL</strong>
                                <p>Click "Copy SQL" below and paste it into the editor</p>
                            </li>
                            <li>
                                <strong>Execute the SQL</strong>
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
                                <textarea id="manual-sql-content"
                                         rows="15"
                                         class="sql-editor"
                                         readonly
                                         aria-label="SQL Schema Content"><?php echo esc_textarea($sql_content); ?></textarea>
                            </div>

                            <div id="manual-copy-status" class="copy-status-container" style="display:none;"></div>
                        </div>

                        <!-- SQL Information -->
                        <div class="sql-info">
                            <h4>📊 What this SQL does:</h4>
                            <div class="sql-features">
                                <div class="sql-feature">
                                    <span class="feature-icon">🗃️</span>
                                    <div class="feature-content">
                                        <strong>Creates Tables</strong>
                                        <p>Products table with full-text and vector search indexes</p>
                                    </div>
                                </div>

                                <div class="sql-feature">
                                    <span class="feature-icon">⚡</span>
                                    <div class="feature-content">
                                        <strong>Search Functions</strong>
                                        <p>FTS, semantic, and SKU search with intelligent ranking</p>
                                    </div>
                                </div>

                                <div class="sql-feature">
                                    <span class="feature-icon">🎯</span>
                                    <div class="feature-content">
                                        <strong>Recommendations</strong>
                                        <p>Similar products and cart-based suggestion algorithms</p>
                                    </div>
                                </div>

                                <div class="sql-feature">
                                    <span class="feature-icon">🔒</span>
                                    <div class="feature-content">
                                        <strong>Security</strong>
                                        <p>Row-level security policies and data protection</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- SQL File Not Found -->
                        <div class="notice notice-error inline">
                            <h4>❌ SQL file not found</h4>
                            <p>The schema file could not be located. Please check your plugin installation.</p>
                            <p><strong>Expected location:</strong> <code><?php echo AIVESESE_PLUGIN_PATH . 'supabase.sql'; ?></code></p>
                            <div class="error-actions">
                                <a href="https://zzzsolutions.ro/support" target="_blank" class="button">
                                    Contact Support
                                </a>
                                <a href="https://github.com/your-repo/ai-search/blob/main/supabase.sql" target="_blank" class="button">
                                    Download SQL
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </div>

    <!-- Installation Help -->
    <div class="installation-help">
        <h3>🤝 Need Help?</h3>
        <div class="help-options">
            <div class="help-option">
                <span class="help-icon">📚</span>
                <div class="help-content">
                    <strong>Documentation</strong>
                    <p>Step-by-step setup guides and troubleshooting</p>
                    <a href="https://zzzsolutions.ro/docs" target="_blank" class="button button-small">
                        View Docs
                    </a>
                </div>
            </div>

            <div class="help-option">
                <span class="help-icon">💬</span>
                <div class="help-content">
                    <strong>Community Support</strong>
                    <p>Get help from other users and developers</p>
                    <a href="https://zzzsolutions.ro/community" target="_blank" class="button button-small">
                        Join Community
                    </a>
                </div>
            </div>

            <div class="help-option">
                <span class="help-icon">🛠️</span>
                <div class="help-content">
                    <strong>Professional Setup</strong>
                    <p>We'll install and configure everything for you</p>
                    <a href="https://zzzsolutions.ro/setup-service" target="_blank" class="button button-primary button-small">
                        Get Setup Service
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
