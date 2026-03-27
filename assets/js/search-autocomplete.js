/**
 * Generic search autocomplete integration for standard WooCommerce themes.
 * File: assets/js/search-autocomplete.js
 */

class AIVectorSearchAutocomplete {
    constructor(config = {}) {
        this.searchCache = new Map();
        this.currentRequest = null;
        this.debounceTimer = null;
        this.ajaxUrl = config.ajaxUrl || '';
        this.searchNonce = config.searchNonce || '';
        this.trackingNonce = config.trackingNonce || '';
        this.autocompleteEnabled = config.autocompleteEnabled === true;
        this.searchContainerSelector = config.searchContainerSelector
            || '.widget_product_search, .woocommerce-product-search';
        this.searchInputSelector = config.searchInputSelector
            || [
                '.widget_product_search input[type="search"]',
                '.widget_product_search input[type="text"]',
                'form.woocommerce-product-search input[type="search"]',
                'form.woocommerce-product-search input[type="text"]',
            ].join(', ');
    }

    init() {
        this.bindSearchEvents();
        this.initResultClickTracking();
        this.initCacheCleanup();
    }

    bindSearchEvents() {
        jQuery(document).on('input.aivesAutocomplete', this.searchInputSelector, (e) => {
            this.handleSearchInput(e);
        });

        jQuery(document).on('submit.aivesAutocomplete', this.searchContainerSelector + ' form, form.woocommerce-product-search', (e) => {
            e.preventDefault();
            this.handleSearchSubmit(e);
        });
    }

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
            }
        }, 300);
    }

    handleSearchSubmit(event) {
        const form = event.target;
        const input = form.querySelector('input[type="text"], input[type="search"]');
        const query = input ? input.value.trim() : '';

        if (query.length >= 2) {
            this.trackSearchSubmission(query);
            window.location.href = this.buildViewAllUrl(query);
        }
    }

    performAutocomplete(query, inputElement) {
        if (this.currentRequest) {
            this.currentRequest.abort();
        }

        const cacheKey = `autocomplete:${query}`;
        if (this.searchCache.has(cacheKey)) {
            this.displayAutocompleteResults(this.searchCache.get(cacheKey), inputElement, query);
            return;
        }

        this.showLoadingState(inputElement);

        if (!this.ajaxUrl) {
            this.showNoResults(inputElement, query, false);
            return;
        }

        this.currentRequest = jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aivesese_autocomplete',
                query: query,
                limit: 8,
                nonce: this.searchNonce
            },
            timeout: 10000,
            success: (response) => {
                this.currentRequest = null;

                if (response.success && response.data) {
                    this.searchCache.set(cacheKey, response.data);
                    this.displayAutocompleteResults(response.data, inputElement, query);
                    return;
                }

                this.showNoResults(inputElement, query, false);
            },
            error: (xhr, status) => {
                this.currentRequest = null;

                if (status !== 'abort') {
                    this.showNoResults(inputElement, query, false);
                }
            }
        });
    }

    getSearchContainer(inputElement) {
        return inputElement.closest(this.searchContainerSelector) || inputElement.closest('form');
    }

    getResultsContainer(inputElement) {
        const searchContainer = this.getSearchContainer(inputElement);
        let resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        if (!resultsContainer) {
            resultsContainer = document.createElement('div');
            resultsContainer.className = 'search-results-wrapper woodmart-search-results';
            searchContainer.appendChild(resultsContainer);
        }

        return resultsContainer;
    }

    cloneTemplate(id) {
        const tpl = document.getElementById(id);
        return tpl ? tpl.content.cloneNode(true) : null;
    }

    displayAutocompleteResults(payload, inputElement, query) {
        const terms = Array.isArray(payload.terms) ? payload.terms : [];
        const products = Array.isArray(payload.products) ? payload.products : [];
        const categories = Array.isArray(payload.categories) ? payload.categories : [];

        if (terms.length === 0 && products.length === 0 && categories.length === 0) {
            this.showNoResults(inputElement, query, false);
            return;
        }

        const fragment = document.createDocumentFragment();

        if (terms.length > 0) {
            const list = document.createElement('div');
            list.className = 'search-results-list search-autocomplete-terms';
            terms.forEach((item, index) => list.appendChild(this.buildAutocompleteLinkNode(item, 'term', index)));
            fragment.appendChild(list);
        }

        if (categories.length > 0) {
            const list = document.createElement('div');
            list.className = 'search-results-list search-autocomplete-categories';
            categories.forEach((item, index) => list.appendChild(this.buildAutocompleteLinkNode(item, 'category', index)));
            fragment.appendChild(list);
        }

        if (products.length > 0) {
            const list = document.createElement('div');
            list.className = 'search-results-list';
            products.forEach((result, index) => list.appendChild(this.buildResultItemNode(result, query, index)));
            fragment.appendChild(list);
        }

        fragment.appendChild(this.buildAutocompleteFooterNode(payload, query, terms.length + categories.length + products.length));

        const resultsContainer = this.getResultsContainer(inputElement);
        resultsContainer.replaceChildren(fragment);
        resultsContainer.style.display = 'block';
        this.initResultInteractions(resultsContainer, query);
    }

    buildResultItemNode(result, query, index) {
        const node = this.cloneTemplate('aivs-tpl-result-item');
        if (!node) {
            return this.buildResultItemFallbackNode(result, query, index);
        }

        const item = node.querySelector('.search-result-item');
        item.dataset.productId = result.id;
        item.dataset.resultIndex = index;

        node.querySelector('.result-link').href = this.addTrackingToUrl(result.url, query, result.id);

        const imageEl = node.querySelector('.result-image');
        if (result.image) {
            const img = imageEl.querySelector('img');
            img.src = result.image;
            img.alt = result.name || '';
        } else if (imageEl) {
            imageEl.replaceWith(Object.assign(document.createElement('div'), { className: 'result-image-placeholder' }));
        }

        node.querySelector('.result-title').innerHTML = this.highlightSearchTerm(result.name, query);

        const priceEl = node.querySelector('.result-price');
        if (result.price) {
            priceEl.innerHTML = result.price;
        } else if (priceEl) {
            priceEl.remove();
        }

        const skuEl = node.querySelector('.result-sku');
        if (result.sku) {
            skuEl.textContent = 'SKU: ' + result.sku;
        } else if (skuEl) {
            skuEl.remove();
        }

        return node;
    }

    buildAutocompleteLinkNode(item, type, index) {
        const node = this.cloneTemplate('aivs-tpl-autocomplete-link');
        if (!node) {
            return this.buildAutocompleteLinkFallbackNode(item, type, index);
        }

        const wrapper = node.querySelector('.search-result-item');
        wrapper.classList.add('search-result-item-' + type);
        wrapper.dataset.resultIndex = index;

        node.querySelector('.result-link').href = item.url || '#';
        node.querySelector('.result-sku').textContent = type === 'category' ? 'Category' : 'Search';
        node.querySelector('.result-title').textContent = item.text || '';

        return node;
    }

    buildAutocompleteFooterNode(payload, query, suggestionCount) {
        const node = this.cloneTemplate('aivs-tpl-autocomplete-footer');
        if (!node) {
            return this.buildFooterFallbackNode(payload.view_all_url || this.buildViewAllUrl(query), `${suggestionCount} suggestions`);
        }

        const viewAllUrl = payload.view_all_url || this.buildViewAllUrl(query);
        node.querySelector('.results-count').textContent = `${suggestionCount} suggestions`;
        node.querySelector('.view-all-results').href = viewAllUrl;
        return node;
    }

    showLoadingState(inputElement) {
        const node = this.cloneTemplate('aivs-tpl-loading') || this.buildLoadingFallbackNode();
        const resultsContainer = this.getResultsContainer(inputElement);
        resultsContainer.replaceChildren(node);
        resultsContainer.style.display = 'block';
    }

    showNoResults(inputElement, query, track = true) {
        const node = this.cloneTemplate('aivs-tpl-no-results') || this.buildNoResultsFallbackNode(query);
        const message = node.querySelector ? node.querySelector('.no-results-message') : null;
        if (message) {
            message.textContent = `No products found for "${query}"`;
        }

        const resultsContainer = this.getResultsContainer(inputElement);
        resultsContainer.replaceChildren(node);
        resultsContainer.style.display = 'block';

        if (track) {
            this.trackSearchPerformed(query, 0);
        }
    }

    hideSearchResults(inputElement) {
        const searchContainer = this.getSearchContainer(inputElement);
        const resultsContainer = searchContainer.querySelector('.search-results-wrapper');

        if (resultsContainer) {
            resultsContainer.style.display = 'none';
        }
    }

    initResultInteractions(container, query) {
        const resultItems = container.querySelectorAll('.search-result-item');

        resultItems.forEach((item) => {
            item.addEventListener('click', () => {
                const productId = item.dataset.productId;
                const resultIndex = item.dataset.resultIndex;
                this.trackResultClick(query, productId, resultIndex);
            });
        });

        document.addEventListener('click', (e) => {
            if (!container.contains(e.target) && !e.target.closest(this.searchContainerSelector)) {
                container.style.display = 'none';
            }
        });
    }

    initResultClickTracking() {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href*="from_search=1"]');
            if (!link) {
                return;
            }

            const url = new URL(link.href);
            const searchTerm = url.searchParams.get('search_term');
            const productId = this.extractProductIdFromUrl(link.href);

            if (searchTerm && productId) {
                this.trackResultClick(searchTerm, productId);
            }
        });
    }

    addTrackingToUrl(url, searchTerm) {
        const trackingUrl = new URL(url);
        trackingUrl.searchParams.set('from_search', '1');
        trackingUrl.searchParams.set('search_term', searchTerm);
        return trackingUrl.toString();
    }

    buildViewAllUrl(query) {
        const viewAllUrl = new URL(window.location.origin);
        viewAllUrl.searchParams.set('s', query);
        viewAllUrl.searchParams.set('post_type', 'product');
        viewAllUrl.searchParams.set('from_search', '1');
        return viewAllUrl.toString();
    }

    highlightSearchTerm(text, searchTerm) {
        if (!searchTerm || searchTerm.length < 2) {
            return this.escapeHtml(text);
        }

        const escapedText = this.escapeHtml(text);
        const regex = new RegExp(`(${this.escapeRegex(searchTerm)})`, 'gi');
        return escapedText.replace(regex, '<mark>$1</mark>');
    }

    trackSearchPerformed(query, resultCount) {
        if (window.gtag) {
            gtag('event', 'search', {
                search_term: query,
                search_results: resultCount
            });
        }

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
        if (!productId) {
            return;
        }

        this.sendAnalyticsEvent('search_result_click', {
            query: query,
            product_id: productId,
            result_index: resultIndex
        });
    }

    sendAnalyticsEvent(eventType, data) {
        if (!this.ajaxUrl) {
            return;
        }

        fetch(this.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'aivs_track_event',
                event_type: eventType,
                event_data: JSON.stringify(data),
                nonce: this.trackingNonce
            })
        }).catch(() => {});
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    escapeRegex(string) {
        return String(string || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    extractProductIdFromUrl(url) {
        const matches = url.match(/[\?&]product_id=(\d+)/);
        if (matches) {
            return matches[1];
        }

        const pathMatches = url.match(/\/product\/[^\/]+\/(\d+)/);
        return pathMatches ? pathMatches[1] : null;
    }

    initCacheCleanup() {
        setInterval(() => {
            if (this.searchCache.size > 50) {
                const entries = Array.from(this.searchCache.entries());
                this.searchCache.clear();
                entries.slice(-25).forEach(([key, value]) => {
                    this.searchCache.set(key, value);
                });
            }
        }, 300000);
    }

    buildResultItemFallbackNode(result, query, index) {
        const wrapper = document.createElement('div');
        wrapper.className = 'search-result-item';
        wrapper.dataset.productId = result.id;
        wrapper.dataset.resultIndex = index;

        const link = document.createElement('a');
        link.className = 'result-link';
        link.href = this.addTrackingToUrl(result.url, query, result.id);

        if (result.image) {
            const image = document.createElement('div');
            image.className = 'result-image';
            const img = document.createElement('img');
            img.src = result.image;
            img.alt = result.name || '';
            img.loading = 'lazy';
            image.appendChild(img);
            link.appendChild(image);
        } else {
            const placeholder = document.createElement('div');
            placeholder.className = 'result-image-placeholder';
            link.appendChild(placeholder);
        }

        const content = document.createElement('div');
        content.className = 'result-content';

        const title = document.createElement('h4');
        title.className = 'result-title';
        title.innerHTML = this.highlightSearchTerm(result.name, query);
        content.appendChild(title);

        if (result.price) {
            const price = document.createElement('div');
            price.className = 'result-price';
            price.innerHTML = result.price;
            content.appendChild(price);
        }

        if (result.sku) {
            const sku = document.createElement('div');
            sku.className = 'result-sku';
            sku.textContent = 'SKU: ' + result.sku;
            content.appendChild(sku);
        }

        link.appendChild(content);
        wrapper.appendChild(link);
        return wrapper;
    }

    buildAutocompleteLinkFallbackNode(item, type, index) {
        const wrapper = document.createElement('div');
        wrapper.className = `search-result-item search-result-item-${type}`;
        wrapper.dataset.resultIndex = index;

        const link = document.createElement('a');
        link.className = 'result-link';
        link.href = item.url || '#';

        const content = document.createElement('div');
        content.className = 'result-content';

        const meta = document.createElement('div');
        meta.className = 'result-sku';
        meta.textContent = type === 'category' ? 'Category' : 'Search';
        content.appendChild(meta);

        const title = document.createElement('h4');
        title.className = 'result-title';
        title.textContent = item.text || '';
        content.appendChild(title);

        link.appendChild(content);
        wrapper.appendChild(link);
        return wrapper;
    }

    buildFooterFallbackNode(url, countText) {
        const wrapper = document.createElement('div');
        wrapper.className = 'search-results-footer';

        const count = document.createElement('span');
        count.className = 'results-count';
        count.textContent = countText;

        const link = document.createElement('a');
        link.className = 'view-all-results';
        link.href = url;
        link.textContent = 'View all results';

        wrapper.appendChild(count);
        wrapper.appendChild(link);
        return wrapper;
    }

    buildLoadingFallbackNode() {
        const wrapper = document.createElement('div');
        wrapper.className = 'search-loading';

        const spinner = document.createElement('div');
        spinner.className = 'loading-spinner';
        const text = document.createElement('span');
        text.textContent = 'Searching...';

        wrapper.appendChild(spinner);
        wrapper.appendChild(text);
        return wrapper;
    }

    buildNoResultsFallbackNode(query) {
        const wrapper = document.createElement('div');
        wrapper.className = 'search-no-results';

        const message = document.createElement('p');
        message.className = 'no-results-message';
        message.textContent = `No products found for "${query}"`;
        wrapper.appendChild(message);

        const suggestions = document.createElement('div');
        suggestions.className = 'no-results-suggestions';
        suggestions.innerHTML = '<p>Try:</p><ul><li>Checking your spelling</li><li>Using different keywords</li><li>Searching for more general terms</li></ul>';
        wrapper.appendChild(suggestions);

        return wrapper;
    }
}

window.AIVectorSearchAutocomplete = AIVectorSearchAutocomplete;

document.addEventListener('DOMContentLoaded', () => {
    const config = window.aivesese_search;
    if (!config || config.enabled !== '1' || config.autocomplete_enabled !== '1') {
        return;
    }

    // WoodmartIntegration extends this class and self-initializes — don't double-bind
    if (window.aivesese_woodmart && window.aivesese_woodmart.enabled === '1') {
        return;
    }

    const autocomplete = new AIVectorSearchAutocomplete({
        ajaxUrl: config.ajax_url,
        searchNonce: config.search_nonce,
        trackingNonce: config.tracking_nonce,
        autocompleteEnabled: true,
    });
    autocomplete.init();
});
