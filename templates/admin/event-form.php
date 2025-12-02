<?php
/**
 * Admin event form template
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_edit = $event !== null;
?>

<div class="wrap">
    <h1><?php echo $is_edit ? __('Edit Event', 'chronotrack-live') : __('Add New Event', 'chronotrack-live'); ?></h1>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="chronotrack_save_event">
        <?php wp_nonce_field('chronotrack_save_event', 'chronotrack_event_nonce'); ?>

        <table class="form-table">

            <tr>
                <th scope="row">
                    <label for="event_id"><?php _e('ChronoTrack Event ID', 'chronotrack-live'); ?> *</label>
                </th>
                <td>
                    <input type="text"
                           id="event_id"
                           name="event_id"
                           value="<?php echo $is_edit ? esc_attr($event->event_id) : ''; ?>"
                           class="regular-text"
                           required
                           <?php echo $is_edit ? 'readonly' : ''; ?>>
                    <p class="description">
                        <?php _e('The event ID from ChronoTrack system (e.g., 89080)', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_name"><?php _e('Event Name', 'chronotrack-live'); ?> *</label>
                </th>
                <td>
                    <input type="text"
                           id="event_name"
                           name="event_name"
                           value="<?php echo $is_edit ? esc_attr($event->event_name) : ''; ?>"
                           class="regular-text"
                           required>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_date"><?php _e('Event Date', 'chronotrack-live'); ?> *</label>
                </th>
                <td>
                    <input type="datetime-local"
                           id="event_date"
                           name="event_date"
                           value="<?php echo $is_edit ? esc_attr(date('Y-m-d\TH:i', strtotime($event->event_date))) : ''; ?>"
                           required>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="location"><?php _e('Location', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="location"
                           name="location"
                           value="<?php echo $is_edit ? esc_attr($event->location ?? '') : ''; ?>"
                           class="regular-text">
                    <p class="description">
                        <?php _e('Event location/city (auto-filled from API)', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_status"><?php _e('Event Status', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <select id="event_status" name="event_status">
                        <option value="active" <?php echo ($is_edit && $event->event_status === 'active') ? 'selected' : ''; ?>>
                            <?php _e('Active (auto-refresh results)', 'chronotrack-live'); ?>
                        </option>
                        <option value="completed" <?php echo ($is_edit && $event->event_status === 'completed') ? 'selected' : ''; ?>>
                            <?php _e('Completed (archived)', 'chronotrack-live'); ?>
                        </option>
                        <option value="upcoming" <?php echo ($is_edit && $event->event_status === 'upcoming') ? 'selected' : ''; ?>>
                            <?php _e('Upcoming', 'chronotrack-live'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Active events will automatically refresh results. Set to Completed to archive.', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_logo"><?php _e('Event Logo', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <?php if ($is_edit && !empty($event->event_logo_url)): ?>
                        <div class="chronotrack-current-logo">
                            <img src="<?php echo esc_url($event->event_logo_url); ?>"
                                 style="max-width: 200px; max-height: 100px; margin-bottom: 10px;">
                            <input type="hidden" name="existing_event_logo" value="<?php echo esc_url($event->event_logo_url); ?>">
                        </div>
                    <?php endif; ?>
                    <input type="file" id="event_logo" name="event_logo" accept="image/*">
                    <p class="description">
                        <?php
                        $max_width = get_option('chronotrack_max_logo_width', 400);
                        $max_height = get_option('chronotrack_max_logo_height', 200);
                        printf(
                            __('Logo will be displayed with maximum size: %dpx × %dpx', 'chronotrack-live'),
                            $max_width,
                            $max_height
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="sponsor_logo"><?php _e('Sponsor Logo', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <?php if ($is_edit && !empty($event->sponsor_logo_url)): ?>
                        <div class="chronotrack-current-logo">
                            <img src="<?php echo esc_url($event->sponsor_logo_url); ?>"
                                 style="max-width: 200px; max-height: 100px; margin-bottom: 10px;">
                            <input type="hidden" name="existing_sponsor_logo" value="<?php echo esc_url($event->sponsor_logo_url); ?>">
                        </div>
                    <?php endif; ?>
                    <input type="file" id="sponsor_logo" name="sponsor_logo" accept="image/*">
                    <p class="description">
                        <?php _e('Optional sponsor logo to display alongside event logo', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e('Split Times Configuration', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <div id="split-times-config">
                        <?php
                        $split_config = $is_edit && !empty($event->split_times_config) ? $event->split_times_config : array();
                        if (empty($split_config)) {
                            $split_config = array(array('name' => '', 'show_in_main' => false));
                        }
                        foreach ($split_config as $index => $split):
                        ?>
                        <div class="split-time-row" style="margin-bottom: 10px;">
                            <input type="text"
                                   name="split_checkpoints[]"
                                   value="<?php echo esc_attr($split['name']); ?>"
                                   placeholder="<?php _e('Checkpoint name (e.g., T1, 10km, etc.)', 'chronotrack-live'); ?>"
                                   style="width: 300px;">
                            <label>
                                <input type="checkbox"
                                       name="split_show_main[<?php echo $index; ?>]"
                                       value="1"
                                       <?php checked($split['show_in_main'] ?? false); ?>>
                                <?php _e('Show in main results', 'chronotrack-live'); ?>
                            </label>
                            <button type="button" class="button remove-split"><?php _e('Remove', 'chronotrack-live'); ?></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-split" class="button">
                        <?php _e('Add Checkpoint', 'chronotrack-live'); ?>
                    </button>
                    <p class="description">
                        <?php _e('Configure checkpoints for split times. Check "Show in main results" to display in the main table (e.g., for triathlon transitions).', 'chronotrack-live'); ?>
                    </p>
                </td>
            </tr>

        </table>

        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php echo $is_edit ? __('Update Event', 'chronotrack-live') : __('Create Event', 'chronotrack-live'); ?>">
            <a href="<?php echo admin_url('admin.php?page=chronotrack-live'); ?>" class="button">
                <?php _e('Cancel', 'chronotrack-live'); ?>
            </a>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    let splitIndex = <?php echo count($split_config); ?>;

    $('#add-split').on('click', function() {
        const row = $('<div class="split-time-row" style="margin-bottom: 10px;"></div>');
        row.html(
            '<input type="text" name="split_checkpoints[]" placeholder="<?php _e('Checkpoint name', 'chronotrack-live'); ?>" style="width: 300px;"> ' +
            '<label><input type="checkbox" name="split_show_main[' + splitIndex + ']" value="1"> <?php _e('Show in main results', 'chronotrack-live'); ?></label> ' +
            '<button type="button" class="button remove-split"><?php _e('Remove', 'chronotrack-live'); ?></button>'
        );
        $('#split-times-config').append(row);
        splitIndex++;
    });

    $(document).on('click', '.remove-split', function() {
        $(this).closest('.split-time-row').remove();
    });
});
</script>
