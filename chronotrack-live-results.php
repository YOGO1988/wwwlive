<?php
/**
 * Plugin Name: ChronoTrack Live Results
 * Plugin URI: https://yogoevents.pl
 * Description: Live race results from ChronoTrack with automatic page generation and multi-event support
 * Version: 4.0.22
 * Author: YOGO Events
 * Author URI: https://yogoevents.pl
 * Text Domain: chronotrack-live
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CHRONOTRACK_LIVE_VERSION', '4.0.22');
define('CHRONOTRACK_LIVE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHRONOTRACK_LIVE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHRONOTRACK_LIVE_PLUGIN_FILE', __FILE__);

// Include required files
require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-database.php';
require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-admin.php';
require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-frontend.php';
require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-ajax.php';
require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-api.php';

// Load PDF generator only if TCPDF library exists
if (file_exists(CHRONOTRACK_LIVE_PLUGIN_DIR . 'lib/tcpdf/tcpdf.php')) {
    require_once CHRONOTRACK_LIVE_PLUGIN_DIR . 'includes/class-chronotrack-pdf-generator.php';
}

/**
 * Main ChronoTrack Live Results Class
 */
class ChronoTrack_Live_Results {

    private static $instance = null;

    public $db;
    public $admin;
    public $frontend;
    public $ajax;
    public $api;
    public $pdf;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init();

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin
     */
    private function init() {
        // Initialize classes
        $this->db = new ChronoTrack_Database();
        $this->admin = new ChronoTrack_Admin();
        $this->frontend = new ChronoTrack_Frontend();
        $this->ajax = new ChronoTrack_Ajax();
        $this->api = new ChronoTrack_API();

        // Initialize PDF generator only if TCPDF is available
        if (class_exists('ChronoTrack_PDF_Generator')) {
            $this->pdf = new ChronoTrack_PDF_Generator();
        }

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only load on ChronoTrack pages
        if (!$this->frontend->is_chronotrack_page()) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'chronotrack-live',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/css/chronotrack-live.css',
            array(),
            CHRONOTRACK_LIVE_VERSION
        );

        // Enqueue country flags CSS
        wp_enqueue_style(
            'chronotrack-country-flags',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/css/country-flags-icons.css',
            array(),
            CHRONOTRACK_LIVE_VERSION
        );

        // Enqueue country flags helper (before main script)
        wp_enqueue_script(
            'chronotrack-country-flags',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/js/country-flags.js',
            array(),
            CHRONOTRACK_LIVE_VERSION,
            true
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'chronotrack-live',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/js/chronotrack-live.js',
            array('jquery', 'chronotrack-country-flags'),
            CHRONOTRACK_LIVE_VERSION,
            true
        );

        // Get event data for auto-refresh logic
        global $post;
        $event = null;
        $event_status = 'live'; // Default fallback
        $event_date = '';

        if ($post) {
            $db = chronotrack_live_results()->db;
            $event = $db->get_event_by_page($post->ID);
            if ($event) {
                $event_status = $event->event_status ?? 'live';
                $event_date = $event->event_date ?? '';
            }
        }

        // Localize script with data
        wp_localize_script('chronotrack-live', 'chronotrackData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chronotrack_nonce'),
            'adminNonce' => wp_create_nonce('chronotrack_admin_nonce'),
            'eventId' => get_the_ID(),
            'refreshInterval' => get_option('chronotrack_refresh_interval', 5000),
            'eventStatus' => $event_status,
            'eventDate' => $event_date,
            'wpTimezone' => wp_timezone_string(), // WordPress timezone setting
            'userCanGeneratePDF' => current_user_can('manage_options'), // Only admins can generate PDF
            'strings' => array(
                'loading' => __('Loading results...', 'chronotrack-live'),
                'error' => __('Error loading results', 'chronotrack-live'),
                'noResults' => __('No results available yet', 'chronotrack-live'),
                'meta' => __('Finish Line', 'chronotrack-live'),
                'details' => __('Details', 'chronotrack-live'),
            )
        ));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on ChronoTrack admin pages
        if (strpos($hook, 'chronotrack') === false) {
            return;
        }

        wp_enqueue_style(
            'chronotrack-admin',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/css/chronotrack-admin.css',
            array(),
            CHRONOTRACK_LIVE_VERSION
        );

        wp_enqueue_script(
            'chronotrack-admin',
            CHRONOTRACK_LIVE_PLUGIN_URL . 'assets/js/chronotrack-admin.js',
            array('jquery'),
            CHRONOTRACK_LIVE_VERSION,
            true
        );

        wp_localize_script('chronotrack-admin', 'chronotrackAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chronotrack_admin_nonce'),
        ));
    }

    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'chronotrack-live',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Activate plugin
     */
    public function activate() {
        $this->db->create_tables();
        flush_rewrite_rules();
    }

    /**
     * Deactivate plugin
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function chronotrack_live_results() {
    return ChronoTrack_Live_Results::instance();
}

// Start the plugin
chronotrack_live_results();
