<?php
/**
 * Admin events list template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('ChronoTrack Events', 'chronotrack-live'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=chronotrack-add-event'); ?>" class="page-title-action">
        <?php _e('Add New Event', 'chronotrack-live'); ?>
    </a>

    <?php if (isset($_GET['message'])): ?>
        <?php if ($_GET['message'] === 'saved'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Event saved successfully.', 'chronotrack-live'); ?></p>
            </div>
        <?php elseif ($_GET['message'] === 'deleted'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Event deleted successfully.', 'chronotrack-live'); ?></p>
            </div>
        <?php elseif ($_GET['message'] === 'results_fetched'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(__('Pobrano %d wyników z ChronoTrack API.', 'chronotrack-live'), isset($_GET['count']) ? intval($_GET['count']) : 0); ?></p>
            </div>
        <?php elseif ($_GET['message'] === 'no_results'): ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e('Brak wyników w ChronoTrack API dla tego wydarzenia.', 'chronotrack-live'); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Event Name', 'chronotrack-live'); ?></th>
                <th><?php _e('Event ID', 'chronotrack-live'); ?></th>
                <th><?php _e('Date', 'chronotrack-live'); ?></th>
                <th><?php _e('Status', 'chronotrack-live'); ?></th>
                <th><?php _e('Page', 'chronotrack-live'); ?></th>
                <th><?php _e('Actions', 'chronotrack-live'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($events)): ?>
                <tr>
                    <td colspan="6">
                        <?php _e('No events found.', 'chronotrack-live'); ?>
                        <a href="<?php echo admin_url('admin.php?page=chronotrack-add-event'); ?>">
                            <?php _e('Add your first event', 'chronotrack-live'); ?>
                        </a>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($event->event_name); ?></strong>
                        </td>
                        <td>
                            <code><?php echo esc_html($event->event_id); ?></code>
                        </td>
                        <td>
                            <?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?>
                        </td>
                        <td>
                            <span class="chronotrack-status chronotrack-status-<?php echo esc_attr($event->event_status); ?>">
                                <?php echo esc_html(ucfirst($event->event_status)); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($event->page_id): ?>
                                <a href="<?php echo get_permalink($event->page_id); ?>" target="_blank">
                                    <?php _e('View Page', 'chronotrack-live'); ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=chronotrack-add-event&event_id=' . urlencode($event->event_id)); ?>">
                                <?php _e('Edit', 'chronotrack-live'); ?>
                            </a> |
                            <a href="<?php echo admin_url('admin.php?page=chronotrack-columns&event_id=' . urlencode($event->event_id)); ?>">
                                <?php _e('Columns', 'chronotrack-live'); ?>
                            </a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=chronotrack_fetch_results&event_id=' . urlencode($event->event_id)), 'chronotrack_fetch_results', 'nonce'); ?>"
                               class="chronotrack-fetch-btn">
                                <?php _e('Pobierz wyniki', 'chronotrack-live'); ?>
                            </a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=chronotrack_delete_event&event_id=' . urlencode($event->event_id)), 'chronotrack_delete_event', 'nonce'); ?>"
                               onclick="return confirm('<?php _e('Are you sure you want to delete this event?', 'chronotrack-live'); ?>');">
                                <?php _e('Delete', 'chronotrack-live'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
