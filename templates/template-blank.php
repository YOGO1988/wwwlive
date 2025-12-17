<?php
/**
 * Template Name: ChronoTrack Blank Template
 * Description: Pusty szablon bez header, footer, sidebar - tylko content
 */

if (!defined('ABSPATH')) {
    exit;
}
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
