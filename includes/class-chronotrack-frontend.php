<?php
/**
 * Frontend display for ChronoTrack Live Results
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_Frontend {

    public function __construct() {
        add_shortcode('chronotrack_results', array($this, 'results_shortcode'));
        add_filter('the_content', array($this, 'add_results_to_content'));
    }

    /**
     * Check if current page is a ChronoTrack page
     */
    public function is_chronotrack_page() {
        if (is_admin()) {
            return false;
        }

        global $post;
        if (!$post) {
            return false;
        }

        $db = chronotrack_live_results()->db;
        $event = $db->get_event_by_page($post->ID);

        return $event !== null;
    }

    /**
     * Results shortcode
     */
    public function results_shortcode($atts) {
        $atts = shortcode_atts(array(
            'event_id' => '',
        ), $atts);

        if (empty($atts['event_id'])) {
            return '<p>' . __('Event ID is required.', 'chronotrack-live') . '</p>';
        }

        return $this->render_results($atts['event_id']);
    }

    /**
     * Add results to page content
     */
    public function add_results_to_content($content) {
        if (!is_singular('page')) {
            return $content;
        }

        global $post;
        $db = chronotrack_live_results()->db;
        $event = $db->get_event_by_page($post->ID);

        if (!$event) {
            return $content;
        }

        return $content . $this->render_results($event->event_id);
    }

    /**
     * Render results display
     */
    private function render_results($event_id) {
        $db = chronotrack_live_results()->db;
        $event = $db->get_event($event_id);

        if (!$event) {
            return '<p>' . __('Event not found.', 'chronotrack-live') . '</p>';
        }

        ob_start();
        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/frontend/results.php';
        return ob_get_clean();
    }

    /**
     * Render participant details modal
     */
    public function render_participant_details($event_id, $participant_id) {
        $db = chronotrack_live_results()->db;
        $result = $db->get_participant_result($event_id, $participant_id);

        if (!$result) {
            return '<p>' . __('Participant not found.', 'chronotrack-live') . '</p>';
        }

        ob_start();
        include CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/frontend/participant-details.php';
        return ob_get_clean();
    }
}
