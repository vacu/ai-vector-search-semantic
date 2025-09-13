<?php
/**
 * PostgreSQL Installation Unavailable Template
 * File: assets/templates/postgres-installation-unavailable.php
 *
 * Variables available:
 * - $status: Array with requirements status
 */

defined('ABSPATH') || exit;
?>

<div class="installation-option postgres-unavailable">
    <h3>🚀 Direct PostgreSQL Installation</h3>
    <div class="notice notice-warning inline">
        <p><strong>⚠️ PostgreSQL installation not available</strong></p>

        <div class="requirements-check">
            <h4>Requirements Status:</h4>
            <ul>
                <?php foreach ($status['requirements'] as $requirement => $met): ?>
                    <?php
                    $icon = $met ? '✅' : '❌';
                    $req_name = ucwords(str_replace('_', ' ', $requirement));
                    ?>
                    <li><?php echo $icon; ?> <?php echo esc_html($req_name); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if (!$status['requirements']['psql_command']): ?>
            <div class="psql-install-help">
                <p><strong>To enable PostgreSQL installation:</strong></p>
                <ol>
                    <li>Install PostgreSQL client on your server:</li>
                    <ul style="margin-left: 20px;">
                        <li><strong>Ubuntu/Debian:</strong> <code>sudo apt-get install postgresql-client</code></li>
                        <li><strong>CentOS/RHEL:</strong> <code>sudo yum install postgresql</code></li>
                        <li><strong>Alpine:</strong> <code>apk add postgresql-client</code></li>
                    </ul>
                    <li>Configure PostgreSQL connection string above</li>
                    <li>Refresh this page</li>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (!$status['requirements']['connection_string']): ?>
            <p><strong>Missing:</strong> Configure your PostgreSQL connection string in the field above.</p>
        <?php endif; ?>
    </div>
</div>
