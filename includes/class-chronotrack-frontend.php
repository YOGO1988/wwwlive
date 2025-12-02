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
            /* Completely remove WordPress sidebar on ChronoTrack pages */
            body.chronotrack-page #secondary,
            body.chronotrack-page aside,
            body.chronotrack-page .sidebar,
            body.chronotrack-page .widget-area,
            body.chronotrack-page aside.sidebar,
            body.chronotrack-page #sidebar,
            body.chronotrack-page .secondary,
            body.chronotrack-page [id*="sidebar"],
            body.chronotrack-page [class*="sidebar"]:not(.chronotrack-distance-filters),
            body.chronotrack-page [class*="widget"]:not(.chronotrack-table-wrapper) {
                display: none !important;
                position: absolute !important;
                left: -9999px !important;
                width: 0 !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }

            /* Force full width layout - remove grid/flex containers */
            body.chronotrack-page .site-content,
            body.chronotrack-page .hfeed,
            body.chronotrack-page .site-main,
            body.chronotrack-page #content {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                grid-template-columns: none !important;
                grid-template-areas: none !important;
            }

            /* Make content full width - no flex basis */
            body.chronotrack-page #primary,
            body.chronotrack-page .site-main,
            body.chronotrack-page .content-area,
            body.chronotrack-page .entry-content,
            body.chronotrack-page article,
            body.chronotrack-page main {
                width: 100% !important;
                max-width: 100% !important;
                flex-basis: 100% !important;
                flex-grow: 1 !important;
                flex-shrink: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            /* Hide meta info */
            body.chronotrack-page .entry-meta,
            body.chronotrack-page .entry-footer,
            body.chronotrack-page .entry-header {
                display: none !important;
            }

            /* Full width container */
            body.chronotrack-page .site-content,
            body.chronotrack-page .hfeed {
                padding: 20px !important;
            }

            /* FORCE VISIBILITY - Block all overlays and loading screens */
            body.chronotrack-page .chronotrack-results-container {
                position: relative !important;
                z-index: 1 !important;
                background: #fff !important;
                visibility: visible !important;
                opacity: 1 !important;
            }

            /* Hide ALL overlay/loading elements from theme */
            body.chronotrack-page .et_pb_section_video_bg,
            body.chronotrack-page .et-pb-icon,
            body.chronotrack-page .et_pb_preload,
            body.chronotrack-page [class*="loading"],
            body.chronotrack-page [class*="overlay"]:not(.chronotrack-modal),
            body.chronotrack-page [id*="loading"]:not(.chronotrack-loading),
            body.chronotrack-page [id*="overlay"]:not(.chronotrack-modal),
            body.chronotrack-page::before,
            body.chronotrack-page::after {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                z-index: -1 !important;
            }

            /* Ensure page background is white */
            body.chronotrack-page {
                background: #fff !important;
            }

            /* Prevent Divi from hiding content */
            body.chronotrack-page .entry-content,
            body.chronotrack-page .et_pb_section,
            body.chronotrack-page #main-content,
            body.chronotrack-page #et-main-area {
                opacity: 1 !important;
                visibility: visible !important;
                display: block !important;
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
