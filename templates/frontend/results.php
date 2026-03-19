<?php
/**
 * Frontend results display template
 * Version: 4.0.8
 */

if (!defined('ABSPATH')) {
    exit;
}

$max_logo_width  = get_option('chronotrack_max_logo_width', 400);
$max_logo_height = get_option('chronotrack_max_logo_height', 200);

// Get dynamic columns configuration
$db      = chronotrack_live_results()->db;
$columns = $db->get_event_columns($event->event_id, true);
?>

<div class="chronotrack-results-container" data-event-id="<?php echo esc_attr($event->event_id); ?>">

    <!-- Event Header -->
    <div class="chronotrack-header">
        <?php if (!empty($event->event_logo_url) || !empty($event->sponsor_logo_url)): ?>
        <div class="chronotrack-logos">
            <?php if (!empty($event->event_logo_url)): ?>
                <div class="chronotrack-event-logo">
                    <img src="<?php echo esc_url($event->event_logo_url); ?>"
                         alt="<?php echo esc_attr($event->event_name); ?>"
                         style="max-width:<?php echo intval($max_logo_width); ?>px;max-height:<?php echo intval($max_logo_height); ?>px;">
                </div>
            <?php endif; ?>
            <?php if (!empty($event->sponsor_logo_url)): ?>
                <div class="chronotrack-sponsor-logo">
                    <img src="<?php echo esc_url($event->sponsor_logo_url); ?>"
                         alt="Sponsor"
                         style="max-width:<?php echo intval($max_logo_width); ?>px;max-height:<?php echo intval($max_logo_height); ?>px;">
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h1 class="chronotrack-event-name"><?php echo esc_html($event->event_name); ?></h1>
        <div class="chronotrack-event-date"><?php echo date_i18n(get_option('date_format'), strtotime($event->event_date)); ?></div>
    </div>

    <!-- View Toggle -->
    <div class="chronotrack-controls">
        <button class="chronotrack-view-toggle active" data-view="standings">
            Klasyfikacja
        </button>
        <button class="chronotrack-view-toggle" data-view="meta">
            META (Linia mety)
        </button>
        <button class="chronotrack-manual-refresh">
            ↺ Odśwież
        </button>
        <div class="chronotrack-last-update">
            <span id="chronotrack-timestamp"></span>
        </div>
    </div>

    <!-- Distance Filter Buttons -->
    <div class="chronotrack-distance-filters" id="chronotrack-distance-filters">
        <!-- Distance & PDF buttons populated by JavaScript -->
    </div>

    <!-- Search / Filter Bar -->
    <div class="chronotrack-filters">
        <input type="text"
               id="chronotrack-search"
               class="chronotrack-search"
               placeholder="&#128269; Szukaj po nazwisku lub numerze startowym...">

        <select id="chronotrack-gender-filter" class="chronotrack-filter">
            <option value="">Wszyscy</option>
            <option value="M">Mężczyźni</option>
            <option value="F">Kobiety</option>
        </select>

        <!-- Category pushed far right via CSS margin-left: auto -->
        <select id="chronotrack-category-filter" class="chronotrack-filter">
            <option value="">Wszystkie kategorie</option>
        </select>
    </div>

    <!-- Loading Indicator -->
    <div class="chronotrack-loading" style="display:none;">
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
                                <th class="col-<?php echo esc_attr($column->column_id); ?>"
                                    title="<?php echo esc_attr($column->column_description ?? ''); ?>">
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
                            <th class="col-name">Imię i Nazwisko</th>
                            <th class="col-category">Kategoria</th>
                            <th class="col-club">Klub</th>
                            <th class="col-time">Czas</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="chronotrack-results-body">
                    <tr>
                        <td colspan="20" class="chronotrack-no-results">
                            Trwa ładowanie wyników…
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Results Table - META (Finish Line) View -->
    <div class="chronotrack-view chronotrack-view-meta">
        <div class="chronotrack-meta-info">
            <p>Zawodnicy na mecie – posortowani od ostatniego wbiegającego</p>
        </div>
        <div class="chronotrack-table-wrapper">
            <table class="chronotrack-results-table chronotrack-meta-table">
                <thead>
                    <tr>
                        <th class="col-finish-time">Godz. mety</th>
                        <th class="col-bib">Nr</th>
                        <th class="col-name">Imię i Nazwisko</th>
                        <th class="col-category">Kategoria</th>
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
    <div id="chronotrack-modal" class="chronotrack-modal" style="display:none;">
        <div class="chronotrack-modal-content">
            <div class="chronotrack-modal-header">
                <span style="color:#fff;font-weight:700;font-size:16px;">Szczegóły zawodnika</span>
                <button class="chronotrack-modal-close">&times;</button>
            </div>
            <div id="chronotrack-modal-body">
                <!-- Details loaded via AJAX -->
            </div>
        </div>
    </div>

</div>
