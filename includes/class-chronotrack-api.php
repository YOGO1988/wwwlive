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
        // Remove trailing slash if present (API doesn't like it with query params)
        $endpoint = rtrim($endpoint, '/');

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

        // Convert event_start_time to MySQL datetime format
        $event_date = '';
        if (!empty($event_data['event_start_time'])) {
            $start_time = $event_data['event_start_time'];

            // If it's a Unix timestamp (numeric)
            if (is_numeric($start_time)) {
                $event_date = date('Y-m-d H:i:s', intval($start_time));
            }
            // If it's already a datetime string
            else if (strtotime($start_time)) {
                $event_date = date('Y-m-d H:i:s', strtotime($start_time));
            }
        }

        return array(
            'event_id' => $event_data['event_id'] ?? '',
            'event_name' => $event_data['event_name'] ?? '',
            'event_date' => $event_date,
            'timezone' => $event_data['location_time_zone'] ?? '',
            'location' => trim(($event_data['location_city'] ?? '') . ', ' . ($event_data['location_country'] ?? ''), ', '),
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
     * Fetch participant entries from ChronoTrack API
     * This provides additional data like city, club, custom fields
     */
    public function fetch_entries($event_id) {
        error_log("ChronoTrack API: Fetching participant entries for event {$event_id}");

        $all_entries = array();
        $page = 1;
        $has_more_pages = true;

        while ($has_more_pages) {
            $params = array(
                'page' => $page,
                'size' => 50,
                'include_test_entries' => 'true',
                'elide_json' => 'false',
                'contact_details' => 'true',
                'include_all_fields' => 'true',
                'need_athlete_birthdate' => 'true',
            );

            $endpoint = "/api/event/{$event_id}/entry";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && (isset($response['event_entry']) || isset($response['entries']))) {
                $entries = $response['event_entry'] ?? $response['entries'] ?? array();

                if (!empty($entries)) {
                    error_log("ChronoTrack API: Page {$page}: found " . count($entries) . " entries");

                    foreach ($entries as $entry) {
                        $bib = $entry['entry_bib'] ?? '';
                        if (!empty($bib)) {
                            // Extract city
                            $city = $entry['location_city'] ?? $entry['athlete_city'] ?? '';

                            // Extract club from various possible fields
                            $club = '';
                            if (!empty($entry['club'])) {
                                $club = $entry['club'];
                            } elseif (!empty($entry['athlete_club'])) {
                                $club = $entry['athlete_club'];
                            } else {
                                // Check custom_element fields for club
                                foreach ($entry as $key => $value) {
                                    if (strpos($key, 'custom_element') === 0 && !empty($value)) {
                                        // Likely club field
                                        if (empty($club)) {
                                            $club = $value;
                                        }
                                    }
                                }
                            }

                            // Extract distance/race name
                            $distance = $entry['race_distance'] ?? $entry['race_name'] ?? $entry['reg_choice_name'] ?? '';

                            $all_entries[$bib] = array(
                                'city' => $city,
                                'club' => $club,
                                'athlete_city' => $city,
                                'athlete_club' => $club,
                                'location_city' => $entry['location_city'] ?? '',
                                'birthdate' => $entry['athlete_birthdate'] ?? $entry['reg_transaction_account_birthdate'] ?? '',
                                'distance' => $distance,
                                'race_name' => $distance,
                            );
                        }
                    }

                    // Check for more pages
                    if (isset($response['page']) && isset($response['page_count'])) {
                        $has_more_pages = intval($response['page']) < intval($response['page_count']);
                    } elseif (count($entries) >= 50) {
                        $has_more_pages = true;
                    } else {
                        $has_more_pages = false;
                    }

                    $page++;
                } else {
                    $has_more_pages = false;
                }
            } else {
                error_log('ChronoTrack API: No entries on this page');
                $has_more_pages = false;
            }
        }

        error_log("ChronoTrack API: Fetched entries for " . count($all_entries) . " participants");
        return $all_entries;
    }

    /**
     * Fetch results from ChronoTrack API with pagination
     */
    public function fetch_results($event_id) {
        error_log("ChronoTrack API: Fetching results for event {$event_id}");

        // First, fetch participant entries to get city and club data
        $entries_by_bib = $this->fetch_entries($event_id);

        $all_results_by_bib = array();
        $page = 1;
        $has_more_pages = true;

        // Fetch all pages of results
        while ($has_more_pages) {
            $params = array(
                'format' => 'json',
                'page' => $page,
                'per_page' => 100,
                'include_all_fields' => 'true',
                'need_athlete_birthdate' => 'true',
                'need_transaction_account' => 'true',
                'interval' => 'ALL',
            );

            $endpoint = "/api/event/{$event_id}/results";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
                error_log("ChronoTrack API: Page {$page}: found " . count($response['event_results']) . " records");

                // Debug first result to see ALL fields
                static $first_result_logged = false;
                if (!$first_result_logged && !empty($response['event_results'])) {
                    error_log("========== FIRST RAW RESULT FROM API ==========");
                    error_log(print_r($response['event_results'][0], true));
                    error_log("===============================================");
                    $first_result_logged = true;
                }

                // Process results from this page
                $skipped_empty_bib = 0;
                foreach ($response['event_results'] as $result) {
                    $bib = $result['results_bib'] ?? '';
                    if (empty($bib)) {
                        $skipped_empty_bib++;
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

                    // Determine if this is main result or split time
                    $is_main_result = false;

                    // Primary check: known main result interval names
                    if (in_array($interval_name, array('Full Course', 'Finish', '')) || empty($interval_name)) {
                        $is_main_result = true;
                    }
                    // Fallback: if we don't have a main result yet, accept first result for this BIB
                    else if ($all_results_by_bib[$bib]['main_result'] === null) {
                        $is_main_result = true;
                        error_log("FALLBACK: Using interval_name '{$interval_name}' as main result for BIB {$bib}");
                    }

                    if ($is_main_result) {
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

        // OPTION: Disable 3-endpoint merge temporarily for debugging
        // Set to false to use simple mode (like v3.x)
        $use_bracket_merge = false;  // DISABLED FOR TESTING

        $sex_results = array();
        $age_results = array();

        if ($use_bracket_merge) {
            // Fetch SEX bracket results (gender positions)
            error_log("========== FETCHING SEX BRACKET FOR EVENT {$event_id} ==========");
            $sex_results = $this->fetch_sex_bracket_results($event_id);
            error_log("SEX BRACKET COMPLETE: Got " . count($sex_results) . " gender positions");

            // Fetch AGE bracket results (category positions)
            error_log("========== FETCHING AGE BRACKET FOR EVENT {$event_id} ==========");
            $age_results = $this->fetch_age_bracket_results($event_id);
            error_log("AGE BRACKET COMPLETE: Got " . count($age_results) . " category positions");
        } else {
            error_log("⚠️ Bracket merge DISABLED - using simple mode");
        }

        // Process collected results
        error_log("ChronoTrack API: Processing results for " . count($all_results_by_bib) . " athletes");
        error_log("DEBUG: all_results_by_bib keys: " . count($all_results_by_bib));

        $processed_results = array();
        $skipped_count = 0;
        $first_bib_logged = false;

        foreach ($all_results_by_bib as $bib => $data) {
            if ($data['main_result'] === null) {
                error_log("ChronoTrack API: No main result for BIB {$bib}, skipping");
                $skipped_count++;
                continue;
            }

            $result = $data['main_result'];
            $entry = $entries_by_bib[$bib] ?? array();

            // Get gender and category positions from bracket results
            $sex_data = $sex_results[$bib] ?? array();
            $age_data = $age_results[$bib] ?? array();

            // Debug first BIB
            if (!$first_bib_logged) {
                error_log("========== MERGE DEBUG FOR BIB {$bib} ==========");
                error_log("SEX DATA: " . print_r($sex_data, true));
                error_log("AGE DATA: " . print_r($age_data, true));
                error_log("MAIN RESULT interval_name: " . ($result['results_interval_name'] ?? 'NULL'));
                $first_bib_logged = true;
            }

            $processed_result = $this->process_single_result($result, $data['split_times'], $entry, $sex_data, $age_data);
            if ($processed_result) {
                // Debug first processed result
                if (count($processed_results) === 0) {
                    error_log("FIRST PROCESSED RESULT:");
                    error_log("  BIB: " . $processed_result['bib_number']);
                    error_log("  Gender position: " . $processed_result['gender_position']);
                    error_log("  Category position: " . $processed_result['category_position']);
                }
                $processed_results[] = $processed_result;
            }
        }

        error_log("DEBUG: Skipped {$skipped_count} results (no main_result)");

        // Save to database
        error_log("========== SAVING TO DATABASE ==========");
        error_log("Processed results count: " . count($processed_results));

        if (!empty($processed_results)) {
            error_log("First 3 processed results:");
            foreach (array_slice($processed_results, 0, 3) as $idx => $res) {
                error_log("  [{$idx}] BIB: {$res['bib_number']}, Gender pos: {$res['gender_position']}, Cat pos: {$res['category_position']}");
            }

            $db = chronotrack_live_results()->db;
            error_log("Calling save_results() for event {$event_id}...");
            $saved_count = $db->save_results($event_id, $processed_results);
            error_log("ChronoTrack API: Saved {$saved_count} results to database");
        } else {
            error_log("⚠️ WARNING: processed_results is EMPTY! Nothing to save.");
        }

        return $processed_results;
    }

    /**
     * Process single result from API
     * Merges result data with entry data (for city, club, etc.) and bracket data
     */
    private function process_single_result($result, $split_times = array(), $entry = array(), $sex_data = array(), $age_data = array()) {
        // Sort split times by time (shortest first)
        usort($split_times, function($a, $b) {
            return $this->parse_time_to_seconds($a['formatted_time']) - $this->parse_time_to_seconds($b['formatted_time']);
        });

        $participant_id = $result['athlete_id'] ?? uniqid('participant_');

        // Extract birth year from birthdate (prefer entry data, fallback to result)
        $birth_year = '';
        if (!empty($entry['birthdate'])) {
            $birth_year = substr($entry['birthdate'], 0, 4);
        } elseif (!empty($result['results_birthdate'])) {
            $birth_year = substr($result['results_birthdate'], 0, 4);
        }

        // City - prefer entry data
        $city = $entry['city'] ?? $result['results_city'] ?? '';

        // Club - prefer entry data
        $club = $entry['club'] ?? $result['results_club'] ?? '';

        // Distance - prefer entry data
        $distance = $entry['distance'] ?? $result['results_race_name'] ?? $result['race_distance'] ?? '';

        return array(
            'participant_id' => $participant_id,
            'bib_number' => $result['results_bib'] ?? '',
            'first_name' => $result['results_first_name'] ?? '',
            'last_name' => $result['results_last_name'] ?? '',
            'full_name' => trim(($result['results_last_name'] ?? '') . ' ' . ($result['results_first_name'] ?? '')),
            'age' => $result['results_age'] ?? 0,
            'gender' => $result['results_sex'] ?? '',
            'city' => $city,
            'athlete_city' => $city,  // Alternative field name
            'location_city' => $city,  // Alternative field name
            'club' => $club,
            'athlete_club' => $club,  // Alternative field name
            'birth_year' => $birth_year,
            'birthdate' => $entry['birthdate'] ?? $result['results_birthdate'] ?? '',  // Alternative field name
            'distance' => $distance,
            'race_name' => $distance,  // Alternative field name
            'race_distance' => $distance,  // Alternative field name
            'category' => $age_data['category'] ?? $result['results_primary_bracket_name'] ?? '',
            'bracket_name' => $age_data['category'] ?? $result['results_primary_bracket_name'] ?? '',  // Alternative
            'results_primary_bracket_name' => $age_data['category'] ?? $result['results_primary_bracket_name'] ?? '',  // Alternative
            'position' => $result['results_rank'] ?? 0,
            'overall_place' => $result['results_rank'] ?? 0,  // Alternative
            'results_rank' => $result['results_rank'] ?? 0,  // Alternative
            'category_position' => $age_data['category_position'] ?? 0,
            'division_place' => $age_data['category_position'] ?? 0,  // Alternative
            'results_division_rank' => $age_data['category_position'] ?? 0,  // Alternative
            'gender_position' => $sex_data['gender_position'] ?? 0,
            'sex_place' => $sex_data['gender_position'] ?? 0,  // Alternative
            'results_sex_rank' => $sex_data['gender_position'] ?? 0,  // Alternative
            'finish_time' => $this->format_time($result['results_gun_time'] ?? ''),
            'gun_time' => $this->format_time($result['results_gun_time'] ?? ''),  // Alternative
            'results_gun_time' => $this->format_time($result['results_gun_time'] ?? ''),  // Alternative
            'finish_time_seconds' => $this->parse_time_to_seconds($result['results_gun_time'] ?? ''),
            'net_time' => $this->format_time($result['results_time'] ?? ''),
            'formatted_net_time' => $this->format_time($result['results_time'] ?? ''),  // Alternative
            'results_time' => $this->format_time($result['results_time'] ?? ''),  // Alternative
            'net_time_seconds' => $this->parse_time_to_seconds($result['results_time'] ?? ''),
            'pace' => $this->format_pace($result['results_pace'] ?? ''),
            'formatted_pace' => $this->format_pace($result['results_pace'] ?? ''),  // Alternative
            'split_times' => $split_times,
            'finish_timestamp' => current_time('mysql'),
            'status' => $result['results_status'] ?? 'OK',
        );
    }

    /**
     * Fetch SEX bracket results (gender positions)
     * Returns array indexed by BIB with gender_position
     */
    private function fetch_sex_bracket_results($event_id) {
        error_log("ChronoTrack API: Fetching SEX bracket results for event {$event_id}");

        $sex_results = array();
        $page = 1;
        $has_more_pages = true;
        $max_pages = 50; // Safety limit

        while ($has_more_pages && $page <= $max_pages) {
            $params = array(
                'format' => 'json',
                'page' => $page,
                'size' => 100,
                'bracket' => 'SEX',
            );

            $endpoint = "/api/event/{$event_id}/results";
            error_log("SEX BRACKET REQUEST: endpoint={$endpoint}, params=" . json_encode($params));
            $response = $this->make_api_request($endpoint, $params);
            error_log("SEX BRACKET RESPONSE: " . (is_array($response) ? 'array with ' . count($response) . ' keys' : 'NULL or error'));

            if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
                error_log("ChronoTrack API: SEX bracket page {$page}: found " . count($response['event_results']) . " records");

                foreach ($response['event_results'] as $result) {
                    $bib = $result['results_bib'] ?? '';
                    if (!empty($bib)) {
                        // In SEX bracket, results_rank is the gender position
                        $sex_results[$bib] = array(
                            'gender_position' => $result['results_rank'] ?? 0,
                            'gender' => $result['results_sex'] ?? '',
                        );
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
                $has_more_pages = false;
            }
        }

        error_log("ChronoTrack API: Fetched gender positions for " . count($sex_results) . " athletes");
        return $sex_results;
    }

    /**
     * Fetch AGE bracket results (category positions)
     * Returns array indexed by BIB with category_position
     */
    private function fetch_age_bracket_results($event_id) {
        error_log("ChronoTrack API: Fetching AGE bracket results for event {$event_id}");

        $age_results = array();
        $page = 1;
        $has_more_pages = true;
        $max_pages = 50; // Safety limit

        while ($has_more_pages && $page <= $max_pages) {
            $params = array(
                'format' => 'json',
                'page' => $page,
                'size' => 100,
                'bracket' => 'AGE',
            );

            $endpoint = "/api/event/{$event_id}/results";
            error_log("AGE BRACKET REQUEST: endpoint={$endpoint}, params=" . json_encode($params));
            $response = $this->make_api_request($endpoint, $params);
            error_log("AGE BRACKET RESPONSE: " . (is_array($response) ? 'array with ' . count($response) . ' keys' : 'NULL or error'));

            if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
                error_log("ChronoTrack API: AGE bracket page {$page}: found " . count($response['event_results']) . " records");

                foreach ($response['event_results'] as $result) {
                    $bib = $result['results_bib'] ?? '';
                    if (!empty($bib)) {
                        // In AGE bracket, results_rank is the category position
                        $age_results[$bib] = array(
                            'category_position' => $result['results_rank'] ?? 0,
                            'category' => $result['results_primary_bracket_name'] ?? '',
                        );
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
                $has_more_pages = false;
            }
        }

        error_log("ChronoTrack API: Fetched category positions for " . count($age_results) . " athletes");
        return $age_results;
    }

    /**
     * Format time from API (seconds to HH:MM:SS)
     * Rounds to full seconds (removes hundredths)
     */
    private function format_time($time_string) {
        if (empty($time_string) || $time_string === '-') {
            return '-';
        }

        // If already formatted (HH:MM:SS), check for hundredths
        if (strpos($time_string, ':') !== false) {
            // Remove hundredths if present (e.g., "01:23:45.67" → "01:23:45")
            $time_string = preg_replace('/\.\d+$/', '', $time_string);
            return $time_string;
        }

        // Convert seconds to HH:MM:SS, rounding to full seconds
        $seconds = round(floatval($time_string)); // Round to remove hundredths
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
