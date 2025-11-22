<?php
/**
 * Admin functionality for ChronoTrack Live Results
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_chronotrack_save_event', array($this, 'save_event'));
        add_action('admin_post_chronotrack_delete_event', array($this, 'delete_event'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('ChronoTrack Live', 'chronotrack-live'),
            __('ChronoTrack Live', 'chronotrack-live'),
            'manage_options',
            'chronotrack-live',
            array($this, 'events_page'),
            'dashicons-clock',
            30
        );

        add_submenu_page(
            'chronotrack-live',
            __('Events', 'chronotrack-live'),
            __('Events', 'chronotrack-live'),
            'manage_options',
            'chronotrack-live',
            array($this, 'events_page')
        );

        add_submenu_page(
            'chronotrack-live',
            __('Add New Event', 'chronotrack-live'),
            __('Add New Event', 'chronotrack-live'),
            'manage_options',
            'chronotrack-add-event',
            array($this, 'add_event_page')
        );

        add_submenu_page(
            'chronotrack-live',
            __('Settings', 'chronotrack-live'),
            __('Settings', 'chronotrack-live'),
            'manage_options',
            'chronotrack-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Events list page
     */
    public function events_page() {
        $db = chronotrack_live_results()->db;
        $events = $db->get_all_events();

        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/admin/events-list.php';
    }

    /**
     * Add/Edit event page
     */
    public function add_event_page() {
        $event = null;

        if (isset($_GET['event_id'])) {
            $db = chronotrack_live_results()->db;
            $event = $db->get_event(sanitize_text_field($_GET['event_id']));
        }

        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/admin/event-form.php';
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if (isset($_POST['chronotrack_settings_nonce']) &&
            wp_verify_nonce($_POST['chronotrack_settings_nonce'], 'chronotrack_settings')) {

            update_option('chronotrack_refresh_interval', absint($_POST['refresh_interval'] ?? 5000));
            update_option('chronotrack_max_logo_width', absint($_POST['max_logo_width'] ?? 400));
            update_option('chronotrack_max_logo_height', absint($_POST['max_logo_height'] ?? 200));

            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'chronotrack-live') . '</p></div>';
        }

        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Save event
     */
    public function save_event() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'chronotrack-live'));
        }

        check_admin_referer('chronotrack_save_event', 'chronotrack_event_nonce');

        $db = chronotrack_live_results()->db;

        // Handle logo uploads
        $event_logo_url = '';
        $sponsor_logo_url = '';

        if (!empty($_FILES['event_logo']['name'])) {
            $event_logo = $this->handle_logo_upload($_FILES['event_logo']);
            if (!is_wp_error($event_logo)) {
                $event_logo_url = $event_logo;
            }
        } elseif (!empty($_POST['existing_event_logo'])) {
            $event_logo_url = esc_url_raw($_POST['existing_event_logo']);
        }

        if (!empty($_FILES['sponsor_logo']['name'])) {
            $sponsor_logo = $this->handle_logo_upload($_FILES['sponsor_logo']);
            if (!is_wp_error($sponsor_logo)) {
                $sponsor_logo_url = $sponsor_logo;
            }
        } elseif (!empty($_POST['existing_sponsor_logo'])) {
            $sponsor_logo_url = esc_url_raw($_POST['existing_sponsor_logo']);
        }

        // Parse split times configuration
        $split_times_config = array();
        if (!empty($_POST['split_checkpoints'])) {
            foreach ($_POST['split_checkpoints'] as $index => $checkpoint) {
                if (!empty($checkpoint)) {
                    $split_times_config[] = array(
                        'name' => sanitize_text_field($checkpoint),
                        'show_in_main' => isset($_POST['split_show_main'][$index]),
                    );
                }
            }
        }

        $event_data = array(
            'event_id' => sanitize_text_field($_POST['event_id']),
            'event_name' => sanitize_text_field($_POST['event_name']),
            'event_date' => sanitize_text_field($_POST['event_date']),
            'event_logo_url' => $event_logo_url,
            'sponsor_logo_url' => $sponsor_logo_url,
            'event_status' => sanitize_text_field($_POST['event_status'] ?? 'active'),
            'split_times_config' => $split_times_config,
        );

        // Save event
        $event_db_id = $db->save_event($event_data);

        // Create or update page
        $page_id = $this->create_event_page($event_data['event_id'], $event_data['event_name']);

        if ($page_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'chronotrack_events',
                array('page_id' => $page_id),
                array('id' => $event_db_id)
            );
        }

        wp_redirect(add_query_arg(
            array('page' => 'chronotrack-live', 'message' => 'saved'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Delete event
     */
    public function delete_event() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'chronotrack-live'));
        }

        check_admin_referer('chronotrack_delete_event', 'nonce');

        $event_id = sanitize_text_field($_GET['event_id']);
        $db = chronotrack_live_results()->db;

        // Get event to find page_id
        $event = $db->get_event($event_id);

        // Delete page if exists
        if ($event && $event->page_id) {
            wp_delete_post($event->page_id, true);
        }

        // Delete event
        $db->delete_event($event_id);

        wp_redirect(add_query_arg(
            array('page' => 'chronotrack-live', 'message' => 'deleted'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handle logo upload
     */
    private function handle_logo_upload($file) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $upload = wp_handle_upload($file, array('test_form' => false));

        if (isset($upload['error'])) {
            return new WP_Error('upload_error', $upload['error']);
        }

        return $upload['url'];
    }

    /**
     * Create event page
     */
    private function create_event_page($event_id, $event_name) {
        // Check if page already exists
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT page_id FROM {$wpdb->prefix}chronotrack_events WHERE event_id = %s",
            $event_id
        ));

        if ($event && $event->page_id) {
            // Update existing page
            wp_update_post(array(
                'ID' => $event->page_id,
                'post_title' => $event_name,
                'post_name' => sanitize_title($event_name . '-' . $event_id),
            ));
            return $event->page_id;
        }

        // Create new page
        $page_data = array(
            'post_title' => $event_name,
            'post_name' => sanitize_title($event_name . '-' . $event_id),
            'post_content' => '', // Empty - results added automatically by the_content filter
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => get_current_user_id(),
        );

        $page_id = wp_insert_post($page_data);

        return $page_id;
    }
}
