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
        this.initFormSpinners();
        this.initAnalyticsNotices();
    }

    /**
     * Connection Mode Toggle Functionality
     */
    initConnectionModeToggle() {
        const radios = document.querySelectorAll('input[name="aivesese_connection_mode"]');

        const toggleFields = () => {
            const mode = document.querySelector('input[name="aivesese_connection_mode"]:checked');
            if (!mode) return;

            const selectedMode = mode.value;

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
                    nonce: window.aivesese_nonce || ''
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
                const licenseInput = document.getElementById('aivesese_license_key');
                if (licenseInput) {
                    licenseInput.value = '';
                }
                document.querySelector('form').submit();
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

        helpDetails.addEventListener('toggle', () => {
            if (!window.AISupabaseHelp) return;

            const formData = new FormData();
            formData.append('action', 'aivesese_toggle_help');
            formData.append('open', helpDetails.open ? '1' : '0');
            formData.append('nonce', window.AISupabaseHelp.nonce);

            fetch(window.AISupabaseHelp.ajax_url, {
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
                nonce: window.aivs_analytics_nonce || ''
            })
        }).then(() => {
            if (notice) {
                notice.style.display = 'none';
            }
        });
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
