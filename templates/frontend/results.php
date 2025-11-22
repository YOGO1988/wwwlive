<?php
/**
 * Frontend results display template
 */

if (!defined('ABSPATH')) {
    exit;
}

$max_logo_width = get_option('chronotrack_max_logo_width', 400);
$max_logo_height = get_option('chronotrack_max_logo_height', 200);
?>

<div class="chronotrack-results-container" data-event-id="<?php echo esc_attr($event->event_id); ?>">

    <!-- Event Header -->
    <div class="chronotrack-header">
        <div class="chronotrack-logos">
            <?php if (!empty($event->event_logo_url)): ?>
                <div class="chronotrack-event-logo">
                    <img src="<?php echo esc_url($event->event_logo_url); ?>"
                         alt="<?php echo esc_attr($event->event_name); ?>"
                         style="max-width: <?php echo $max_logo_width; ?>px; max-height: <?php echo $max_logo_height; ?>px;">
                </div>
            <?php endif; ?>

            <?php if (!empty($event->sponsor_logo_url)): ?>
                <div class="chronotrack-sponsor-logo">
                    <img src="<?php echo esc_url($event->sponsor_logo_url); ?>"
                         alt="<?php _e('Sponsor', 'chronotrack-live'); ?>"
                         style="max-width: <?php echo $max_logo_width; ?>px; max-height: <?php echo $max_logo_height; ?>px;">
                </div>
            <?php endif; ?>
        </div>

        <h1 class="chronotrack-event-name"><?php echo esc_html($event->event_name); ?></h1>
        <div class="chronotrack-event-date"><?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?></div>
    </div>

    <!-- View Toggle -->
    <div class="chronotrack-controls">
        <button class="chronotrack-view-toggle active" data-view="standings">
            <?php _e('Standings', 'chronotrack-live'); ?>
        </button>
        <button class="chronotrack-view-toggle" data-view="meta">
            <?php _e('Finish Line (META)', 'chronotrack-live'); ?>
        </button>
        <div class="chronotrack-last-update">
            <?php _e('Last update:', 'chronotrack-live'); ?> <span id="chronotrack-timestamp">--:--:--</span>
        </div>
    </div>

    <!-- Search/Filter -->
    <div class="chronotrack-filters">
        <input type="text"
               id="chronotrack-search"
               class="chronotrack-search"
               placeholder="<?php _e('Search by name, bib number...', 'chronotrack-live'); ?>">

        <select id="chronotrack-category-filter" class="chronotrack-filter">
            <option value=""><?php _e('All Categories', 'chronotrack-live'); ?></option>
        </select>

        <select id="chronotrack-gender-filter" class="chronotrack-filter">
            <option value=""><?php _e('All Genders', 'chronotrack-live'); ?></option>
            <option value="M"><?php _e('Men', 'chronotrack-live'); ?></option>
            <option value="F"><?php _e('Women', 'chronotrack-live'); ?></option>
        </select>
    </div>

    <!-- Loading Indicator -->
    <div class="chronotrack-loading" style="display: none;">
        <div class="chronotrack-spinner"></div>
        <p><?php _e('Loading results...', 'chronotrack-live'); ?></p>
    </div>

    <!-- Results Table - Standings View -->
    <div class="chronotrack-view chronotrack-view-standings active">
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table">
                <thead>
                    <tr>
                        <th class="col-position"><?php _e('Pos', 'chronotrack-live'); ?></th>
                        <th class="col-bib"><?php _e('Bib', 'chronotrack-live'); ?></th>
                        <th class="col-name"><?php _e('Name', 'chronotrack-live'); ?></th>
                        <th class="col-category"><?php _e('Category', 'chronotrack-live'); ?></th>
                        <th class="col-club"><?php _e('Club', 'chronotrack-live'); ?></th>
                        <th class="col-time"><?php _e('Time', 'chronotrack-live'); ?></th>
                        <?php if (!empty($event->split_times_config)): ?>
                            <?php foreach ($event->split_times_config as $split): ?>
                                <?php if ($split['show_in_main']): ?>
                                    <th class="col-split"><?php echo esc_html($split['name']); ?></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="chronotrack-results-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            <?php _e('No results available yet', 'chronotrack-live'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Results Table - META (Finish Line) View -->
    <div class="chronotrack-view chronotrack-view-meta">
        <div class="chronotrack-meta-info">
            <p><?php _e('Latest finishers - newest first', 'chronotrack-live'); ?></p>
        </div>
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table chronotrack-meta-table">
                <thead>
                    <tr>
                        <th class="col-finish-time"><?php _e('Finish Time', 'chronotrack-live'); ?></th>
                        <th class="col-bib"><?php _e('Bib', 'chronotrack-live'); ?></th>
                        <th class="col-name"><?php _e('Name', 'chronotrack-live'); ?></th>
                        <th class="col-category"><?php _e('Category', 'chronotrack-live'); ?></th>
                        <th class="col-club"><?php _e('Club', 'chronotrack-live'); ?></th>
                        <th class="col-time"><?php _e('Time', 'chronotrack-live'); ?></th>
                        <th class="col-position"><?php _e('Position', 'chronotrack-live'); ?></th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="chronotrack-meta-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            <?php _e('No finishers yet', 'chronotrack-live'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Participant Details Modal -->
    <div id="chronotrack-modal" class="chronotrack-modal" style="display: none;">
        <div class="chronotrack-modal-content">
            <span class="chronotrack-modal-close">&times;</span>
            <div id="chronotrack-modal-body">
                <!-- Details loaded via AJAX -->
            </div>
        </div>
    </div>

</div>
