<?php
/**
 * Search autocomplete HTML templates.
 * File: templates/search-autocomplete.php
 *
 * These <template> elements are inert — invisible to the browser and excluded
 * from the normal DOM — but JavaScript can clone and populate them at runtime.
 *
 * To customise, copy this file to your-theme/aivesese/search-autocomplete.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<!-- Product result row -->
<template id="aivs-tpl-result-item">
    <div class="search-result-item" data-product-id="" data-result-index="">
        <a href="" class="result-link">
            <div class="result-image">
                <img src="" alt="" loading="lazy">
            </div>
            <div class="result-content">
                <h4 class="result-title"></h4>
                <div class="result-price"></div>
                <div class="result-sku"></div>
            </div>
        </a>
    </div>
</template>

<!-- Autocomplete suggestion row (search term or category) -->
<template id="aivs-tpl-autocomplete-link">
    <div class="search-result-item" data-result-index="">
        <a href="" class="result-link">
            <div class="result-content">
                <div class="result-sku"></div>
                <h4 class="result-title"></h4>
            </div>
        </a>
    </div>
</template>

<!-- Footer shown below product results -->
<template id="aivs-tpl-results-footer">
    <div class="search-results-footer">
        <span class="results-count"></span>
        <a href="" class="view-all-results"><?php esc_html_e( 'View all results &rarr;', 'ai-vector-search-semantic' ); ?></a>
    </div>
</template>

<!-- Footer shown below autocomplete suggestions -->
<template id="aivs-tpl-autocomplete-footer">
    <div class="search-results-footer">
        <span class="results-count"></span>
        <a href="" class="view-all-results"><?php esc_html_e( 'View all results', 'ai-vector-search-semantic' ); ?></a>
    </div>
</template>

<!-- Loading spinner -->
<template id="aivs-tpl-loading">
    <div class="search-loading">
        <div class="loading-spinner"></div>
        <span><?php esc_html_e( 'Searching...', 'ai-vector-search-semantic' ); ?></span>
    </div>
</template>

<!-- No results message -->
<template id="aivs-tpl-no-results">
    <div class="search-no-results">
        <p class="no-results-message"></p>
        <div class="no-results-suggestions">
            <p><?php esc_html_e( 'Try:', 'ai-vector-search-semantic' ); ?></p>
            <ul>
                <li><?php esc_html_e( 'Checking your spelling', 'ai-vector-search-semantic' ); ?></li>
                <li><?php esc_html_e( 'Using different keywords', 'ai-vector-search-semantic' ); ?></li>
                <li><?php esc_html_e( 'Searching for more general terms', 'ai-vector-search-semantic' ); ?></li>
            </ul>
        </div>
    </div>
</template>
