/**
 * ChronoTrack Live Results - Frontend JavaScript
 */

(function($) {
    'use strict';

    const ChronoTrackResults = {
        eventId: null,
        currentView: 'standings',
        refreshInterval: null,
        searchTimeout: null,
        lastUpdate: null,
        isLoading: false,
        consecutiveErrors: 0,
        maxConsecutiveErrors: 3,
        lastResultCount: 0,
        unchangedCount: 0,
        currentInterval: 10000, // 10 seconds for incremental updates
        fullCheckInterval: null, // Separate interval for full checks every 60 seconds
        columns: [], // Dynamic columns configuration
        distances: [], // Available distances
        selectedDistance: '', // Currently selected distance filter

        init: function() {
            console.log('=== ChronoTrack Live Init START ===');

            const container = $('.chronotrack-results-container');
            if (container.length === 0) {
                console.log('❌ No ChronoTrack container found');
                return;
            }

            this.eventId = container.data('event-id');
            console.log('✅ Event ID:', this.eventId);

            if (!this.eventId) {
                console.error('❌ Event ID not found');
                return;
            }

            if (typeof chronotrackData === 'undefined') {
                console.error('❌ chronotrackData not defined');
                return;
            }

            console.log('✅ chronotrackData loaded:', chronotrackData);

            this.bindEvents();

            // Start auto-refresh immediately (user wants this!)
            this.startAutoRefresh();

            console.log('=== ChronoTrack Live Init END ===');
        },

        bindEvents: function() {
            // View toggle
            $(document).on('click', '.chronotrack-view-toggle', (e) => {
                const view = $(e.currentTarget).data('view');
                this.switchView(view);
            });

            // Search
            $(document).on('keyup', '#chronotrack-search', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.filterResults();
                }, 300);
            });

            // Filters
            $(document).on('change', '.chronotrack-filter', () => {
                this.filterResults();
            });

            // Distance filter buttons
            $(document).on('click', '.chronotrack-distance-filter-btn', (e) => {
                const distance = $(e.currentTarget).data('distance');
                this.selectDistance(distance);
            });

            // Participant details
            $(document).on('click', '.chronotrack-view-details', (e) => {
                e.preventDefault();
                const participantId = $(e.currentTarget).data('participant-id');
                this.showParticipantDetails(participantId);
            });

            // Modal close
            $(document).on('click', '.chronotrack-modal-close, .chronotrack-modal', (e) => {
                if (e.target === e.currentTarget) {
                    this.closeModal();
                }
            });

            // Manual refresh button (both old button and new icon)
            $(document).on('click', '.chronotrack-manual-refresh, .chronotrack-manual-refresh-icon', (e) => {
                e.preventDefault();
                console.log('🔄 Manual refresh triggered - fetching from API');
                this.refreshFromAPI();
            });

            // Toggle auto-refresh
            $(document).on('click', '.chronotrack-toggle-autorefresh', (e) => {
                e.preventDefault();
                if (this.refreshInterval) {
                    this.stopAutoRefresh();
                    $(e.currentTarget).text('Włącz auto-odświeżanie');
                } else {
                    this.startAutoRefresh();
                    $(e.currentTarget).text('Wyłącz auto-odświeżanie');
                }
            });
        },

        loadResults: function(view) {
            // Prevent concurrent requests
            if (this.isLoading) {
                console.log('⏳ Already loading, skipping...');
                return;
            }

            // Stop if too many consecutive errors
            if (this.consecutiveErrors >= this.maxConsecutiveErrors) {
                console.error('❌ Too many consecutive errors, stopping auto-refresh');
                this.stopAutoRefresh();
                this.showError('Zbyt wiele błędów. Odświeżanie zatrzymane.');
                return;
            }

            view = view || this.currentView;
            this.isLoading = true;
            this.showLoading();

            const action = view === 'meta' ? 'chronotrack_get_recent_finishers' : 'chronotrack_get_results';

            console.log('📡 Loading results, view:', view, 'action:', action);

            $.ajax({
                url: chronotrackData.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    event_id: this.eventId,
                    nonce: chronotrackData.nonce
                },
                timeout: 10000, // 10 second timeout
                success: (response) => {
                    console.log('✅ AJAX Success:', response);
                    if (response.success) {
                        this.consecutiveErrors = 0; // Reset error counter

                        // Save columns configuration
                        if (response.data.columns && response.data.columns.length > 0) {
                            this.columns = response.data.columns;
                            console.log('📋 Columns loaded:', this.columns.length);
                        }

                        // Save and render distances
                        if (response.data.distances && response.data.distances.length > 0) {
                            this.distances = response.data.distances;
                            console.log('📏 Distances loaded:', this.distances.length);
                            this.renderDistanceButtons();
                        }

                        const newCount = response.data.count || 0;
                        const hasChanges = newCount !== this.lastResultCount;

                        if (hasChanges) {
                            console.log('🆕 New results detected:', newCount, '(was:', this.lastResultCount + ')');
                            this.lastResultCount = newCount;
                            this.unchangedCount = 0;
                        } else {
                            this.unchangedCount++;
                            console.log('⏸️ No changes, count:', this.unchangedCount);
                        }

                        if (view === 'meta') {
                            this.renderMetaResults(response.data.results);
                        } else {
                            this.renderResults(response.data.results);
                        }
                        this.updateTimestamp();
                        this.populateFilters(response.data.results);
                    } else {
                        this.consecutiveErrors++;
                        this.showError(response.data.message || 'Błąd pobierania wyników');
                    }
                },
                error: (xhr, status, error) => {
                    this.consecutiveErrors++;
                    console.error('❌ AJAX Error:', {xhr, status, error});
                    console.error('Response:', xhr.responseText);

                    let errorMsg = 'Błąd pobierania wyników';
                    if (status === 'timeout') {
                        errorMsg = 'Przekroczono limit czasu';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Brak połączenia z serwerem';
                    }

                    this.showError(errorMsg + ' (błąd ' + this.consecutiveErrors + '/' + this.maxConsecutiveErrors + ')');
                },
                complete: () => {
                    console.log('✅ AJAX Complete - resetting isLoading');
                    this.isLoading = false;
                    this.hideLoading();
                }
            });
        },

        refreshFromAPI: function() {
            // Prevent concurrent requests
            if (this.isLoading) {
                console.log('⏳ Already loading, skipping...');
                return;
            }

            this.isLoading = true;
            this.showLoading();

            console.log('📡 Refreshing from API for event:', this.eventId);

            $.ajax({
                url: chronotrackData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chronotrack_refresh_results',
                    event_id: this.eventId,
                    nonce: chronotrackData.nonce
                },
                timeout: 60000, // 60 seconds for API fetch
                success: (response) => {
                    if (response.success) {
                        console.log('✅ API refresh successful:', response.data.count, 'results');
                        this.consecutiveErrors = 0;

                        // After API refresh, reload from cache to get full data
                        this.loadResults(this.currentView);
                    } else {
                        console.error('❌ API refresh failed:', response.data.message);
                        this.showError(response.data.message || 'Błąd odświeżania');
                        this.consecutiveErrors++;
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ AJAX error during API refresh:', {status, error, xhr});
                    this.consecutiveErrors++;

                    let errorMsg = 'Błąd pobierania wyników z API';
                    if (status === 'timeout') {
                        errorMsg = 'Przekroczono limit czasu (60s) - API powolne lub nieaktywny event';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Brak połączenia z serwerem';
                    }

                    this.showError(errorMsg);
                },
                complete: () => {
                    console.log('✅ API refresh complete');
                    this.isLoading = false;
                    this.hideLoading();
                }
            });
        },

        renderResults: function(results) {
            console.log('🎨 renderResults called, results:', results ? results.length : 'NULL');
            const tbody = $('#chronotrack-results-body');

            if (!results || results.length === 0) {
                console.log('❌ No results to render');
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak wyników</td></tr>');
                return;
            }

            // Remove "Brak wyników" row if it exists
            tbody.find('.chronotrack-no-results').closest('tr').remove();

            // Build a map of existing rows by bib_number (unique identifier)
            const existingRows = {};
            tbody.find('tr[data-bib]').each(function() {
                const bib = $(this).attr('data-bib');
                existingRows[bib] = $(this);
            });

            // Track which bibs we've processed
            const processedBibs = new Set();

            // Debug first result
            if (results.length > 0) {
                console.log('🔍 First result data:', {
                    bib: results[0].bib_number,
                    name: results[0].full_name,
                    gender: results[0].gender,
                    category_position: results[0].category_position,
                    gender_position: results[0].gender_position,
                    city: results[0].city,
                    club: results[0].club,
                    distance: results[0].distance
                });
                console.log('📊 All fields available:', Object.keys(results[0]));
            }

            // Update or add each result
            results.forEach((result, index) => {
                const bib = result.bib_number;

                // Skip if we already processed this bib (prevent duplicates)
                if (processedBibs.has(bib)) {
                    console.warn('⚠️ Duplicate bib detected:', bib);
                    return;
                }
                processedBibs.add(bib);

                const existingRow = existingRows[bib];

                if (existingRow) {
                    // Update existing row in place - preserve position and visibility
                    const isVisible = existingRow.is(':visible');
                    const newRow = this.createResultRow(result);

                    // Copy visibility state
                    if (!isVisible) {
                        newRow.hide();
                    }

                    existingRow.replaceWith(newRow);
                    delete existingRows[bib];
                } else {
                    // New result - add it
                    const newRow = this.createResultRow(result);
                    tbody.append(newRow);
                }
            });

            // Remove rows that no longer exist in results
            $.each(existingRows, function(bib, row) {
                row.remove();
            });

            // Recolor rows after rendering
            this.recolorRows(tbody);

            console.log('✅ Rendered', results.length, 'results (background update, preserved filters)');
        },

        createResultRow: function(result) {
            const row = $('<tr>')
                .attr('data-result-id', result.id)
                .attr('data-bib', result.bib_number)
                .attr('data-distance', result.distance || '')
                .attr('data-bracket-positions', JSON.stringify(result.bracket_positions || {}));

            // Use dynamic columns if available
            if (this.columns && this.columns.length > 0) {
                this.columns.forEach((column) => {
                    const value = this.getColumnValue(result, column);
                    const cell = $('<td>').addClass('col-' + column.id);

                    // Special formatting for full_name - make it clickable
                    if (column.id === 'full_name' || column.id.includes('name')) {
                        const nameLink = $('<a>')
                            .attr('href', '#')
                            .addClass('chronotrack-view-details')
                            .attr('data-participant-id', result.participant_id)
                            .html('<strong>' + this.escapeHtml(value) + '</strong>');
                        cell.append(nameLink);
                    } else {
                        cell.text(this.cleanValue(value));
                    }

                    row.append(cell);
                });
            } else {
                // Fallback to hardcoded columns
                row.append($('<td>').addClass('col-position').text(this.cleanValue(result.position)));
                row.append($('<td>').addClass('col-bib').text(this.cleanValue(result.bib_number)));

                // Make name clickable in fallback mode too
                const nameLink = $('<a>')
                    .attr('href', '#')
                    .addClass('chronotrack-view-details')
                    .attr('data-participant-id', result.participant_id)
                    .html('<strong>' + this.escapeHtml(result.full_name) + '</strong>');
                row.append($('<td>').addClass('col-name').append(nameLink));

                row.append($('<td>').addClass('col-category').text(this.cleanValue(result.category)));
                row.append($('<td>').addClass('col-club').text(this.cleanValue(result.club)));
                row.append($('<td>').addClass('col-time').text(this.cleanValue(result.finish_time)));
            }

            // No separate actions column - name is now clickable

            return row;
        },

        getColumnValue: function(result, column) {
            // Try each API attribute in order until we find a value
            if (column.api_attributes && column.api_attributes.length > 0) {
                for (let i = 0; i < column.api_attributes.length; i++) {
                    const attr = column.api_attributes[i];

                    // Handle special case for full_name
                    if (attr === 'full_name' || attr === 'athlete_last_name,athlete_first_name') {
                        if (result.full_name) {
                            return result.full_name;
                        }
                        if (result.last_name || result.first_name) {
                            return (result.last_name || '') + ' ' + (result.first_name || '');
                        }
                    }

                    // Try direct attribute - accept 0 as valid value
                    if (result.hasOwnProperty(attr) && result[attr] !== null && result[attr] !== undefined && result[attr] !== '') {
                        return result[attr];
                    }
                }
            }

            return '-';
        },

        renderMetaResults: function(results) {
            const tbody = $('#chronotrack-meta-body');
            tbody.empty();

            if (!results || results.length === 0) {
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">' +
                    chronotrackData.strings.noResults + '</td></tr>');
                return;
            }

            results.forEach((result) => {
                const row = this.createMetaResultRow(result);
                tbody.append(row);
            });
        },

        createMetaResultRow: function(result) {
            const row = $('<tr>').attr('data-result-id', result.id);

            const finishTime = new Date(result.finish_timestamp).toLocaleTimeString();

            row.append($('<td>').addClass('col-finish-time').text(finishTime));
            row.append($('<td>').addClass('col-bib').text(this.cleanValue(result.bib_number)));

            // Make name clickable
            const nameLink = $('<a>')
                .attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id)
                .html('<strong>' + this.escapeHtml(result.full_name) + '</strong>');
            row.append($('<td>').addClass('col-name').append(nameLink));

            row.append($('<td>').addClass('col-category').text(this.cleanValue(result.category)));
            row.append($('<td>').addClass('col-club').text(this.cleanValue(result.club)));
            row.append($('<td>').addClass('col-time').text(this.cleanValue(result.finish_time)));
            row.append($('<td>').addClass('col-position').text(this.cleanValue(result.position)));

            // No separate actions column - name is now clickable

            return row;
        },

        switchView: function(view) {
            this.currentView = view;

            $('.chronotrack-view-toggle').removeClass('active');
            $('.chronotrack-view-toggle[data-view="' + view + '"]').addClass('active');

            $('.chronotrack-view').removeClass('active');
            $('.chronotrack-view-' + view).addClass('active');

            this.loadResults(view);
        },

        filterResults: function() {
            const searchTerm = $('#chronotrack-search').val().toLowerCase();
            const category = $('#chronotrack-category-filter').val();
            const gender = $('#chronotrack-gender-filter').val();
            const distance = this.selectedDistance;

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            tbody.find('tr').each(function() {
                const row = $(this);
                const name = row.find('.col-name, .col-full_name').text().toLowerCase();
                const bib = row.find('.col-bib, .col-entry_bib').text().toLowerCase();
                const rowCategory = row.find('.col-category').text();
                const rowGender = row.find('.col-gender').text();
                const rowDistance = row.attr('data-distance') || '';

                // Get bracket positions from data attribute
                let bracketPositions = {};
                try {
                    const bracketData = row.attr('data-bracket-positions');
                    if (bracketData) {
                        bracketPositions = JSON.parse(bracketData);
                    }
                } catch (e) {
                    // Ignore parse errors
                }

                let show = true;

                // Search filter
                if (searchTerm && !name.includes(searchTerm) && !bib.includes(searchTerm)) {
                    show = false;
                }

                // Category filter - check both category column AND bracket_positions
                if (category) {
                    // Check if participant belongs to this bracket
                    const belongsToBracket = Object.keys(bracketPositions).includes(category);
                    const matchesCategory = rowCategory === category;

                    if (!belongsToBracket && !matchesCategory) {
                        show = false;
                    }
                }

                // Gender filter
                if (gender && rowGender !== gender) {
                    show = false;
                }

                // Distance filter
                if (distance && rowDistance !== distance) {
                    show = false;
                }

                row.toggle(show);
            });

            // Recolor visible rows alternately
            this.recolorRows(tbody);
        },

        recolorRows: function(tbody) {
            // Remove old classes
            tbody.find('tr').removeClass('row-even row-odd');

            // Add classes to visible rows only
            let visibleIndex = 0;
            tbody.find('tr:visible').each(function() {
                if (visibleIndex % 2 === 0) {
                    $(this).addClass('row-even');
                } else {
                    $(this).addClass('row-odd');
                }
                visibleIndex++;
            });
        },

        populateFilters: function(results) {
            const brackets = new Set();

            // Collect all unique brackets from bracket_positions
            results.forEach((result) => {
                if (result.bracket_positions && typeof result.bracket_positions === 'object') {
                    Object.keys(result.bracket_positions).forEach((bracketName) => {
                        brackets.add(bracketName);
                    });
                }
                // Fallback: also add category field if available
                if (result.category) {
                    brackets.add(result.category);
                }
            });

            const categorySelect = $('#chronotrack-category-filter');
            const currentValue = categorySelect.val();

            categorySelect.find('option:not(:first)').remove();
            Array.from(brackets).sort().forEach((bracket) => {
                categorySelect.append(
                    $('<option>').val(bracket).text(bracket)
                );
            });

            categorySelect.val(currentValue);
        },

        showParticipantDetails: function(participantId) {
            $.ajax({
                url: chronotrackData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chronotrack_get_participant_details',
                    event_id: this.eventId,
                    participant_id: participantId,
                    nonce: chronotrackData.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderParticipantDetails(response.data.participant);
                        this.openModal();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading participant details:', error);
                    alert(chronotrackData.strings.error);
                }
            });
        },

        renderParticipantDetails: function(participant) {
            // Polish translations for participant details
            let html = '<div class="chronotrack-participant-details">';
            html += '<h2>' + this.escapeHtml(participant.full_name) + '</h2>';
            html += '<div class="chronotrack-details-grid-3col">';

            // Column 1: Basic info + Bracket positions
            html += '<div class="chronotrack-details-column">';

            // Basic info - Podstawowe informacje
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Podstawowe informacje</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Numer startowy:</th><td>' + this.escapeHtml(this.cleanValue(participant.bib_number)) + '</td></tr>';
            html += '<tr><th>Płeć:</th><td>' + this.escapeHtml(this.cleanValue(participant.gender)) + '</td></tr>';
            html += '<tr><th>Miejscowość:</th><td>' + this.escapeHtml(this.cleanValue(participant.city)) + '</td></tr>';
            html += '<tr><th>Klub:</th><td>' + this.escapeHtml(this.cleanValue(participant.club)) + '</td></tr>';
            html += '<tr><th>Kategoria:</th><td>' + this.escapeHtml(this.cleanValue(participant.category)) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Bracket Positions - Pozycje w kategoriach
            if (participant.bracket_positions && Object.keys(participant.bracket_positions).length > 0) {
                html += '<div class="chronotrack-details-section">';
                html += '<h3>Pozycje w kategoriach</h3>';
                html += '<div class="chronotrack-bracket-list">';

                // Sort brackets alphabetically
                const sortedBrackets = Object.keys(participant.bracket_positions).sort();
                sortedBrackets.forEach((bracketName) => {
                    const position = participant.bracket_positions[bracketName];
                    if (position && position > 0) {
                        // Format: "M20 - 3" (bracket name - position)
                        html += '<div class="chronotrack-bracket-item">';
                        html += this.escapeHtml(bracketName) + ' - ' + position;
                        html += '</div>';
                    }
                });

                html += '</div>';
                html += '</div>';
            }

            html += '</div>'; // End column 1

            // Column 2: Results
            html += '<div class="chronotrack-details-column">';
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Wyniki</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Miejsce Open:</th><td class="chronotrack-position">' + this.formatPosition(participant.position) + '</td></tr>';
            html += '<tr><th>Miejsce w kategorii:</th><td class="chronotrack-position">' + this.formatPosition(participant.category_position) + '</td></tr>';
            html += '<tr><th>Miejsce M/K:</th><td class="chronotrack-position">' + this.formatPosition(participant.gender_position) + '</td></tr>';
            html += '<tr><th>Czas brutto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</td></tr>';
            html += '<tr><th>Czas netto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.net_time) + '</td></tr>';
            html += '</table>';
            html += '</div>';
            html += '</div>'; // End column 2

            // Column 3: Split Times
            html += '<div class="chronotrack-details-column">';

            // Split Times - Międzyczasy
            if (participant.split_times && participant.split_times.length > 0) {
                html += '<div class="chronotrack-details-section">';
                html += '<h3>Międzyczasy</h3>';
                html += '<table class="chronotrack-details-table">';
                participant.split_times.forEach((split) => {
                    if (split.interval_name && split.formatted_time) {
                        // Build interval name with distance in km if available
                        let intervalLabel = this.escapeHtml(split.interval_name);
                        if (split.distance_km) {
                            intervalLabel += ' (' + this.escapeHtml(split.distance_km) + ')';
                        }

                        html += '<tr>';
                        html += '<th>' + intervalLabel + ':</th>';
                        html += '<td>';
                        html += '<span class="chronotrack-time">' + this.escapeHtml(split.formatted_time) + '</span>';
                        // Add position if available - AFTER the time
                        if (split.position && split.position > 0) {
                            html += ' <span class="chronotrack-split-position">(mce: ' + split.position + ')</span>';
                        }
                        html += '</td>';
                        html += '</tr>';
                    }
                });
                html += '</table>';
                html += '</div>';
            }

            html += '</div>'; // End column 3
            html += '</div>'; // End grid-3col
            html += '</div>'; // End participant-details

            $('#chronotrack-modal-body').html(html);
        },

        openModal: function() {
            $('#chronotrack-modal').fadeIn(200);
        },

        closeModal: function() {
            $('#chronotrack-modal').fadeOut(200);
        },

        renderDistanceButtons: function() {
            const container = $('#chronotrack-distance-filters');
            if (!container.length) return;

            container.empty();

            // Auto-select first distance if nothing selected
            if (!this.selectedDistance && this.distances.length > 0) {
                this.selectedDistance = this.distances[0];
            }

            // Add buttons for each distance (no "Wszystkie" button)
            this.distances.forEach((distance) => {
                const btn = $('<button>')
                    .addClass('chronotrack-distance-filter-btn')
                    .addClass(this.selectedDistance === distance ? 'active' : '')
                    .attr('data-distance', distance)
                    .text(distance);
                container.append(btn);
            });
        },

        selectDistance: function(distance) {
            this.selectedDistance = distance;
            console.log('📏 Selected distance:', distance || 'Wszystkie');

            // Update button states
            $('.chronotrack-distance-filter-btn').removeClass('active');
            $('.chronotrack-distance-filter-btn[data-distance="' + distance + '"]').addClass('active');

            // Filter results
            this.filterResults();
        },

        startAutoRefresh: function() {
            // Fetch from API every 60 seconds for live updates
            const interval = 60000; // 60 seconds
            this.currentInterval = interval;

            console.log('▶️ Starting auto-refresh with interval:', interval + 'ms (60s API fetch)');

            // Fetch fresh data from API every 60 seconds
            this.refreshInterval = setInterval(() => {
                this.refreshFromAPI();  // Fetch from API
            }, interval);

            // Initial fetch
            this.refreshFromAPI();
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                console.log('⏸️ Stopping auto-refresh');
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
            if (this.fullCheckInterval) {
                console.log('⏸️ Stopping full check interval');
                clearInterval(this.fullCheckInterval);
                this.fullCheckInterval = null;
            }
        },

        adjustRefreshInterval: function(newInterval) {
            console.log('🔄 Adjusting refresh interval from', this.currentInterval + 'ms to', newInterval + 'ms');
            this.currentInterval = newInterval;

            // Restart timer with new interval (but keep max at 10 seconds)
            this.stopAutoRefresh();
            const finalInterval = Math.min(newInterval, 10000); // Max 10 seconds
            this.refreshInterval = setInterval(() => {
                this.refreshFromAPI();  // Fetch from API, not cache
            }, finalInterval);

            // Keep full check at 60 seconds
            this.fullCheckInterval = setInterval(() => {
                console.log('🔄 Full check (60s interval)');
                this.refreshFromAPI();  // Fetch from API, not cache
            }, 60000);
        },

        updateTimestamp: function() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const timeString = hours + ':' + minutes + ':' + seconds;

            $('#chronotrack-timestamp').text('Aktualizacja: ' + timeString);
        },

        showLoading: function() {
            $('.chronotrack-loading-row').show();
        },

        hideLoading: function() {
            $('.chronotrack-loading-row').hide();
        },

        showError: function(message) {
            console.error('ChronoTrack Error:', message);
        },

        /**
         * Format any value - clean empty values (-, 0, null)
         */
        cleanValue: function(value) {
            if (value === '-' || value === 0 || value === '0' || value === '' || value === null || value === undefined || value === 'null') {
                return '';
            }
            return value;
        },

        /**
         * Format position - show empty string if position is 0
         */
        formatPosition: function(value) {
            return this.cleanValue(value);
        },

        /**
         * Format club - show empty string if club is '-'
         */
        formatClub: function(value) {
            return this.cleanValue(value);
        },

        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        console.log('=== ChronoTrack DOM Ready ===');
        ChronoTrackResults.init();
    });

})(jQuery);
