<?php
/**
 * Columns Manager Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$db = chronotrack_live_results()->db;
$event_id = sanitize_text_field($_GET['event_id'] ?? '');

if (empty($event_id)) {
    echo '<div class="notice notice-error"><p>Musisz najpierw zapisać wydarzenie, aby zarządzać kolumnami.</p></div>';
    return;
}

$columns = $db->get_event_columns($event_id, false);
$available_attrs = $db->get_default_columns();

// Get event details for split times
$event = $db->get_event($event_id);

// Extract all possible API attributes
$all_api_attributes = array();
foreach ($available_attrs as $col) {
    if (isset($col['api_options'])) {
        $all_api_attributes = array_merge($all_api_attributes, $col['api_options']);
    }
}

// AUTOMATIC: Fetch split times from ChronoTrack API
// This will get ALL checkpoints/intervals configured for this event
$api = chronotrack_live_results()->api;

// Try to get split times from API by fetching a small sample of results
error_log("Columns Manager: Fetching split times from API for event {$event_id}");

try {
    // Fetch just 1 page (up to 100 results) to extract interval names
    $api_results = $api->fetch_results_page($event_id, 1, 100);

    if (!empty($api_results)) {
        error_log("Columns Manager: Found " . count($api_results) . " results, extracting split times");

        // Extract unique interval names from all results
        $found_intervals = array();

        foreach ($api_results as $result) {
            if (isset($result['split_times']) && is_array($result['split_times'])) {
                foreach ($result['split_times'] as $split) {
                    if (!empty($split['interval_name'])) {
                        $interval_name = $split['interval_name'];
                        if (!isset($found_intervals[$interval_name])) {
                            $found_intervals[$interval_name] = true;
                            $attr = 'split_time:' . $interval_name;
                            if (!in_array($attr, $all_api_attributes)) {
                                $all_api_attributes[] = $attr;
                                error_log("Columns Manager: Added split_time attribute: {$attr}");
                            }
                        }
                    }
                }
            }
        }

        error_log("Columns Manager: Found " . count($found_intervals) . " unique split time intervals");
    } else {
        error_log("Columns Manager: No results found from API, checking database cache");

        // Fallback: try database cache if API returns nothing
        $sample_results = $db->get_results($event_id, 10); // Get up to 10 results
        if (!empty($sample_results)) {
            foreach ($sample_results as $sample) {
                if (!empty($sample->split_times)) {
                    $split_times = is_string($sample->split_times)
                        ? json_decode($sample->split_times, true)
                        : $sample->split_times;

                    if (is_array($split_times)) {
                        foreach ($split_times as $split) {
                            if (!empty($split['interval_name'])) {
                                $attr = 'split_time:' . $split['interval_name'];
                                if (!in_array($attr, $all_api_attributes)) {
                                    $all_api_attributes[] = $attr;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Columns Manager: Error fetching split times from API: " . $e->getMessage());
    // Continue without split times - not fatal
}

$all_api_attributes = array_unique($all_api_attributes);
sort($all_api_attributes);
?>

<div class="wrap">
    <h1>Zarządzanie Kolumnami - <?php echo esc_html($event_id); ?></h1>

    <?php if (isset($_GET['message']) && $_GET['message'] === 'columns_saved'): ?>
        <div class="chronotrack-message">
            <p><strong>Kolumny zostały zapisane!</strong></p>
        </div>
    <?php endif; ?>

    <div class="chronotrack-columns-manager">
        <p class="description">
            Skonfiguruj kolumny wyświetlane w tabeli wyników. Możesz dodawać własne kolumny, edytować nazwy i zmieniać kolejność (przeciągnij aby zmienić).
        </p>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="chronotrack_save_columns">
            <input type="hidden" name="event_id" value="<?php echo esc_attr($event_id); ?>">
            <?php wp_nonce_field('chronotrack_save_columns', 'chronotrack_columns_nonce'); ?>

            <div class="chronotrack-columns-list">
                <?php foreach ($columns as $index => $column): ?>
                    <div class="chronotrack-column-item" data-index="<?php echo $index; ?>">
                        <div class="chronotrack-column-header">
                            <span class="chronotrack-column-handle dashicons dashicons-menu"></span>
                            <div class="chronotrack-column-name">
                                <input type="text"
                                       name="columns[<?php echo $index; ?>][name]"
                                       value="<?php echo esc_attr($column->column_name); ?>"
                                       class="regular-text"
                                       placeholder="Nazwa kolumny"
                                       required>
                                <input type="hidden"
                                       name="columns[<?php echo $index; ?>][id]"
                                       value="<?php echo esc_attr($column->column_id); ?>">
                            </div>
                            <div class="chronotrack-column-actions">
                                <button type="button" class="button button-delete chronotrack-delete-column">
                                    Usuń
                                </button>
                            </div>
                        </div>
                        <div class="chronotrack-column-body">
                            <div class="chronotrack-column-field">
                                <label>Opis</label>
                                <input type="text"
                                       name="columns[<?php echo $index; ?>][description]"
                                       value="<?php echo esc_attr($column->column_description ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="Opcjonalny opis">
                            </div>
                            <div class="chronotrack-column-field">
                                <label>Atrybuty API (w kolejności priorytetu)</label>
                                <div class="chronotrack-api-attributes">
                                    <?php if (!empty($column->api_attributes)): ?>
                                        <?php foreach ($column->api_attributes as $attr): ?>
                                            <span class="chronotrack-api-attr" data-attr="<?php echo esc_attr($attr); ?>">
                                                <?php echo esc_html($attr); ?>
                                                <button type="button" class="chronotrack-remove-attr" title="Usuń">&times;</button>
                                                <input type="hidden"
                                                       name="columns[<?php echo $index; ?>][api_attributes][]"
                                                       value="<?php echo esc_attr($attr); ?>">
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="chronotrack-add-attribute">
                                    <select class="chronotrack-attr-select">
                                        <option value="">-- Wybierz atrybut API --</option>
                                        <?php foreach ($all_api_attributes as $attr): ?>
                                            <option value="<?php echo esc_attr($attr); ?>">
                                                <?php echo esc_html($attr); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button chronotrack-add-attr-btn">Dodaj</button>
                                </div>
                                <p class="description">
                                    Kolumna będzie próbowała użyć pierwszego dostępnego atrybutu z listy.
                                    Jeśli pierwszy nie istnieje, spróbuje drugi, itd.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button chronotrack-add-column-btn">
                <span class="dashicons dashicons-plus"></span> Dodaj kolumnę
            </button>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    Zapisz kolumny
                </button>
                <a href="<?php echo admin_url('admin.php?page=chronotrack-live'); ?>" class="button button-large">
                    Anuluj
                </a>
            </p>
        </form>

        <div class="chronotrack-available-attributes">
            <h3>Dostępne atrybuty API</h3>
            <p class="description">
                Poniżej znajdują się wszystkie dostępne atrybuty z ChronoTrack API.
                Możesz użyć ich w swoich kolumnach.
            </p>
            <div class="chronotrack-attr-list">
                <?php foreach ($all_api_attributes as $attr): ?>
                    <div class="chronotrack-attr-item" title="Kliknij aby skopiować">
                        <?php echo esc_html($attr); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.chronotrack-column-placeholder {
    height: 100px;
    background: #f0f0f0;
    border: 2px dashed #ccc;
    margin-bottom: 10px;
}
</style>
