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

            // Check event status and start auto-refresh based on status and time
            this.checkEventStatusAndStartRefresh();

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

            // Generate PDF button
            $(document).on('click', '.chronotrack-generate-pdf-btn', (e) => {
                e.preventDefault();
                this.generatePDF();
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

                        // CRITICAL FIX: Update distances and columns from refresh response
                        if (response.data.distances && response.data.distances.length > 0) {
                            this.distances = response.data.distances;
                            console.log('📏 Distances updated from refresh:', this.distances.length);
                            this.renderDistanceButtons();
                        }

                        if (response.data.columns && response.data.columns.length > 0) {
                            this.columns = response.data.columns;
                            console.log('📋 Columns updated from refresh:', this.columns.length);
                        }

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

            // CRITICAL FIX: Apply filters after rendering (for initial load)
            // This ensures newly added rows are filtered if a distance is selected
            if (this.selectedDistance) {
                console.log('🔄 Applying distance filter after render:', this.selectedDistance);
                this.filterResults();
            } else {
                // Just recolor if no filters active
                this.recolorRows(tbody);
            }

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

                    // Special formatting for full_name - make it clickable with flag
                    if (column.id === 'full_name' || column.id.includes('name')) {
                        // Add country flag before name if available
                        let flagEmoji = '';
                        if (result.country && typeof CountryFlags !== 'undefined') {
                            flagEmoji = CountryFlags.getFlag(result.country);
                            if (flagEmoji) {
                                flagEmoji = flagEmoji + ' '; // Add space after flag
                            }
                        }

                        const nameLink = $('<a>')
                            .attr('href', '#')
                            .addClass('chronotrack-view-details')
                            .attr('data-participant-id', result.participant_id)
                            .html(flagEmoji + '<strong>' + this.escapeHtml(value) + '</strong>');
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

                // Make name clickable in fallback mode too (with flag)
                let flagEmoji = '';
                if (result.country && typeof CountryFlags !== 'undefined') {
                    flagEmoji = CountryFlags.getFlag(result.country);
                    if (flagEmoji) {
                        flagEmoji = flagEmoji + ' '; // Add space after flag
                    }
                }

                const nameLink = $('<a>')
                    .attr('href', '#')
                    .addClass('chronotrack-view-details')
                    .attr('data-participant-id', result.participant_id)
                    .html(flagEmoji + '<strong>' + this.escapeHtml(result.full_name) + '</strong>');
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

                    // CRITICAL FIX: Handle category_position with bracket_positions fallback
                    if ((attr === 'category_position' || attr === 'division_place' || attr === 'results_division_rank')) {
                        let catPosition = result[attr];

                        // If category_position is 0 or empty, try to get from bracket_positions
                        if ((!catPosition || catPosition == 0) && result.bracket_positions && typeof result.bracket_positions === 'object') {
                            // PRIORITY 1: If we have category name (e.g., "M50"), look for matching bracket position
                            const categoryName = result.category || result.bracket_name || result.results_primary_bracket_name || '';
                            if (categoryName && result.bracket_positions[categoryName] && result.bracket_positions[categoryName] > 0) {
                                return result.bracket_positions[categoryName];
                            }

                            // PRIORITY 2: Use first NON-SEX bracket with position > 0
                            const brackets = Object.keys(result.bracket_positions);
                            let fallbackPosition = 0;

                            for (let j = 0; j < brackets.length; j++) {
                                const bracketName = brackets[j];
                                const position = result.bracket_positions[bracketName];
                                if (position && position > 0) {
                                    // Skip SEX brackets (M, K, F) if we can find other brackets
                                    if (!['M', 'K', 'F', 'Male', 'Female'].includes(bracketName)) {
                                        return position;  // Found non-SEX bracket
                                    } else if (fallbackPosition === 0) {
                                        fallbackPosition = position;  // Store SEX as fallback
                                    }
                                }
                            }

                            // PRIORITY 3: Use SEX bracket as last resort
                            if (fallbackPosition > 0) {
                                return fallbackPosition;
                            }
                        }

                        // Return category_position if it exists and > 0
                        if (catPosition && catPosition > 0) {
                            return catPosition;
                        }
                    }

                    // Try direct attribute - accept 0 as valid value (except for category_position handled above)
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
            const distance = this.selectedDistance;

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            tbody.find('tr').each(function() {
                const row = $(this);
                const name = row.find('.col-name, .col-full_name').text().toLowerCase();
                const bib = row.find('.col-bib, .col-entry_bib').text().toLowerCase();
                const rowCategory = row.find('.col-category').text();
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

            // CRITICAL FIX: Filter brackets by selected distance
            const currentDistance = this.selectedDistance;

            // Collect unique brackets from bracket_positions for SELECTED DISTANCE ONLY
            results.forEach((result) => {
                // Skip if distance doesn't match current filter
                if (currentDistance && result.distance !== currentDistance) {
                    return;
                }

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

            // Remove all options
            categorySelect.find('option').remove();

            // Add "Open" option to show all results
            categorySelect.append(
                $('<option>').val('').text('Open (wszystkie)')
            );

            // Add categories sorted alphabetically
            Array.from(brackets).sort().forEach((bracket) => {
                categorySelect.append(
                    $('<option>').val(bracket).text(bracket)
                );
            });

            // Restore previous value if it exists
            const optionExists = Array.from(categorySelect.find('option')).some(opt => opt.value === currentValue);
            if (optionExists) {
                categorySelect.val(currentValue);
            } else {
                // Default to "Open" (show all)
                categorySelect.val('');
            }
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
                        this.renderParticipantDetails(response.data.participant, response.data.event);
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

        renderParticipantDetails: function(participant, event) {
            // Polish translations for participant details
            let html = '<div class="chronotrack-participant-details">';

            // Add country flag before name if available
            let flagEmoji = '';
            if (participant.country && typeof CountryFlags !== 'undefined') {
                flagEmoji = CountryFlags.getFlag(participant.country);
                if (flagEmoji) {
                    flagEmoji = flagEmoji + ' '; // Add space after flag
                }
            }

            html += '<h2>' + flagEmoji + this.escapeHtml(participant.full_name) + '</h2>';

            // Event info header
            if (event) {
                html += '<div class="chronotrack-event-info-header">';
                if (event.name) {
                    html += '<div class="event-info-item"><strong>Bieg:</strong> ' + this.escapeHtml(event.name) + '</div>';
                }
                if (event.date) {
                    const eventDate = new Date(event.date);
                    const formattedDate = eventDate.toLocaleDateString('pl-PL', { year: 'numeric', month: 'long', day: 'numeric' });
                    html += '<div class="event-info-item"><strong>Data:</strong> ' + formattedDate + '</div>';
                }
                if (participant.distance) {
                    html += '<div class="event-info-item"><strong>Dystans:</strong> ' + this.escapeHtml(participant.distance) + '</div>';
                }
                html += '</div>';
            }

            // CHANGED: 2-column layout with split times below
            html += '<div class="chronotrack-details-grid-2col">';

            // Column 1: Basic info
            html += '<div class="chronotrack-details-column">';

            // Basic info - Podstawowe informacje
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Podstawowe informacje</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Numer startowy:</th><td>' + this.escapeHtml(this.cleanValue(participant.bib_number)) + '</td></tr>';
            html += '<tr><th>Płeć:</th><td>' + this.escapeHtml(this.cleanValue(participant.gender)) + '</td></tr>';
            html += '<tr><th>Miejscowość:</th><td>' + this.escapeHtml(this.cleanValue(participant.city)) + '</td></tr>';
            html += '<tr><th>Klub:</th><td>' + this.escapeHtml(this.cleanValue(participant.club)) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            html += '</div>'; // End column 1

            // Column 2: Results + Bracket positions (ONLY with position > 0)
            html += '<div class="chronotrack-details-column">';
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Wyniki</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Miejsce Open:</th><td class="chronotrack-position">' + this.formatPosition(participant.position) + '</td></tr>';
            html += '<tr><th>Miejsce M/K:</th><td class="chronotrack-position">' + this.formatPosition(participant.gender_position) + '</td></tr>';

            // CRITICAL FIX: Add bracket positions HERE (in Results section), ONLY if position > 0
            if (participant.bracket_positions && Object.keys(participant.bracket_positions).length > 0) {
                const sortedBrackets = Object.keys(participant.bracket_positions).sort();
                sortedBrackets.forEach((bracketName) => {
                    const position = participant.bracket_positions[bracketName];
                    // ONLY show brackets with actual position (> 0)
                    if (position && position > 0) {
                        // CRITICAL FIX: Skip SEX brackets (M, K, F) if they duplicate gender_position
                        // This prevents showing "Miejsce M/K: 6" and then "M: 6" (redundant)
                        const isSexBracket = ['M', 'K', 'F', 'Male', 'Female', 'Mężczyźni', 'Kobiety'].includes(bracketName);
                        if (isSexBracket && position == participant.gender_position) {
                            return; // Skip this bracket - it's already shown as "Miejsce M/K"
                        }

                        html += '<tr><th>' + this.escapeHtml(bracketName) + ':</th><td class="chronotrack-position">' + position + '</td></tr>';
                    }
                });
            }

            html += '<tr><th>Czas brutto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</td></tr>';
            html += '<tr><th>Czas netto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.net_time) + '</td></tr>';

            html += '</table>';
            html += '</div>';
            html += '</div>'; // End column 2

            html += '</div>'; // End grid-2col

            // Split Times BELOW the 2-column layout (full width)
            if (participant.split_times && participant.split_times.length > 0) {
                html += '<div class="chronotrack-details-section chronotrack-splits-full-width">';
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

                // Add META (finish line) at the end with finish time and overall position
                html += '<tr>';
                html += '<th>Meta:</th>';
                html += '<td>';
                html += '<span class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</span>';
                if (participant.position && participant.position > 0) {
                    html += ' <span class="chronotrack-split-position">(mce: ' + participant.position + ')</span>';
                }
                // Add pace with unit if available
                if (participant.pace) {
                    const paceUnit = 'min/km'; // Default assumption for metric
                    html += ' <span class="chronotrack-pace">(' + this.escapeHtml(participant.pace) + ' ' + paceUnit + ')</span>';
                }
                html += '</td>';
                html += '</tr>';

                html += '</table>';
                html += '</div>';
            }

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
            const wasEmpty = !this.selectedDistance;
            if (!this.selectedDistance && this.distances.length > 0) {
                this.selectedDistance = this.distances[0];
                console.log('📏 Auto-selected first distance:', this.selectedDistance);
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

            // Add "Generuj PDF" button if there's a selected distance
            if (this.selectedDistance && chronotrackData.userCanGeneratePDF) {
                const pdfBtn = $('<button>')
                    .addClass('chronotrack-generate-pdf-btn')
                    .html('📄 Generuj PDF')
                    .attr('data-distance', this.selectedDistance)
                    .css({
                        'margin-left': '20px',
                        'background': '#28a745',
                        'color': '#fff',
                        'border': '1px solid #28a745',
                        'padding': '8px 16px',
                        'border-radius': '4px',
                        'cursor': 'pointer',
                        'font-size': '14px'
                    });
                container.append(pdfBtn);
            }

            // NOTE: Don't call filterResults() here - it will be called in renderResults()
            // after rows are actually added to the table
        },

        selectDistance: function(distance) {
            this.selectedDistance = distance;
            console.log('📏 Selected distance:', distance || 'Wszystkie');

            // Update button states
            $('.chronotrack-distance-filter-btn').removeClass('active');
            $('.chronotrack-distance-filter-btn[data-distance="' + distance + '"]').addClass('active');

            // CRITICAL FIX: Update category filter to show only categories for this distance
            // Get all results from table to repopulate filters
            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            const results = [];
            tbody.find('tr[data-bib]').each(function() {
                const row = $(this);
                const result = {
                    distance: row.attr('data-distance'),
                    category: row.find('.col-category').text(),
                    bracket_positions: {}
                };
                try {
                    const bracketData = row.attr('data-bracket-positions');
                    if (bracketData) {
                        result.bracket_positions = JSON.parse(bracketData);
                    }
                } catch (e) {
                    // Ignore
                }
                results.push(result);
            });

            this.populateFilters(results);  // Update category filter for selected distance

            // Filter results
            this.filterResults();
        },

        checkEventStatusAndStartRefresh: function() {
            const status = chronotrackData.eventStatus || 'live';
            const eventDate = chronotrackData.eventDate;

            console.log('📊 Event status:', status, 'Event date:', eventDate);

            // Handle different event statuses
            if (status === 'completed') {
                // Event is completed - show final results, NO auto-refresh
                console.log('🏁 Event completed - showing final results (no auto-refresh)');
                $('.chronotrack-live-text').text('ZAWODY ZAKOŃCZONE').css('color', '#856404');
                $('.chronotrack-live-indicator').css('background', '#fff3cd');
                this.loadResults(this.currentView);  // Load once from database
                return;
            }

            if (status === 'upcoming' && eventDate) {
                // Event is upcoming - check if it's time to start
                const now = new Date();
                const startTime = new Date(eventDate);

                console.log('⏰ Current time:', now);
                console.log('⏰ Event start time:', startTime);

                if (now < startTime) {
                    // Still before event start - show countdown, NO auto-refresh yet
                    const hours = Math.floor((startTime - now) / (1000 * 60 * 60));
                    const minutes = Math.floor(((startTime - now) % (1000 * 60 * 60)) / (1000 * 60));

                    const formattedDate = startTime.toLocaleDateString('pl-PL', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });

                    let countdownMsg = 'Zawody rozpoczną się ' + formattedDate;
                    if (hours > 0) {
                        countdownMsg += ' (za ' + hours + 'h ' + minutes + 'min)';
                    } else if (minutes > 0) {
                        countdownMsg += ' (za ' + minutes + ' minut)';
                    } else {
                        countdownMsg += ' (już niebawem!)';
                    }

                    console.log('⏳ Event not started yet:', countdownMsg);
                    $('.chronotrack-live-text').text('ZAWODY NADCHODZĄCE').css('color', '#856404');
                    $('.chronotrack-live-indicator').css('background', '#fff3cd');
                    this.showUpcomingMessage(countdownMsg, true);

                    // Check every minute if it's time to start
                    setInterval(() => {
                        const nowCheck = new Date();
                        if (nowCheck >= startTime) {
                            console.log('🚀 Event time reached! Starting auto-refresh...');
                            location.reload(); // Reload page to start auto-refresh
                        }
                    }, 60000); // Check every minute

                    return;
                }
            }

            // Status is 'live' OR 'upcoming' with time passed - start auto-refresh
            console.log('▶️ Event is LIVE - starting auto-refresh');
            $('.chronotrack-live-text').text('NA ŻYWO').css('color', '#dc3545');
            $('.chronotrack-live-indicator').css('background', '');
            this.startAutoRefresh();
        },

        showUpcomingMessage: function(message, showEmptyTable) {
            // Show message above results area
            const container = $('.chronotrack-results-container');
            const messageHtml = '<div class="chronotrack-upcoming-message" style="' +
                'padding: 20px; ' +
                'margin: 20px 0; ' +
                'background: #fff3cd; ' +
                'border: 1px solid #ffc107; ' +
                'border-radius: 4px; ' +
                'text-align: center; ' +
                'font-size: 16px; ' +
                'font-weight: 600; ' +
                'color: #856404;">' +
                message +
                '</div>';

            container.find('.chronotrack-controls').after(messageHtml);

            if (!showEmptyTable) {
                // Hide distance filters and category filter for completed events
                container.find('.chronotrack-distance-filters').hide();
                container.find('.chronotrack-filters').hide();
            }
        },

        startAutoRefresh: function() {
            // Fetch from API every 60 seconds for live updates
            const interval = 60000; // 60 seconds
            this.currentInterval = interval;

            console.log('▶️ Starting auto-refresh with interval:', interval + 'ms (60s API fetch)');

            // CRITICAL FIX: Initial load from CACHE (fast), then API fetch every 60s
            this.loadResults(this.currentView);  // Fast load from database

            // Fetch fresh data from API every 60 seconds
            this.refreshInterval = setInterval(() => {
                this.refreshFromAPI();  // Fetch from API
            }, interval);
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

        /**
         * Generate PDF for currently selected distance
         */
        generatePDF: function() {
            if (!this.selectedDistance) {
                alert('Proszę wybrać dystans przed generowaniem PDF.');
                return;
            }

            const btn = $('.chronotrack-generate-pdf-btn');
            const originalHtml = btn.html();

            // Show loading state
            btn.prop('disabled', true).html('⏳ Generowanie PDF...');

            console.log('📄 Generating PDF for distance:', this.selectedDistance);

            $.ajax({
                url: chronotrackData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'chronotrack_generate_pdf',
                    nonce: chronotrackData.adminNonce,
                    event_id: chronotrackData.eventId,
                    distance: this.selectedDistance
                },
                success: (response) => {
                    console.log('✅ PDF generation response:', response);

                    if (response.success) {
                        // Create download link
                        const downloadLink = $('<a>')
                            .attr('href', response.data.download_url)
                            .attr('download', response.data.filename)
                            .css('display', 'none')
                            .appendTo('body');

                        // Trigger download
                        downloadLink[0].click();

                        // Clean up
                        setTimeout(() => downloadLink.remove(), 100);

                        // Show success message
                        alert('PDF wygenerowany pomyślnie! Pobieranie rozpoczęte.');
                    } else {
                        alert('Błąd: ' + (response.data.message || 'Nie udało się wygenerować PDF.'));
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ PDF generation failed:', error, xhr.responseText);
                    alert('Błąd podczas generowania PDF. Sprawdź czy TCPDF jest zainstalowany.');
                },
                complete: () => {
                    // Restore button state
                    btn.prop('disabled', false).html(originalHtml);
                }
            });
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
