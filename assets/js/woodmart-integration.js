/**
 * Woodmart Theme Integration JavaScript
 * File: assets/js/woodmart-integration.js
 */

class WoodmartIntegration {
    constructor() {
        this.searchCache = new Map();
        this.currentRequest = null;
        this.debounceTimer = null;
        this.init();
    }

    init() {
        this.interceptWoodmartSearch();
        this.initSearchTracking();
        this.initResultClickTracking();
    }

    /**
     * Intercept Woodmart's AJAX search functionality
     */
    interceptWoodmartSearch() {
        // Wait for Woodmart to load
        if (typeof woodmartThemeModule !== 'undefined') {
            this.overrideWoodmartSearch();
        } else {
            // Fallback: wait for DOM and try again
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => this.overrideWoodmartSearch(), 1000);
            });
        }

        // Also handle direct AJAX search forms
        this.initDirectSearchHandling();
    }

    /**
     * Override Woodmart's search handlers
     */
    overrideWoodmartSearch() {
        // Remove existing Woodmart search handlers
        jQuery(document).off('input.woodmartAjaxSearch');

        // Add our enhanced search handler
        jQuery(document).on('input.aivesAjaxSearch', '.woodmart-ajax-search input[type="text"]', (e) => {
            this.handleSearchInput(e);
        });

        // Handle search form submissions
        jQuery(document).on('submit.aivesAjaxSearch', '.woodmart-ajax-search form', (e) => {
            e.preventDefault();
            this.handleSearchSubmit(e);
        });
    }

    /**
     * Handle search input with debouncing
     */
    handleSearchInput(event) {
        const input = event.target;
        const query = input.value.trim();
        const minLength = 2;

        // Clear previous debounce timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        // Hide results if query too short
        if (query.length < minLength) {
            this.hideSearchResults(input);
            return;
        }

        // Debounce the search
        this.debounceTimer = setTimeout(() => {
            this.performAjaxSearch(query, input);
        }, 300);
    }

    /**
     * Handle search form submission
     */
    handleSearchSubmit(event) {
        const form = event.target;
        const input = form.querySelector('input[type="text"]');
        const query = input ? input.value.trim() : '';

        if (query.length >= 2) {
            // Track the search submission
            this.trackSearchSubmission(query);

            // Redirect to search results page
            const searchUrl = new URL(window.location.origin);
            searchUrl.searchParams.set('s', query);
            searchUrl.searchParams.set('post_type', 'product');

            window.location.href = searchUrl.toString();
        }
    }

    /**
     * Perform AJAX search with AI results
     */
    performAjaxSearch(query, inputElement) {
        // Cancel previous request
        if (this.currentRequest) {
            this.currentRequest.abort();
        }

        // Check cache first
        if (this.searchCache.has(query)) {
            const cachedResults = this.searchCache.get(query);
            this.displaySearchResults(cachedResults, inputElement, query);
            return;
        }

        // Show loading state
        this.showLoadingState(inputElement);

        // Make AJAX request
        const requestData = {
            action: 'aivs_woodmart_search',
            query: query,
            limit: 10,
            nonce: window.aivs_search_nonce || ''
        };

        this.currentRequest = jQuery.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: requestData,
            timeout: 10000,
            success: (response) => {
                this.currentRequest = null;

                if (response.success && response.data) {
                    // Cache results
                    this.searchCache.set(query, response.data);

                    // Display results
                    this.displaySearchResults(response.data, inputElement, query);

                    // Track search
                    this.trackSearchPerformed(query, response.data.length);
                } else {
                    this.showNoResults(inputElement, query);
                }
            },
            error: (xhr, status, error) => {
                this.currentRequest = null;

                if (status !== 'abort') {
                    console.warn('AI search failed, falling back to default:', error);
                    this.fallbackToDefaultSearch(query, inputElement);
                }
            }
        });
    }

    /**
     * Display search results in Woodmart format
     */
    displaySearchResults(results, inputElement, query) {
        const searchContainer = inputElement.closest('.woodmart-ajax-search');
        let resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        // Create results container if it doesn't exist
        if (!resultsContainer) {
            resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results-wrapper woodmart-search-results';
            searchContainer.appendChild(resultsContainer);
        }

        // Clear previous results
        resultsContainer.innerHTML = '';

        if (!results || results.length === 0) {
            this.showNoResults(inputElement, query);
            return;
        }

        // Build results HTML
        let html = '<div class="search-results-list">';

        results.forEach((result, index) => {
            html += this.buildResultItemHTML(result, query, index);
        });

        html += '</div>';

        // Add footer with view all link
        html += this.buildResultsFooterHTML(query, results.length);

        resultsContainer.innerHTML = html;
        resultsContainer.style.display = 'block';

        // Initialize result interactions
        this.initResultInteractions(resultsContainer, query);
    }

    /**
     * Build HTML for a single search result item
     */
    buildResultItemHTML(result, query, index) {
        const imageHtml = result.image
            ? `<div class="result-image"><img src="${result.image}" alt="${this.escapeHtml(result.name)}" loading="lazy"></div>`
            : '<div class="result-image-placeholder"></div>';

        const priceHtml = result.price
            ? `<div class="result-price">${result.price}</div>`
            : '';

        const skuHtml = result.sku
            ? `<div class="result-sku">SKU: ${this.escapeHtml(result.sku)}</div>`
            : '';

        return `
            <div class="search-result-item" data-product-id="${result.id}" data-result-index="${index}">
                <a href="${this.addTrackingToUrl(result.url, query, result.id)}" class="result-link">
                    ${imageHtml}
                    <div class="result-content">
                        <h4 class="result-title">${this.highlightSearchTerm(result.name, query)}</h4>
                        ${priceHtml}
                        ${skuHtml}
                    </div>
                </a>
            </div>
        `;
    }

    /**
     * Build results footer HTML
     */
    buildResultsFooterHTML(query, resultCount) {
        const viewAllUrl = new URL(window.location.origin);
        viewAllUrl.searchParams.set('s', query);
        viewAllUrl.searchParams.set('post_type', 'product');
        viewAllUrl.searchParams.set('from_search', '1');

        return `
            <div class="search-results-footer">
                <div class="results-count">${resultCount} products found</div>
                <a href="${viewAllUrl.toString()}" class="view-all-results">
                    View all results →
                </a>
            </div>
        `;
    }

    /**
     * Show loading state
     */
    showLoadingState(inputElement) {
        const searchContainer = inputElement.closest('.woodmart-ajax-search');
        let resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        if (!resultsContainer) {
            resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results-wrapper woodmart-search-results';
            searchContainer.appendChild(resultsContainer);
        }

        resultsContainer.innerHTML = `
            <div class="search-loading">
                <div class="loading-spinner"></div>
                <span>Searching...</span>
            </div>
        `;
        resultsContainer.style.display = 'block';
    }

    /**
     * Show no results message
     */
    showNoResults(inputElement, query) {
        const searchContainer = inputElement.closest('.woodmart-ajax-search');
        let resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        if (!resultsContainer) {
            resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results-wrapper woodmart-search-results';
            searchContainer.appendChild(resultsContainer);
        }

        resultsContainer.innerHTML = `
            <div class="search-no-results">
                <p>No products found for "${this.escapeHtml(query)}"</p>
                <div class="no-results-suggestions">
                    <p>Try:</p>
                    <ul>
                        <li>Checking your spelling</li>
                        <li>Using different keywords</li>
                        <li>Searching for more general terms</li>
                    </ul>
                </div>
            </div>
        `;
        resultsContainer.style.display = 'block';

        // Track zero results
        this.trackSearchPerformed(query, 0);
    }

    /**
     * Hide search results
     */
    hideSearchResults(inputElement) {
        const searchContainer = inputElement.closest('.woodmart-ajax-search');
        const resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        if (resultsContainer) {
            resultsContainer.style.display = 'none';
        }
    }

    /**
     * Fallback to default Woodmart search
     */
    fallbackToDefaultSearch(query, inputElement) {
        // Re-enable original Woodmart search temporarily
        if (typeof woodmartThemeModule !== 'undefined' && woodmartThemeModule.ajaxSearch) {
            woodmartThemeModule.ajaxSearch.call(woodmartThemeModule, inputElement);
        } else {
            this.showNoResults(inputElement, query);
        }
    }

    /**
     * Initialize result click interactions
     */
    initResultInteractions(container, query) {
        const resultItems = container.querySelectorAll('.search-result-item');

        resultItems.forEach(item => {
            item.addEventListener('click', (e) => {
                const productId = item.dataset.productId;
                const resultIndex = item.dataset.resultIndex;

                // Track click
                this.trackResultClick(query, productId, resultIndex);
            });
        });

        // Hide results when clicking outside
        document.addEventListener('click', (e) => {
            if (!container.contains(e.target) && !e.target.closest('.woodmart-ajax-search')) {
                container.style.display = 'none';
            }
        });
    }

    /**
     * Initialize direct search form handling (fallback)
     */
    initDirectSearchHandling() {
        // Handle any search forms that aren't caught by Woodmart
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.search-form, .ajax-search-form')) {
                const input = e.target.querySelector('input[type="text"], input[type="search"]');
                if (input && input.value.trim()) {
                    this.trackSearchSubmission(input.value.trim());
                }
            }
        });
    }

    /**
     * Initialize search result click tracking
     */
    initResultClickTracking() {
        // Track clicks on search result links
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href*="from_search=1"]');
            if (link) {
                const url = new URL(link.href);
                const searchTerm = url.searchParams.get('search_term');
                const productId = this.extractProductIdFromUrl(link.href);

                if (searchTerm && productId) {
                    this.trackResultClick(searchTerm, productId);
                }
            }
        });
    }

    /**
     * Add tracking parameters to product URLs
     */
    addTrackingToUrl(url, searchTerm, productId) {
        const trackingUrl = new URL(url);
        trackingUrl.searchParams.set('from_search', '1');
        trackingUrl.searchParams.set('search_term', searchTerm);
        return trackingUrl.toString();
    }

    /**
     * Highlight search term in text
     */
    highlightSearchTerm(text, searchTerm) {
        if (!searchTerm || searchTerm.length < 2) {
            return this.escapeHtml(text);
        }

        const escapedText = this.escapeHtml(text);
        const escapedTerm = this.escapeHtml(searchTerm);
        const regex = new RegExp(`(${this.escapeRegex(escapedTerm)})`, 'gi');

        return escapedText.replace(regex, '<mark>$1</mark>');
    }

    /**
     * Tracking methods
     */
    trackSearchPerformed(query, resultCount) {
        // Send tracking data to analytics
        if (window.gtag) {
            gtag('event', 'search', {
                'search_term': query,
                'search_results': resultCount
            });
        }

        // Send to our analytics system
        this.sendAnalyticsEvent('search_performed', {
            query: query,
            results: resultCount,
            search_type: 'ajax'
        });
    }

    trackSearchSubmission(query) {
        this.sendAnalyticsEvent('search_submitted', {
            query: query,
            search_type: 'form_submit'
        });
    }

    trackResultClick(query, productId, resultIndex = null) {
        this.sendAnalyticsEvent('search_result_click', {
            query: query,
            product_id: productId,
            result_index: resultIndex
        });
    }

    /**
     * Send analytics event to server
     */
    sendAnalyticsEvent(eventType, data) {
        // Debounce analytics requests
        if (this.analyticsTimeout) {
            clearTimeout(this.analyticsTimeout);
        }

        this.analyticsTimeout = setTimeout(() => {
            fetch(window.ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'aivs_track_event',
                    event_type: eventType,
                    event_data: JSON.stringify(data),
                    nonce: window.aivs_tracking_nonce || ''
                })
            }).catch(error => {
                console.log('Analytics tracking failed:', error);
            });
        }, 500);
    }

    /**
     * Utility methods
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    extractProductIdFromUrl(url) {
        const matches = url.match(/[\?&]product_id=(\d+)/);
        if (matches) return matches[1];

        // Try to extract from permalink structure
        const pathMatches = url.match(/\/product\/[^\/]+\/(\d+)/);
        if (pathMatches) return pathMatches[1];

        return null;
    }

    /**
     * Clear search cache periodically
     */
    initCacheCleanup() {
        setInterval(() => {
            if (this.searchCache.size > 50) {
                // Keep only the last 25 searches
                const entries = Array.from(this.searchCache.entries());
                this.searchCache.clear();
                entries.slice(-25).forEach(([key, value]) => {
                    this.searchCache.set(key, value);
                });
            }
        }, 300000); // 5 minutes
    }
}

// Initialize Woodmart integration when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize if Woodmart integration is enabled
    if (window.aivs_woodmart_enabled === '1') {
        new WoodmartIntegration();
    }
});
