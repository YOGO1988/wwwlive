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

        // Force disable sidebars at WordPress core level
        add_filter('is_active_sidebar', array($this, 'disable_sidebar'), 10, 2);
        add_filter('sidebars_widgets', array($this, 'remove_sidebar_widgets'));
        add_filter('theme_page_templates', array($this, 'add_full_width_template'));

        // Use blank template for ChronoTrack pages
        add_filter('template_include', array($this, 'use_blank_template'), 99);
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
            /* POPRAWKA: Całkowicie usuń menu, sidebar i niepotrzebne elementy na stronach ChronoTrack */

            /* Ukryj główne menu nawigacji WordPress */
            body.chronotrack-page .site-header,
            body.chronotrack-page header.site-header,
            body.chronotrack-page #masthead,
            body.chronotrack-page .main-navigation,
            body.chronotrack-page .site-navigation,
            body.chronotrack-page nav.primary-navigation,
            body.chronotrack-page .nav-menu,
            body.chronotrack-page .menu,
            body.chronotrack-page #site-navigation {
                display: none !important;
                visibility: hidden !important;
                position: absolute !important;
                left: -9999px !important;
                height: 0 !important;
                overflow: hidden !important;
            }

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

            /* Ukryj meta info, header i footer strony */
            body.chronotrack-page .entry-meta,
            body.chronotrack-page .entry-footer,
            body.chronotrack-page .entry-header,
            body.chronotrack-page .page-header,
            body.chronotrack-page .entry-title {
                display: none !important;
            }

            /* Ukryj stopkę i wszelkie elementy na górze */
            body.chronotrack-page .site-footer,
            body.chronotrack-page footer,
            body.chronotrack-page .footer {
                display: none !important;
            }

            /* Ustaw body na pełną szerokość bez marginesów */
            body.chronotrack-page {
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Zapewnij, że wrapper jest na pełną szerokość */
            body.chronotrack-page #page,
            body.chronotrack-page .site,
            body.chronotrack-page #wrapper {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Full width container */
            body.chronotrack-page .site-content,
            body.chronotrack-page .hfeed {
                padding: 20px !important;
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
            $classes[] = 'page-template-default';
        }
        return $classes;
    }

    /**
     * Disable sidebar on ChronoTrack pages at WordPress core level
     */
    public function disable_sidebar($is_active, $sidebar_id) {
        if ($this->is_chronotrack_page()) {
            // Disable ALL sidebars on ChronoTrack pages
            return false;
        }
        return $is_active;
    }

    /**
     * Remove all sidebar widgets on ChronoTrack pages
     */
    public function remove_sidebar_widgets($sidebars_widgets) {
        if ($this->is_chronotrack_page()) {
            // Return empty array for all sidebars
            return array('wp_inactive_widgets' => array());
        }
        return $sidebars_widgets;
    }

    /**
     * Add full-width template option
     */
    public function add_full_width_template($templates) {
        $templates['chronotrack-full-width.php'] = 'ChronoTrack Full Width';
        return $templates;
    }

    /**
     * Use blank template for ChronoTrack pages
     */
    public function use_blank_template($template) {
        if ($this->is_chronotrack_page()) {
            $blank_template = CHRONOTRACK_LIVE_PLUGIN_DIR . 'templates/template-blank.php';
            if (file_exists($blank_template)) {
                return $blank_template;
            }
        }
        return $template;
    }
}
