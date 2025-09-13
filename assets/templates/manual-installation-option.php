<?php
/**
 * Manual Installation Option Template
 * File: assets/templates/manual-installation-option.php
 *
 * Variables available:
 * - $sql_content: SQL schema content for display
 */

defined('ABSPATH') || exit;
?>

<div class="installation-option manual-option">
    <h3>📝 Manual Installation</h3>
    <p>Copy the SQL and run it manually in Supabase SQL Editor - always available as fallback.</p>

    <div class="manual-benefits">
        <ul>
            <li>✅ <strong>Always works</strong> - No server requirements</li>
            <li>✅ <strong>Full control</strong> - See exactly what gets executed</li>
            <li>✅ <strong>Educational</strong> - Learn the database structure</li>
            <li>✅ <strong>Universal</strong> - Works on any hosting environment</li>
        </ul>
    </div>

    <details>
        <summary class="manual-toggle"><strong>Show Manual Installation</strong></summary>
        <div class="manual-content" style="margin-top: 15px;">
            <?php include AIVESESE_PLUGIN_PATH . 'assets/templates/manual-installation-steps.php'; ?>
        </div>
    </details>
</div>
