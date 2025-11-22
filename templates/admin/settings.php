<?php
/**
 * Admin settings template
 */

if (!defined('ABSPATH')) {
    exit;
}

$refresh_interval = get_option('chronotrack_refresh_interval', 5000);
$max_logo_width = get_option('chronotrack_max_logo_width', 400);
$max_logo_height = get_option('chronotrack_max_logo_height', 200);
?>

<div class="wrap">
    <h1><?php _e('ChronoTrack Settings', 'chronotrack-live'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('chronotrack_settings', 'chronotrack_settings_nonce'); ?>

        <table class="form-table">

            <tr>
                <th scope="row">
                    <label for="refresh_interval"><?php _e('Refresh Interval', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="refresh_interval"
                           name="refresh_interval"
                           value="<?php echo esc_attr($refresh_interval); ?>"
                           min="1000"
                           step="1000"
                           class="small-text">
                    <span><?php _e('milliseconds', 'chronotrack-live'); ?></span>
                    <p class="description">
                        <?php _e('How often to refresh results on the frontend (default: 5000ms = 5 seconds)', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_logo_width"><?php _e('Maximum Logo Width', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="max_logo_width"
                           name="max_logo_width"
                           value="<?php echo esc_attr($max_logo_width); ?>"
                           min="100"
                           max="1000"
                           class="small-text">
                    <span><?php _e('pixels', 'chronotrack-live'); ?></span>
                    <p class="description">
                        <?php _e('Maximum width for event and sponsor logos (default: 400px)', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="max_logo_height"><?php _e('Maximum Logo Height', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="max_logo_height"
                           name="max_logo_height"
                           value="<?php echo esc_attr($max_logo_height); ?>"
                           min="50"
                           max="500"
                           class="small-text">
                    <span><?php _e('pixels', 'chronotrack-live'); ?></span>
                    <p class="description">
                        <?php _e('Maximum height for event and sponsor logos (default: 200px)', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

        </table>

        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php _e('Save Settings', 'chronotrack-live'); ?>">
        </p>
    </form>
</div>
