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
        <div class="chronotrack-event-date"><?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?> &middot; <?php echo esc_html($event->event_location ?? ''); ?></div>
    </div>

    <!-- Top Controls Bar -->
    <div class="chronotrack-controls">
        <button class="chronotrack-view-toggle active" data-view="standings">
            Klasyfikacja
        </button>
        <button class="chronotrack-view-toggle" data-view="meta">
            META (Linia mety)
        </button>

        <div class="chronotrack-live-status">
            <span class="chronotrack-live-dot"></span>
            <span class="chronotrack-live-label">NA ŻYWO</span>
            <button class="chronotrack-manual-refresh" title="Odśwież">&#8635;</button>
        </div>

        <div class="chronotrack-last-update">
            <span id="chronotrack-timestamp"></span>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="chronotrack-stats-bar">
        <div class="chronotrack-stat">
            <span class="chronotrack-stat-label">WYSTARTOWAŁO</span>
            <span class="chronotrack-stat-value" id="chronotrack-stat-started">—</span>
        </div>
        <div class="chronotrack-stat-sep"></div>
        <div class="chronotrack-stat">
            <span class="chronotrack-stat-label">NA TRASIE</span>
            <span class="chronotrack-stat-value chronotrack-stat-ontrack" id="chronotrack-stat-ontrack">—</span>
        </div>
        <div class="chronotrack-stat-sep"></div>
        <div class="chronotrack-stat">
            <span class="chronotrack-stat-label">UKOŃCZYŁO</span>
            <span class="chronotrack-stat-value chronotrack-stat-finished" id="chronotrack-stat-finished">—</span>
        </div>
    </div>

    <!-- Distance Filter Buttons + PDF Button -->
    <div class="chronotrack-distance-row">
        <div class="chronotrack-distance-filters" id="chronotrack-distance-filters">
            <!-- Distance buttons populated by JavaScript -->
        </div>
        <button class="chronotrack-pdf-btn" id="chronotrack-generate-pdf">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            Generuj PDF
        </button>
    </div>

    <!-- Search/Filter Bar -->
    <div class="chronotrack-filters">
        <input type="text"
               id="chronotrack-search"
               class="chronotrack-search"
               placeholder="Szukaj po nazwisku lub numerze startowym...">

        <div class="chronotrack-filters-right">
            <select id="chronotrack-category-filter" class="chronotrack-filter">
                <option value="">Open</option>
            </select>

            <select id="chronotrack-gender-filter" class="chronotrack-filter">
                <option value="">K + M</option>
                <option value="M">Mężczyźni</option>
                <option value="F">Kobiety</option>
            </select>
        </div>
    </div>

    <!-- Loading Indicator -->
    <div class="chronotrack-loading" style="display: none;">
        <div class="chronotrack-spinner"></div>
        <p>Ładowanie wyników...</p>
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
                                    $name = $column->column_name;
                                    if (strpos($name, ' ') !== false) {
                                        $parts = explode(' ', $name, 2);
                                        echo esc_html($parts[0]) . '<br>' . esc_html($parts[1]);
                                    } else {
                                        echo esc_html($name);
                                    }
                                    ?>
                                </th>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <th class="col-position">Miej.</th>
                            <th class="col-bib">Nr</th>
                            <th class="col-name">Nazwisko Imię</th>
                            <th class="col-city">Miejscowość</th>
                            <th class="col-club">Klub</th>
                            <th class="col-category">Kat</th>
                            <th class="col-category-position">Msc<br>Kat</th>
                            <th class="col-gender-position">Msc<br>M/K</th>
                            <th class="col-time">Czas<br>Brutto</th>
                            <th class="col-net-time">Czas<br>Netto</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="chronotrack-results-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            Ładowanie wyników...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Results Table - META (Finish Line) View -->
    <div class="chronotrack-view chronotrack-view-meta">
        <div class="chronotrack-meta-info">
            <p>&#9873; Zawodnicy na mecie &mdash; od ostatniego wbiegającego</p>
        </div>
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table chronotrack-meta-table">
                <thead>
                    <tr>
                        <th class="col-finish-time">Godzina</th>
                        <th class="col-bib">Nr</th>
                        <th class="col-name">Nazwisko Imię</th>
                        <th class="col-category">Kat</th>
                        <th class="col-club">Klub</th>
                        <th class="col-time">Czas</th>
                        <th class="col-position">Miejsce</th>
                    </tr>
                </thead>
                <tbody id="chronotrack-meta-body">
                    <tr>
                        <td colspan="7" class="chronotrack-no-results">
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
