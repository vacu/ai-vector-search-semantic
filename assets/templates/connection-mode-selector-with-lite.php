<?php
/**
 * Enhanced Connection Mode Selector Template
 * File: assets/templates/connection-mode-selector-with-lite.php
 *
 * Variables available:
 * - $current_mode: Current connection mode ('lite', 'api', or 'self_hosted')
 * - $api_available: Whether API mode is available
 * - $connection_manager: Connection manager instance
 */

defined('ABSPATH') || exit;

$connection_manager = $connection_manager ?? AIVectorSearch_Connection_Manager::instance();
$config_summary = $connection_manager->get_config_summary();
$total_products = wp_count_posts('product')->publish ?? 0;
?>

<div class="connection-mode-selector enhanced">
    <div class="mode-selector-header">
        <h3>🚀 Choose Your Search Engine</h3>
        <p>Select the search mode that best fits your needs. You can change this anytime!</p>
    </div>

    <div class="connection-modes">
        <!-- Lite Mode - New Default -->
        <label class="connection-option lite-option <?php echo $current_mode === 'lite' ? 'active' : ''; ?>">
            <input type="radio" name="aivesese_connection_mode" value="lite" <?php checked($current_mode, 'lite'); ?>>
            <div class="option-card">
                <div class="option-header">
                    <h4>⚡ Lite Mode</h4>
                    <span class="option-badge recommended">Recommended to Start</span>
                </div>

                <p class="option-description">
                    Intelligent local search using TF-IDF with synonym expansion. <strong>Zero setup required!</strong>
                </p>

                <div class="option-features">
                    <div class="feature-grid">
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Works instantly after activation</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Smart TF-IDF + category boosting</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Configurable product indexing</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Auto-updates when products change</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Multi-language support (EN/RO)</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">⚠️</span>
                            <span>No semantic AI search</span>
                        </div>
                    </div>
                </div>

                <div class="option-stats">
                    <?php if ($current_mode === 'lite'): ?>
                        <?php $lite_stats = AIVectorSearch_Lite_Engine::instance()->get_index_stats(); ?>
                        <div class="stats-row">
                            <strong><?php echo number_format($lite_stats['indexed_products']); ?></strong>
                            <span>Products Indexed</span>
                        </div>
                    <?php else: ?>
                        <div class="stats-row">
                            <strong><?php echo number_format($total_products); ?></strong>
                            <span>Products Available</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="option-pricing">
                    <div class="price"><strong>FREE</strong></div>
                    <div class="price-note">No external costs</div>
                </div>
            </div>
        </label>

        <!-- Self-Hosted Mode -->
        <label class="connection-option self-hosted-option <?php echo $current_mode === 'self_hosted' ? 'active' : ''; ?>">
            <input type="radio" name="aivesese_connection_mode" value="self_hosted" <?php checked($current_mode, 'self_hosted'); ?>>
            <div class="option-card">
                <div class="option-header">
                    <h4>🏗️ Self-Hosted</h4>
                    <span class="option-badge power">Power User</span>
                </div>

                <p class="option-description">
                    Use your own Supabase + OpenAI accounts. Maximum control and performance!
                </p>

                <div class="option-features">
                    <div class="feature-grid">
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Lightning fast search (<50ms)</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>AI semantic search with OpenAI</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Unlimited products</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Advanced analytics</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Full data ownership</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">⚠️</span>
                            <span>Requires Supabase + SQL setup</span>
                        </div>
                    </div>
                </div>

                <div class="option-stats">
                    <?php if ($current_mode === 'self_hosted'): ?>
                        <div class="stats-row">
                            <strong><?php echo number_format($connection_manager->get_synced_count()); ?></strong>
                            <span>Products Synced</span>
                        </div>
                    <?php else: ?>
                        <div class="stats-row">
                            <strong>Setup Required</strong>
                            <span>Supabase + OpenAI</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="option-pricing">
                    <div class="price"><strong>$0-25/month*</strong></div>
                    <div class="price-note">*Your API usage costs</div>
                </div>
            </div>
        </label>

        <!-- API Service -->
        <?php if ($api_available): ?>
        <label class="connection-option api-option <?php echo $current_mode === 'api' ? 'active' : ''; ?>">
            <input type="radio" name="aivesese_connection_mode" value="api" <?php checked($current_mode, 'api'); ?>>
            <div class="option-card">
                <div class="option-header">
                    <h4>🚀 Managed API Service</h4>
                    <span class="option-badge premium">Premium</span>
                </div>

                <p class="option-description">
                    Hosted service with your license key. Zero maintenance, maximum performance!
                </p>

                <div class="option-features">
                    <div class="feature-grid">
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Ultra-fast search (<30ms)</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Advanced AI semantic search</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Unlimited products</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Priority support</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Automatic updates & maintenance</span>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">✅</span>
                            <span>Just add license key</span>
                        </div>
                    </div>
                </div>

                <div class="option-stats">
                    <?php if ($current_mode === 'api'): ?>
                        <div class="stats-row">
                            <strong><?php echo number_format($connection_manager->get_synced_count()); ?></strong>
                            <span>Products Synced</span>
                        </div>
                    <?php else: ?>
                        <div class="stats-row">
                            <strong>License Required</strong>
                            <span>Managed hosting</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="option-pricing">
                    <div class="price"><strong>$29/month</strong></div>
                    <div class="price-note">All-inclusive</div>
                </div>
            </div>
        </label>
        <?php else: ?>
        <!-- API Service Coming Soon -->
        <div class="connection-option-disabled api-preview">
            <div class="option-card disabled">
                <div class="option-header">
                    <h4>🚀 Managed API Service</h4>
                    <span class="option-badge coming-soon">Coming Soon</span>
                </div>
                <p class="option-description">
                    <em>We're working on a fully-managed service to eliminate all technical setup.</em>
                </p>
                <div class="option-pricing">
                    <div class="price"><strong>~$29/month</strong></div>
                    <div class="price-note">Estimated pricing</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Smart Recommendations -->
    <div class="mode-recommendations">
        <h4>💡 Our Recommendations</h4>
        <div class="recommendation-cards">
            <?php if ($total_products < 500): ?>
            <div class="rec-card">
                <div class="rec-icon">🎯</div>
                <div class="rec-content">
                    <strong>Perfect for your store size!</strong>
                    <p>With <?php echo number_format($total_products); ?> products, <strong>Lite Mode</strong> will give you excellent results instantly.</p>
                </div>
            </div>
            <?php elseif ($total_products < 2000): ?>
            <div class="rec-card">
                <div class="rec-icon">⚡</div>
                <div class="rec-content">
                    <strong>Consider Self-Hosted for better performance</strong>
                    <p>With <?php echo number_format($total_products); ?> products, you might benefit from Supabase's faster search.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="rec-card">
                <div class="rec-icon">🚀</div>
                <div class="rec-content">
                    <strong>Large catalog detected!</strong>
                    <p>With <?php echo number_format($total_products); ?> products, Self-Hosted or API mode will provide the best experience.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="rec-card">
                <div class="rec-icon">🎓</div>
                <div class="rec-content">
                    <strong>Start with Lite, upgrade when ready</strong>
                    <p>Try Lite Mode now, then upgrade to unlock semantic search: "comfortable shoes" finds "cozy sneakers".</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Migration Notice -->
    <?php if ($current_mode === 'lite'): ?>
    <div class="migration-notice">
        <h4>🔄 Seamless Upgrades</h4>
        <p>When you're ready to upgrade, your search analytics and settings will be preserved.
           Switching modes is instant and reversible.</p>
    </div>
    <?php endif; ?>
</div>
