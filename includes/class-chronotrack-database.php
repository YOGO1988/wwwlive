<?php
/**
 * Database operations for ChronoTrack Live Results
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_Database {

    public function __construct() {
        // Run migrations on plugin load (version check prevents running every time)
        $this->maybe_run_migrations();
    }

    /**
     * Run database migrations if needed
     */
    private function maybe_run_migrations() {
        $db_version = get_option('chronotrack_db_version', '0');
        $current_version = '4.2.0';  // UPDATED: Increment version for birthdate migration

        if (version_compare($db_version, $current_version, '<')) {
            $this->run_migrations();
            update_option('chronotrack_db_version', $current_version);
        }
    }

    /**
     * Run database migrations
     */
    private function run_migrations() {
        global $wpdb;
        $events_table = $wpdb->prefix . 'chronotrack_events';
        $results_table = $wpdb->prefix . 'chronotrack_results';

        // Migration 1: Add event_location column to events table
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $events_table LIKE 'event_location'"
        );
        if (empty($column_exists)) {
            error_log("Migration: Adding event_location column to $events_table");
            $wpdb->query("ALTER TABLE $events_table ADD COLUMN event_location varchar(500) AFTER event_date");
        }

        // Migration 2: Add distance column to results table
        $distance_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $results_table LIKE 'distance'"
        );
        if (empty($distance_exists)) {
            error_log("Migration: Adding distance column to $results_table");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN distance varchar(255) AFTER club");
        }

        // Migration 3: Add bracket_positions column to results table
        $bracket_positions_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $results_table LIKE 'bracket_positions'"
        );
        if (empty($bracket_positions_exists)) {
            error_log("Migration: Adding bracket_positions column to $results_table");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN bracket_positions TEXT AFTER split_times");
        }

        // Migration 4: Add pace and distance columns to splits table
        $splits_table = $wpdb->prefix . 'chronotrack_splits';

        $segment_pace_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $splits_table LIKE 'segment_pace'"
        );
        if (empty($segment_pace_exists)) {
            error_log("Migration: Adding pace and distance columns to $splits_table");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN segment_pace varchar(50) AFTER segment_time_seconds");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN average_pace varchar(50) AFTER segment_pace");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN segment_distance_km decimal(10,3) AFTER average_pace");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN cumulative_distance_km decimal(10,3) AFTER segment_distance_km");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN pace_unit varchar(20) DEFAULT 'min/km' AFTER cumulative_distance_km");
            $wpdb->query("ALTER TABLE $splits_table ADD COLUMN show_pace tinyint(1) DEFAULT 1 AFTER pace_unit");
        }

        // Migration 5: Add country and nationality columns to results table
        $country_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $results_table LIKE 'country'"
        );
        if (empty($country_exists)) {
            error_log("Migration: Adding country and nationality columns to $results_table");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN country varchar(255) AFTER city");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN nationality varchar(255) AFTER country");
        }

        // Migration 6: Add birthdate and birth_year columns to results table
        $birthdate_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM $results_table LIKE 'birthdate'"
        );
        if (empty($birthdate_exists)) {
            error_log("Migration: Adding birthdate and birth_year columns to $results_table");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN birthdate varchar(50) AFTER club");
            $wpdb->query("ALTER TABLE $results_table ADD COLUMN birth_year varchar(10) AFTER birthdate");
        }
    }

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
            event_end_time datetime DEFAULT NULL,
            event_location varchar(500),
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
            country varchar(255),
            nationality varchar(255),
            club varchar(255),
            birthdate varchar(50),
            birth_year varchar(10),
            distance varchar(255),
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
            KEY position (position),
            KEY distance (distance),
            UNIQUE KEY event_bib (event_id, bib_number)
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
            segment_pace varchar(50),
            average_pace varchar(50),
            segment_distance_km decimal(10,3),
            cumulative_distance_km decimal(10,3),
            pace_unit varchar(20) DEFAULT 'min/km',
            show_pace tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY result_id (result_id),
            KEY event_id (event_id),
            KEY participant_id (participant_id)
        ) $charset_collate;";

        // Columns configuration table
        $columns_table = $wpdb->prefix . 'chronotrack_columns';
        $columns_sql = "CREATE TABLE IF NOT EXISTS $columns_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_id varchar(255) NOT NULL,
            column_id varchar(100) NOT NULL,
            column_name varchar(255) NOT NULL,
            column_description text,
            api_attributes text NOT NULL,
            column_order int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY is_active (is_active),
            UNIQUE KEY event_column (event_id, column_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($events_sql);
        dbDelta($results_sql);
        dbDelta($splits_sql);
        dbDelta($columns_sql);

        // Add country and nationality columns if they don't exist
        $wpdb->query("ALTER TABLE $results_table ADD COLUMN IF NOT EXISTS country VARCHAR(255) AFTER city");
        $wpdb->query("ALTER TABLE $results_table ADD COLUMN IF NOT EXISTS nationality VARCHAR(255) AFTER country");
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
            'event_location' => sanitize_text_field($event_data['event_location'] ?? ''),
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
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'),  // 9 format specifiers for 9 fields
                array('%s')
            );
            error_log("Event updated: {$data['event_id']} (DB ID: {$existing->id})");
            return $existing->id;
        } else {
            $result = $wpdb->insert(
                $table,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')  // 9 format specifiers for 9 fields
            );

            if ($result === false) {
                error_log("ERROR saving event: " . $wpdb->last_error);
                return false;
            }

            error_log("Event created: {$data['event_id']} (DB ID: {$wpdb->insert_id})");
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

        error_log("DB save_results() called: event_id={$event_id}, input count=" . count($results_data));

        // Deduplicate results by bib_number before saving
        $deduplicated = array();
        foreach ($results_data as $result) {
            $bib = $result['bib_number'] ?? '';
            if (empty($bib)) {
                error_log("  Skipping result without bib_number");
                continue; // Skip results without bib number
            }

            // Keep only the first occurrence of each bib number
            if (!isset($deduplicated[$bib])) {
                $deduplicated[$bib] = $result;
            }
        }

        error_log("After deduplication: " . count($deduplicated) . " unique BIBs");

        $saved_count = 0;
        $first_logged = false;
        foreach ($deduplicated as $result) {
            // Debug first result
            if (!$first_logged) {
                error_log("========== FIRST RESULT TO SAVE ==========");
                error_log("BIB: " . ($result['bib_number'] ?? 'NULL'));
                error_log("Name: " . ($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
                error_log("Position: " . ($result['position'] ?? 'NULL'));
                error_log("Finish time: " . ($result['finish_time'] ?? 'NULL'));
                error_log("==========================================");
                $first_logged = true;
            }

            $data = array(
                'event_id' => sanitize_text_field($event_id),
                'participant_id' => sanitize_text_field($result['participant_id'] ?? ''),
                'bib_number' => sanitize_text_field($result['bib_number'] ?? ''),
                'first_name' => sanitize_text_field($result['first_name'] ?? ''),
                'last_name' => sanitize_text_field($result['last_name'] ?? ''),
                'age' => absint($result['age'] ?? 0),
                'gender' => sanitize_text_field($result['gender'] ?? ''),
                'city' => sanitize_text_field($result['city'] ?? ''),
                'country' => sanitize_text_field($result['country'] ?? ''),
                'nationality' => sanitize_text_field($result['nationality'] ?? ''),
                'club' => sanitize_text_field($result['club'] ?? ''),
                'birthdate' => sanitize_text_field($result['birthdate'] ?? ''),  // NEW: Save birthdate
                'birth_year' => sanitize_text_field($result['birth_year'] ?? ''),  // NEW: Save birth_year
                'distance' => sanitize_text_field($result['distance'] ?? ''),
                'category' => sanitize_text_field($result['category'] ?? ''),
                'position' => absint($result['position'] ?? 0),
                'category_position' => absint($result['category_position'] ?? 0),
                'gender_position' => absint($result['gender_position'] ?? 0),
                'finish_time' => sanitize_text_field($result['finish_time'] ?? ''),
                'finish_time_seconds' => absint($result['finish_time_seconds'] ?? 0),
                'net_time' => sanitize_text_field($result['net_time'] ?? ''),
                'net_time_seconds' => absint($result['net_time_seconds'] ?? 0),
                'split_times' => wp_json_encode($result['split_times'] ?? array()),
                'bracket_positions' => wp_json_encode($result['bracket_positions'] ?? array()),
                'finish_timestamp' => $result['finish_timestamp'] ?? current_time('mysql'),
                'raw_data' => wp_json_encode($result),
            );

            // Check if result exists by bib_number (unique per event)
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE event_id = %s AND bib_number = %s",
                $data['event_id'],
                $data['bib_number']
            ));

            if ($existing) {
                // Update existing result - merge data instead of replacing
                $existing_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d",
                    $existing->id
                ), ARRAY_A);

                // Merge: use new data if available, keep old if new is empty
                foreach ($data as $key => $value) {
                    if (empty($value) && !empty($existing_data[$key])) {
                        $data[$key] = $existing_data[$key];
                    }
                }

                $update_result = $wpdb->update(
                    $table,
                    $data,
                    array('id' => $existing->id)
                );
                if ($update_result !== false) {
                    $saved_count++;
                    // Save split times to splits table
                    $this->save_split_times($existing->id, $event_id, $result);
                } else {
                    error_log("⚠️ UPDATE FAILED for BIB {$data['bib_number']}: " . $wpdb->last_error);
                }
            } else {
                $insert_result = $wpdb->insert($table, $data);
                if ($insert_result !== false) {
                    $result_id = $wpdb->insert_id;
                    $saved_count++;
                    // Save split times to splits table
                    $this->save_split_times($result_id, $event_id, $result);
                } else {
                    error_log("⚠️ INSERT FAILED for BIB {$data['bib_number']}: " . $wpdb->last_error);
                }
            }
        }

        error_log("DB save_results() complete: saved {$saved_count} records to database");
        return $saved_count;
    }

    /**
     * Save split times to splits table
     */
    private function save_split_times($result_id, $event_id, $result) {
        global $wpdb;
        $splits_table = $wpdb->prefix . 'chronotrack_splits';

        // Delete existing splits for this result
        $wpdb->delete($splits_table, array('result_id' => $result_id));

        // Insert new splits
        if (!empty($result['split_times']) && is_array($result['split_times'])) {
            foreach ($result['split_times'] as $split) {
                $wpdb->insert($splits_table, array(
                    'result_id' => $result_id,
                    'event_id' => sanitize_text_field($event_id),
                    'participant_id' => sanitize_text_field($result['participant_id'] ?? ''),
                    'checkpoint_name' => sanitize_text_field($split['interval_name'] ?? $split['checkpoint_name'] ?? ''),
                    'checkpoint_time' => sanitize_text_field($split['formatted_time'] ?? $split['checkpoint_time'] ?? ''),
                    'checkpoint_time_seconds' => absint($split['time_seconds'] ?? $split['checkpoint_time_seconds'] ?? 0),
                    'checkpoint_position' => absint($split['rank'] ?? $split['checkpoint_position'] ?? $split['position'] ?? 0),
                    'segment_time' => sanitize_text_field($split['segment_time'] ?? ''),
                    'segment_time_seconds' => absint($split['segment_time_seconds'] ?? 0),
                    'segment_pace' => sanitize_text_field($split['segment_pace'] ?? ''),
                    'average_pace' => sanitize_text_field($split['average_pace'] ?? ''),
                    'segment_distance_km' => floatval($split['segment_distance_km'] ?? 0),
                    'cumulative_distance_km' => floatval($split['cumulative_distance_km'] ?? ($split['distance_m'] ?? 0) / 1000),
                    'pace_unit' => sanitize_text_field($split['pace_unit'] ?? 'min/km'),
                    'show_pace' => absint($split['show_pace'] ?? 1),
                ));
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
            if (!empty($result->bracket_positions)) {
                $result->bracket_positions = json_decode($result->bracket_positions, true);
            }
            if (!empty($result->raw_data)) {
                $result->raw_data = json_decode($result->raw_data, true);
            }
        }

        return $results;
    }

    /**
     * Get unique distances for an event
     */
    public function get_unique_distances($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        $distances = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT distance FROM $table WHERE event_id = %s AND distance != ''",
            $event_id
        ));

        // Sort by numeric value (longest first)
        usort($distances, function($a, $b) {
            // Extract numbers from distance strings (e.g., "10km" -> 10, "5 km" -> 5)
            preg_match('/(\d+(?:\.\d+)?)/', $a, $matches_a);
            preg_match('/(\d+(?:\.\d+)?)/', $b, $matches_b);

            $num_a = isset($matches_a[1]) ? floatval($matches_a[1]) : 0;
            $num_b = isset($matches_b[1]) ? floatval($matches_b[1]) : 0;

            // Sort descending (longest first)
            return $num_b - $num_a;
        });

        return $distances;
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
            if (!empty($result->bracket_positions)) {
                $result->bracket_positions = json_decode($result->bracket_positions, true);
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
            if (!empty($result->bracket_positions)) {
                $result->bracket_positions = json_decode($result->bracket_positions, true);
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

    /**
     * Get default columns configuration
     */
    public function get_default_columns() {
        return array(
            array('id' => 'overall_place', 'name' => 'Mce Open', 'description' => 'Miejsce w klasyfikacji ogólnej', 'api_options' => array('overall_place', 'position', 'results_rank'), 'selected' => true),
            array('id' => 'entry_bib', 'name' => 'Nr Start', 'description' => 'Numer startowy', 'api_options' => array('entry_bib', 'bib_number', 'results_bib'), 'selected' => true),
            array('id' => 'full_name', 'name' => 'Nazwisko Imię', 'description' => 'Nazwisko i imię zawodnika', 'api_options' => array('full_name', 'athlete_last_name,athlete_first_name'), 'selected' => true),
            array('id' => 'city', 'name' => 'Miejscowość', 'description' => 'Miejscowość zawodnika', 'api_options' => array('city', 'results_city', 'athlete_city'), 'selected' => true),
            array('id' => 'country', 'name' => 'Kraj', 'description' => 'Kraj (miejscowość)', 'api_options' => array('country', 'athlete_country', 'location_country'), 'selected' => false),
            array('id' => 'nationality', 'name' => 'Narodowość', 'description' => 'Narodowość zawodnika', 'api_options' => array('nationality', 'athlete_nationality'), 'selected' => false),
            array('id' => 'club', 'name' => 'Klub', 'description' => 'Klub zawodnika', 'api_options' => array('club', 'results_club', 'athlete_club'), 'selected' => true),
            array('id' => 'birthdate', 'name' => 'Data Ur.', 'description' => 'Data urodzenia', 'api_options' => array('birthdate', 'athlete_birthdate'), 'selected' => false),
            array('id' => 'birth_year', 'name' => 'Rok Ur', 'description' => 'Rok urodzenia', 'api_options' => array('birth_year', 'birthdate', 'athlete_birthdate'), 'selected' => false),
            array('id' => 'distance', 'name' => 'Dystans', 'description' => 'Dystans (nazwa)', 'api_options' => array('distance', 'race_name', 'race_distance'), 'selected' => false),
            array('id' => 'category', 'name' => 'Kat', 'description' => 'Kategoria wiekowa', 'api_options' => array('category', 'bracket_name', 'results_primary_bracket_name'), 'selected' => true),
            array('id' => 'category_position', 'name' => 'Msc Kat', 'description' => 'Miejsce w kategorii wiekowej', 'api_options' => array('category_position', 'division_place', 'results_division_rank'), 'selected' => true),
            array('id' => 'gender_position', 'name' => 'Msc M/K', 'description' => 'Miejsce w kategorii płci', 'api_options' => array('gender_position', 'sex_place', 'results_sex_rank'), 'selected' => true),
            array('id' => 'finish_time', 'name' => 'Czas Brutto', 'description' => 'Czas od wystrzału', 'api_options' => array('finish_time', 'gun_time', 'formatted_gun_time'), 'selected' => true),
            array('id' => 'net_time', 'name' => 'Czas Netto', 'description' => 'Czas od przekroczenia linii startu', 'api_options' => array('net_time', 'formatted_net_time'), 'selected' => true),
            array('id' => 'pace', 'name' => 'Tempo Min/km', 'description' => 'Tempo biegu', 'api_options' => array('pace', 'formatted_pace', 'results_pace'), 'selected' => false),
            array('id' => 'age', 'name' => 'Wiek', 'description' => 'Wiek zawodnika', 'api_options' => array('age', 'entry_race_age', 'results_age'), 'selected' => false),
            array('id' => 'gender', 'name' => 'Płeć', 'description' => 'Płeć zawodnika', 'api_options' => array('gender', 'athlete_sex', 'results_sex'), 'selected' => false),
        );
    }

    /**
     * Initialize default columns for an event
     */
    public function initialize_default_columns($event_id) {
        $default_columns = $this->get_default_columns();
        $order = 0;

        foreach ($default_columns as $column) {
            if ($column['selected']) {
                $this->save_column($event_id, array(
                    'column_id' => $column['id'],
                    'column_name' => $column['name'],
                    'column_description' => $column['description'],
                    'api_attributes' => $column['api_options'],
                    'column_order' => $order++,
                    'is_active' => 1,
                ));
            }
        }
    }

    /**
     * Save or update column configuration
     */
    public function save_column($event_id, $column_data) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_columns';

        $data = array(
            'event_id' => sanitize_text_field($event_id),
            'column_id' => sanitize_text_field($column_data['column_id']),
            'column_name' => sanitize_text_field($column_data['column_name']),
            'column_description' => sanitize_text_field($column_data['column_description'] ?? ''),
            'api_attributes' => wp_json_encode($column_data['api_attributes'] ?? array()),
            'column_order' => absint($column_data['column_order'] ?? 0),
            'is_active' => absint($column_data['is_active'] ?? 1),
        );

        // Check if column exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE event_id = %s AND column_id = %s",
            $data['event_id'],
            $data['column_id']
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                array('id' => $existing->id)
            );
            return $existing->id;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Get columns for an event
     */
    public function get_event_columns($event_id, $active_only = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_columns';

        if ($active_only) {
            $columns = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %s AND is_active = 1 ORDER BY column_order ASC",
                $event_id
            ));
        } else {
            $columns = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %s ORDER BY column_order ASC",
                $event_id
            ));
        }

        foreach ($columns as $column) {
            if (!empty($column->api_attributes)) {
                $column->api_attributes = json_decode($column->api_attributes, true);
            }
        }

        return $columns;
    }

    /**
     * Delete column
     */
    public function delete_column($event_id, $column_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_columns';

        return $wpdb->delete($table, array(
            'event_id' => $event_id,
            'column_id' => $column_id,
        ));
    }

    /**
     * Update column order
     */
    public function update_column_order($event_id, $column_orders) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_columns';

        foreach ($column_orders as $column_id => $order) {
            $wpdb->update(
                $table,
                array('column_order' => absint($order)),
                array(
                    'event_id' => $event_id,
                    'column_id' => $column_id,
                )
            );
        }
    }

    /**
     * Clean duplicate results from database
     * Keeps only the most recent record for each (event_id, bib_number) pair
     */
    public function clean_duplicate_results($event_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        // Build query to find duplicates
        $where = $event_id ? $wpdb->prepare("WHERE event_id = %s", $event_id) : "";

        // Delete duplicates, keeping the one with highest ID (most recent)
        $query = "DELETE t1 FROM $table t1
                  INNER JOIN $table t2
                  WHERE t1.event_id = t2.event_id
                    AND t1.bib_number = t2.bib_number
                    AND t1.id < t2.id
                  $where";

        $deleted = $wpdb->query($query);

        return $deleted;
    }

    /**
     * Add unique constraint to results table if it doesn't exist
     */
    public function add_unique_constraint() {
        global $wpdb;
        $table = $wpdb->prefix . 'chronotrack_results';

        // Check if constraint already exists
        $constraint_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE table_schema = %s
             AND table_name = %s
             AND index_name = 'event_bib'",
            DB_NAME,
            $table
        ));

        if (!$constraint_exists) {
            // Clean duplicates first
            $this->clean_duplicate_results();

            // Add unique constraint
            $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY event_bib (event_id, bib_number)");

            return true;
        }

        return false;
    }
}
