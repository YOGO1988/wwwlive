/**
 * ChronoTrack Live - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Auto-fetch event info from API when Event ID is entered
        $('#event_id').on('blur', function() {
            const eventId = $(this).val().trim();
            if (!eventId || $(this).attr('readonly')) {
                return;
            }

            // Check if event name is already filled
            if ($('#event_name').val().trim()) {
                return;
            }

            // Fetch event info from API
            $.ajax({
                url: chronotrackAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chronotrack_fetch_event_info',
                    event_id: eventId,
                    nonce: chronotrackAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        $('#event_name').val(response.data.event_name);
                        if (response.data.event_date) {
                            // Convert to datetime-local format
                            const date = new Date(response.data.event_date);
                            const formatted = date.toISOString().slice(0, 16);
                            $('#event_date').val(formatted);
                        }
                        if (response.data.location) {
                            console.log('Miejscowość pobrana: ' + response.data.location);
                            // Location will be saved automatically when form is submitted
                        }
                        alert('Dane pobrane z ChronoTrack API!\n\nNazwa: ' + response.data.event_name + '\nData: ' + response.data.event_date + '\nMiejscowość: ' + response.data.location);
                    }
                },
                error: function() {
                    console.log('Nie udało się pobrać danych wydarzenia');
                }
            });
        });

        // Initialize sortable columns
        if ($('.chronotrack-columns-list').length) {
            $('.chronotrack-columns-list').sortable({
                handle: '.chronotrack-column-handle',
                placeholder: 'chronotrack-column-placeholder',
                axis: 'y',
                update: function(event, ui) {
                    updateColumnIndices();
                }
            });
        }

        // Add column button
        $(document).on('click', '.chronotrack-add-column-btn', function(e) {
            e.preventDefault();
            addNewColumn();
        });

        // Delete column button
        $(document).on('click', '.chronotrack-delete-column', function(e) {
            e.preventDefault();
            if (confirm('Czy na pewno chcesz usunąć tę kolumnę?')) {
                $(this).closest('.chronotrack-column-item').remove();
                updateColumnIndices();
            }
        });

        // Add API attribute
        $(document).on('click', '.chronotrack-add-attr-btn', function(e) {
            e.preventDefault();
            const columnItem = $(this).closest('.chronotrack-column-item');
            const select = columnItem.find('.chronotrack-attr-select');
            const attrValue = select.val();

            if (!attrValue) {
                return;
            }

            // Check if already exists
            const existing = columnItem.find('.chronotrack-api-attr[data-attr="' + attrValue + '"]');
            if (existing.length > 0) {
                alert('Ten atrybut jest już dodany');
                return;
            }

            // Add attribute tag
            const attrContainer = columnItem.find('.chronotrack-api-attributes');
            const attrTag = $('<span class="chronotrack-api-attr" data-attr="' + attrValue + '">' +
                escapeHtml(attrValue) +
                '<button type="button" class="chronotrack-remove-attr" title="Usuń">&times;</button>' +
                '<input type="hidden" name="columns[' + columnItem.data('index') + '][api_attributes][]" value="' + escapeHtml(attrValue) + '">' +
                '</span>');

            attrContainer.append(attrTag);
            select.val('');
        });

        // Remove API attribute
        $(document).on('click', '.chronotrack-remove-attr', function(e) {
            e.preventDefault();
            $(this).closest('.chronotrack-api-attr').remove();
        });

        function addNewColumn() {
            const index = $('.chronotrack-column-item').length;
            const template = `
                <div class="chronotrack-column-item" data-index="${index}">
                    <div class="chronotrack-column-header">
                        <span class="chronotrack-column-handle dashicons dashicons-menu"></span>
                        <div class="chronotrack-column-name">
                            <input type="text"
                                   name="columns[${index}][name]"
                                   placeholder="Nazwa kolumny"
                                   class="regular-text"
                                   required>
                            <input type="hidden" name="columns[${index}][id]" value="custom_${index}_${Date.now()}">
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
                                   name="columns[${index}][description]"
                                   placeholder="Opcjonalny opis"
                                   class="regular-text">
                        </div>
                        <div class="chronotrack-column-field">
                            <label>Atrybuty API</label>
                            <div class="chronotrack-api-attributes">
                                <!-- Attributes will be added here -->
                            </div>
                            <div class="chronotrack-add-attribute">
                                <select class="chronotrack-attr-select">
                                    <option value="">-- Wybierz atrybut API --</option>
                                    ${getAvailableAttributes()}
                                </select>
                                <button type="button" class="button chronotrack-add-attr-btn">Dodaj</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('.chronotrack-columns-list').append(template);
            updateColumnIndices();
        }

        function updateColumnIndices() {
            $('.chronotrack-column-item').each(function(index) {
                $(this).data('index', index);

                // Update all name attributes
                $(this).find('input, select').each(function() {
                    const name = $(this).attr('name');
                    if (name) {
                        const newName = name.replace(/columns\[\d+\]/, 'columns[' + index + ']');
                        $(this).attr('name', newName);
                    }
                });

                // Update attr select
                $(this).find('.chronotrack-add-attr-btn').data('index', index);
            });
        }

        function getAvailableAttributes() {
            const attributes = [
                'overall_place', 'position', 'results_rank',
                'entry_bib', 'bib_number', 'results_bib',
                'full_name', 'athlete_last_name', 'athlete_first_name',
                'athlete_city', 'city',
                'club', 'athlete_club',
                'age', 'entry_race_age', 'results_age',
                'category', 'bracket_name', 'results_primary_bracket_name',
                'category_position', 'division_place', 'results_division_rank',
                'gender_position', 'results_sex_rank',
                'finish_time', 'gun_time', 'formatted_gun_time',
                'net_time', 'formatted_net_time', 'results_time',
                'pace', 'formatted_pace', 'results_pace',
                'gender', 'athlete_sex', 'results_sex',
                'race_name', 'results_race_name',
                'status', 'results_status'
            ];

            return attributes.map(attr =>
                `<option value="${escapeHtml(attr)}">${escapeHtml(attr)}</option>`
            ).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    });

})(jQuery);
