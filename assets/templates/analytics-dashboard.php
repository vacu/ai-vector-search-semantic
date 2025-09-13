<?php
/**
 * Analytics Dashboard Template
 * File: assets/templates/analytics-dashboard.php
 *
 * Expected variables:
 * - $stats: array with keys total_searches, unique_terms, successful_searches, success_rate, avg_results_per_search, click_through_rate
 * - $popular_terms: array of term objects (search_term, search_count, found_count, avg_results, click_count, ctr_percent)
 * - $zero_results: array of term objects (search_term, search_count, last_searched)
 * - $insights: array of associative arrays (type, icon, title, message, action)
 */

defined('ABSPATH') || exit;

// Helper closures for CSS classes
$perfClass = function(float $value, float $good, float $okay) {
    if ($value >= $good) return 'aivs-stat-good';
    if ($value >= $okay) return 'aivs-stat-okay';
    return 'aivs-stat-poor';
};

$badgeClass = function(float $value, float $good, float $okay) {
    if ($value >= $good) return 'aivs-good';
    if ($value >= $okay) return 'aivs-okay';
    return 'aivs-poor';
};
?>

<div class="wrap aivs-analytics-dashboard">
    <h1>📊 Search Analytics</h1>

    <?php if (!empty($insights)): ?>
        <div class="aivs-insights-section">
            <h2>💡 Business Insights</h2>
            <?php foreach ($insights as $insight): ?>
                <?php $type_class = 'aivs-insight-' . esc_attr($insight['type']); ?>
                <div class="aivs-insight <?php echo $type_class; ?>">
                    <div class="aivs-insight-icon"><?php echo $insight['icon']; ?></div>
                    <div class="aivs-insight-content">
                        <h4><?php echo esc_html($insight['title']); ?></h4>
                        <p><?php echo esc_html($insight['message']); ?></p>
                        <small class="aivs-insight-action">➡ <?php echo esc_html($insight['action']); ?></small>
                    </div>
                    <button class="insight-dismiss-btn" title="Dismiss">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="aivs-stats-grid">
        <div class="aivs-stat-card" data-stat="total-searches">
            <h3>Total Searches</h3>
            <div class="aivs-stat-number"><?php echo number_format((int) ($stats['total_searches'] ?? 0)); ?></div>
            <small>Last 30 days</small>
        </div>

        <?php $sr = (float) ($stats['success_rate'] ?? 0); ?>
        <div class="aivs-stat-card" data-stat="success-rate">
            <h3>Success Rate</h3>
            <div class="aivs-stat-number <?php echo $perfClass($sr, 80, 60); ?>"><?php echo esc_html($sr); ?>%</div>
            <small><?php echo number_format((int) ($stats['successful_searches'] ?? 0)); ?> found results</small>
        </div>

        <?php $ctr = (float) ($stats['click_through_rate'] ?? 0); ?>
        <div class="aivs-stat-card" data-stat="click-through-rate">
            <h3>Click-Through Rate</h3>
            <div class="aivs-stat-number <?php echo $perfClass($ctr, 40, 25); ?>"><?php echo esc_html($ctr); ?>%</div>
            <small>Users clicking results</small>
        </div>

        <div class="aivs-stat-card" data-stat="unique-terms">
            <h3>Unique Terms</h3>
            <div class="aivs-stat-number"><?php echo number_format((int) ($stats['unique_terms'] ?? 0)); ?></div>
            <small>Different searches</small>
        </div>
    </div>

    <div class="aivs-analytics-section">
        <div class="aivs-section-header">
            <h2>🔥 Popular Search Terms</h2>
            <a href="<?php echo esc_url(add_query_arg('export', 'csv')); ?>" class="button export-csv-btn">⬇ Export CSV</a>
        </div>

        <?php if (!empty($popular_terms)): ?>
            <table class="wp-list-table widefat fixed striped aivs-data-table aivs-terms-table">
                <thead>
                    <tr>
                        <th>Search Term</th>
                        <th class="numeric">Searches</th>
                        <th class="numeric">Success Rate</th>
                        <th class="numeric">Click Rate</th>
                        <th class="numeric">Avg Results</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($popular_terms as $term): ?>
                    <?php
                        $success_rate = $term->search_count > 0
                            ? round(($term->found_count / $term->search_count) * 100, 1)
                            : 0;
                        $badge = $badgeClass($success_rate, 80, 60);
                    ?>
                    <tr>
                        <td><strong class="search-term"><?php echo esc_html($term->search_term); ?></strong></td>
                        <td class="numeric"><?php echo number_format((int) $term->search_count); ?></td>
                        <td class="numeric"><span class="aivs-performance-badge <?php echo $badge; ?>"><?php echo esc_html($success_rate); ?>%</span></td>
                        <td class="numeric"><?php echo esc_html(round((float) $term->ctr_percent, 1)); ?>%</td>
                        <td class="numeric"><?php echo esc_html(round((float) $term->avg_results, 1)); ?></td>
                        <td><button class="table-action-btn preview-search-btn" data-term="<?php echo esc_attr($term->search_term); ?>">Preview</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="aivs-empty-state">
                <div class="aivs-empty-state-icon dashicons dashicons-chart-line"></div>
                <h3>No search data yet</h3>
                <p>Start getting insights as soon as customers search your store!</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="aivs-analytics-section">
        <h2>🔎 Opportunity Finder - Zero Result Searches</h2>

        <?php if (!empty($zero_results)): ?>
            <p class="description">These searches returned no results. Consider adding products or content for these terms:</p>
            <div class="aivs-opportunity-grid">
                <?php foreach ($zero_results as $term): ?>
                    <div class="aivs-opportunity-card">
                        <div class="aivs-opportunity-term">"<?php echo esc_html($term->search_term); ?>"</div>
                        <div class="aivs-opportunity-stats">
                            <span class="aivs-search-count"><?php echo (int) $term->search_count; ?> searches</span>
                            <span class="aivs-last-search"><?php echo esc_html(human_time_diff(strtotime($term->last_searched))); ?> ago</span>
                        </div>
                        <div class="aivs-opportunity-actions">
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=product')); ?>" class="button add-product-btn" data-term="<?php echo esc_attr($term->search_term); ?>">Add Product</a>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&s=' . urlencode($term->search_term))); ?>" class="button search-existing-btn" data-term="<?php echo esc_attr($term->search_term); ?>">Search Existing</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>✅ Great! All searches are returning results. No opportunities found.</p>
        <?php endif; ?>
    </div>
</div>

