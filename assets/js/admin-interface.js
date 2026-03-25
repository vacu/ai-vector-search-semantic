/**
 * Admin Interface JavaScript
 * File: assets/js/admin-interface.js
 */

class AIVectorSearchAdmin {
    constructor() {
        this.init();
    }

    init() {
        this.initConnectionModeToggle();
        this.initLicenseActivation();
        this.initHelpToggle();
        this.initSyncAllBatches();
        this.initFieldSync();
        this.initFormSpinners();
        this.initAnalyticsNotices();
        this.initSoldCountUpdate();
    }

    initSyncAllBatches() {
        const btn = document.querySelector('[data-aivesese-sync-all-btn="1"]');
        const statusDiv = document.getElementById('sync-status');

        if (!btn || !statusDiv) {
            return;
        }

        btn.addEventListener('click', () => {
            if (!window.confirm('This will sync all products in batches. Continue?')) {
                return;
            }

            const form = btn.closest('form');
            const batchSizeInput = form?.querySelector('input[name="batch_size"]');
            const batchSize = Math.min(Math.max(parseInt(batchSizeInput?.value || '50', 10) || 50, 1), 200);

            this.runSyncAllBatches({
                batchSize,
                offset: 0,
                submitButton: btn,
                statusDiv
            });
        });
    }

    runSyncAllBatches({batchSize, offset, submitButton, statusDiv, syncedTotal = 0}) {
        const ajaxUrl = window.aivesese_admin?.ajax_url || window.ajaxurl;
        const nonce = window.aivesese_admin?.nonce || '';

        if (!ajaxUrl) {
            this.renderSyncStatus(statusDiv, 'Missing AJAX endpoint.', 'error');
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Syncing...';
        }

        if (offset === 0) {
            this.renderSyncProgress(statusDiv, {processed: 0, total: 0, synced: 0, done: false, starting: true});
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivesese_sync_products_batch',
                batch_size: batchSize,
                offset: offset,
                nonce: nonce
            })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                const message = data.data?.message || 'Batch sync failed.';
                this.renderSyncStatus(statusDiv, message, 'error');
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Sync All Products';
                }
                return;
            }

            const payload = data.data || {};
            const nextSyncedTotal = syncedTotal + (payload.synced || 0);
            const processed = payload.processed || 0;
            const total = payload.total_products || 0;

            this.renderSyncProgress(statusDiv, {
                processed,
                total,
                synced: nextSyncedTotal,
                done: payload.done
            });

            if (payload.done) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Sync All Products';
                }
                return;
            }

            this.runSyncAllBatches({
                batchSize,
                offset: payload.next_offset || (offset + batchSize),
                submitButton,
                statusDiv,
                syncedTotal: nextSyncedTotal
            });
        })
        .catch(() => {
            this.renderSyncStatus(statusDiv, 'Batch sync failed.', 'error');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Sync All Products';
            }
        });
    }

    initFieldSync() {
        const btn = document.querySelector('[data-aivesese-field-sync-btn="1"]');
        const statusDiv = document.getElementById('sync-status');

        if (!btn || !statusDiv) {
            return;
        }

        btn.addEventListener('click', () => {
            const form = btn.closest('form');
            const field = form?.querySelector('select[name="field"]')?.value;
            const batchSizeInput = form?.querySelector('input[name="batch_size"]');
            const batchSize = Math.min(Math.max(parseInt(batchSizeInput?.value || '50', 10) || 50, 1), 200);

            if (!field) {
                return;
            }

            const fieldLabel = form.querySelector(`select[name="field"] option[value="${field}"]`)?.textContent || field;
            if (!window.confirm(`This will update "${fieldLabel}" for all products. Continue?`)) {
                return;
            }

            this.runFieldSyncBatches({ field, batchSize, offset: 0, submitButton: btn, statusDiv });
        });
    }

    runFieldSyncBatches({ field, batchSize, offset, submitButton, statusDiv, syncedTotal = 0 }) {
        const ajaxUrl = window.aivesese_admin?.ajax_url || window.ajaxurl;
        const nonce = window.aivesese_admin?.nonce || '';

        if (!ajaxUrl) {
            this.renderSyncStatus(statusDiv, 'Missing AJAX endpoint.', 'error');
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Syncing\u2026';
        }

        if (offset === 0) {
            this.renderSyncProgress(statusDiv, { processed: 0, total: 0, synced: 0, done: false, starting: true });
        }

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'aivesese_sync_field_batch',
                field,
                batch_size: batchSize,
                offset,
                nonce
            })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                const message = data.data?.message || 'Field sync failed.';
                this.renderSyncStatus(statusDiv, message, 'error');
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Sync Field for All Products';
                }
                return;
            }

            const payload = data.data || {};
            const nextSyncedTotal = syncedTotal + (payload.synced || 0);
            const processed = payload.processed || 0;
            const total = payload.total_products || 0;

            this.renderSyncProgress(statusDiv, { processed, total, synced: nextSyncedTotal, done: payload.done });

            if (payload.done) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Sync Field for All Products';
                }
                return;
            }

            this.runFieldSyncBatches({
                field,
                batchSize,
                offset: payload.next_offset || (offset + batchSize),
                submitButton,
                statusDiv,
                syncedTotal: nextSyncedTotal
            });
        })
        .catch(() => {
            this.renderSyncStatus(statusDiv, 'Field sync failed.', 'error');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Sync Field for All Products';
            }
        });
    }

    renderSyncStatus(element, message, type) {
        if (!element) {
            return;
        }

        const notice = document.createElement('div');
        notice.className = `notice notice-${type === 'error' ? 'error' : type === 'success' ? 'success' : 'info'}`;

        const paragraph = document.createElement('p');
        paragraph.textContent = message;
        notice.appendChild(paragraph);

        element.innerHTML = '';
        element.appendChild(notice);
    }

    renderSyncProgress(element, {processed, total, synced, done, starting = false}) {
        if (!element) {
            return;
        }

        const percent = total > 0 ? Math.min(Math.round((processed / total) * 100), 100) : (done ? 100 : 0);
        const label = starting
            ? 'Starting sync\u2026'
            : done
                ? `Sync complete \u2014 ${synced.toLocaleString()} of ${total.toLocaleString()} products synced.`
                : `Syncing\u2026 ${processed.toLocaleString()} / ${total.toLocaleString()} products (${percent}%)`;

        element.innerHTML = `
            <div class="aivesese-sync-progress">
                <div class="aivesese-progress-bar-wrap">
                    <div class="aivesese-progress-bar${done ? ' is-done' : ''}" style="width:${starting ? 0 : percent}%"></div>
                </div>
                <p class="aivesese-progress-label${done ? ' is-done' : ''}">${label}</p>
            </div>`;
    }

    /**
     * Connection Mode Toggle Functionality
     */
    initConnectionModeToggle() {
        const radios = document.querySelectorAll('input[name="aivesese_connection_mode"]');
        const optionLabels = document.querySelectorAll('.connection-mode-selector label.connection-option');

        if (!radios.length) {
            return;
        }

        const toggleFields = () => {
            const mode = document.querySelector('input[name="aivesese_connection_mode"]:checked');
            if (!mode) return;

            const selectedMode = mode.value;

            // Sync active styling on the cards
            optionLabels.forEach(label => label.classList.remove('active'));
            const activeLabel = mode.closest('label.connection-option');
            if (activeLabel) {
                activeLabel.classList.add('active');
            }

            // Toggle license key field
            const licenseRow = document.querySelector('#aivesese_license_key');
            if (licenseRow) {
                const row = licenseRow.closest('tr');
                if (row) {
                    row.style.display = selectedMode === 'api' ? 'table-row' : 'none';
                }
            }

            // Toggle self-hosted fields
            const selfHostedFields = ['aivesese_url', 'aivesese_key', 'aivesese_store', 'aivesese_openai'];
            selfHostedFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    const row = field.closest('tr');
                    if (row) {
                        row.style.display = selectedMode === 'self_hosted' ? 'table-row' : 'none';
                    }
                }
            });

            // Hide options that are not available in Lite mode
            const liteRestrictedFields = ['aivesese_semantic_toggle', 'aivesese_auto_sync'];
            liteRestrictedFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field) {
                    return;
                }

                const row = field.closest('tr');
                if (!row) {
                    return;
                }

                const isLite = selectedMode === 'lite';
                row.style.display = isLite ? 'none' : 'table-row';
                field.disabled = isLite;
            });

            const liteModeSection = document.querySelector('.lite-mode-section');
            if (liteModeSection) {
                liteModeSection.style.display = selectedMode === 'lite' ? 'block' : 'none';
            }

            // Show/hide help sections
            const helpSections = document.querySelectorAll('.ai-supabase-help');
            helpSections.forEach(section => {
                section.style.display = selectedMode === 'self_hosted' ? 'block' : 'none';
            });
        };

        toggleFields();

        // Initial toggle with delay
        setTimeout(toggleFields, 100);

        // Toggle on change
        radios.forEach(radio => {
            radio.addEventListener('change', toggleFields);
        });
    }

    /**
     * License Activation Functionality
     */
    initLicenseActivation() {
        // License activation function
        window.activateLicense = () => {
            const keyInput = document.getElementById('aivesese_license_key');
            const button = document.getElementById('activate-license');
            const status = document.getElementById('license-status');

            if (!keyInput || !button || !status) return;

            const key = keyInput.value.trim();

            if (!key) {
                this.showLicenseStatus('error', 'Please enter a license key');
                return;
            }

            button.disabled = true;
            button.textContent = 'Activating...';
            this.showLicenseStatus('loading', '🔄 Activating license...');

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'aivesese_activate_license',
                    license_key: key,
                    nonce: window.aivesese_admin?.license_nonce || ''
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.showLicenseStatus('success', '✅ License activated successfully! Refreshing page...');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    this.showLicenseStatus('error', '❌ ' + (data.data?.message || 'Activation failed'));
                    button.disabled = false;
                    button.textContent = 'Activate License';
                }
            })
            .catch(error => {
                this.showLicenseStatus('error', '❌ Connection error. Please try again.');
                button.disabled = false;
                button.textContent = 'Activate License';
            });
        };

        // License revocation function
        window.revokeLicense = () => {
            if (confirm('Are you sure you want to deactivate your license? This will switch back to self-hosted mode.')) {
                const licenseInput = document.getElementById('aivesese_license_key')
                    || document.querySelector('input[name="aivesese_license_key"]');
                if (licenseInput) {
                    licenseInput.value = '';
                }

                const selfHosted = document.querySelector('input[name="aivesese_connection_mode"][value="self_hosted"]');
                if (selfHosted) {
                    selfHosted.checked = true;
                }

                const form = document.querySelector('.aivesese-admin form') || document.querySelector('form');
                if (form) {
                    form.submit();
                }
            }
        };
    }

    /**
     * Show license status message
     */
    showLicenseStatus(type, message) {
        const status = document.getElementById('license-status');
        if (!status) return;

        const className = `license-${type}`;
        status.innerHTML = `<div class="${className}">${message}</div>`;
    }

    /**
     * Help Toggle Functionality
     */
    initHelpToggle() {
        const helpDetails = document.getElementById('ai-supabase-help-details');
        if (!helpDetails) return;

        const ajaxUrl = (window.AISupabaseHelp && window.AISupabaseHelp.ajax_url) || window.ajaxurl;
        const nonce = (window.AISupabaseHelp && window.AISupabaseHelp.nonce) || window.aivesese_admin?.help_nonce;

        if (!ajaxUrl || !nonce) {
            return;
        }

        helpDetails.addEventListener('toggle', () => {
            const formData = new FormData();
            formData.append('action', 'aivesese_toggle_help');
            formData.append('open', helpDetails.open ? '1' : '0');
            formData.append('nonce', nonce);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });
        }, { passive: true });
    }

    /**
     * Form Submit Spinners
     */
    initFormSpinners() {
        const forms = document.querySelectorAll('form');

        forms.forEach(form => {
            form.addEventListener('submit', () => {
                if (form.matches('[data-aivesese-sync-all-form="1"]')) {
                    return;
                }

                const button = form.querySelector('button[type=submit]');
                if (button) {
                    button.innerHTML = 'Processing...';
                    button.disabled = true;
                }

                const statusDiv = document.getElementById('sync-status');
                if (statusDiv) {
                    statusDiv.innerHTML = '<div class="notice notice-info"><p>⏳ Processing... Please wait.</p></div>';
                }
            });
        });
    }

    /**
     * Analytics Notice Dismissal
     */
    initAnalyticsNotices() {
        // Dismiss analytics notices
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('aivs-dismiss-notice')) {
                e.preventDefault();
                const key = e.target.dataset.key;
                const notice = e.target.closest('.notice');

                if (key && notice) {
                    this.dismissAnalyticsNotice(key, notice);
                }
            }
        });

        // Handle notice dismiss buttons
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('notice-dismiss')) {
                const notice = e.target.closest('.notice[data-dismiss-key]');
                if (notice) {
                    const key = notice.dataset.dismissKey;
                    if (key) {
                        this.dismissAnalyticsNotice(key);
                    }
                }
            }
        });
    }

    /**
     * Dismiss analytics notice via AJAX
     */
    dismissAnalyticsNotice(key, notice = null) {
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivs_dismiss_analytics_notice',
                key: key,
                nonce: window.aivesese_analytics?.analytics_nonce || window.aivesese_admin?.analytics_nonce || ''
            })
        }).then(() => {
            if (notice) {
                notice.style.display = 'none';
            }
        });
    }

    /**
     * Sold count update (self-hosted mode)
     */
    initSoldCountUpdate() {
        const button = document.getElementById('aivesese-sold-count-update');
        const select = document.getElementById('aivesese-sold-count-range');
        const status = document.getElementById('aivesese-sold-count-status');

        if (!button || !select) {
            return;
        }

        button.addEventListener('click', () => {
            const days = parseInt(select.value, 10) || 30;
            const ajaxUrl = window.aivesese_admin?.ajax_url || window.ajaxurl;
            const nonce = window.aivesese_admin?.nonce || '';

            if (!ajaxUrl) {
                this.setSoldCountStatus(status, 'Missing AJAX endpoint.', 'error');
                return;
            }

            button.disabled = true;
            const previousLabel = button.textContent;
            button.textContent = 'Updating...';
            this.setSoldCountStatus(status, 'Updating sold counts...', 'info');

            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'aivesese_update_sold_counts',
                    days: days,
                    nonce: nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const details = data.data || {};
                    this.setSoldCountStatus(
                        status,
                        `Updated ${details.updated || 0} products from ${details.orders || 0} orders.`,
                        'success'
                    );
                } else {
                    const message = data.data?.message || 'Update failed.';
                    this.setSoldCountStatus(status, message, 'error');
                }
            })
            .catch(() => {
                this.setSoldCountStatus(status, 'Update failed.', 'error');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = previousLabel;
            });
        });
    }

    setSoldCountStatus(element, message, type) {
        if (!element) {
            return;
        }

        element.className = `aivesese-sold-count-status aivesese-${type}`;
        element.textContent = message;
    }

    /**
     * Copy text to clipboard utility
     */
    static copyToClipboard(text) {
        return navigator.clipboard.writeText(text).then(() => {
            return true;
        }).catch(() => {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            const success = document.execCommand('copy');
            document.body.removeChild(textArea);
            return success;
        });
    }

    /**
     * SQL Copy Functionality
     */
    initSQLCopy() {
        const copyButton = document.getElementById('ai-copy-sql');
        const sqlTextarea = document.getElementById('ai-sql');
        const statusElement = document.getElementById('ai-copy-status');

        if (!copyButton || !sqlTextarea) return;

        copyButton.addEventListener('click', async () => {
            try {
                await AIVectorSearchAdmin.copyToClipboard(sqlTextarea.value);
                this.showCopyStatus(statusElement, 'SQL copied to clipboard.', 'success');
            } catch (error) {
                this.showCopyStatus(statusElement, 'Failed to copy SQL.', 'error');
            }
        });
    }

    /**
     * Show copy status message
     */
    showCopyStatus(element, message, type) {
        if (!element) return;

        element.textContent = message;
        element.style.display = 'block';
        element.style.color = type === 'success' ? 'green' : 'red';

        setTimeout(() => {
            element.style.display = 'none';
        }, 3000);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new AIVectorSearchAdmin();
});

// Global utility functions for backward compatibility
window.copyToClipboard = (text) => {
    AIVectorSearchAdmin.copyToClipboard(text).then(() => {
        alert('✅ Copied to clipboard!');
    }).catch(() => {
        alert('❌ Failed to copy to clipboard');
    });
};

