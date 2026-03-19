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
                    <?php if (!$is_edit): ?>
                        <button type="button" id="fetch-from-api" class="button" style="margin-left: 10px;">
                            <?php _e('Pobierz z API', 'chronotrack-live'); ?>
                        </button>
                        <span id="api-fetch-status" style="margin-left: 10px;"></span>
                    <?php endif; ?>
                    <p class="description">
                        <?php _e('Enter ChronoTrack Event ID (e.g., 89332) and click "Pobierz z API" to fetch event details.', 'chronotrack-live'); ?>
                    </p>

                    <!-- Hidden fields for API data -->
                    <input type="hidden" id="event_name" name="event_name" value="<?php echo $is_edit ? esc_attr($event->event_name) : ''; ?>">
                    <input type="hidden" id="event_date" name="event_date" value="<?php echo $is_edit ? esc_attr($event->event_date) : ''; ?>">
                    <input type="hidden" id="event_location" name="event_location" value="<?php echo $is_edit ? esc_attr($event->event_location ?? '') : ''; ?>">

                    <div id="event-info-preview" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1; display: none;">
                        <p style="margin: 0;"><strong><?php _e('Event Name:', 'chronotrack-live'); ?></strong> <span id="preview-name"></span></p>
                        <p style="margin: 5px 0;"><strong><?php _e('Event Date:', 'chronotrack-live'); ?></strong> <span id="preview-date"></span></p>
                        <p style="margin: 5px 0 0 0;"><strong><?php _e('Location:', 'chronotrack-live'); ?></strong> <span id="preview-location"></span></p>
                    </div>

                    <?php if ($is_edit): ?>
                        <div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                            <p style="margin: 0;"><strong><?php _e('Event Name:', 'chronotrack-live'); ?></strong> <?php echo esc_html($event->event_name); ?></p>
                            <p style="margin: 5px 0;"><strong><?php _e('Event Date:', 'chronotrack-live'); ?></strong> <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($event->event_date)); ?></p>
                            <p style="margin: 5px 0 0 0;"><strong><?php _e('Location:', 'chronotrack-live'); ?></strong> <?php echo esc_html($event->event_location ?? 'N/A'); ?></p>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="event_status"><?php _e('Status', 'chronotrack-live'); ?></label>
                </th>
                <td>
                    <select id="event_status" name="event_status">
                        <option value="upcoming" <?php echo ($is_edit && $event->event_status === 'upcoming') ? 'selected' : ''; ?>>
                            <?php _e('Nadchodzące (auto-refresh czeka na godzinę startu)', 'chronotrack-live'); ?>
                        </option>
                        <option value="live" <?php echo ($is_edit && ($event->event_status === 'live' || $event->event_status === 'active')) ? 'selected' : ''; ?>>
                            <?php _e('Trwające (auto-refresh działa teraz)', 'chronotrack-live'); ?>
                        </option>
                        <option value="completed" <?php echo ($is_edit && $event->event_status === 'completed') ? 'selected' : ''; ?>>
                            <?php _e('Zakończone (auto-refresh zatrzymany)', 'chronotrack-live'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Nadchodzące: auto-refresh startuje automatycznie o wybranej dacie/godzinie. Trwające: auto-refresh działa. Zakończone: auto-refresh zatrzymany.', 'chronotrack-live'); ?>
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
    // Fetch event info from API
    $('#fetch-from-api').on('click', function() {
        const eventId = $('#event_id').val().trim();

        if (!eventId) {
            alert('<?php _e('Please enter Event ID', 'chronotrack-live'); ?>');
            return;
        }

        const $button = $(this);
        const $status = $('#api-fetch-status');

        $button.prop('disabled', true).text('<?php _e('Fetching...', 'chronotrack-live'); ?>');
        $status.html('<span style="color: #999;">⏳ <?php _e('Connecting to ChronoTrack API...', 'chronotrack-live'); ?></span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chronotrack_fetch_event_info',
                event_id: eventId,
                nonce: '<?php echo wp_create_nonce('chronotrack_fetch_event_info'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;

                    // Fill hidden fields
                    $('#event_name').val(data.event_name);
                    $('#event_date').val(data.event_date);
                    $('#event_location').val(data.location);

                    // Show preview
                    $('#preview-name').text(data.event_name);
                    $('#preview-date').text(data.event_date_formatted);
                    $('#preview-location').text(data.location || 'N/A');
                    $('#event-info-preview').slideDown();

                    // Show split times if available
                    let statusMsg = '✓ <?php _e('Event data loaded successfully!', 'chronotrack-live'); ?>';
                    if (data.split_times && data.split_times.length > 0) {
                        statusMsg += '<br><strong>Znalezione punkty kontrolne:</strong> ' + data.split_times.join(', ');
                    }
                    $status.html('<span style="color: #46b450;">' + statusMsg + '</span>');
                } else {
                    alert('<?php _e('Error:', 'chronotrack-live'); ?> ' + response.data.message);
                    $status.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                alert('<?php _e('Connection error. Please try again.', 'chronotrack-live'); ?>');
                $status.html('<span style="color: #dc3232;">✗ <?php _e('Connection error', 'chronotrack-live'); ?></span>');
            },
            complete: function() {
                $button.prop('disabled', false).text('<?php _e('Pobierz z API', 'chronotrack-live'); ?>');
            }
        });
    });
});
</script>
