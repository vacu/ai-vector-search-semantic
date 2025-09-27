<?php
/**
 * Lite Mode Configuration Template
 * File: assets/templates/lite-mode-config.php
 */

defined('ABSPATH') || exit;

$connection_manager = AIVectorSearch_Connection_Manager::instance();
$lite_engine = AIVectorSearch_Lite_Engine::instance();
$config_summary = $connection_manager->get_config_summary();
$index_stats = $lite_engine->get_index_stats();
$stopwords_option = get_option('aivesese_lite_stopwords', null);
$stopwords_value = $stopwords_option === null ? implode("\n", $lite_engine->get_builtin_stopwords()) : $stopwords_option;

$synonyms_option = get_option('aivesese_lite_synonyms', null);
$synonyms_value = $synonyms_option === null ? $lite_engine->format_synonyms_for_editor($lite_engine->get_builtin_synonyms()) : $synonyms_option;
$upgrade_suggestions = $connection_manager->get_upgrade_suggestions();
?>

<div class="lite-mode-configuration">
    <!-- Lite Mode Status -->
    <div class="lite-mode-status">
        <div class="status-header">
            <h3>🚀 Lite Mode Active</h3>
            <span class="status-badge lite-mode">Local Search Engine</span>
        </div>

        <div class="status-description">
            <p>Your search is powered by an intelligent local engine using TF-IDF scoring with synonym expansion and category boosts.
               <strong>No external setup required!</strong></p>
        </div>

        <div class="status-stats">
            <div class="stat-item">
                <strong><?php echo number_format($index_stats['indexed_products']); ?></strong>
                <span>Products Indexed</span>
            </div>
            <div class="stat-item">
                <strong><?php echo number_format($index_stats['total_terms']); ?></strong>
                <span>Search Terms</span>
            </div>
            <div class="stat-item">
                <strong><?php echo $config_summary['index_limit_label']; ?></strong>
                <span>Current Limit</span>
            </div>
        </div>
    </div>

    <!-- Index Configuration -->
    <div class="lite-index-configuration">
        <h4>📊 Indexing Configuration</h4>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="aivesese_lite_index_limit">Products to Index</label>
                </th>
                <td>
                    <select name="aivesese_lite_index_limit" id="aivesese_lite_index_limit">
                        <option value="200" <?php selected(get_option('aivesese_lite_index_limit', '500'), '200'); ?>>
                            Recent 200 products (fastest)
                        </option>
                        <option value="500" <?php selected(get_option('aivesese_lite_index_limit', '500'), '500'); ?>>
                            Recent 500 products (balanced) - Recommended
                        </option>
                        <option value="1000" <?php selected(get_option('aivesese_lite_index_limit', '500'), '1000'); ?>>
                            Recent 1000 products (comprehensive)
                        </option>
                        <option value="0" <?php selected(get_option('aivesese_lite_index_limit', '500'), '0'); ?>>
                            All products (may be slower on large catalogs)
                        </option>
                    </select>
                    <p class="description">
                        Choose how many products to include in your local search index.
                        More products = better coverage but potentially slower performance.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="aivesese_lite_stopwords"><?php esc_html_e('Stopwords', 'ai-vector-search-semantic'); ?></label>
                </th>
                <td>
                    <textarea name="aivesese_lite_stopwords" id="aivesese_lite_stopwords" rows="8" class="large-text code"><?php echo esc_textarea($stopwords_value); ?></textarea>
                    <p class="description"><?php esc_html_e('Add one term per line. Lines starting with # are treated as comments. Leave empty to disable stopword filtering.', 'ai-vector-search-semantic'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="aivesese_lite_synonyms"><?php esc_html_e('Synonym Map', 'ai-vector-search-semantic'); ?></label>
                </th>
                <td>
                    <textarea name="aivesese_lite_synonyms" id="aivesese_lite_synonyms" rows="10" class="large-text code"><?php echo esc_textarea($synonyms_value); ?></textarea>
                    <p class="description"><?php esc_html_e('Use the format term: synonym1, synonym2 (one per line). Leave empty to disable synonym expansion.', 'ai-vector-search-semantic'); ?></p>
                </td>
            </tr>
        </table>

        <!-- Index Management Actions -->
        <div class="index-actions">
            <h4>🔧 Index Management</h4>
            <p>Your search index is automatically rebuilt when products change, but you can manually force a rebuild here.</p>

            <div class="action-buttons">
                <button type="button" class="button button-secondary" id="rebuild-lite-index">
                    <span class="dashicons dashicons-update"></span>
                    Rebuild Search Index
                </button>

                <button type="button" class="button button-secondary" id="test-lite-search">
                    <span class="dashicons dashicons-search"></span>
                    Test Search
                </button>
            </div>

            <div id="index-action-results" class="action-results" style="display:none;"></div>
        </div>
    </div>

    <!-- Performance Insights -->
    <?php
    $avg_search_time = get_option('aivesese_lite_avg_search_time', 0);
    if ($avg_search_time > 0):
    ?>
    <div class="performance-insights">
        <h4>⚡ Performance Insights</h4>
        <div class="performance-stats">
            <div class="perf-stat">
                <span class="perf-label">Average Search Time</span>
                <span class="perf-value <?php echo $avg_search_time > 500 ? 'slow' : 'fast'; ?>">
                    <?php echo $avg_search_time; ?>ms
                </span>
            </div>
            <?php if ($avg_search_time > 500): ?>
            <div class="perf-recommendation">
                <p>⚠️ <strong>Performance Notice:</strong> Your searches are averaging over 500ms.
                   Consider upgrading to Supabase for <50ms response times.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upgrade Suggestions -->
    <?php if (!empty($upgrade_suggestions)): ?>
    <div class="upgrade-suggestions">
        <h4>💡 Upgrade Recommendations</h4>

        <?php foreach ($upgrade_suggestions as $suggestion): ?>
        <div class="upgrade-card upgrade-<?php echo esc_attr($suggestion['type']); ?>">
            <div class="upgrade-content">
                <h5><?php echo esc_html($suggestion['title']); ?></h5>
                <p><?php echo esc_html($suggestion['message']); ?></p>
            </div>
            <div class="upgrade-action">
                <a href="#upgrade-options" class="button button-primary"><?php echo esc_html($suggestion['cta']); ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Feature Comparison -->
    <div class="feature-comparison" id="upgrade-options">
        <h4>🆚 Compare Search Modes</h4>

        <div class="comparison-table">
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th class="current-mode">Lite Mode <span class="current-badge">Current</span></th>
                        <th class="upgrade-option">Self-Hosted</th>
                        <th class="upgrade-option premium">API Service</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Setup Required</strong></td>
                        <td class="feature-yes">✅ None</td>
                        <td class="feature-partial">⚠️ Supabase + SQL</td>
                        <td class="feature-yes">✅ Just License Key</td>
                    </tr>
                    <tr>
                        <td><strong>Search Speed</strong></td>
                        <td class="feature-partial">⚠️ 200-500ms</td>
                        <td class="feature-yes">✅ <50ms</td>
                        <td class="feature-yes">✅ <30ms</td>
                    </tr>
                    <tr>
                        <td><strong>Semantic AI Search</strong></td>
                        <td class="feature-no">❌ Not Available</td>
                        <td class="feature-yes">✅ With OpenAI</td>
                        <td class="feature-yes">✅ Included</td>
                    </tr>
                    <tr>
                        <td><strong>Product Limit</strong></td>
                        <td class="feature-partial">⚠️ Configurable</td>
                        <td class="feature-yes">✅ Unlimited</td>
                        <td class="feature-yes">✅ Unlimited</td>
                    </tr>
                    <tr>
                        <td><strong>Advanced Analytics</strong></td>
                        <td class="feature-partial">⚠️ Basic</td>
                        <td class="feature-yes">✅ Full</td>
                        <td class="feature-yes">✅ Enhanced</td>
                    </tr>
                    <tr>
                        <td><strong>Support</strong></td>
                        <td class="feature-partial">⚠️ Community</td>
                        <td class="feature-partial">⚠️ Community</td>
                        <td class="feature-yes">✅ Priority</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Monthly Cost</strong></td>
                        <td class="current-mode">FREE</td>
                        <td>$0-25/month*</td>
                        <td class="premium">$29/month</td>
                    </tr>
                </tfoot>
            </table>
            <p class="cost-note">* Supabase free tier covers most small/medium stores. OpenAI costs ~$0.10-1.00 one-time setup.</p>
        </div>

        <!-- Upgrade Actions -->
        <div class="upgrade-actions">
            <div class="upgrade-option-card">
                <h5>🏗️ Self-Hosted (DIY)</h5>
                <p>Use your own Supabase + OpenAI accounts. You control everything!</p>
                <a href="<?php echo admin_url('options-general.php?page=aivesese&tab=self-hosted'); ?>"
                   class="button button-secondary">Configure Self-Hosted</a>
            </div>

            <div class="upgrade-option-card premium">
                <h5>🚀 Managed API Service</h5>
                <p>Zero setup, maximum performance. Let us handle the infrastructure!</p>
                <a href="<?php echo admin_url('options-general.php?page=aivesese&tab=api-service'); ?>"
                   class="button button-primary">Get API License</a>
            </div>
        </div>
    </div>

    <!-- Lite Mode Benefits (Don't make users feel bad) -->
    <div class="lite-mode-benefits">
        <h4>✨ What You're Getting in Lite Mode</h4>
        <div class="benefits-grid">
            <div class="benefit-item">
                <span class="benefit-icon">⚡</span>
                <div>
                    <strong>Instant Setup</strong>
                    <p>No external accounts or complex configuration needed</p>
                </div>
            </div>
            <div class="benefit-item">
                <span class="benefit-icon">🧠</span>
                <div>
                    <strong>Smart TF-IDF Algorithm</strong>
                    <p>Intelligent scoring with synonym expansion and category boosts</p>
                </div>
            </div>
            <div class="benefit-item">
                <span class="benefit-icon">🔄</span>
                <div>
                    <strong>Auto-Sync</strong>
                    <p>Index automatically updates when products change</p>
                </div>
            </div>
            <div class="benefit-item">
                <span class="benefit-icon">🌍</span>
                <div>
                    <strong>Multi-Language</strong>
                    <p>Supports English and Romanian out of the box</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Handle index rebuild
    $('#rebuild-lite-index').on('click', function() {
        const $button = $(this);
        const $results = $('#index-action-results');

        $button.prop('disabled', true).find('.dashicons').addClass('spin');
        $results.hide();

        $.post(ajaxurl, {
            action: 'aivesese_rebuild_lite_index',
            nonce: '<?php echo wp_create_nonce("aivesese_lite_actions"); ?>'
        }, function(response) {
            $results.show();
            if (response.success) {
                $results.html('<div class="notice notice-success"><p>' + response.data.message + '</p>' +
                    '<ul><li>Products indexed: ' + response.data.stats.indexed_products + '</li>' +
                    '<li>Total terms: ' + response.data.stats.total_terms + '</li>' +
                    '<li>Build time: ' + response.data.stats.build_time + 's</li></ul></div>');
            } else {
                $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
        }).fail(function() {
            $results.show().html('<div class="notice notice-error"><p>Failed to rebuild index. Please try again.</p></div>');
        }).always(function() {
            $button.prop('disabled', false).find('.dashicons').removeClass('spin');
        });
    });

    // Handle test search
    $('#test-lite-search').on('click', function() {
        const $button = $(this);
        const $results = $('#index-action-results');
        const testTerm = prompt('Enter a search term to test:', 'laptop');

        if (!testTerm) return;

        $button.prop('disabled', true).find('.dashicons').addClass('spin');
        $results.hide();

        $.post(ajaxurl, {
            action: 'aivesese_test_lite_search',
            term: testTerm,
            nonce: '<?php echo wp_create_nonce("aivesese_lite_actions"); ?>'
        }, function(response) {
            $results.show();
            if (response.success) {
                let html = '<div class="notice notice-info"><p><strong>Test Results for "' + testTerm + '":</strong></p>';
                if (response.data.products.length > 0) {
                    html += '<ul>';
                    response.data.products.forEach(function(product) {
                        html += '<li>' + product.name + ' (ID: ' + product.id + ')</li>';
                    });
                    html += '</ul>';
                    html += '<p>Search completed in ' + response.data.search_time + 'ms</p>';
                } else {
                    html += '<p>No products found for this term.</p>';
                }
                html += '</div>';
                $results.html(html);
            } else {
                $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
        }).fail(function() {
            $results.show().html('<div class="notice notice-error"><p>Test search failed. Please try again.</p></div>');
        }).always(function() {
            $button.prop('disabled', false).find('.dashicons').removeClass('spin');
        });
    });

    // Handle index limit change
    $('#aivesese_lite_index_limit').on('change', function() {
        // Show notice that index will be rebuilt
        const $results = $('#index-action-results');
        $results.show().html('<div class="notice notice-info"><p>💡 <strong>Note:</strong> Your search index will be rebuilt automatically with the new limit after saving settings.</p></div>');
    });
});
</script>

<style>
.lite-mode-configuration {
    max-width: 1200px;
}

.lite-mode-status {
    background: #f8f9fa;
    border: 1px solid #e1e5e9;
    border-left: 4px solid #00a32a;
    padding: 20px;
    margin-bottom: 20px;
}

.status-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.lite-mode {
    background: #e7f3ff;
    color: #0073aa;
}

.status-stats {
    display: flex;
    gap: 30px;
    margin-top: 15px;
}

.stat-item {
    text-align: center;
}

.stat-item strong {
    display: block;
    font-size: 24px;
    color: #0073aa;
}

.stat-item span {
    font-size: 12px;
    color: #666;
}

.lite-index-configuration,
.performance-insights,
.upgrade-suggestions,
.feature-comparison,
.lite-mode-benefits {
    background: white;
    border: 1px solid #ccd0d4;
    padding: 20px;
    margin-bottom: 20px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.action-results {
    margin-top: 15px;
}

.performance-stats {
    display: flex;
    align-items: center;
    gap: 20px;
}

.perf-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.perf-value.fast {
    color: #00a32a;
    font-weight: bold;
}

.perf-value.slow {
    color: #d63638;
    font-weight: bold;
}

.upgrade-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
}

.comparison-table table {
    width: 100%;
}

.comparison-table th.current-mode {
    background: #e7f3ff;
    color: #0073aa;
}

.comparison-table th.upgrade-option {
    background: #f0f0f1;
}

.comparison-table th.upgrade-option.premium {
    background: #fff2e7;
    color: #d54e21;
}

.current-badge {
    background: #00a32a;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    margin-left: 5px;
}

.feature-yes { color: #00a32a; }
.feature-no { color: #d63638; }
.feature-partial { color: #dba617; }

.upgrade-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}

.upgrade-option-card {
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-align: center;
}

.upgrade-option-card.premium {
    border-color: #d54e21;
    background: #fff9f7;
}

.benefits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.benefit-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.benefit-icon {
    font-size: 24px;
    flex-shrink: 0;
}

.dashicons.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
