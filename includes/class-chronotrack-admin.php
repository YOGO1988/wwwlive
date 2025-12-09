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
        add_action('admin_post_chronotrack_save_columns', array($this, 'save_columns'));
        add_action('admin_post_chronotrack_fetch_results', array($this, 'manual_fetch_results'));
        add_action('admin_post_chronotrack_clean_duplicates', array($this, 'clean_duplicates'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_chronotrack_fetch_event_info', array($this, 'ajax_fetch_event_info'));
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

        // Hidden submenu for columns management (accessed via event edit)
        add_submenu_page(
            null, // Hidden from menu
            __('Manage Columns', 'chronotrack-live'),
            __('Manage Columns', 'chronotrack-live'),
            'manage_options',
            'chronotrack-columns',
            array($this, 'columns_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'chronotrack') === false) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('chronotrack-admin', CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/css/admin.css', array(), CHRONOTRACK_LIVE_VERSION);
        wp_enqueue_script('chronotrack-admin', CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-sortable'), CHRONOTRACK_LIVE_VERSION, true);

        wp_localize_script('chronotrack-admin', 'chronotrackAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chronotrack_admin'),
        ));
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
     * Columns management page
     */
    public function columns_page() {
        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/admin/columns-manager.php';
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
            'event_location' => sanitize_text_field($_POST['event_location'] ?? ''),
            'event_logo_url' => $event_logo_url,
            'sponsor_logo_url' => $sponsor_logo_url,
            'event_status' => sanitize_text_field($_POST['event_status'] ?? 'active'),
            'split_times_config' => $split_times_config,
        );

        // Save event
        $event_db_id = $db->save_event($event_data);

        // Initialize default columns for new events
        $existing_columns = $db->get_event_columns($event_data['event_id'], false);
        if (empty($existing_columns)) {
            $db->initialize_default_columns($event_data['event_id']);
        }

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
        // Check if page already exists for this event
        global $wpdb;
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT page_id FROM {$wpdb->prefix}chronotrack_events WHERE event_id = %s",
            $event_id
        ));

        if ($event && $event->page_id) {
            // Check if the page still exists in WordPress
            $page = get_post($event->page_id);
            if ($page && $page->post_status !== 'trash') {
                // Update existing page title only (keep same slug!)
                wp_update_post(array(
                    'ID' => $event->page_id,
                    'post_title' => $event_name,
                    // DON'T update post_name - keep the same URL!
                ));
                return $event->page_id;
            }
        }

        // Check if page with this slug already exists (from previous event)
        $slug = sanitize_title($event_name . '-' . $event_id);
        $existing_page = get_page_by_path($slug, OBJECT, 'page');

        if ($existing_page) {
            // Reuse existing page
            wp_update_post(array(
                'ID' => $existing_page->ID,
                'post_title' => $event_name,
                'post_status' => 'publish',
            ));
            return $existing_page->ID;
        }

        // Create new page only if doesn't exist
        $page_data = array(
            'post_title' => $event_name,
            'post_name' => $slug,
            'post_content' => '', // Empty - results added automatically by the_content filter
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => get_current_user_id(),
        );

        $page_id = wp_insert_post($page_data);

        // Set blank/full-width template to hide sidebar
        // Try common template names - WordPress will use first available
        $templates_to_try = array(
            'elementor_canvas',           // Elementor Canvas (blank)
            'page-templates/blank.php',   // Common blank template
            'templates/blank.php',        // Alternative blank
            'template-blank.php',         // Alternative blank
            'page-templates/full-width.php', // Full width
            'templates/full-width.php',   // Alternative full width
            'template-fullwidth.php',     // Alternative full width
        );

        // Try to set a blank/full-width template if available
        foreach ($templates_to_try as $template) {
            $theme_templates = wp_get_theme()->get_page_templates();
            if (isset($theme_templates[$template]) || $template === 'elementor_canvas') {
                update_post_meta($page_id, '_wp_page_template', $template);
                error_log("ChronoTrack: Set page template to '{$template}' for page {$page_id}");
                break;
            }
        }

        // Also try to disable Elementor's header/footer if Elementor is active
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($page_id, '_elementor_page_assets_css', 'inline');
            update_post_meta($page_id, '_elementor_template_type', 'wp-page');
        }

        return $page_id;
    }

    /**
     * Save columns configuration
     */
    public function save_columns() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'chronotrack-live'));
        }

        check_admin_referer('chronotrack_save_columns', 'chronotrack_columns_nonce');

        $event_id = sanitize_text_field($_POST['event_id']);
        $db = chronotrack_live_results()->db;

        // Delete all existing columns for this event
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'chronotrack_columns', array('event_id' => $event_id));

        // Save new columns configuration
        if (!empty($_POST['columns'])) {
            foreach ($_POST['columns'] as $order => $column_data) {
                $db->save_column($event_id, array(
                    'column_id' => sanitize_text_field($column_data['id']),
                    'column_name' => sanitize_text_field($column_data['name']),
                    'column_description' => sanitize_text_field($column_data['description'] ?? ''),
                    'api_attributes' => array_map('sanitize_text_field', $column_data['api_attributes'] ?? array()),
                    'column_order' => absint($order),
                    'is_active' => 1,
                ));
            }
        }

        wp_redirect(add_query_arg(
            array(
                'page' => 'chronotrack-add-event',
                'event_id' => $event_id,
                'tab' => 'columns',
                'message' => 'columns_saved'
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Manual fetch results from API
     */
    public function manual_fetch_results() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'chronotrack-live'));
        }

        check_admin_referer('chronotrack_fetch_results', 'nonce');

        $event_id = sanitize_text_field($_GET['event_id']);
        $api = chronotrack_live_results()->api;

        // Fetch results from API
        $results = $api->fetch_results($event_id);

        // Check database to see what was actually saved
        $db = chronotrack_live_results()->db;
        $db_results = $db->get_results($event_id);
        $db_count = count($db_results);

        $message = !empty($results) ? 'results_fetched' : 'no_results';

        // Add debug info to message
        if (!empty($results) && $db_count === 0) {
            $message = urlencode("⚠️ PROBLEM: API zwróciło " . count($results) . " wyników, ale w bazie jest 0! Sprawdź format danych lub parametry API.");
        } else if (!empty($results) && $db_count > 0) {
            $message = urlencode("✅ Pobrano " . count($results) . " wyników z API i zapisano " . $db_count . " do bazy.");
        }

        wp_redirect(add_query_arg(
            array(
                'page' => 'chronotrack-live',
                'message' => $message,
                'count' => count($results),
                'db_count' => $db_count
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * AJAX: Fetch event info from API
     */
    public function ajax_fetch_event_info() {
        check_ajax_referer('chronotrack_fetch_event_info', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($event_id)) {
            wp_send_json_error(array('message' => 'Brak Event ID'));
            return;
        }

        $api = chronotrack_live_results()->api;
        $event_info = $api->fetch_event_info($event_id);

        if ($event_info) {
            // Format date for display
            $event_date_formatted = 'N/A';
            if (!empty($event_info['event_date'])) {
                $timestamp = is_numeric($event_info['event_date'])
                    ? $event_info['event_date']
                    : strtotime($event_info['event_date']);
                $event_date_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            }

            wp_send_json_success(array(
                'event_name' => $event_info['event_name'],
                'event_date' => $event_info['event_date'],
                'event_date_formatted' => $event_date_formatted,
                'location' => $event_info['location'],
                'status' => $event_info['status'],
            ));
        } else {
            wp_send_json_error(array('message' => 'Nie można pobrać danych wydarzenia z API. Sprawdź czy Event ID jest prawidłowy.'));
        }
    }

    /**
     * Clean duplicate results from database
     */
    public function clean_duplicates() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('chronotrack_clean_duplicates');

        $event_id = isset($_GET['event_id']) ? sanitize_text_field($_GET['event_id']) : null;

        $db = chronotrack_live_results()->db;
        $deleted = $db->clean_duplicate_results($event_id);

        // Try to add unique constraint if it doesn't exist
        $db->add_unique_constraint();

        $message = $deleted > 0
            ? sprintf(__('Usunięto %d zduplikowanych rekordów.', 'chronotrack-live'), $deleted)
            : __('Nie znaleziono duplikatów.', 'chronotrack-live');

        wp_redirect(add_query_arg(array(
            'page' => 'chronotrack-live',
            'message' => urlencode($message)
        ), admin_url('admin.php')));
        exit;
    }
}
