/**
 * Analytics Dashboard JavaScript
 * File: assets/js/analytics-dashboard.js
 */

class AnalyticsDashboard {
    constructor() {
        this.init();
    }

    init() {
        this.initCharts();
        this.initFilters();
        this.initExportButtons();
        this.initInsightActions();
        this.initSearchPreview();
    }

    /**
     * Initialize analytics charts (placeholder for future chart library integration)
     */
    initCharts() {
        // Placeholder for Chart.js or similar chart library integration
        const chartContainers = document.querySelectorAll('.analytics-chart');

        chartContainers.forEach(container => {
            // Future: Initialize charts here
            // Example: new Chart(container, chartConfig);
        });
    }

    /**
     * Initialize dashboard filters
     */
    initFilters() {
        const timeFilters = document.querySelectorAll('.time-filter');
        const searchTypeFilters = document.querySelectorAll('.search-type-filter');

        timeFilters.forEach(filter => {
            filter.addEventListener('change', (e) => {
                this.applyTimeFilter(e.target.value);
            });
        });

        searchTypeFilters.forEach(filter => {
            filter.addEventListener('change', (e) => {
                this.applySearchTypeFilter(e.target.value);
            });
        });
    }

    /**
     * Apply time filter to dashboard
     */
    applyTimeFilter(timeframe) {
        const params = new URLSearchParams(window.location.search);
        params.set('timeframe', timeframe);

        window.location.search = params.toString();
    }

    /**
     * Apply search type filter
     */
    applySearchTypeFilter(searchType) {
        const params = new URLSearchParams(window.location.search);
        if (searchType === 'all') {
            params.delete('search_type');
        } else {
            params.set('search_type', searchType);
        }

        window.location.search = params.toString();
    }

    /**
     * Initialize export functionality
     */
    initExportButtons() {
        const exportCsvBtn = document.querySelector('.export-csv-btn');
        const exportPdfBtn = document.querySelector('.export-pdf-btn');

        if (exportCsvBtn) {
            exportCsvBtn.addEventListener('click', () => this.exportData('csv'));
        }

        if (exportPdfBtn) {
            exportPdfBtn.addEventListener('click', () => this.exportData('pdf'));
        }
    }

    /**
     * Export analytics data
     */
    exportData(format) {
        const params = new URLSearchParams(window.location.search);
        params.set('export', format);

        // Create temporary link to trigger download
        const link = document.createElement('a');
        link.href = window.location.pathname + '?' + params.toString();
        link.download = `search-analytics-${new Date().toISOString().split('T')[0]}.${format}`;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    /**
     * Initialize insight action buttons
     */
    initInsightActions() {
        // Add product buttons for zero-result searches
        const addProductBtns = document.querySelectorAll('.add-product-btn');
        addProductBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const searchTerm = e.target.dataset.term;
                if (searchTerm) {
                    this.openProductCreationPage(searchTerm);
                }
            });
        });

        // Search existing product buttons
        const searchExistingBtns = document.querySelectorAll('.search-existing-btn');
        searchExistingBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const searchTerm = e.target.dataset.term;
                if (searchTerm) {
                    this.searchExistingProducts(searchTerm);
                }
            });
        });

        // Insight dismiss buttons
        const dismissBtns = document.querySelectorAll('.insight-dismiss-btn');
        dismissBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.dismissInsight(e.target.closest('.aivs-insight'));
            });
        });
    }

    /**
     * Open product creation page with pre-filled search term
     */
    openProductCreationPage(searchTerm) {
        const url = new URL(this.getAdminUrl('post-new.php'));
        url.searchParams.set('post_type', 'product');
        url.searchParams.set('suggested_title', searchTerm);

        window.open(url.toString(), '_blank');
    }

    /**
     * Search existing products
     */
    searchExistingProducts(searchTerm) {
        const url = new URL(this.getAdminUrl('edit.php'));
        url.searchParams.set('post_type', 'product');
        url.searchParams.set('s', searchTerm);

        window.open(url.toString(), '_blank');
    }

    /**
     * Dismiss an insight
     */
    dismissInsight(insightElement) {
        if (!insightElement) return;

        insightElement.style.opacity = '0.5';

        // Animate out
        setTimeout(() => {
            insightElement.style.height = '0';
            insightElement.style.overflow = 'hidden';
            insightElement.style.margin = '0';
            insightElement.style.padding = '0';

            setTimeout(() => {
                insightElement.remove();
            }, 300);
        }, 150);
    }

    /**
     * Initialize search preview functionality
     */
    initSearchPreview() {
        const previewBtns = document.querySelectorAll('.preview-search-btn');

        previewBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const searchTerm = e.target.dataset.term;
                if (searchTerm) {
                    this.showSearchPreview(searchTerm, e.target);
                }
            });
        });
    }

    /**
     * Show search preview modal/dropdown
     */
    showSearchPreview(searchTerm, triggerElement) {
        // Remove existing previews
        const existingPreviews = document.querySelectorAll('.search-preview-modal');
        existingPreviews.forEach(preview => preview.remove());

        // Create preview container
        const previewModal = document.createElement('div');
        previewModal.className = 'search-preview-modal';
        previewModal.innerHTML = `
            <div class="search-preview-content">
                <div class="search-preview-header">
                    <h4>Search Preview: "${this.escapeHtml(searchTerm)}"</h4>
                    <button class="search-preview-close">&times;</button>
                </div>
                <div class="search-preview-body">
                    <div class="search-preview-loading">
                        <span class="dashicons dashicons-update spin"></span>
                        Loading search results...
                    </div>
                </div>
            </div>
        `;

        // Add to page
        document.body.appendChild(previewModal);

        // Position near trigger element
        this.positionPreviewModal(previewModal, triggerElement);

        // Add close functionality
        const closeBtn = previewModal.querySelector('.search-preview-close');
        closeBtn.addEventListener('click', () => {
            previewModal.remove();
        });

        // Close on outside click
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) {
                previewModal.remove();
            }
        });

        // Load search results
        this.loadSearchPreview(searchTerm, previewModal);
    }

    /**
     * Position preview modal near trigger element
     */
    positionPreviewModal(modal, triggerElement) {
        const rect = triggerElement.getBoundingClientRect();
        const modalContent = modal.querySelector('.search-preview-content');

        modalContent.style.position = 'fixed';
        modalContent.style.top = Math.min(rect.bottom + 10, window.innerHeight - 400) + 'px';
        modalContent.style.left = Math.max(10, rect.left) + 'px';
        modalContent.style.maxWidth = '500px';
        modalContent.style.maxHeight = '400px';
        modalContent.style.zIndex = '10000';
    }

    /**
     * Load and display search preview results
     */
    loadSearchPreview(searchTerm, modal) {
        const body = modal.querySelector('.search-preview-body');

        // Make AJAX request to get search results
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivs_preview_search',
                term: searchTerm,
                nonce: window.aivs_preview_nonce || ''
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                this.renderSearchResults(data.data, body);
            } else {
                body.innerHTML = '<p>No results found for this search term.</p>';
            }
        })
        .catch(error => {
            body.innerHTML = '<p class="error">Error loading search results: ' + this.escapeHtml(error.message) + '</p>';
        });
    }

    /**
     * Render search results in preview
     */
    renderSearchResults(results, container) {
        if (!results || results.length === 0) {
            container.innerHTML = '<p>No products found for this search.</p>';
            return;
        }

        let html = '<div class="search-results-grid">';

        results.forEach(result => {
            html += `
                <div class="search-result-item">
                    ${result.image ? `<img src="${result.image}" alt="${this.escapeHtml(result.name)}" class="result-image">` : ''}
                    <div class="result-details">
                        <h5><a href="${result.url}" target="_blank">${this.escapeHtml(result.name)}</a></h5>
                        <div class="result-price">${result.price}</div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        html += `<div class="search-results-footer">
                    <p>Found ${results.length} results</p>
                    <a href="${this.getStoreSearchUrl(results[0]?.search_term || '')}" target="_blank" class="button">
                        View on Store
                    </a>
                 </div>`;

        container.innerHTML = html;
    }

    /**
     * Initialize real-time dashboard updates
     */
    initRealTimeUpdates() {
        // Poll for new data every 30 seconds
        setInterval(() => {
            this.updateDashboardStats();
        }, 30000);
    }

    /**
     * Update dashboard statistics
     */
    updateDashboardStats() {
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'aivs_get_live_stats',
                nonce: window.aivs_stats_nonce || ''
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.updateStatCards(data.data);
            }
        })
        .catch(error => {
            console.log('Failed to update live stats:', error);
        });
    }

    /**
     * Update stat cards with new data
     */
    updateStatCards(stats) {
        const statCards = {
            'total-searches': stats.total_searches,
            'success-rate': stats.success_rate + '%',
            'click-through-rate': stats.click_through_rate + '%',
            'unique-terms': stats.unique_terms
        };

        Object.entries(statCards).forEach(([id, value]) => {
            const element = document.querySelector(`[data-stat="${id}"] .aivs-stat-number`);
            if (element && element.textContent !== value) {
                element.style.transform = 'scale(1.1)';
                element.textContent = value;

                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 200);
            }
        });
    }

    /**
     * Initialize keyboard shortcuts
     */
    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + E for export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                this.exportData('csv');
            }

            // Ctrl/Cmd + F for search filter focus
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchFilter = document.querySelector('.analytics-search-filter');
                if (searchFilter) {
                    searchFilter.focus();
                }
            }

            // Escape to close previews
            if (e.key === 'Escape') {
                const previews = document.querySelectorAll('.search-preview-modal');
                previews.forEach(preview => preview.remove());
            }
        });
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

    getStoreSearchUrl(searchTerm) {
        const homeUrl = window.location.origin;
        return `${homeUrl}/?s=${encodeURIComponent(searchTerm)}&post_type=product`;
    }

    formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        } else if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num.toString();
    }

    formatPercentage(num, decimals = 1) {
        return Number(num).toFixed(decimals) + '%';
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize on analytics pages
    if (document.querySelector('.aivs-analytics-dashboard')) {
        new AnalyticsDashboard();
    }
});
