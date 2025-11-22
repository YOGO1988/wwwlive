<?php
/**
 * Database operations for ChronoTrack Live Results
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_Database {

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Events table
        $events_table = $wpdb->prefix . 'chronotrack_events';
        $events_sql = "CREATE TABLE IF NOT EXISTS $events_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_id varchar(255) NOT NULL,
            event_name varchar(255) NOT NULL,
            event_date datetime NOT NULL,
            event_logo_url text,
            sponsor_logo_url text,
            event_status varchar(50) DEFAULT 'active',
            page_id bigint(20),
            split_times_config text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY event_status (event_status)
        ) $charset_collate;";

        // Results cache table
        $results_table = $wpdb->prefix . 'chronotrack_results';
        $results_sql = "CREATE TABLE IF NOT EXISTS $results_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_id varchar(255) NOT NULL,
            participant_id varchar(255) NOT NULL,
            bib_number varchar(50),
            first_name varchar(255),
            last_name varchar(255),
            age int(11),
            gender varchar(10),
            city varchar(255),
            club varchar(255),
            category varchar(255),
            position int(11),
            category_position int(11),
            gender_position int(11),
            finish_time varchar(50),
            finish_time_seconds int(11),
            net_time varchar(50),
            net_time_seconds int(11),
            split_times text,
            finish_timestamp datetime,
            raw_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY participant_id (participant_id),
            KEY finish_timestamp (finish_timestamp),
            KEY position (position)
        ) $charset_collate;";

        // Split times table
        $splits_table = $wpdb->prefix . 'chronotrack_splits';
        $splits_sql = "CREATE TABLE IF NOT EXISTS $splits_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            result_id bigint(20) NOT NULL,
            event_id varchar(255) NOT NULL,
            participant_id varchar(255) NOT NULL,
            checkpoint_name varchar(255) NOT NULL,
            checkpoint_time varchar(50),
            checkpoint_time_seconds int(11),
            checkpoint_position int(11),
            segment_time varchar(50),
            segment_time_seconds int(11),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY result_id (result_id),
            KEY event_id (event_id),
            KEY participant_id (participant_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($events_sql);
        dbDelta($results_sql);
        dbDelta($splits_sql);
    }

    /**
     * Save or update event
     */
    public function save_event($event_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_events';

        $data = array(
            'event_id' => sanitize_text_field($event_data['event_id']),
            'event_name' => sanitize_text_field($event_data['event_name']),
            'event_date' => sanitize_text_field($event_data['event_date']),
            'event_logo_url' => esc_url_raw($event_data['event_logo_url'] ?? ''),
            'sponsor_logo_url' => esc_url_raw($event_data['sponsor_logo_url'] ?? ''),
            'event_status' => sanitize_text_field($event_data['event_status'] ?? 'active'),
            'page_id' => absint($event_data['page_id'] ?? 0),
            'split_times_config' => wp_json_encode($event_data['split_times_config'] ?? array()),
        );

        // Check if event exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE event_id = %s",
            $data['event_id']
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                array('event_id' => $data['event_id']),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'),
                array('%s')
            );
            return $existing->id;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Get event by event_id
     */
    public function get_event($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_events';

        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %s",
            $event_id
        ));

        if ($event && !empty($event->split_times_config)) {
            $event->split_times_config = json_decode($event->split_times_config, true);
        }

        return $event;
    }

    /**
     * Get event by page_id
     */
    public function get_event_by_page($page_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_events';

        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE page_id = %d",
            $page_id
        ));

        if ($event && !empty($event->split_times_config)) {
            $event->split_times_config = json_decode($event->split_times_config, true);
        }

        return $event;
    }

    /**
     * Get all events
     */
    public function get_all_events($status = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_events';

        if ($status) {
            $events = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE event_status = %s ORDER BY event_date DESC",
                $status
            ));
        } else {
            $events = $wpdb->get_results("SELECT * FROM $table ORDER BY event_date DESC");
        }

        foreach ($events as $event) {
            if (!empty($event->split_times_config)) {
                $event->split_times_config = json_decode($event->split_times_config, true);
            }
        }

        return $events;
    }

    /**
     * Save or update results
     */
    public function save_results($event_id, $results_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        foreach ($results_data as $result) {
            $data = array(
                'event_id' => sanitize_text_field($event_id),
                'participant_id' => sanitize_text_field($result['participant_id'] ?? ''),
                'bib_number' => sanitize_text_field($result['bib_number'] ?? ''),
                'first_name' => sanitize_text_field($result['first_name'] ?? ''),
                'last_name' => sanitize_text_field($result['last_name'] ?? ''),
                'age' => absint($result['age'] ?? 0),
                'gender' => sanitize_text_field($result['gender'] ?? ''),
                'city' => sanitize_text_field($result['city'] ?? ''),
                'club' => sanitize_text_field($result['club'] ?? ''),
                'category' => sanitize_text_field($result['category'] ?? ''),
                'position' => absint($result['position'] ?? 0),
                'category_position' => absint($result['category_position'] ?? 0),
                'gender_position' => absint($result['gender_position'] ?? 0),
                'finish_time' => sanitize_text_field($result['finish_time'] ?? ''),
                'finish_time_seconds' => absint($result['finish_time_seconds'] ?? 0),
                'net_time' => sanitize_text_field($result['net_time'] ?? ''),
                'net_time_seconds' => absint($result['net_time_seconds'] ?? 0),
                'split_times' => wp_json_encode($result['split_times'] ?? array()),
                'finish_timestamp' => $result['finish_timestamp'] ?? current_time('mysql'),
                'raw_data' => wp_json_encode($result),
            );

            // Check if result exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE event_id = %s AND participant_id = %s",
                $data['event_id'],
                $data['participant_id']
            ));

            if ($existing) {
                $wpdb->update(
                    $table,
                    $data,
                    array('id' => $existing->id)
                );
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }

    /**
     * Get results for an event
     */
    public function get_results($event_id, $order_by = 'position', $order = 'ASC', $limit = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %s ORDER BY $order_by $order",
            $event_id
        );

        if ($limit) {
            $query .= $wpdb->prepare(" LIMIT %d", $limit);
        }

        $results = $wpdb->get_results($query);

        foreach ($results as $result) {
            if (!empty($result->split_times)) {
                $result->split_times = json_decode($result->split_times, true);
            }
            if (!empty($result->raw_data)) {
                $result->raw_data = json_decode($result->raw_data, true);
            }
        }

        return $results;
    }

    /**
     * Get recent finishers (for META button)
     */
    public function get_recent_finishers($event_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %s ORDER BY finish_timestamp DESC LIMIT %d",
            $event_id,
            $limit
        ));

        foreach ($results as $result) {
            if (!empty($result->split_times)) {
                $result->split_times = json_decode($result->split_times, true);
            }
            if (!empty($result->raw_data)) {
                $result->raw_data = json_decode($result->raw_data, true);
            }
        }

        return $results;
    }

    /**
     * Get single participant result
     */
    public function get_participant_result($event_id, $participant_id) {
        global $wpdb;
        $results_table = $wpdb->prefix . 'chronotrack_results';
        $splits_table = $wpdb->prefix . 'chronotrack_splits';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $results_table WHERE event_id = %s AND participant_id = %s",
            $event_id,
            $participant_id
        ));

        if ($result) {
            if (!empty($result->split_times)) {
                $result->split_times = json_decode($result->split_times, true);
            }
            if (!empty($result->raw_data)) {
                $result->raw_data = json_decode($result->raw_data, true);
            }

            // Get detailed split times
            $result->detailed_splits = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $splits_table WHERE result_id = %d ORDER BY id ASC",
                $result->id
            ));
        }

        return $result;
    }

    /**
     * Delete event and its results
     */
    public function delete_event($event_id) {
        global $wpdb;

        $events_table = $wpdb->prefix . 'chronotrack_events';
        $results_table = $wpdb->prefix . 'chronotrack_results';
        $splits_table = $wpdb->prefix . 'chronotrack_splits';

        // Get result IDs
        $result_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $results_table WHERE event_id = %s",
            $event_id
        ));

        // Delete splits
        if (!empty($result_ids)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $splits_table WHERE result_id IN (" . implode(',', array_map('absint', $result_ids)) . ")"
            ));
        }

        // Delete results
        $wpdb->delete($results_table, array('event_id' => $event_id));

        // Delete event
        $wpdb->delete($events_table, array('event_id' => $event_id));
    }
}
