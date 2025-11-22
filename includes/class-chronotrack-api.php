<?php
/**
 * ChronoTrack API integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_API {

    private $api_base = 'https://api.chronotrack.com/api/v1/';

    public function __construct() {
        // Schedule automatic refresh for active events
        add_action('chronotrack_auto_refresh', array($this, 'auto_refresh_active_events'));

        if (!wp_next_scheduled('chronotrack_auto_refresh')) {
            wp_schedule_event(time(), 'every_minute', 'chronotrack_auto_refresh');
        }
    }

    /**
     * Fetch results from ChronoTrack API
     */
    public function fetch_results($event_id) {
        $url = $this->api_base . 'events/' . $event_id . '/results';

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            return new WP_Error('invalid_response', __('Invalid response from ChronoTrack API', 'chronotrack-live'));
        }

        // Process and save results
        $results = $this->process_results($event_id, $data);

        // Save to database
        $db = chronotrack_live_results()->db;
        $db->save_results($event_id, $results);

        return $db->get_results($event_id);
    }

    /**
     * Process raw API results
     */
    private function process_results($event_id, $data) {
        $results = array();

        // Assuming ChronoTrack API returns results in a standard format
        // This may need to be adjusted based on actual API response
        if (isset($data['results'])) {
            foreach ($data['results'] as $result) {
                $results[] = array(
                    'participant_id' => $result['id'] ?? uniqid('participant_'),
                    'bib_number' => $result['bib'] ?? '',
                    'first_name' => $result['firstName'] ?? '',
                    'last_name' => $result['lastName'] ?? '',
                    'age' => $result['age'] ?? 0,
                    'gender' => $result['gender'] ?? '',
                    'city' => $result['city'] ?? '',
                    'club' => $result['club'] ?? '',
                    'category' => $result['category'] ?? '',
                    'position' => $result['position'] ?? 0,
                    'category_position' => $result['categoryPosition'] ?? 0,
                    'gender_position' => $result['genderPosition'] ?? 0,
                    'finish_time' => $result['finishTime'] ?? '',
                    'finish_time_seconds' => $this->time_to_seconds($result['finishTime'] ?? ''),
                    'net_time' => $result['netTime'] ?? '',
                    'net_time_seconds' => $this->time_to_seconds($result['netTime'] ?? ''),
                    'split_times' => $result['splits'] ?? array(),
                    'finish_timestamp' => $result['finishTimestamp'] ?? current_time('mysql'),
                );
            }
        }

        return $results;
    }

    /**
     * Convert time string to seconds
     */
    private function time_to_seconds($time_string) {
        if (empty($time_string)) {
            return 0;
        }

        $parts = explode(':', $time_string);
        $seconds = 0;

        if (count($parts) === 3) {
            // HH:MM:SS
            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        } elseif (count($parts) === 2) {
            // MM:SS
            $seconds = ($parts[0] * 60) + $parts[1];
        }

        return $seconds;
    }

    /**
     * Auto-refresh active events
     */
    public function auto_refresh_active_events() {
        $db = chronotrack_live_results()->db;
        $events = $db->get_all_events('active');

        foreach ($events as $event) {
            $this->fetch_results($event->event_id);
        }
    }

    /**
     * Test API connection
     */
    public function test_connection($event_id) {
        $url = $this->api_base . 'events/' . $event_id;

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        return array(
            'success' => $code === 200,
            'message' => $code === 200 ? 'Connection successful' : 'Connection failed',
            'code' => $code,
        );
    }
}
