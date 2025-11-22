<?php
/**
 * AJAX handlers for ChronoTrack Live Results
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_Ajax {

    public function __construct() {
        // Public AJAX actions (accessible to logged-in and non-logged-in users)
        add_action('wp_ajax_chronotrack_get_results', array($this, 'get_results'));
        add_action('wp_ajax_nopriv_chronotrack_get_results', array($this, 'get_results'));

        add_action('wp_ajax_chronotrack_get_recent_finishers', array($this, 'get_recent_finishers'));
        add_action('wp_ajax_nopriv_chronotrack_get_recent_finishers', array($this, 'get_recent_finishers'));

        add_action('wp_ajax_chronotrack_get_participant_details', array($this, 'get_participant_details'));
        add_action('wp_ajax_nopriv_chronotrack_get_participant_details', array($this, 'get_participant_details'));

        add_action('wp_ajax_chronotrack_refresh_results', array($this, 'refresh_results'));
        add_action('wp_ajax_nopriv_chronotrack_refresh_results', array($this, 'refresh_results'));
    }

    /**
     * Get results for an event
     */
    public function get_results() {
        check_ajax_referer('chronotrack_nonce', 'nonce');

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($event_id)) {
            wp_send_json_error(array('message' => __('Event ID is required.', 'chronotrack-live')));
        }

        $db = chronotrack_live_results()->db;
        $results = $db->get_results($event_id);

        wp_send_json_success(array(
            'results' => $this->format_results($results),
            'count' => count($results),
            'timestamp' => current_time('timestamp'),
        ));
    }

    /**
     * Get recent finishers (META button)
     */
    public function get_recent_finishers() {
        check_ajax_referer('chronotrack_nonce', 'nonce');

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $limit = absint($_POST['limit'] ?? 50);

        if (empty($event_id)) {
            wp_send_json_error(array('message' => __('Event ID is required.', 'chronotrack-live')));
        }

        $db = chronotrack_live_results()->db;
        $results = $db->get_recent_finishers($event_id, $limit);

        wp_send_json_success(array(
            'results' => $this->format_results($results),
            'count' => count($results),
            'timestamp' => current_time('timestamp'),
        ));
    }

    /**
     * Get participant details
     */
    public function get_participant_details() {
        check_ajax_referer('chronotrack_nonce', 'nonce');

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $participant_id = sanitize_text_field($_POST['participant_id'] ?? '');

        if (empty($event_id) || empty($participant_id)) {
            wp_send_json_error(array('message' => __('Event ID and Participant ID are required.', 'chronotrack-live')));
        }

        $db = chronotrack_live_results()->db;
        $result = $db->get_participant_result($event_id, $participant_id);

        if (!$result) {
            wp_send_json_error(array('message' => __('Participant not found.', 'chronotrack-live')));
        }

        wp_send_json_success(array(
            'participant' => $this->format_participant_details($result),
        ));
    }

    /**
     * Refresh results from ChronoTrack API
     */
    public function refresh_results() {
        check_ajax_referer('chronotrack_nonce', 'nonce');

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($event_id)) {
            wp_send_json_error(array('message' => __('Event ID is required.', 'chronotrack-live')));
        }

        $api = chronotrack_live_results()->api;
        $results = $api->fetch_results($event_id);

        if (is_wp_error($results)) {
            wp_send_json_error(array('message' => $results->get_error_message()));
        }

        wp_send_json_success(array(
            'results' => $this->format_results($results),
            'count' => count($results),
            'timestamp' => current_time('timestamp'),
        ));
    }

    /**
     * Format results for JSON response
     */
    private function format_results($results) {
        $formatted = array();

        foreach ($results as $result) {
            $formatted[] = array(
                'id' => $result->id,
                'participant_id' => $result->participant_id,
                'bib_number' => $result->bib_number,
                'first_name' => $result->first_name,
                'last_name' => $result->last_name,
                'full_name' => $result->first_name . ' ' . $result->last_name,
                'age' => $result->age,
                'gender' => $result->gender,
                'city' => $result->city,
                'club' => $result->club,
                'category' => $result->category,
                'position' => $result->position,
                'category_position' => $result->category_position,
                'gender_position' => $result->gender_position,
                'finish_time' => $result->finish_time,
                'net_time' => $result->net_time,
                'split_times' => $result->split_times ?? array(),
                'finish_timestamp' => $result->finish_timestamp,
            );
        }

        return $formatted;
    }

    /**
     * Format participant details for JSON response
     */
    private function format_participant_details($result) {
        return array(
            'id' => $result->id,
            'participant_id' => $result->participant_id,
            'bib_number' => $result->bib_number,
            'first_name' => $result->first_name,
            'last_name' => $result->last_name,
            'full_name' => $result->first_name . ' ' . $result->last_name,
            'age' => $result->age,
            'gender' => $result->gender,
            'city' => $result->city,
            'club' => $result->club,
            'category' => $result->category,
            'position' => $result->position,
            'category_position' => $result->category_position,
            'gender_position' => $result->gender_position,
            'finish_time' => $result->finish_time,
            'net_time' => $result->net_time,
            'split_times' => $result->split_times ?? array(),
            'detailed_splits' => $result->detailed_splits ?? array(),
            'finish_timestamp' => $result->finish_timestamp,
            'raw_data' => $result->raw_data ?? array(),
        );
    }
}
