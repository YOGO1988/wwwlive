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
        add_action('wp_head', array($this, 'hide_sidebar_for_chronotrack'));
        add_filter('body_class', array($this, 'add_body_class'));
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

    /**
     * Hide sidebar on ChronoTrack pages
     */
    public function hide_sidebar_for_chronotrack() {
        if (!$this->is_chronotrack_page()) {
            return;
        }

        ?>
        <style type="text/css">
            /* Aggressively hide WordPress sidebar on ChronoTrack pages */
            .chronotrack-page #secondary,
            .chronotrack-page aside,
            .chronotrack-page .sidebar,
            .chronotrack-page .widget-area,
            .chronotrack-page aside.sidebar,
            .chronotrack-page #sidebar,
            .chronotrack-page .secondary,
            .chronotrack-page [id*="sidebar"],
            .chronotrack-page [class*="sidebar"],
            .chronotrack-page [class*="widget"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Make content full width */
            .chronotrack-page #primary,
            .chronotrack-page .site-main,
            .chronotrack-page .content-area,
            .chronotrack-page .entry-content,
            .chronotrack-page article,
            .chronotrack-page main {
                width: 100% !important;
                max-width: 100% !important;
                flex: 0 0 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            /* Hide meta info */
            .chronotrack-page .entry-meta,
            .chronotrack-page .entry-footer,
            .chronotrack-page .entry-header {
                display: none !important;
            }

            /* Full width container */
            .chronotrack-page .site-content,
            .chronotrack-page .hfeed {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto;
                padding: 20px;
            }

            /* Force single column layout */
            .chronotrack-page .site-content {
                display: block !important;
                grid-template-columns: 1fr !important;
            }
        </style>
        <?php
    }

    /**
     * Add body class for ChronoTrack pages
     */
    public function add_body_class($classes) {
        if ($this->is_chronotrack_page()) {
            $classes[] = 'chronotrack-page';
            $classes[] = 'page-template-full-width';
        }
        return $classes;
    }
}
