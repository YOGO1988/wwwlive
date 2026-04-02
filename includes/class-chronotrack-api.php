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
        // CRITICAL: Handle timezone properly to avoid time shifts
        $event_date = '';
        $timezone = $event_data['location_time_zone'] ?? 'UTC';

        if (!empty($event_data['event_start_time'])) {
            $start_time = $event_data['event_start_time'];

            try {
                // If it's a Unix timestamp (numeric)
                if (is_numeric($start_time)) {
                    // Create DateTime in event's timezone
                    $dt = new DateTime('@' . intval($start_time));
                    $dt->setTimezone(new DateTimeZone($timezone));
                    $event_date = $dt->format('Y-m-d H:i:s');
                }
                // If it's already a datetime string
                else if (strtotime($start_time)) {
                    // Parse in event's timezone
                    $dt = new DateTime($start_time, new DateTimeZone($timezone));
                    $event_date = $dt->format('Y-m-d H:i:s');
                }
            } catch (Exception $e) {
                error_log("ChronoTrack: Timezone conversion error: " . $e->getMessage());
                // Fallback to old method if timezone fails
                if (is_numeric($start_time)) {
                    $event_date = date('Y-m-d H:i:s', intval($start_time));
                } else if (strtotime($start_time)) {
                    $event_date = date('Y-m-d H:i:s', strtotime($start_time));
                }
            }
        }

        // Fallback: try event_date if event_start_time failed
        if (empty($event_date) && !empty($event_data['event_date'])) {
            try {
                if (is_numeric($event_data['event_date'])) {
                    $dt = new DateTime('@' . intval($event_data['event_date']));
                    $dt->setTimezone(new DateTimeZone($timezone));
                    $event_date = $dt->format('Y-m-d H:i:s');
                } else if (strtotime($event_data['event_date'])) {
                    $dt = new DateTime($event_data['event_date'], new DateTimeZone($timezone));
                    $event_date = $dt->format('Y-m-d H:i:s');
                }
            } catch (Exception $e) {
                error_log("ChronoTrack: Timezone conversion error (fallback): " . $e->getMessage());
                if (is_numeric($event_data['event_date'])) {
                    $event_date = date('Y-m-d H:i:s', intval($event_data['event_date']));
                } else if (strtotime($event_data['event_date'])) {
                    $event_date = date('Y-m-d H:i:s', strtotime($event_data['event_date']));
                }
            }
        }

        // Clean location - remove comma and everything after it
        $location = $event_data['location_city'] ?? '';
        if (strpos($location, ',') !== false) {
            $location = trim(substr($location, 0, strpos($location, ',')));
        }

        return array(
            'event_id' => $event_data['event_id'] ?? '',
            'event_name' => $event_data['event_name'] ?? '',
            'event_date' => $event_date,
            'timezone' => $timezone,
            'location' => $location,
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
     * Fetch all brackets (categories) for an event
     */
    public function fetch_event_brackets($event_id) {
        error_log("ChronoTrack API: Fetching brackets for event {$event_id}");

        $all_brackets = array();
        $page = 1;
        $has_more_pages = true;

        while ($has_more_pages) {
            $params = array(
                'page' => $page,
                'size' => 50,
            );

            $endpoint = "/api/event/{$event_id}/bracket";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && isset($response['event_brackets']) && !empty($response['event_brackets'])) {
                error_log("ChronoTrack API: Brackets page {$page}: found " . count($response['event_brackets']) . " brackets");

                foreach ($response['event_brackets'] as $bracket) {
                    $bracket_name = $bracket['bracket_name'] ?? '';
                    if (!empty($bracket_name)) {
                        $all_brackets[] = array(
                            'name' => $bracket_name,
                            'type' => $bracket['bracket_type'] ?? '',
                            'id' => $bracket['bracket_id'] ?? '',
                        );
                    }
                }

                // Check for more pages
                if (isset($response['page']) && isset($response['page_count'])) {
                    $has_more_pages = intval($response['page']) < intval($response['page_count']);
                } elseif (count($response['event_brackets']) >= 50) {
                    $has_more_pages = true;
                } else {
                    $has_more_pages = false;
                }

                $page++;
            } else {
                $has_more_pages = false;
            }
        }

        error_log("ChronoTrack API: Total brackets found: " . count($all_brackets));
        return $all_brackets;
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
                            // Ignore yes/no question responses: Tak, Nie, Yes, No (ONLY if they are the complete answer)
                            $invalid_club_names = array('Tak', 'Nie', 'Yes', 'No');

                            $club = '';
                            if (!empty($entry['club']) && !in_array($entry['club'], $invalid_club_names, true)) {
                                $club = $entry['club'];
                            } elseif (!empty($entry['athlete_club']) && !in_array($entry['athlete_club'], $invalid_club_names, true)) {
                                $club = $entry['athlete_club'];
                            } else {
                                // Check custom_element fields for club (ignore yes/no responses)
                                foreach ($entry as $key => $value) {
                                    if (strpos($key, 'custom_element') === 0 && !empty($value) && !in_array($value, $invalid_club_names, true)) {
                                        // Likely club field - ignore yes/no question answers
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
                                // Country data - CRITICAL for nationality flags!
                                'country_name' => $entry['country_name'] ?? '',
                                'country' => $entry['country'] ?? '',
                                'nationality' => $entry['nationality'] ?? '',
                                'location_country' => $entry['location_country'] ?? '',
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
     * Fetch interval metadata from ChronoTrack API
     * This provides accurate distance data (interval_iv_distance_m) for pace calculation
     *
     * @param string $event_id ChronoTrack Event ID
     * @return array Interval metadata indexed by interval name
     */
    public function fetch_intervals_metadata($event_id) {
        $intervals_data = array();
        $page = 1;
        $has_more = true;

        error_log("ChronoTrack API: Fetching interval metadata for event {$event_id}");

        while ($has_more && $page <= 10) {  // Limit to 10 pages (500 results)
            $params = array(
                'format' => 'json',
                'page' => $page,
                'size' => 50,
            );

            $endpoint = "/api/event/{$event_id}/interval";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && isset($response['event_intervals']) && !empty($response['event_intervals'])) {
                error_log("ChronoTrack API: Interval metadata page {$page}: " . count($response['event_intervals']) . " intervals");

                foreach ($response['event_intervals'] as $interval) {
                    $interval_name = $interval['interval_iv_name'] ?? '';
                    $distance_m = intval($interval['interval_iv_distance_m'] ?? 0);

                    if (!empty($interval_name) && $distance_m > 0) {
                        $intervals_data[$interval_name] = array(
                            'distance_m' => $distance_m,
                            'distance_km' => $distance_m / 1000,
                        );
                    }
                }

                $page++;
            } else {
                $has_more = false;
            }
        }

        error_log("ChronoTrack API: Found " . count($intervals_data) . " intervals with distance data");
        return $intervals_data;
    }

    /**
     * Calculate pace (min/km) from time and distance
     *
     * @param string $time_string Time in format HH:MM:SS or MM:SS
     * @param float $distance_km Distance in kilometers
     * @return string Formatted pace (e.g., "5:30" for 5 min 30 sec per km)
     */
    private function calculate_pace($time_string, $distance_km) {
        if (empty($time_string) || empty($distance_km) || $distance_km <= 0) {
            return '-';
        }

        $seconds = $this->parse_time_to_seconds($time_string);
        if ($seconds === PHP_INT_MAX || $seconds <= 0) {
            return '-';
        }

        // Calculate pace in seconds per km
        $pace_seconds = $seconds / $distance_km;

        // Convert to min:sec format
        $pace_min = floor($pace_seconds / 60);
        $pace_sec = round($pace_seconds % 60);

        return sprintf('%d:%02d', $pace_min, $pace_sec);
    }

    /**
     * Fetch a single page of results from ChronoTrack API (lightweight, no database save)
     * Used for extracting split time intervals when configuring columns
     *
     * @param string $event_id ChronoTrack Event ID
     * @param int $page Page number (default 1)
     * @param int $size Results per page (default 100)
     * @return array Raw results array or empty array on error
     */
    public function fetch_results_page($event_id, $page = 1, $size = 100) {
        $params = array(
            'format' => 'json',
            'page' => $page,
            'size' => $size,
            'include_all_fields' => 'true',
            'interval' => 'ALL',
        );

        $endpoint = "/api/event/{$event_id}/results";
        $response = $this->make_api_request($endpoint, $params);

        if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
            // Group split times by bib number
            // Each row in API response is ONE interval for ONE athlete
            $results_by_bib = array();

            foreach ($response['event_results'] as $result) {
                $bib = $result['results_bib'] ?? '';
                if (empty($bib)) {
                    continue;
                }

                $interval_name = $result['results_interval_name'] ?? '';

                // Skip main result intervals - we only want split times
                if (in_array($interval_name, array('Full Course', 'Finish', '')) || empty($interval_name)) {
                    continue;
                }

                // This is a split time - add it to the bib's split times array
                if (!isset($results_by_bib[$bib])) {
                    $results_by_bib[$bib] = array(
                        'bib' => $bib,
                        'split_times' => array(),
                    );
                }

                // Extract distance from interval name
                $distance_meters = 0;
                if (preg_match('/(\d+)\s*m/', $interval_name, $matches)) {
                    $distance_meters = intval($matches[1]);
                } elseif (preg_match('/(\d+(?:\.\d+)?)\s*km/', $interval_name, $matches)) {
                    $distance_meters = floatval($matches[1]) * 1000;
                }

                $results_by_bib[$bib]['split_times'][] = array(
                    'interval_name' => $interval_name,
                    'formatted_time' => $this->format_time($result['results_time'] ?? ''),
                    'position' => intval($result['results_rank'] ?? 0),
                    'distance_km' => $distance_meters > 0 ? number_format($distance_meters / 1000, 1) . ' km' : '',
                );
            }

            return array_values($results_by_bib);
        }

        return array();
    }

    /**
     * Fetch results from ChronoTrack API with pagination
     *
     * @param string $event_id ChronoTrack Event ID
     * @param string $mode 'full' = fetch entries + results (first load or manual refresh)
     *                     'live' = only fetch results, use cached entries (10s auto-refresh)
     */
    public function fetch_results($event_id, $mode = 'full') {
        error_log("ChronoTrack API: Fetching results for event {$event_id} (mode: {$mode})");

        // Fetch interval metadata for accurate pace calculation
        $intervals_metadata = $this->fetch_intervals_metadata($event_id);

        // OPTIMIZATION: Skip expensive entries fetch for live updates
        $entries_by_bib = array();
        if ($mode === 'full') {
            // First load or manual refresh - fetch participant entries to get city and club data
            error_log("ChronoTrack API: Fetching entries (personal data: city, club, country)");
            $entries_by_bib = $this->fetch_entries($event_id);
        } else {
            // Live mode - use cached entries from database
            error_log("ChronoTrack API: LIVE MODE - Skipping entries fetch (using cached data)");
            $db = chronotrack_live_results()->db;
            $cached_results = $db->get_results($event_id);

            // Build entries cache from database
            foreach ($cached_results as $cached) {
                $bib = $cached->bib_number;
                if (!empty($bib)) {
                    $entries_by_bib[$bib] = array(
                        'city' => $cached->city ?? '',
                        'club' => $cached->club ?? '',
                        'athlete_city' => $cached->city ?? '',
                        'athlete_club' => $cached->club ?? '',
                        'location_city' => $cached->city ?? '',
                        'country_name' => $cached->country ?? '',
                        'country' => $cached->country ?? '',
                        'nationality' => $cached->nationality ?? '',
                    );
                }
            }
            error_log("ChronoTrack API: Loaded " . count($entries_by_bib) . " cached entries from database");
        }

        $all_results_by_bib = array();
        $page = 1;
        $has_more_pages = true;

        // Fetch all pages of results
        while ($has_more_pages) {
            $params = array(
                'format' => 'json',
                'page' => $page,
                'size' => 100,  // CHANGED from per_page to size - API requires 'size' parameter
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
                        // Split time - use interval metadata for accurate distance
                        $distance_meters = 0;
                        $distance_km_value = 0;

                        // Try to get distance from interval metadata first (most accurate)
                        if (isset($intervals_metadata[$interval_name])) {
                            $distance_meters = $intervals_metadata[$interval_name]['distance_m'];
                            $distance_km_value = $intervals_metadata[$interval_name]['distance_km'];
                            error_log("Using interval metadata for '{$interval_name}': {$distance_meters}m");
                        }
                        // Fallback: extract from interval name
                        else if (preg_match('/(\d+)\s*m/', $interval_name, $matches)) {
                            $distance_meters = intval($matches[1]);
                            $distance_km_value = $distance_meters / 1000;
                        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*km/', $interval_name, $matches)) {
                            $distance_meters = floatval($matches[1]) * 1000;
                            $distance_km_value = floatval($matches[1]);
                        }

                        // Format distance for display
                        $distance_km = '';
                        if ($distance_meters > 0) {
                            if ($distance_meters >= 1000) {
                                $km = $distance_meters / 1000;
                                $distance_km = ($km == intval($km)) ? intval($km) . ' km' : number_format($km, 1, '.', '') . ' km';
                            } else {
                                $distance_km = $distance_meters . ' m';
                            }
                        }

                        // Calculate pace using accurate distance from interval metadata
                        $calculated_pace = $this->calculate_pace($result['results_time'] ?? '', $distance_km_value);

                        $split_data = array(
                            'interval_name' => $interval_name,
                            'distance_km' => $distance_km,
                            'position' => $result['results_rank'] ?? 0,
                            'time' => $result['results_time'] ?? '',
                            'pace' => $calculated_pace,  // Use calculated pace instead of API pace
                            'formatted_time' => $this->format_time($result['results_time'] ?? ''),
                            'formatted_pace' => $calculated_pace,  // Already formatted by calculate_pace
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
                    $current_page = intval($response['page']);
                    $total_pages = intval($response['page_count']);
                    $has_more_pages = $current_page < $total_pages;
                    error_log("ChronoTrack API OPEN: Page {$current_page} of {$total_pages} (has_more: " . ($has_more_pages ? 'YES' : 'NO') . ")");
                } elseif (count($response['event_results']) >= 100) {
                    $has_more_pages = true;
                    error_log("ChronoTrack API OPEN: Got 100 results, assuming more pages exist");
                } else {
                    $has_more_pages = false;
                    error_log("ChronoTrack API OPEN: Got " . count($response['event_results']) . " results (< 100), this is the last page");
                }

                $page++;
            } else {
                error_log('ChronoTrack API: No results on this page or invalid response');
                $has_more_pages = false;
            }
        }

        // OPTION: Disable 3-endpoint merge temporarily for debugging
        // Set to false to use simple mode (like v3.x)
        $use_bracket_merge = true;  // ENABLED - fetch gender & category positions

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

            // Fetch ALL brackets (categories) and their results
            error_log("========== FETCHING ALL BRACKETS FOR EVENT {$event_id} ==========");
            $all_brackets = $this->fetch_event_brackets($event_id);
            error_log("BRACKETS COMPLETE: Found " . count($all_brackets) . " total brackets from /bracket endpoint");

            // CRITICAL FIX: If /bracket endpoint returns 0, extract brackets from results
            if (empty($all_brackets)) {
                error_log("WARNING: /bracket endpoint returned 0 brackets, extracting from results_primary_bracket_name");
                $unique_brackets = array();
                foreach ($all_results_by_bib as $bib => $data) {
                    if (isset($data['main_result']['results_primary_bracket_name'])) {
                        $bracket_name = $data['main_result']['results_primary_bracket_name'];
                        if (!empty($bracket_name) && !isset($unique_brackets[$bracket_name])) {
                            $unique_brackets[$bracket_name] = array(
                                'name' => $bracket_name,
                                'type' => 'PRIMARY',
                                'id' => ''
                            );
                        }
                    }
                }
                $all_brackets = array_values($unique_brackets);
                error_log("FALLBACK: Extracted " . count($all_brackets) . " unique brackets from results");
            }

            if (!empty($all_brackets)) {
                error_log("BRACKET NAMES: " . implode(', ', array_column($all_brackets, 'name')));
            }

            // Fetch results for each bracket to get category positions
            $bracket_results = array();  // bib => [bracket_name => position]
            foreach ($all_brackets as $bracket) {
                $bracket_name = $bracket['name'];
                error_log("Fetching results for bracket: {$bracket_name}");

                $bracket_positions = $this->fetch_bracket_results($event_id, $bracket_name);
                error_log("  -> Got " . count($bracket_positions) . " positions for bracket: {$bracket_name}");

                foreach ($bracket_positions as $bib => $position) {
                    if (!isset($bracket_results[$bib])) {
                        $bracket_results[$bib] = array();
                    }
                    $bracket_results[$bib][$bracket_name] = $position;
                }
            }
            error_log("BRACKET RESULTS COMPLETE: Got positions for " . count($bracket_results) . " athletes across all brackets");

            // Debug first athlete's bracket positions
            if (!empty($bracket_results)) {
                $first_bib = array_key_first($bracket_results);
                error_log("FIRST ATHLETE BIB {$first_bib} brackets: " . print_r($bracket_results[$first_bib], true));
            }
        } else {
            error_log("⚠️ Bracket merge DISABLED - using simple mode");
            $bracket_results = array();
        }

        // Process collected results
        error_log("ChronoTrack API: Processing results for " . count($all_results_by_bib) . " athletes");
        error_log("DEBUG: all_results_by_bib keys: " . count($all_results_by_bib));

        if (empty($all_results_by_bib)) {
            error_log("⚠️ CRITICAL: all_results_by_bib is EMPTY! No results to process!");
            return array();
        }

        $processed_results = array();
        $skipped_count = 0;
        $null_count = 0;
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
            $bracket_positions = $bracket_results[$bib] ?? array();

            // Debug first BIB
            if (!$first_bib_logged) {
                error_log("========== MERGE DEBUG FOR BIB {$bib} ==========");
                error_log("SEX DATA: " . print_r($sex_data, true));
                error_log("AGE DATA: " . print_r($age_data, true));
                error_log("BRACKET POSITIONS: " . print_r($bracket_positions, true));
                error_log("MAIN RESULT interval_name: " . ($result['results_interval_name'] ?? 'NULL'));
                error_log("MAIN RESULT results_bib: " . ($result['results_bib'] ?? 'NULL'));
                error_log("MAIN RESULT results_first_name: " . ($result['results_first_name'] ?? 'NULL'));
                $first_bib_logged = true;
            }

            $processed_result = $this->process_single_result($result, $data['split_times'], $entry, $sex_data, $age_data, $bracket_positions);
            if ($processed_result) {
                // Debug first processed result
                if (count($processed_results) === 0) {
                    error_log("FIRST PROCESSED RESULT:");
                    error_log("  BIB: " . $processed_result['bib_number']);
                    error_log("  Name: " . $processed_result['first_name'] . ' ' . $processed_result['last_name']);
                    error_log("  Position: " . $processed_result['position']);
                    error_log("  Gender position: " . $processed_result['gender_position']);
                    error_log("  Category position: " . $processed_result['category_position']);
                }
                $processed_results[] = $processed_result;
            } else {
                $null_count++;
                error_log("⚠️ process_single_result returned NULL/false for BIB {$bib}");
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
    private function process_single_result($result, $split_times = array(), $entry = array(), $sex_data = array(), $age_data = array(), $bracket_positions = array()) {
        // Sort split times by time (shortest first)
        usort($split_times, function($a, $b) {
            return $this->parse_time_to_seconds($a['formatted_time']) - $this->parse_time_to_seconds($b['formatted_time']);
        });

        // CRITICAL FIX: Use stable participant_id based on bib_number
        // This prevents "participant not found" errors after refresh
        // If athlete_id available, use it; otherwise use bib-based ID (stable across refreshes)
        $bib = $result['results_bib'] ?? '';
        if (!empty($result['athlete_id'])) {
            $participant_id = $result['athlete_id'];
        } elseif (!empty($bib)) {
            $participant_id = 'bib_' . $bib;  // Stable ID based on bib number
        } else {
            $participant_id = uniqid('participant_');  // Fallback for missing data
        }

        // Extract birth year from birthdate (prefer entry data, fallback to result)
        $birth_year = '';
        if (!empty($entry['birthdate'])) {
            $birth_year = substr($entry['birthdate'], 0, 4);
        } elseif (!empty($result['results_birthdate'])) {
            $birth_year = substr($result['results_birthdate'], 0, 4);
        }

        // City - prefer entry data
        $city = $entry['city'] ?? $result['results_city'] ?? '';

        // Country code → name mapping (used multiple times below)
        $country_code_map = array(
            'PL' => 'Poland',
            'DE' => 'Germany',
            'CZ' => 'Czech Republic',
            'SK' => 'Slovakia',
            'UA' => 'Ukraine',
            'BY' => 'Belarus',
            'LT' => 'Lithuania',
            'LV' => 'Latvia',
            'EE' => 'Estonia',
            'RU' => 'Russia',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'IE' => 'Ireland',
            'CA' => 'Canada',
            'US' => 'United States',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'JP' => 'Japan',
            'CN' => 'China',
            'KR' => 'South Korea',
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'MX' => 'Mexico',
            'ZA' => 'South Africa',
            'KE' => 'Kenya',
            'ET' => 'Ethiopia',
        );

        // Country - prefer entry data (try country_name first - it's the most reliable!)
        $country = $entry['country_name'] ?? $entry['country'] ?? $result['results_country'] ?? '';

        // Convert location_country code to name (PL → Poland)
        if (empty($country) && !empty($entry['location_country'])) {
            $location_country_code = $entry['location_country'];
            $country = $country_code_map[$location_country_code] ?? $location_country_code;
        }

        // If no country yet, try to extract from results_hometown (e.g., "Września, Poland")
        // ONLY if country is still empty!
        if (empty($country) && !empty($result['results_hometown'])) {
            $hometown_parts = explode(',', $result['results_hometown']);
            if (count($hometown_parts) > 1) {
                $last_part = trim($hometown_parts[count($hometown_parts) - 1]);
                // Only use if it looks like a country name (not a city)
                // Check if it's in our known countries or is already "Poland" etc.
                if (in_array($last_part, $country_code_map) || strlen($last_part) <= 2) {
                    // It's a code or known country
                    $country = $country_code_map[$last_part] ?? $last_part;
                } else {
                    // Likely a country name like "Poland"
                    $country = $last_part;
                }
            }
        }

        // If still no country, check results_country_code and convert to country name
        if (empty($country) && !empty($result['results_country_code'])) {
            $country_code = $result['results_country_code'];
            $country = $country_code_map[$country_code] ?? '';
        }

        // Nationality - prefer entry data
        $nationality = $entry['nationality'] ?? $result['results_nationality'] ?? $country;

        // DEBUG: Log all available country-related fields
        error_log("COUNTRY DEBUG for BIB {$result['results_bib']}: entry[country_name]=" . ($entry['country_name'] ?? 'NULL') .
                  ", entry[country]=" . ($entry['country'] ?? 'NULL') .
                  ", result[results_country]=" . ($result['results_country'] ?? 'NULL') .
                  ", result[results_country_code]=" . ($result['results_country_code'] ?? 'NULL') .
                  ", result[results_hometown]=" . ($result['results_hometown'] ?? 'NULL') .
                  ", entry[location_country]=" . ($entry['location_country'] ?? 'NULL') .
                  ", entry[nationality]=" . ($entry['nationality'] ?? 'NULL') .
                  ", result[results_nationality]=" . ($result['results_nationality'] ?? 'NULL') .
                  ", FINAL country={$country}, nationality={$nationality}");

        // SMART FIX: If country is still empty, try to detect from city or club
        if (empty($country) && !empty($city)) {
            $country = $this->detect_country_from_city($city);
            if (!empty($country) && empty($nationality)) {
                $nationality = $country; // Also set nationality if detected
            }
        }

        // Club - prefer entry data
        $club = $entry['club'] ?? $result['results_club'] ?? '';

        // Distance - prefer entry data
        $distance = $entry['distance'] ?? $result['results_race_name'] ?? $result['race_distance'] ?? '';

        // CRITICAL FIX: Get category position from bracket_positions if AGE bracket failed
        $category_position = $age_data['category_position'] ?? 0;
        $category_name = $age_data['category'] ?? $result['results_primary_bracket_name'] ?? '';

        // If AGE bracket didn't provide position, use bracket_positions as fallback
        if ($category_position == 0 && !empty($bracket_positions)) {
            // PRIORITY 1: If we have category_name (e.g., "M50"), look for matching bracket position
            if (!empty($category_name) && isset($bracket_positions[$category_name]) && $bracket_positions[$category_name] > 0) {
                $category_position = $bracket_positions[$category_name];
            } else {
                // PRIORITY 2: Use first bracket with position > 0 (but skip SEX bracket if possible)
                $fallback_position = 0;
                $fallback_name = '';

                foreach ($bracket_positions as $bracket_name => $position) {
                    if ($position > 0) {
                        // Skip SEX brackets (M, K, F) if we can find other brackets
                        if (!in_array($bracket_name, array('M', 'K', 'F', 'Male', 'Female'))) {
                            $category_position = $position;
                            if (empty($category_name)) {
                                $category_name = $bracket_name;
                            }
                            break;  // Found non-SEX bracket, use it
                        } else if ($fallback_position == 0) {
                            // Store SEX bracket as fallback
                            $fallback_position = $position;
                            $fallback_name = $bracket_name;
                        }
                    }
                }

                // If no non-SEX bracket found, use SEX bracket as last resort
                if ($category_position == 0 && $fallback_position > 0) {
                    $category_position = $fallback_position;
                    if (empty($category_name)) {
                        $category_name = $fallback_name;
                    }
                }
            }
        }

        return array(
            'participant_id' => $participant_id,
            'bib_number' => $result['results_bib'] ?? '',
            'first_name' => $result['results_first_name'] ?? '',
            'last_name' => $result['results_last_name'] ?? '',
            'full_name' => trim(($result['results_last_name'] ?? '') . ' ' . ($result['results_first_name'] ?? '')),
            'age' => $result['results_age'] ?? 0,
            'gender' => ($result['results_sex'] ?? '') === 'F' ? 'K' : ($result['results_sex'] ?? ''),
            'city' => $city,
            'athlete_city' => $city,  // Alternative field name
            'location_city' => $city,  // Alternative field name
            'country' => $country,
            'location_country' => $country,  // Alternative field name
            'athlete_country' => $country,  // Alternative field name
            'nationality' => $nationality,
            'athlete_nationality' => $nationality,  // Alternative field name
            'club' => $club,
            'athlete_club' => $club,  // Alternative field name
            'birth_year' => $birth_year,
            'birthdate' => $entry['birthdate'] ?? $result['results_birthdate'] ?? '',  // Alternative field name
            'distance' => $distance,
            'race_name' => $distance,  // Alternative field name
            'race_distance' => $distance,  // Alternative field name
            'category' => $category_name,
            'bracket_name' => $category_name,  // Alternative
            'results_primary_bracket_name' => $category_name,  // Alternative
            'position' => $result['results_rank'] ?? 0,
            'overall_place' => $result['results_rank'] ?? 0,  // Alternative
            'results_rank' => $result['results_rank'] ?? 0,  // Alternative
            'category_position' => $category_position,
            'division_place' => $category_position,  // Alternative
            'results_division_rank' => $category_position,  // Alternative
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
            'bracket_positions' => $bracket_positions,  // All bracket/category positions (bracket_name => position)
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
     * Fetch results for a specific bracket (category)
     * Returns: bib => position mapping
     */
    private function fetch_bracket_results($event_id, $bracket_name) {
        $bracket_results = array();
        $page = 1;
        $has_more_pages = true;

        while ($has_more_pages) {
            $params = array(
                'page' => $page,
                'size' => 100,
                'bracket' => $bracket_name,
            );

            $endpoint = "/api/event/{$event_id}/results";
            $response = $this->make_api_request($endpoint, $params);

            if ($response && isset($response['event_results']) && !empty($response['event_results'])) {
                foreach ($response['event_results'] as $result) {
                    $bib = $result['results_bib'] ?? '';
                    $position = $result['results_rank'] ?? 0;

                    // CRITICAL FIX: Store ALL bracket memberships, even when position = 0
                    // This allows showing informational categories (Policja, Straż) without rankings
                    if (!empty($bib)) {
                        // Only store if not already stored (first position wins)
                        if (!isset($bracket_results[$bib])) {
                            $bracket_results[$bib] = $position;  // Can be 0 for non-ranked categories
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
                $has_more_pages = false;
            }
        }

        return $bracket_results;
    }

    /**
     * Detect country from city name
     * Uses common Polish, German, Czech, etc. city names
     */
    private function detect_country_from_city($city) {
        if (empty($city)) {
            return '';
        }

        $city_lower = mb_strtolower($city, 'UTF-8');

        // Polish cities (most common)
        $polish_cities = array(
            'warszawa', 'kraków', 'krakow', 'łódź', 'lodz', 'wrocław', 'wroclaw',
            'poznań', 'poznan', 'gdańsk', 'gdansk', 'szczecin', 'bydgoszcz',
            'lublin', 'katowice', 'białystok', 'bialystok', 'gdynia', 'częstochowa',
            'czestochowa', 'radom', 'sosnowiec', 'toruń', 'torun', 'kielce', 'gliwice',
            'zabrze', 'bytom', 'olsztyn', 'bielsko-biała', 'bielsko-biala', 'rzeszów',
            'rzeszow', 'ruda śląska', 'ruda slaska', 'rybnik', 'tychy', 'dąbrowa górnicza',
            'dabrowa gornicza', 'płock', 'plock', 'elbląg', 'elblag', 'opole', 'gorzów',
            'gorzow', 'wałbrzych', 'walbrzych', 'zielona góra', 'zielona gora', 'tarnów',
            'tarnow', 'chorzów', 'chorzow', 'koszalin', 'legnica', 'grudziądz', 'grudziadz',
            'jaworzno', 'słupsk', 'slupsk', 'jastrzębie', 'jastrzebie', 'nowy sącz',
            'nowy sacz', 'jelenia góra', 'jelenia gora', 'konin', 'piotrków', 'piotrkow',
            'lubin', 'inowrocław', 'inowroclaw', 'ostrów', 'ostrow', 'suwałki', 'suwalki',
            'stargard', 'piła', 'pila', 'głogów', 'glogów', 'gniezno', 'zamość', 'zamosc',
            'pruszków', 'pruszkow', 'racibórz', 'raciborz', 'oświęcim', 'oswiecim',
            'świnoujście', 'swinoujscie', 'stalowa wola', 'mielec', 'kędzierzyn', 'kedzierzyn',
            'przelewice', // Event location
        );

        // German cities
        $german_cities = array('berlin', 'hamburg', 'münchen', 'munchen', 'köln', 'koln',
            'frankfurt', 'stuttgart', 'düsseldorf', 'dusseldorf', 'dortmund', 'essen', 'leipzig', 'bremen');

        // Czech cities
        $czech_cities = array('praha', 'prague', 'brno', 'ostrava', 'plzeň', 'plzen', 'liberec', 'olomouc');

        // Check for matches
        foreach ($polish_cities as $polish_city) {
            if (strpos($city_lower, $polish_city) !== false) {
                return 'Poland';
            }
        }

        foreach ($german_cities as $german_city) {
            if (strpos($city_lower, $german_city) !== false) {
                return 'Germany';
            }
        }

        foreach ($czech_cities as $czech_city) {
            if (strpos($city_lower, $czech_city) !== false) {
                return 'Czech Republic';
            }
        }

        // Default: empty (unknown country)
        return '';
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
