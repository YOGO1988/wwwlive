<?php
/**
 * Frontend results display template
 */

if (!defined('ABSPATH')) {
    exit;
}

$max_logo_width = get_option('chronotrack_max_logo_width', 400);
$max_logo_height = get_option('chronotrack_max_logo_height', 200);

// Get dynamic columns configuration
$db = chronotrack_live_results()->db;
$columns = $db->get_event_columns($event->event_id, true);
?>

<div class="chronotrack-results-container" data-event-id="<?php echo esc_attr($event->event_id); ?>">

    <!-- Event Header -->
    <div class="chronotrack-header">
        <div class="chronotrack-header-flex">
            <div class="chronotrack-event-info">
                <h1 class="chronotrack-event-name"><?php echo esc_html($event->event_name); ?></h1>
                <div class="chronotrack-event-date">
                    <?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?>
                    <?php if (!empty($event->event_location)): ?>
                        · <?php echo esc_html($event->event_location); ?>
                    <?php endif; ?>
                </div>
            </div>

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
        </div>
    </div>

    <!-- View Toggle -->
    <div class="chronotrack-controls">
        <!-- Removed META view - only show standings -->

        <div class="chronotrack-live-wrapper">
            <div class="chronotrack-live-indicator">
                <span class="chronotrack-live-text">Na żywo</span>
            </div>
            <div class="chronotrack-last-update">
                <span id="chronotrack-timestamp"></span>
            </div>

            <!-- Participant Statistics - moved here under NA ŻYWO -->
            <div class="chronotrack-stats" id="chronotrack-stats" style="display: none; margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; font-size: 12px;">
                <div style="display: flex; justify-content: flex-end; gap: 20px; flex-wrap: wrap;">
                    <div class="chronotrack-stat-item">
                        <span class="chronotrack-stat-label" style="color: #666; font-weight: 600;">WYSTARTOWAŁO:</span>
                        <span class="chronotrack-stat-value" id="stat-started" style="font-weight: 700; color: #333;">-</span>
                    </div>
                    <div class="chronotrack-stat-item">
                        <span class="chronotrack-stat-label" style="color: #666; font-weight: 600;">NA TRASIE:</span>
                        <span class="chronotrack-stat-value" id="stat-on-course" style="font-weight: 700; color: #2196F3;">-</span>
                    </div>
                    <div class="chronotrack-stat-item">
                        <span class="chronotrack-stat-label" style="color: #666; font-weight: 600;">UKOŃCZYŁO:</span>
                        <span class="chronotrack-stat-value" id="stat-finished" style="font-weight: 700; color: #2196F3;">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Distance Filter Buttons -->
    <div class="chronotrack-distance-filters" id="chronotrack-distance-filters" style="margin: 20px 0; display: flex; align-items: center; gap: 10px;">
        <!-- Distance buttons will be populated by JavaScript -->
        <!-- PDF button will be added here with fixed position -->
    </div>

    <!-- Search/Filter -->
    <div class="chronotrack-filters">
        <input type="text"
               id="chronotrack-search"
               class="chronotrack-search"
               placeholder="Szukaj po nazwisku lub numerze...">

        <select id="chronotrack-category-filter" class="chronotrack-filter">
        </select>
    </div>

    <!-- Results Table - Standings View -->
    <div class="chronotrack-view chronotrack-view-standings active">
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table">
                <thead>
                    <tr>
                        <!-- Flag column FIRST - no header text, just empty -->
                        <th class="col-flag" style="width: 30px; text-align: center;"></th>

                        <?php if (!empty($columns)): ?>
                            <?php foreach ($columns as $column):
                                // Get first API attribute for sorting
                                $sortKey = '';
                                if (!empty($column->api_attributes) && is_array($column->api_attributes) && count($column->api_attributes) > 0) {
                                    $sortKey = $column->api_attributes[0];
                                }
                                $sortable = !empty($sortKey) ? 'sortable' : '';
                            ?>
                                <th class="col-<?php echo esc_attr($column->column_id); ?> <?php echo $sortable; ?>"
                                    <?php if ($sortKey): ?>data-column="<?php echo esc_attr($sortKey); ?>"<?php endif; ?>
                                    title="<?php echo esc_attr($column->column_description ?? ''); ?><?php if ($sortKey): echo ' (kliknij aby sortować)'; endif; ?>"
                                    style="<?php if ($sortKey): ?>cursor: pointer;<?php endif; ?>">
                                    <?php
                                    // Split multi-word column names into multiple lines
                                    $name = $column->column_name;
                                    if (strpos($name, ' ') !== false) {
                                        $parts = explode(' ', $name, 2); // Split on first space
                                        echo esc_html($parts[0]) . '<br>' . esc_html($parts[1]);
                                    } else {
                                        echo esc_html($name);
                                    }
                                    ?>
                                </th>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Fallback to default columns if none configured -->
                            <th class="col-position">Miej.</th>
                            <th class="col-bib">Nr</th>
                            <th class="col-name">Imię i nazwisko</th>
                            <th class="col-category">Kategoria</th>
                            <th class="col-club">Klub</th>
                            <th class="col-time">Czas</th>
                        <?php endif; ?>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="chronotrack-results-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            Brak wyników
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="chronotrack-loading-row" style="display: none;">
                        <td colspan="20" style="text-align: center; padding: 15px;">
                            <div class="chronotrack-spinner"></div>
                            <p style="margin: 10px 0 0 0;">Ładowanie wyników...</p>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- META view removed - not needed -->
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
