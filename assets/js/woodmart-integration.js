/**
 * Woodmart Theme Integration JavaScript
 * File: assets/js/woodmart-integration.js
 *
 * Extends AIVectorSearchAutocomplete to add:
 * - Woodmart handler override (removes the theme's own AJAX search)
 * - Full AJAX search path (aivs_woodmart_search) used when autocomplete is disabled
 */

class WoodmartIntegration extends AIVectorSearchAutocomplete {
    constructor() {
        const config = window.aivesese_woodmart || {};
        super({
            ajaxUrl:             config.ajax_url
                                    || (window.aivs_search_data && window.aivs_search_data.ajax_url)
                                    || window.ajaxurl
                                    || '',
            searchNonce:         config.search_nonce
                                    || (window.aivs_search_data && window.aivs_search_data.nonce)
                                    || '',
            trackingNonce:       config.tracking_nonce
                                    || (window.aivesese_analytics && window.aivesese_analytics.tracking_nonce)
                                    || '',
            autocompleteEnabled: config.autocomplete_enabled === '1',
            searchContainerSelector: '.woodmart-ajax-search, .widget_product_search, .woocommerce-product-search',
            searchInputSelector: [
                '.woodmart-ajax-search input[type="text"]',
                '.widget_product_search input[type="search"]',
                '.widget_product_search input[type="text"]',
                'form.woocommerce-product-search input[type="search"]',
                'form.woocommerce-product-search input[type="text"]',
            ].join(', '),
        });
    }

    init() {
        // Remove Woodmart's own AJAX search handler before binding ours
        jQuery(document).off('input.woodmartAjaxSearch');
        super.init();
    }

    /**
     * Override to support both the autocomplete path and the full AJAX search path
     */
    handleSearchInput(event) {
        const input = event.target;
        const query = input.value.trim();

        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        if (query.length < 2) {
            this.hideSearchResults(input);
            return;
        }

        this.debounceTimer = setTimeout(() => {
            if (this.autocompleteEnabled) {
                this.performAutocomplete(query, input);
            } else {
                this.performAjaxSearch(query, input);
            }
        }, 300);
    }

    /**
     * Full AJAX product search via the Woodmart endpoint (used when autocomplete is off)
     */
    performAjaxSearch(query, inputElement) {
        if (this.currentRequest) {
            this.currentRequest.abort();
        }

        if (this.searchCache.has(query)) {
            this.displaySearchResults(this.searchCache.get(query), inputElement, query);
            return;
        }

        this.showLoadingState(inputElement);

        if (!this.ajaxUrl) {
            this.showNoResults(inputElement, query);
            return;
        }

        this.currentRequest = jQuery.ajax({
            url:  this.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aivs_woodmart_search',
                query:  query,
                limit:  10,
                nonce:  this.searchNonce
            },
            timeout: 10000,
            success: (response) => {
                this.currentRequest = null;

                if (response.success && response.data) {
                    this.searchCache.set(query, response.data);
                    this.displaySearchResults(response.data, inputElement, query);
                    this.trackSearchPerformed(query, response.data.length);
                } else {
                    this.showNoResults(inputElement, query);
                }
            },
            error: (xhr, status, error) => {
                this.currentRequest = null;

                if (status !== 'abort') {
                    console.warn('AI search failed:', error);
                    this.showNoResults(inputElement, query);
                }
            }
        });
    }

    /**
     * Display a flat product list returned by aivs_woodmart_search
     */
    displaySearchResults(results, inputElement, query) {
        if (!results || results.length === 0) {
            this.showNoResults(inputElement, query);
            return;
        }

        const list = document.createElement('div');
        list.className = 'search-results-list';
        results.forEach((result, index) => list.appendChild(this.buildResultItemNode(result, query, index)));

        const footer = this.cloneTemplate('aivs-tpl-results-footer')
            || this.buildFooterFallbackNode(this.buildViewAllUrl(query), results.length + ' products found');

        const countEl = footer.querySelector && footer.querySelector('.results-count');
        const linkEl  = footer.querySelector && footer.querySelector('.view-all-results');
        if (countEl) countEl.textContent = results.length + ' products found';
        if (linkEl)  linkEl.href         = this.buildViewAllUrl(query);

        const fragment = document.createDocumentFragment();
        fragment.appendChild(list);
        fragment.appendChild(footer);

        const resultsContainer = this.getResultsContainer(inputElement);
        resultsContainer.replaceChildren(fragment);
        resultsContainer.style.display = 'block';
        this.initResultInteractions(resultsContainer, query);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const config = window.aivesese_woodmart;
    if (!config || config.enabled !== '1') {
        return;
    }

    new WoodmartIntegration().init();
});
