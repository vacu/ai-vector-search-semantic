<?php
/**
 * PostgreSQL Installation Option Template
 * File: assets/templates/postgres-installation-option.php
 *
 * This template displays the direct PostgreSQL installation option
 */

defined('ABSPATH') || exit;
?>

<div class="installation-option postgres-option">
    <h3>🚀 Direct PostgreSQL Installation (Recommended)</h3>
    <p>Install schema directly via PostgreSQL connection - fastest and most reliable method.</p>

    <div class="postgres-benefits">
        <ul>
            <li>✅ <strong>One-click installation</strong> - No copy/paste needed</li>
            <li>✅ <strong>Transactional safety</strong> - Automatic rollback on errors</li>
            <li>✅ <strong>Real-time feedback</strong> - See exactly what happens</li>
            <li>✅ <strong>Professional grade</strong> - Same method used by WP-CLI</li>
        </ul>
    </div>

    <div class="postgres-action">
        <button type="button" class="button button-primary button-large" id="postgres-install-btn">
            <span class="dashicons dashicons-database" style="margin-right: 8px;"></span>
            Install Schema via PostgreSQL
        </button>
    </div>

    <div id="postgres-installation-progress" style="display: none; margin: 20px 0;">
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <div class="progress-text">Preparing installation...</div>
    </div>

    <div id="postgres-installation-result" style="margin-top: 20px;"></div>
</div>
