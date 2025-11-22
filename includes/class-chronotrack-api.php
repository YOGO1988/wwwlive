<?php
/**
 * ChronoTrack API integration
 * Based on 62v12.py implementation
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_API {

    private $api_base = 'https://api.chronotrack.com';
    private $client_id = '727dae7f';
    private $user_id = 'lukasz@yogoevents.pl';
    private $user_pass = 'f2b2f3082a9d2ae5091eb5920bee538dc01bb413';

    public function __construct() {
        // Schedule automatic refresh for active events
        add_action('chronotrack_auto_refresh', array($this, 'auto_refresh_active_events'));

        if (!wp_next_scheduled('chronotrack_auto_refresh')) {
            wp_schedule_event(time(), 'every_minute', 'chronotrack_auto_refresh');
        }
    }

    /**
     * Build API URL with authentication parameters
     */
    private function build_api_url($endpoint, $params = array()) {
        // Add trailing slash if not present
        if (substr($endpoint, -1) !== '/') {
            $endpoint .= '/';
        }

        // Authentication parameters
        $auth_params = array(
            'format' => 'json',
            'client_id' => $this->client_id,
            'user_id' => $this->user_id,
            'user_pass' => $this->user_pass,
        );

        // Merge with additional parameters
        $all_params = array_merge($auth_params, $params);

        // Build query string
        $query_string = http_build_query($all_params);

        return $this->api_base . $endpoint . '?' . $query_string;
    }

    /**
     * Make API request
     */
    private function make_api_request($endpoint, $params = array()) {
        $url = $this->build_api_url($endpoint, $params);

        error_log('ChronoTrack API Request: ' . $endpoint);

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            error_log('ChronoTrack API Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            error_log('ChronoTrack API: Invalid JSON response');
            return null;
        }

        return $data;
    }

    /**
     * Fetch event info from API
     */
    public function fetch_event_info($event_id) {
        $endpoint = "/api/event/{$event_id}";
        $response = $this->make_api_request($endpoint);

        if (!$response || !isset($response['event'])) {
            error_log('ChronoTrack API: No event data in response');
            return null;
        }

        $event_data = $response['event'];

        return array(
            'event_id' => $event_data['event_id'] ?? '',
            'event_name' => $event_data['event_name'] ?? '',
            'event_date' => $event_data['event_start_time'] ?? '',
            'timezone' => $event_data['location_time_zone'] ?? '',
            'location' => ($event_data['location_city'] ?? '') . ', ' . ($event_data['location_country'] ?? ''),
            'status' => ($event_data['event_is_published'] ?? '0') === '1' ? 'active' : 'inactive',
        );
    }

    /**
     * Fetch registration choices (distances) for event
     */
    public function fetch_reg_choices($event_id) {
        $endpoint = "/api/event/{$event_id}/reg-choice";
        $response = $this->make_api_request($endpoint, array('page' => 1, 'per_page' => 100));

        if (!$response || !isset($response['reg_choices'])) {
            return array();
        }

        $choices = array();
        foreach ($response['reg_choices'] as $choice) {
            $choices[] = array(
                'id' => $choice['reg_choice_id'] ?? '',
                'name' => $choice['reg_choice_name'] ?? '',
                'distance' => $choice['reg_choice_distance'] ?? '',
            );
        }

        return $choices;
    }

    /**
     * Fetch results from ChronoTrack API with pagination
     */
    public function fetch_results($event_id) {
        error_log("ChronoTrack API: Fetching results for event {$event_id}");

        $all_results_by_bib = array();
        $page = 1;
        $has_more_pages = true;

        // Fetch all pages of results
        while ($has_more_pages) {
            $params = array(
                'format' => 'json',
                'page' => $page,
                'per_page' => 100,
            );

            $endpoint = "/api/event/{$event_id}/results";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
                error_log("ChronoTrack API: Page {$page}: found " . count($response['event_results']) . " records");

                // Process results from this page
                foreach ($response['event_results'] as $result) {
                    $bib = $result['results_bib'] ?? '';
                    if (empty($bib)) {
                        continue;
                    }

                    // Initialize bib entry if not exists
                    if (!isset($all_results_by_bib[$bib])) {
                        $all_results_by_bib[$bib] = array(
                            'main_result' => null,
                            'split_times' => array(),
                        );
                    }

                    $interval_name = $result['results_interval_name'] ?? '';

                    // Check if this is main result or split time
                    if (in_array($interval_name, array('Full Course', 'Finish', '')) || empty($interval_name)) {
                        // Main result - only save if we don't have one yet
                        if ($all_results_by_bib[$bib]['main_result'] === null) {
                            $all_results_by_bib[$bib]['main_result'] = $result;
                        }
                    } else {
                        // Split time
                        $split_data = array(
                            'interval_name' => $interval_name,
                            'time' => $result['results_time'] ?? '',
                            'pace' => $result['results_pace'] ?? '',
                            'formatted_time' => $this->format_time($result['results_time'] ?? ''),
                            'formatted_pace' => $this->format_pace($result['results_pace'] ?? ''),
                        );

                        // Check if we already have this split for this bib
                        $existing = false;
                        foreach ($all_results_by_bib[$bib]['split_times'] as $existing_split) {
                            if ($existing_split['interval_name'] === $interval_name) {
                                $existing = true;
                                break;
                            }
                        }

                        if (!$existing) {
                            $all_results_by_bib[$bib]['split_times'][] = $split_data;
                        }
                    }
                }

                // Check for more pages
                if (isset($response['page']) && isset($response['page_count'])) {
                    $has_more_pages = intval($response['page']) < intval($response['page_count']);
                } elseif (count($response['event_results']) >= 100) {
                    $has_more_pages = true;
                } else {
                    $has_more_pages = false;
                }

                $page++;
            } else {
                error_log('ChronoTrack API: No results on this page or invalid response');
                $has_more_pages = false;
            }
        }

        // Process collected results
        error_log("ChronoTrack API: Processing results for " . count($all_results_by_bib) . " athletes");

        $processed_results = array();
        foreach ($all_results_by_bib as $bib => $data) {
            if ($data['main_result'] === null) {
                error_log("ChronoTrack API: No main result for BIB {$bib}, skipping");
                continue;
            }

            $result = $data['main_result'];
            $processed_result = $this->process_single_result($result, $data['split_times']);
            if ($processed_result) {
                $processed_results[] = $processed_result;
            }
        }

        // Save to database
        if (!empty($processed_results)) {
            $db = chronotrack_live_results()->db;
            $db->save_results($event_id, $processed_results);
            error_log("ChronoTrack API: Saved " . count($processed_results) . " results to database");
        }

        return $processed_results;
    }

    /**
     * Process single result from API
     */
    private function process_single_result($result, $split_times = array()) {
        // Sort split times by time (shortest first)
        usort($split_times, function($a, $b) {
            return $this->parse_time_to_seconds($a['formatted_time']) - $this->parse_time_to_seconds($b['formatted_time']);
        });

        $participant_id = $result['athlete_id'] ?? uniqid('participant_');

        return array(
            'participant_id' => $participant_id,
            'bib_number' => $result['results_bib'] ?? '',
            'first_name' => $result['results_first_name'] ?? '',
            'last_name' => $result['results_last_name'] ?? '',
            'full_name' => trim(($result['results_last_name'] ?? '') . ' ' . ($result['results_first_name'] ?? '')),
            'age' => $result['results_age'] ?? 0,
            'gender' => $result['results_sex'] ?? '',
            'city' => $result['results_city'] ?? '',
            'club' => $result['results_club'] ?? '',
            'category' => $result['results_primary_bracket_name'] ?? '',
            'position' => $result['results_rank'] ?? 0,
            'category_position' => $result['results_division_rank'] ?? 0,
            'gender_position' => $result['results_sex_rank'] ?? 0,
            'finish_time' => $this->format_time($result['results_gun_time'] ?? ''),
            'finish_time_seconds' => $this->parse_time_to_seconds($result['results_gun_time'] ?? ''),
            'net_time' => $this->format_time($result['results_time'] ?? ''),
            'net_time_seconds' => $this->parse_time_to_seconds($result['results_time'] ?? ''),
            'pace' => $this->format_pace($result['results_pace'] ?? ''),
            'split_times' => $split_times,
            'finish_timestamp' => current_time('mysql'),
            'status' => $result['results_status'] ?? 'OK',
        );
    }

    /**
     * Format time from API (seconds to HH:MM:SS)
     */
    private function format_time($time_string) {
        if (empty($time_string) || $time_string === '-') {
            return '-';
        }

        // If already formatted, return as is
        if (strpos($time_string, ':') !== false) {
            return $time_string;
        }

        // Convert seconds to HH:MM:SS
        $seconds = intval($time_string);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Format pace from API
     */
    private function format_pace($pace_string) {
        if (empty($pace_string) || $pace_string === '-') {
            return '-';
        }

        return $pace_string;
    }

    /**
     * Parse time string to seconds
     */
    private function parse_time_to_seconds($time_string) {
        if (empty($time_string) || $time_string === '-') {
            return PHP_INT_MAX; // For sorting purposes
        }

        if (strpos($time_string, ':') !== false) {
            $parts = explode(':', $time_string);
            if (count($parts) === 3) {
                // HH:MM:SS
                return intval($parts[0]) * 3600 + intval($parts[1]) * 60 + intval($parts[2]);
            } elseif (count($parts) === 2) {
                // MM:SS
                return intval($parts[0]) * 60 + intval($parts[1]);
            }
        }

        return intval($time_string);
    }

    /**
     * Convert time string to seconds (legacy method)
     */
    private function time_to_seconds($time_string) {
        return $this->parse_time_to_seconds($time_string);
    }

    /**
     * Auto-refresh active events
     */
    public function auto_refresh_active_events() {
        $db = chronotrack_live_results()->db;
        $events = $db->get_all_events('active');

        foreach ($events as $event) {
            error_log("ChronoTrack API: Auto-refreshing event {$event->event_id}");
            $this->fetch_results($event->event_id);
        }
    }

    /**
     * Test API connection
     */
    public function test_connection($event_id) {
        $endpoint = "/api/event/{$event_id}";
        $url = $this->build_api_url($endpoint);

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
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return array(
            'success' => $code === 200 && isset($data['event']),
            'message' => $code === 200 ? 'Connection successful' : 'Connection failed',
            'code' => $code,
            'event_name' => $data['event']['event_name'] ?? 'Unknown',
        );
    }
}
