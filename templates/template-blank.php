<?php
/**
 * Template Name: ChronoTrack Blank Template
 * Description: Pusty szablon bez header, footer, sidebar - tylko content
 */

if (!defined('ABSPATH')) {
    exit;
}

// Dequeue scripts from other plugins that might conflict
add_action('wp_enqueue_scripts', function() {
    // Dequeue plugin "x" scripts that cause jQuery errors on blank template
    wp_dequeue_script('x-frontend');
    wp_dequeue_script('yogo-x-frontend');
}, 100);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class('chronotrack-blank-template'); ?>>
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
    <?php wp_footer(); ?>
</body>
</html>
