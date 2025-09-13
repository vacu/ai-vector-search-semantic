<?php
/**
 * Connection Mode Selector Template
 * File: assets/templates/connection-mode-selector.php
 *
 * Variables available:
 * - $current_mode: Current connection mode ('api' or 'self_hosted')
 * - $api_available: Whether API mode is available
 */

defined('ABSPATH') || exit;
?>

<div class="connection-mode-selector">
    <?php if ($api_available): ?>
        <!-- API Service Available -->
        <label class="connection-option">
            <input type="radio" name="aivesese_connection_mode" value="api" <?php checked($current_mode, 'api'); ?>>
            <div class="option-card api-option">
                <h4>🚀 Managed API Service</h4>
                <p>Use our hosted service with your license key. No setup required!</p>
                <ul>
                    <li>✅ No database setup needed</li>
                    <li>✅ Automatic updates and maintenance</li>
                    <li>✅ Professional support included</li>
                    <li>✅ Guaranteed uptime and performance</li>
                </ul>
                <small><strong>Starts at $29/month</strong></small>
            </div>
        </label>
    <?php else: ?>
        <!-- API Service Coming Soon - NOT CLICKABLE -->
        <div class="connection-option-disabled">
            <div class="api-service-preview">
                <h5>🚀 Managed API Service (Coming Soon!)</h5>
                <p><em>We're working on a hosted service that will eliminate setup complexity.</em></p>
            </div>
        </div>
    <?php endif; ?>

    <label class="connection-option">
        <input type="radio" name="aivesese_connection_mode" value="self_hosted" <?php checked($current_mode, 'self_hosted'); ?>>
        <div class="option-card self-hosted-option">
            <h4>⚙️ Self-Hosted (Bring Your Own Keys)</h4>
            <p>Use your own Supabase and OpenAI accounts. Full control!</p>
            <ul>
                <li>🔧 Requires Supabase project setup</li>
                <li>🔧 Manual SQL installation needed</li>
                <li>🔧 You manage infrastructure</li>
                <li>💰 Pay only for API usage</li>
            </ul>
            <small><strong>Free plugin + your API costs</strong></small>
        </div>
    </label>
</div>

<!-- <div class="connection-mode-help">
    <h3>Need Help Choosing?</h3>
    <div class="help-comparison">
        <div class="comparison-item">
            <strong>Choose API Service if:</strong>
            <ul>
                <li>You want zero technical setup</li>
                <li>You prefer predictable monthly pricing</li>
                <li>You need guaranteed support and uptime</li>
                <li>You want automatic updates and maintenance</li>
            </ul>
        </div>
        <div class="comparison-item">
            <strong>Choose Self-Hosted if:</strong>
            <ul>
                <li>You enjoy technical challenges</li>
                <li>You want full control over your data</li>
                <li>You prefer pay-per-use pricing</li>
                <li>You have development resources available</li>
            </ul>
        </div>
    </div>
</div> -->
