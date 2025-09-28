/**
 * PostgreSQL Installation JavaScript
 * File: assets/js/postgres-installation.js
 */

class PostgreSQLInstaller {
    constructor() {
        this.init();
    }

    init() {
        this.initInstallationHandlers();
        this.initManualCopy();
        this.initConnectionStringToggle();
        this.initCLICommandCopy();
        this.addSpinAnimation();
    }

    /**
     * Initialize PostgreSQL installation handlers
     */
    initInstallationHandlers() {
        const installBtn = document.getElementById('postgres-install-btn');
        const reinstallBtn = document.getElementById('postgres-reinstall-btn');
        const checkStatusBtn = document.getElementById('postgres-check-status-btn');

        if (installBtn) {
            installBtn.addEventListener('click', () => this.handleInstallation(false));
        }

        if (reinstallBtn) {
            reinstallBtn.addEventListener('click', () => this.handleInstallation(true));
        }

        if (checkStatusBtn) {
            checkStatusBtn.addEventListener('click', () => this.handleStatusCheck());
        }
    }

    /**
     * Handle PostgreSQL installation
     */
    handleInstallation(isReinstall = false) {
        const btn = isReinstall ? document.getElementById('postgres-reinstall-btn') : document.getElementById('postgres-install-btn');
        if (!btn) return;

        const originalHTML = btn.innerHTML;
        const progressDiv = document.getElementById('postgres-installation-progress');
        const progressFill = progressDiv?.querySelector('.progress-fill');
        const progressText = progressDiv?.querySelector('.progress-text');
        const resultDiv = document.getElementById('postgres-installation-result');

        // Update button state
        btn.disabled = true;
        btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> ' + (isReinstall ? 'Updating...' : 'Installing...');

        // Show progress
        if (progressDiv) {
            progressDiv.style.display = 'block';
            if (progressFill) progressFill.style.width = '20%';
            if (progressText) progressText.textContent = 'Connecting to PostgreSQL database...';
        }

        if (resultDiv) {
            resultDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Installing schema via PostgreSQL connection...</p></div>';
        }

        // Progress simulation
        const progressInterval = this.simulateProgress(progressFill, progressText);

        // Make AJAX request
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivesese_postgres_install_schema',
                nonce: window.aivesese_postgres.install_nonce || ''
            })
        })
        .then(response => response.json())
        .then(data => {
            clearInterval(progressInterval);
            this.completeProgress(progressFill, progressText, data.success);

            if (data.success) {
                this.handleSuccessfulInstallation(data.data, resultDiv);
            } else {
                this.handleFailedInstallation(data.data, resultDiv);
            }
        })
        .catch(error => {
            clearInterval(progressInterval);
            this.handleInstallationError(error, resultDiv);
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;

            setTimeout(() => {
                if (progressDiv) progressDiv.style.display = 'none';
            }, 5000);
        });
    }

    /**
     * Simulate installation progress
     */
    simulateProgress(progressFill, progressText) {
        let progress = 20;

        return setInterval(() => {
            if (progress < 90) {
                progress += Math.random() * 15;
                if (progressFill) {
                    progressFill.style.width = Math.min(progress, 90) + '%';
                }

                if (progressText) {
                    if (progress > 40 && progress < 60) {
                        progressText.textContent = 'Executing SQL schema...';
                    } else if (progress > 60 && progress < 80) {
                        progressText.textContent = 'Creating functions and triggers...';
                    }
                }
            }
        }, 800);
    }

    /**
     * Complete progress animation
     */
    completeProgress(progressFill, progressText, success) {
        if (progressFill) {
            progressFill.style.width = '100%';
            progressFill.style.background = success ? '#46b450' : '#dc3232';
        }

        if (progressText) {
            progressText.textContent = success ? 'Installation completed!' : 'Installation failed';
        }
    }

    /**
     * Handle successful installation
     */
    handleSuccessfulInstallation(data, resultDiv) {
        if (!resultDiv) return;

        let message = '<div class="notice notice-success">';
        message += '<h4>✅ ' + data.message + '</h4>';

        if (data.details && data.details.stdout) {
            message += '<details style="margin: 15px 0;"><summary><strong>Installation Details</strong></summary>';
            message += '<pre style="background: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;">';
            message += this.escapeHtml(data.details.stdout);
            message += '</pre></details>';
        }

        message += '<div style="margin: 15px 0; padding: 15px; background: #e8f4fd; border-left: 4px solid #0073aa; border-radius: 0 4px 4px 0;">';
        message += '<h4>🎉 Next Steps:</h4>';
        message += '<ol>';
        message += '<li>✅ Database schema is ready</li>';
        message += '<li>📦 <a href="' + this.getAdminUrl('admin.php?page=aivesese-sync') + '">Sync your products</a></li>';
        message += '<li>🔍 Test search functionality on your store</li>';
        message += '</ol>';
        message += '</div>';
        message += '</div>';

        resultDiv.innerHTML = message;

        // Refresh page after delay
        setTimeout(() => {
            window.location.reload();
        }, 8000);
    }

    /**
     * Handle failed installation
     */
    handleFailedInstallation(data, resultDiv) {
        if (!resultDiv) return;

        let message = '<div class="notice notice-error">';
        message += '<h4>❌ Installation Failed</h4>';
        message += '<p><strong>Error:</strong> ' + this.escapeHtml(data.message) + '</p>';

        if (data.details) {
            if (data.details.errors && data.details.errors.length > 0) {
                message += '<details style="margin: 15px 0;"><summary><strong>Error Details</strong></summary>';
                message += '<div style="background: #fef2f2; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                data.details.errors.forEach(error => {
                    message += '<div style="color: #dc3232; margin: 5px 0;">' + this.escapeHtml(error) + '</div>';
                });
                message += '</div></details>';
            }

            if (data.details.suggestions && data.details.suggestions.length > 0) {
                message += '<div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 0 4px 4px 0;">';
                message += '<h4>💡 Suggestions:</h4>';
                message += '<ul>';
                data.details.suggestions.forEach(suggestion => {
                    message += '<li>' + this.escapeHtml(suggestion) + '</li>';
                });
                message += '</ul>';
                message += '</div>';
            }
        }

        message += this.getTroubleshootingSection();
        message += '</div>';

        resultDiv.innerHTML = message;
    }

    /**
     * Handle installation network error
     */
    handleInstallationError(error, resultDiv) {
        if (!resultDiv) return;

        resultDiv.innerHTML = '<div class="notice notice-error">' +
            '<h4>❌ Connection Error</h4>' +
            '<p>Unable to communicate with the installation service.</p>' +
            '<p><strong>Error:</strong> ' + this.escapeHtml(error.message) + '</p>' +
            '<p><strong>Try:</strong> Refresh the page and try again, or use manual installation.</p>' +
            '</div>';
    }

    /**
     * Handle status check
     */
    handleStatusCheck() {
        const btn = document.getElementById('postgres-check-status-btn');
        const resultDiv = document.getElementById('postgres-installation-result');

        if (!btn) return;

        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Checking...';

        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivesese_postgres_check_status',
                // FIX: Use the correct nonce variable from localized script
                nonce: aivesese_postgres.status_nonce || ''
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.displayStatusResults(data.data, resultDiv);
            } else {
                if (resultDiv) {
                    resultDiv.innerHTML = '<div class="notice notice-error"><p>Status check failed: ' + this.escapeHtml(data.data?.message || 'Unknown error') + '</p></div>';
                }
            }
        })
        .catch(error => {
            if (resultDiv) {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>Status check failed: ' + this.escapeHtml(error.message) + '</p></div>';
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
    }

    /**
     * Display status check results
     */
    displayStatusResults(status, resultDiv) {
        if (!resultDiv) return;

        let message = '<div class="notice notice-info">';
        message += '<h4>📊 PostgreSQL Installation Status</h4>';
        message += '<div style="margin: 15px 0;">';
        message += '<p><strong>Installation Ready:</strong> ' + (status.can_run ? '✅ Yes' : '❌ No') + '</p>';
        message += '<h4>Requirements Check:</h4>';
        message += '<ul style="margin-left: 20px;">';

        Object.entries(status.requirements || {}).forEach(([req, met]) => {
            const icon = met ? '✅' : '❌';
            const name = req.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            message += '<li>' + icon + ' ' + name + '</li>';
        });

        message += '</ul>';
        message += '</div>';

        if (!status.can_run) {
            message += '<div style="background: #fff3cd; padding: 15px; border-radius: 4px; margin: 15px 0;">';
            message += '<p><strong>💡 To enable PostgreSQL installation:</strong></p>';

            if (!status.requirements?.psql_command) {
                message += '<p>Install PostgreSQL client on your server</p>';
            }
            if (!status.requirements?.connection_string) {
                message += '<p>Configure PostgreSQL connection string above</p>';
            }
            if (!status.requirements?.sql_file) {
                message += '<p>Ensure supabase.sql file exists in plugin directory</p>';
            }

            message += '</div>';
        }

        message += '</div>';
        resultDiv.innerHTML = message;
    }

    /**
     * Initialize manual SQL copy functionality
     */
    initManualCopy() {
        const copyButton = document.getElementById('copy-manual-sql-btn');
        const sqlTextarea = document.getElementById('manual-sql-content');
        const statusElement = document.getElementById('manual-copy-status');

        if (!copyButton || !sqlTextarea) return;

        copyButton.addEventListener('click', () => {
            // Check if clipboard API is available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                // Use modern clipboard API
                navigator.clipboard.writeText(sqlTextarea.value).then(() => {
                    this.showCopyStatus(statusElement, '✅ SQL copied to clipboard! Paste it in Supabase → SQL Editor and run it.', 'success');
                }).catch(() => {
                    // Fallback if clipboard API fails
                    this.fallbackCopy(sqlTextarea, statusElement);
                });
            } else {
                // Use fallback method if clipboard API is not available
                this.fallbackCopy(sqlTextarea, statusElement);
            }
        });
    }

    /**
     * Fallback copy method using document.execCommand
     */
    fallbackCopy(textArea, statusElement) {
        try {
            // Select the text
            textArea.select();
            textArea.setSelectionRange(0, 99999); // For mobile devices

            // Execute copy command
            const successful = document.execCommand('copy');

            if (successful) {
                this.showCopyStatus(statusElement, '✅ SQL copied to clipboard! Paste it in Supabase → SQL Editor and run it.', 'success');
            } else {
                this.showCopyStatus(statusElement, '⚠️ Copy failed. Please manually select and copy the SQL above.', 'warning');
            }
        } catch (err) {
            console.error('Copy fallback failed:', err);
            this.showCopyStatus(statusElement, '⚠️ Copy not supported. Please manually select and copy the SQL above.', 'warning');
        }

        // Clear selection
        if (window.getSelection) {
            window.getSelection().removeAllRanges();
        }
    }

    /**
     * Initialize connection string toggle
     */
    initConnectionStringToggle() {
        window.toggleConnectionString = () => {
            const input = document.getElementById('connection-string-input');
            const status = document.querySelector('.connection-status');

            if (!input || !status) return;

            if (input.style.display === 'none') {
                input.style.display = 'block';
                status.style.display = 'none';
            } else {
                input.style.display = 'none';
                status.style.display = 'block';
            }
        };
    }

    /**
     * Initialize CLI command copy
     */
    initCLICommandCopy() {
        window.copyCliCommand = () => {
            const command = 'wp aivs install-schema';

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(command).then(() => {
                    alert('✅ WP-CLI command copied to clipboard!');
                }).catch(() => {
                    this.fallbackCopyCommand(command);
                });
            } else {
                this.fallbackCopyCommand(command);
            }
        };
    }

    /**
     * Fallback method for copying CLI command
     */
    fallbackCopyCommand(command) {
        try {
            // Create temporary textarea
            const textArea = document.createElement('textarea');
            textArea.value = command;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);

            // Select and copy
            textArea.select();
            textArea.setSelectionRange(0, 99999);
            const successful = document.execCommand('copy');

            // Clean up
            document.body.removeChild(textArea);

            if (successful) {
                alert('✅ WP-CLI command copied to clipboard!');
            } else {
                alert('⚠️ Copy failed. Command: ' + command);
            }
        } catch (err) {
            console.error('CLI copy fallback failed:', err);
            alert('⚠️ Copy not supported. Command: ' + command);
        }
    }

    /**
     * Show copy status message
     */
    showCopyStatus(element, message, type) {
        if (!element) return;

        element.innerHTML = '<div style="color: ' + (type === 'success' ? '#00a32a' : '#dc3232') + '; background: #f0f9ff; padding: 10px; border-radius: 4px;">' + message + '</div>';
        element.style.display = 'block';

        setTimeout(() => {
            element.style.display = 'none';
        }, 8000);
    }

    /**
     * Add spin animation styles
     */
    addSpinAnimation() {
        if (document.getElementById('postgres-spin-styles')) return;

        const style = document.createElement('style');
        style.id = 'postgres-spin-styles';
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .spin {
                animation: spin 1s linear infinite;
                display: inline-block;
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Get troubleshooting section HTML
     */
    getTroubleshootingSection() {
        return '<div style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-radius: 4px;">' +
               '<h4>🛠️ What to do:</h4>' +
               '<ol>' +
               '<li>Check the error details above for specific issues</li>' +
               '<li>Verify your PostgreSQL connection string is correct</li>' +
               '<li>Try the manual installation method below</li>' +
               '<li>Contact support if issues persist</li>' +
               '</ol>' +
               '</div>';
    }

    /**
     * Utility functions
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getAdminUrl(path) {
        return (window.ajaxurl || '/wp-admin/admin-ajax.php').replace('admin-ajax.php', path);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new PostgreSQLInstaller();
});
