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
        <div class="chronotrack-event-date">
            <?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?>
            <?php if (!empty($event->event_location)): ?>
                · <?php echo esc_html($event->event_location); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Toggle -->
    <div class="chronotrack-controls">
        <button class="chronotrack-view-toggle active" data-view="standings">
            Klasyfikacja
        </button>
        <button class="chronotrack-view-toggle" data-view="meta">
            META (Linia mety)
        </button>

        <div class="chronotrack-live-indicator">
            <div style="text-align: center;">
                <div class="chronotrack-live-text">Na żywo</div>
                <button class="chronotrack-manual-refresh-icon" title="Odśwież">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="chronotrack-last-update">
            <span id="chronotrack-timestamp"></span>
        </div>
    </div>

    <!-- Distance Filter Buttons -->
    <div class="chronotrack-distance-filters" id="chronotrack-distance-filters" style="margin: 20px 0;">
        <!-- Distance buttons will be populated by JavaScript -->
    </div>

    <!-- Search/Filter -->
    <div class="chronotrack-filters">
        <input type="text"
               id="chronotrack-search"
               class="chronotrack-search"
               placeholder="Szukaj po nazwisku lub numerze...">

        <select id="chronotrack-category-filter" class="chronotrack-filter">
            <option value="">Wszystkie kategorie</option>
        </select>
    </div>

    <!-- Results Table - Standings View -->
    <div class="chronotrack-view chronotrack-view-standings active">
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table">
                <thead>
                    <tr>
                        <?php if (!empty($columns)): ?>
                            <?php foreach ($columns as $column): ?>
                                <th class="col-<?php echo esc_attr($column->column_id); ?>" title="<?php echo esc_attr($column->column_description ?? ''); ?>">
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

    <!-- Results Table - META (Finish Line) View -->
    <div class="chronotrack-view chronotrack-view-meta">
        <div class="chronotrack-meta-info">
            <p>Najnowsi zawodnicy na mecie - od ostatniego wbiegającego</p>
        </div>
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table chronotrack-meta-table">
                <thead>
                    <tr>
                        <th class="col-finish-time">Godzina mety</th>
                        <th class="col-bib">Nr</th>
                        <th class="col-name">Imię i nazwisko</th>
                        <th class="col-category">Kategoria</th>
                        <th class="col-club">Klub</th>
                        <th class="col-time">Czas</th>
                        <th class="col-position">Miejsce</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody id="chronotrack-meta-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            Brak zawodników na mecie
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
