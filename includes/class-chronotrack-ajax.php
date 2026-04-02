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

        // PDF generation (accessible to all users, including guests)
        add_action('wp_ajax_chronotrack_generate_pdf', array($this, 'generate_pdf'));
        add_action('wp_ajax_nopriv_chronotrack_generate_pdf', array($this, 'generate_pdf'));
    }

    /**
     * Get results for an event
     * CRITICAL: No nonce verification - results are public data that should always be accessible
     * This prevents issues with expired nonces for completed events
     */
    public function get_results() {
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($event_id)) {
            wp_send_json_error(array('message' => 'Brak Event ID'));
            return;
        }

        try {
            $db = chronotrack_live_results()->db;
            $results = $db->get_results($event_id);
            $columns = $db->get_event_columns($event_id, true);
            $distances = $db->get_unique_distances($event_id);

            wp_send_json_success(array(
                'results' => $this->format_results($results),
                'columns' => $this->format_columns($columns),
                'distances' => $distances,
                'count' => count($results),
                'timestamp' => current_time('timestamp'),
                'event_id' => $event_id
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Błąd bazy danych: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Get recent finishers (META button)
     */
    public function get_recent_finishers() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chronotrack_nonce')) {
            wp_send_json_error(array(
                'message' => 'Nieprawidłowy nonce'
            ));
            return;
        }

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $limit = absint($_POST['limit'] ?? 50);

        if (empty($event_id)) {
            wp_send_json_error(array('message' => 'Brak Event ID'));
            return;
        }

        try {
            $db = chronotrack_live_results()->db;
            $results = $db->get_recent_finishers($event_id, $limit);
            $columns = $db->get_event_columns($event_id, true);

            wp_send_json_success(array(
                'results' => $this->format_results($results),
                'columns' => $this->format_columns($columns),
                'count' => count($results),
                'timestamp' => current_time('timestamp'),
                'event_id' => $event_id
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Błąd bazy danych: ' . $e->getMessage()
            ));
        }
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

        // Get event info for modal header
        $event = $db->get_event($event_id);

        wp_send_json_success(array(
            'participant' => $this->format_participant_details($result),
            'event' => array(
                'name' => $event->event_name ?? '',
                'date' => $event->event_date ?? '',
                'location' => $event->event_location ?? '',
            ),
        ));
    }

    /**
     * Refresh results from ChronoTrack API
     *
     * Supports two modes:
     * - 'full': Fetch entries + results (manual refresh, first load)
     * - 'live': Only fetch results, use cached entries (auto 10s refresh)
     */
    public function refresh_results() {
        check_ajax_referer('chronotrack_nonce', 'nonce');

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $mode = sanitize_text_field($_POST['mode'] ?? 'live'); // Default to live mode for auto-refresh

        if (empty($event_id)) {
            wp_send_json_error(array('message' => __('Event ID is required.', 'chronotrack-live')));
        }

        error_log("AJAX refresh_results: event_id={$event_id}, mode={$mode}");

        $api = chronotrack_live_results()->api;
        $results = $api->fetch_results($event_id, $mode);

        if (is_wp_error($results)) {
            wp_send_json_error(array('message' => $results->get_error_message()));
        }

        // CRITICAL FIX: Also fetch and include distances/columns after API refresh
        // Otherwise frontend can't render distance buttons
        $db = chronotrack_live_results()->db;
        $columns = $db->get_event_columns($event_id, true);
        $distances = $db->get_unique_distances($event_id);

        wp_send_json_success(array(
            'results' => $this->format_results($results),
            'columns' => $this->format_columns($columns),
            'distances' => $distances,
            'count' => count($results),
            'timestamp' => current_time('timestamp'),
        ));
    }

    /**
     * Format results for JSON response
     * Handles both objects (from database) and arrays (from API)
     */
    private function format_results($results) {
        $formatted = array();

        foreach ($results as $result) {
            // Handle both object and array formats
            $is_array = is_array($result);

            $formatted[] = array(
                'id' => $is_array ? ($result['id'] ?? 0) : $result->id,
                'participant_id' => $is_array ? ($result['participant_id'] ?? '') : $result->participant_id,
                'bib_number' => $is_array ? ($result['bib_number'] ?? '') : $result->bib_number,
                'first_name' => $is_array ? ($result['first_name'] ?? '') : $result->first_name,
                'last_name' => $is_array ? ($result['last_name'] ?? '') : $result->last_name,
                'full_name' => $is_array ?
                    ($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '') :
                    $result->first_name . ' ' . $result->last_name,
                'age' => $is_array ? ($result['age'] ?? 0) : $result->age,
                'birth_year' => $is_array ? ($result['birth_year'] ?? '') : ($result->birth_year ?? ''),
                'gender' => $is_array ? ($result['gender'] ?? '') : $result->gender,
                'city' => $is_array ? ($result['city'] ?? '') : $result->city,
                'country' => $is_array ? ($result['country'] ?? '') : ($result->country ?? ''),
                'nationality' => $is_array ? ($result['nationality'] ?? '') : ($result->nationality ?? ''),
                'club' => $is_array ? ($result['club'] ?? '') : $result->club,
                'distance' => $is_array ? ($result['distance'] ?? '') : ($result->distance ?? ''),
                'category' => $is_array ? ($result['category'] ?? '') : $result->category,
                'position' => $is_array ? ($result['position'] ?? 0) : $result->position,
                'category_position' => $is_array ? ($result['category_position'] ?? 0) : $result->category_position,
                'gender_position' => $is_array ? ($result['gender_position'] ?? 0) : $result->gender_position,
                'finish_time' => $is_array ? ($result['finish_time'] ?? '') : $result->finish_time,
                'net_time' => $is_array ? ($result['net_time'] ?? '') : $result->net_time,
                'split_times' => $is_array ? ($result['split_times'] ?? array()) : ($result->split_times ?? array()),
                'bracket_positions' => $is_array ? ($result['bracket_positions'] ?? array()) : ($result->bracket_positions ?? array()),
                'finish_timestamp' => $is_array ? ($result['finish_timestamp'] ?? '') : $result->finish_timestamp,
                // CRITICAL: Add country and nationality for flags!
                'country' => $is_array ? ($result['country'] ?? '') : ($result->country ?? ''),
                'nationality' => $is_array ? ($result['nationality'] ?? '') : ($result->nationality ?? ''),
            );
        }

        return $formatted;
    }

    /**
     * Format columns configuration for JSON response
     */
    private function format_columns($columns) {
        $formatted = array();

        foreach ($columns as $column) {
            $formatted[] = array(
                'id' => $column->column_id,
                'name' => $column->column_name,
                'description' => $column->column_description ?? '',
                'api_attributes' => $column->api_attributes ?? array(),
                'order' => $column->column_order,
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
            'birth_year' => $result->birth_year ?? '',
            'gender' => $result->gender,
            'city' => $result->city,
            'country' => $result->country ?? '',
            'nationality' => $result->nationality ?? '',
            'club' => $result->club,
            'distance' => $result->distance ?? '',
            'category' => $result->category,
            'position' => $result->position,
            'category_position' => $result->category_position,
            'gender_position' => $result->gender_position,
            'finish_time' => $result->finish_time,
            'net_time' => $result->net_time,
            'split_times' => $result->split_times ?? array(),
            'bracket_positions' => $result->bracket_positions ?? array(),
            'detailed_splits' => $result->detailed_splits ?? array(),
            'finish_timestamp' => $result->finish_timestamp,
            'raw_data' => $result->raw_data ?? array(),
            // CRITICAL: Add country and nationality for flags!
            'country' => $result->country ?? '',
            'nationality' => $result->nationality ?? '',
        );
    }

    /**
     * Generate PDF for specific distance (accessible to all users)
     */
    public function generate_pdf() {
        // Verify nonce (public nonce, not admin-only)
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chronotrack_nonce')) {
            wp_send_json_error(array('message' => __('Nieprawidłowy nonce.', 'chronotrack-live')));
            return;
        }

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $distance = sanitize_text_field($_POST['distance'] ?? '');

        if (empty($event_id) || empty($distance)) {
            wp_send_json_error(array('message' => __('Brak wymaganych parametrów.', 'chronotrack-live')));
            return;
        }

        // Check if PDF generator is available
        if (!isset(chronotrack_live_results()->pdf)) {
            wp_send_json_error(array('message' => __('PDF generator nie jest dostępny. Brakuje biblioteki TCPDF.', 'chronotrack-live')));
            return;
        }

        // Generate PDF
        $pdf = chronotrack_live_results()->pdf;

        try {
            $result = $pdf->generate_pdf($event_id, $distance);

            if (is_wp_error($result)) {
                error_log("PDF AJAX Error: " . $result->get_error_message());
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }
        } catch (Exception $e) {
            error_log("PDF AJAX Fatal Error: " . $e->getMessage());
            error_log("PDF AJAX Stack trace: " . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Wystąpił błąd podczas generowania PDF: ' . $e->getMessage()));
            return;
        }

        // Return download URL
        $upload_dir = wp_upload_dir();
        $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $result);

        wp_send_json_success(array(
            'message' => __('PDF wygenerowany pomyślnie.', 'chronotrack-live'),
            'download_url' => $pdf_url,
            'filename' => basename($result)
        ));
    }
}
