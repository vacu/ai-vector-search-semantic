<?php
/**
 * Sold count update section (self-hosted mode).
 *
 * Variables:
 * - $ranges (array)
 */
?>
<div class="aivesese-sold-count">
    <h2><?php echo esc_html__('Update Sold Counts', 'ai-vector-search-semantic'); ?></h2>
    <p><?php echo esc_html__('Recalculate sold_count in Supabase based on WooCommerce orders.', 'ai-vector-search-semantic'); ?></p>
    <div class="aivesese-sold-count-controls">
        <label for="aivesese-sold-count-range">
            <?php echo esc_html__('Timeframe', 'ai-vector-search-semantic'); ?>
        </label>
        <select id="aivesese-sold-count-range">
            <?php foreach ($ranges as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>">
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-secondary" id="aivesese-sold-count-update">
            <?php echo esc_html__('Update Sold Counts', 'ai-vector-search-semantic'); ?>
        </button>
    </div>
    <p class="description">
        <?php echo esc_html__('This uses paid orders and updates existing products in Supabase.', 'ai-vector-search-semantic'); ?>
    </p>
    <div id="aivesese-sold-count-status" class="aivesese-sold-count-status" aria-live="polite"></div>
</div>
