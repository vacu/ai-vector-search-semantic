<?php
/**
 * License Activation Template
 * File: assets/templates/license-activation.php
 *
 * Variables available:
 * - $license_key: Current license key value
 * - $is_activated: Whether license is already activated
 * - $activation_data: License activation data (plan, store_id, etc.)
 */

defined('ABSPATH') || exit;
?>

<div class="license-key-section">
    <?php if ($is_activated): ?>
        <!-- Activated License Display -->
        <div class="license-status activated">
            <span class="dashicons dashicons-yes-alt"></span>
            <div class="license-status-content">
                <strong>License Active</strong>
                <p>Your API service is connected and ready!</p>

                <?php if (!empty($activation_data)): ?>
                    <div class="license-details">
                        <?php if (!empty($activation_data['plan'])): ?>
                            <div class="license-detail">
                                <span class="detail-label">Plan:</span>
                                <span class="detail-value"><?php echo esc_html(ucfirst($activation_data['plan'])); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($activation_data['store_id'])): ?>
                            <div class="license-detail">
                                <span class="detail-label">Store ID:</span>
                                <span class="detail-value"><code><?php echo esc_html($activation_data['store_id']); ?></code></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($activation_data['expires_at'])): ?>
                            <div class="license-detail">
                                <span class="detail-label">Next Payment:</span>
                                <span class="detail-value"><?php echo esc_html(date('M j, Y', strtotime($activation_data['expires_at']))); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="license-actions">
                <button type="button" class="button license-change-btn" onclick="revokeLicense()">
                    Change License
                </button>
                <a href="https://zzzsolutions.ro/my-account" target="_blank" class="button button-secondary">
                    Manage Subscription
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- License Activation Form -->
        <div class="license-activation-form">
            <h3>Activate Your License</h3>
            <p>Enter your license key from ZZZ Solutions to get started with our managed API service.</p>

            <div class="license-input-group">
                <input type="text"
                       id="aivesese_license_key"
                       name="aivesese_license_key"
                       value="<?php echo esc_attr($license_key); ?>"
                       class="regular-text license-key-input"
                       placeholder="Enter your license key">

                <button type="button"
                        id="activate-license"
                        class="button button-primary license-activate-btn"
                        onclick="activateLicense()">
                    <span class="button-text">Activate License</span>
                    <span class="button-spinner" style="display: none;">
                        <span class="dashicons dashicons-update spin"></span>
                    </span>
                </button>
            </div>

            <div id="license-status" class="license-status-messages" style="margin-top: 15px;"></div>

            <div class="license-help">
                <p class="description">
                    <strong>Don't have a license?</strong>
                    <a href="https://zzzsolutions.ro/ai-search-service" target="_blank" class="license-purchase-link">
                        Get one here →
                    </a>
                </p>

                <details class="license-help-details">
                    <summary>Need help with activation?</summary>
                    <div class="license-help-content">
                        <h4>How to activate:</h4>
                        <ol>
                            <li>Purchase a license from our website</li>
                            <li>Check your email for the license key</li>
                            <li>Paste the key in the field above</li>
                            <li>Click "Activate License"</li>
                        </ol>

                        <h4>Having trouble?</h4>
                        <ul>
                            <li>Make sure you're using the correct license key</li>
                            <li>Check that your server can connect to our API</li>
                            <li>Ensure your license hasn't expired</li>
                            <li>Contact support if problems persist</li>
                        </ul>

                        <p>
                            <a href="https://zzzsolutions.ro/support" target="_blank" class="button button-small">
                                Contact Support
                            </a>
                        </p>
                    </div>
                </details>
            </div>
        </div>

        <!-- License Benefits -->
        <div class="license-benefits">
            <h4>What you get with a license:</h4>
            <div class="benefits-grid">
                <div class="benefit-item">
                    <span class="benefit-icon">🚀</span>
                    <div class="benefit-content">
                        <strong>Zero Setup</strong>
                        <p>No Supabase configuration needed</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon">🛡️</span>
                    <div class="benefit-content">
                        <strong>Guaranteed Uptime</strong>
                        <p>99.9% SLA with monitoring</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon">🎯</span>
                    <div class="benefit-content">
                        <strong>Premium Support</strong>
                        <p>Priority email and chat support</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon">📊</span>
                    <div class="benefit-content">
                        <strong>Advanced Analytics</strong>
                        <p>Detailed insights and reporting</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon">🔄</span>
                    <div class="benefit-content">
                        <strong>Auto Updates</strong>
                        <p>Always latest features and fixes</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span class="benefit-icon">⚡</span>
                    <div class="benefit-content">
                        <strong>High Performance</strong>
                        <p>Optimized infrastructure</p>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php if (!$is_activated): ?>
<!-- Pricing Information -->
<div class="license-pricing-info">
    <h3>Simple, Transparent Pricing</h3>
    <div class="pricing-tiers">
        <div class="pricing-tier tier-starter">
            <h4>Starter</h4>
            <div class="price">$29<span>/month</span></div>
            <ul>
                <li>1,000 products</li>
                <li>10,000 searches/month</li>
                <li>Email support</li>
                <li>1 website</li>
            </ul>
        </div>

        <div class="pricing-tier tier-growth recommended">
            <div class="tier-badge">Most Popular</div>
            <h4>Growth</h4>
            <div class="price">$99<span>/month</span></div>
            <ul>
                <li>10,000 products</li>
                <li>100,000 searches/month</li>
                <li>Priority support</li>
                <li>3 websites</li>
                <li>Advanced analytics</li>
            </ul>
        </div>

        <div class="pricing-tier tier-scale">
            <h4>Scale</h4>
            <div class="price">$299<span>/month</span></div>
            <ul>
                <li>Unlimited products</li>
                <li>500,000 searches/month</li>
                <li>Priority support</li>
                <li>10 websites</li>
                <li>Custom features</li>
            </ul>
        </div>
    </div>

    <p class="pricing-note">
        All plans include semantic search, recommendations, and automatic updates.
        <a href="https://zzzsolutions.ro/pricing" target="_blank">View detailed comparison →</a>
    </p>
</div>
<?php endif; ?>
