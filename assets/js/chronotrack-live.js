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
        sortColumn: null, // Currently sorted column
        sortDirection: 'asc', // Sort direction: 'asc' or 'desc'
        allResults: [], // Store all results for sorting

        init: function() {
            console.log('=== ChronoTrack Live Init START ===');

            // Global AJAX error handler for nonce expiration
            $(document).ajaxError((event, jqXHR, ajaxSettings, thrownError) => {
                // Check if error is 403 Forbidden (nonce expired)
                if (jqXHR.status === 403) {
                    console.error('❌ 403 Forbidden - Nonce expired!');

                    // Show user-friendly message
                    const message = 'Sesja wygasła. Strona zostanie odświeżona za 3 sekundy...';
                    alert(message);

                    // Auto-reload page after 3 seconds
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                }
            });

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

            // CRITICAL: Change column header from "M/K" to "K/M" in the DOM
            this.updateColumnHeaders();

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

            // Column sorting
            $(document).on('click', '.chronotrack-results-table thead th.sortable', (e) => {
                const column = $(e.currentTarget).data('column');
                this.sortByColumn(column);
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

            // Manual refresh button (admin only)
            $(document).on('click', '.chronotrack-manual-refresh', (e) => {
                e.preventDefault();
                console.log('🔄 Manual refresh triggered - FULL MODE (fetch all data)');
                this.refreshFromAPI('full'); // Full refresh: entries + results
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

        updateColumnHeaders: function() {
            // Change column header text from "K/M" back to "M/K"
            // M is on LEFT, K is on RIGHT, so header should be M/K
            $('.chronotrack-results-table thead th').each(function() {
                const headerText = $(this).html();
                if (headerText && headerText.includes('K/M')) {
                    const newText = headerText.replace(/K\/M/g, 'M/K');
                    $(this).html(newText);
                    console.log('✏️ Updated column header from "K/M" to "M/K"');
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

                            // CRITICAL: Change column header from "K/M" back to "M/K"
                            // M is on LEFT, K is on RIGHT, so header is M/K
                            this.columns.forEach((column) => {
                                if (column.column_name && column.column_name.includes('K/M')) {
                                    column.column_name = column.column_name.replace('K/M', 'M/K');
                                    console.log('✏️ Renamed column header to:', column.column_name);
                                }
                            });
                        }

                        // Save distances (but don't render yet - need results first)
                        if (response.data.distances && response.data.distances.length > 0) {
                            this.distances = response.data.distances;
                            console.log('📏 Distances loaded:', this.distances.length);
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

                            // CRITICAL: Default sort by position on first load
                            if (!this.sortColumn && this.allResults.length > 0) {
                                console.log('📊 First load - sorting by position');
                                this.sortByColumn('position');
                            }
                        }

                        // CRITICAL FIX: Render distance buttons AFTER results are rendered
                        // This ensures allResults is populated so counts are correct
                        if (this.distances && this.distances.length > 0) {
                            this.renderDistanceButtons();
                        }

                        this.updateTimestamp();
                        this.updateStats(); // Update participant statistics
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

        refreshFromAPI: function(mode = 'live') {
            // Prevent concurrent requests
            if (this.isLoading) {
                console.log('⏳ Already loading, skipping...');
                return;
            }

            this.isLoading = true;
            this.showLoading();

            console.log('📡 Refreshing from API for event:', this.eventId, '| Mode:', mode);
            if (mode === 'live') {
                console.log('⚡ LIVE MODE: Only fetching results (times/positions), using cached personal data');
            } else {
                console.log('🔄 FULL MODE: Fetching entries + results (personal data + times/positions)');
            }

            $.ajax({
                url: chronotrackData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chronotrack_refresh_results',
                    event_id: this.eventId,
                    mode: mode, // 'live' or 'full'
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
                            // Don't render buttons yet - wait until results are loaded
                        }

                        if (response.data.columns && response.data.columns.length > 0) {
                            this.columns = response.data.columns;
                            console.log('📋 Columns updated from refresh:', this.columns.length);
                        }

                        // CRITICAL FIX: Reset isLoading BEFORE calling loadResults
                        // Otherwise loadResults will skip because isLoading is still true
                        this.isLoading = false;
                        this.hideLoading();

                        // After API refresh, reload from cache to get full data
                        // renderDistanceButtons will be called after renderResults in loadResults()
                        console.log('📥 Calling loadResults after API refresh...');
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
                    // Reset isLoading on error
                    this.isLoading = false;
                    this.hideLoading();
                },
                complete: () => {
                    console.log('✅ API refresh complete');
                    // isLoading is already reset in success or error handlers
                }
            });
        },

        renderResults: function(results, forceRebuild) {
            console.log('🎨 renderResults called, results:', results ? results.length : 'NULL', 'forceRebuild:', forceRebuild);
            const tbody = $('#chronotrack-results-body');

            if (!results || results.length === 0) {
                console.log('❌ No results to render');
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak wyników</td></tr>');
                this.allResults = [];
                return;
            }

            // CRITICAL: Filter out participants without finish time (on course, DNS, etc.)
            // Only show participants who have crossed the finish line
            const finishedResults = results.filter(r => {
                const time = r.finish_time || r.net_time;
                return time && time !== '-' && time !== '00:00:00' && time !== '';
            });

            console.log('📊 Filtered results:', results.length, '→', finishedResults.length, '(removed', results.length - finishedResults.length, 'without finish)');

            // Store ALL results for stats (including on-course), but use filtered for display
            this.allResults = results;

            if (finishedResults.length === 0) {
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak ukończonych wyników</td></tr>');
                return;
            }

            // CRITICAL FIX: If forceRebuild is true (from sorting), clear table and rebuild from scratch
            if (forceRebuild) {
                console.log('🔄 Force rebuild - clearing table and rebuilding in sorted order');
                tbody.empty();

                // Use FILTERED results (only finished) for display
                const filteredForDisplay = this.allResults.filter(r => {
                    const time = r.finish_time || r.net_time;
                    return time && time !== '-' && time !== '00:00:00' && time !== '';
                });

                filteredForDisplay.forEach((result) => {
                    const newRow = this.createResultRow(result);
                    tbody.append(newRow);
                });

                // Apply filters after rebuild
                if (this.selectedDistance) {
                    console.log('🔄 Applying distance filter after rebuild:', this.selectedDistance);
                    this.filterResults();  // This also calls updateSplitTimeColumnVisibility()
                } else {
                    this.recolorRows(tbody);
                    this.updateSplitTimeColumnVisibility();
                }

                console.log('✅ Rebuilt table with', filteredForDisplay.length, 'finished results in sorted order');
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

            // Update or add each result (ONLY finished participants)
            finishedResults.forEach((result, index) => {
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
                // Update split_time column visibility based on all visible rows
                this.updateSplitTimeColumnVisibility();
            }

            console.log('✅ Rendered', results.length, 'results (background update, preserved filters)');
        },

        createResultRow: function(result) {
            const row = $('<tr>')
                .attr('data-result-id', result.id)
                .attr('data-bib', result.bib_number)
                .attr('data-distance', result.distance || '')
                .attr('data-bracket-positions', JSON.stringify(result.bracket_positions || {}));

            // Get country flag for separate column (will be added AFTER name column)
            const countryCode = result.country || result.Country || result.nationality || result.Nationality ||
                              result.athlete_country || result.country_code || result.CountryCode;
            let flagEmoji = '';
            if (countryCode && typeof CountryFlags !== 'undefined') {
                flagEmoji = CountryFlags.getFlag(countryCode) || '';
            }

            // Use dynamic columns if available
            if (this.columns && this.columns.length > 0) {
                this.columns.forEach((column) => {
                    const value = this.getColumnValue(result, column);
                    const cell = $('<td>').addClass('col-' + column.id);

                    // CRITICAL DEBUG: Log EVERY column to identify the gender position column
                    if (result.id === '1' || Math.random() < 0.05) {  // Log first result + 5% sample
                        console.log('🔍 DEBUG Column:', {
                            'column_id': column.id,
                            'column_name': column.column_name,
                            'value': value,
                            'result_id': result.id,
                            'athlete_gender': result.gender || result.sex || result.athlete_sex || 'UNKNOWN'
                        });
                    }

                    // Special formatting for full_name - make it clickable WITHOUT flag (flag is in separate column now)
                    if (column.id === 'full_name' || column.id.includes('name')) {
                        const nameLink = $('<a>')
                            .attr('href', '#')
                            .addClass('chronotrack-view-details')
                            .attr('data-participant-id', result.participant_id)
                            .html('<strong>' + this.escapeHtml(value) + '</strong>');
                        cell.append(nameLink);
                        row.append(cell);

                        // Add flag column RIGHT AFTER name column
                        const flagCell = $('<td>').addClass('col-flag').css({'text-align': 'center', 'font-size': '20px'}).html(flagEmoji);
                        row.append(flagCell);
                    }
                    // Special formatting for gender POSITION column (Msc M/K)
                    // CRITICAL: 6-digit layout split 50/50:
                    // Left 3 chars (35px) = Men, RIGHT aligned (number ends near middle)
                    // Right 3 chars (35px) = Women, LEFT aligned (number starts near middle)
                    else if (column.id === 'gender_position' || column.id === 'sex_place' || column.id === 'sex_position' ||
                             column.id.toLowerCase().includes('gender_position') || column.id.toLowerCase().includes('sex_place') ||
                             column.id.toLowerCase().includes('m/k') || column.id.toLowerCase().includes('k/m') ||
                             (column.column_name && (column.column_name.includes('M/K') || column.column_name.includes('K/M')))) {
                        // Get athlete's gender from result data
                        const athleteGender = result.gender || result.sex || result.athlete_sex || '';
                        let cellStyle = {};

                        // Push BOTH left by same amount
                        const cellElem = cell[0];
                        if (athleteGender === 'M' || athleteGender === 'Male' || athleteGender === 'Mężczyźni') {
                            // M: RIGHT align so double digits grow LEFT (0 stays put, 1 extends left)
                            cellElem.style.setProperty('text-align', 'right', 'important');
                            cellElem.style.setProperty('padding', '6px 54px 6px 2px', 'important');  // Reduced from 60px to 54px
                            cellElem.style.setProperty('direction', 'ltr', 'important');  // Ensure left-to-right
                            console.log('🎨 M/K Column | ID:', result.id, '| Gender: M | Value:', value, '| Pad-R: 54px');
                        } else if (athleteGender === 'K' || athleteGender === 'F' || athleteGender === 'Female') {
                            // K: LEFT align, shifted left to be under "K"
                            cellElem.style.setProperty('text-align', 'left', 'important');
                            cellElem.style.setProperty('padding', '6px 2px 6px 24px', 'important');  // Reduced from 30px to 24px
                            cellElem.style.setProperty('direction', 'ltr', 'important');
                            console.log('🎨 M/K Column | ID:', result.id, '| Gender: K | Value:', value, '| Pad-L: 24px');
                        } else {
                            // Unknown gender - center align
                            cellElem.style.setProperty('text-align', 'center', 'important');
                            console.warn('⚠️ Unknown gender:', athleteGender, 'for result:', result.id);
                        }
                        cell.text(this.cleanValue(value));
                        row.append(cell);
                    } else {
                        cell.text(this.cleanValue(value));
                        row.append(cell);
                    }
                });
            } else {
                // Fallback to hardcoded columns
                row.append($('<td>').addClass('col-position').text(this.cleanValue(result.position)));
                row.append($('<td>').addClass('col-bib').text(this.cleanValue(result.bib_number)));

                // Make name clickable WITHOUT flag (flag is in separate column)
                const nameLink = $('<a>')
                    .attr('href', '#')
                    .addClass('chronotrack-view-details')
                    .attr('data-participant-id', result.participant_id)
                    .html('<strong>' + this.escapeHtml(result.full_name) + '</strong>');
                row.append($('<td>').addClass('col-name').append(nameLink));

                // Add flag column RIGHT AFTER name column
                const flagCell = $('<td>').addClass('col-flag').css({'text-align': 'center', 'font-size': '20px'}).html(flagEmoji);
                row.append(flagCell);

                row.append($('<td>').addClass('col-category').text(this.cleanValue(result.category)));
                row.append($('<td>').addClass('col-club').text(this.cleanValue(result.club)));
                row.append($('<td>').addClass('col-time').text(this.cleanValue(result.finish_time)));
            }

            // No separate actions column - name is now clickable

            return row;
        },

        getColumnValue: function(result, column) {
            // DEBUG: Log raw result object to see all available fields
            if (column.label && (column.label === 'Rok ur.' || column.label.includes('Dystans') || column.label.includes('Tempo'))) {
                console.log('🔴 RAW RESULT for column "' + column.label + '":', result);
            }

            // Try each API attribute in order until we find a value
            if (column.api_attributes && column.api_attributes.length > 0) {
                for (let i = 0; i < column.api_attributes.length; i++) {
                    const attr = column.api_attributes[i];

                    // Handle special case for split_time (międzyczasy)
                    // Format: split_time:IntervalName (np. split_time:5km)
                    if (attr.startsWith('split_time:')) {
                        const intervalName = attr.substring(11); // Remove "split_time:" prefix

                        // CRITICAL FIX: Parse split_times if it's a JSON string
                        let splitTimes = result.split_times;
                        if (typeof splitTimes === 'string') {
                            try {
                                splitTimes = JSON.parse(splitTimes);
                            } catch (e) {
                                console.error('Failed to parse split_times:', e);
                                return '';
                            }
                        }

                        if (splitTimes && Array.isArray(splitTimes)) {
                            // Find split time with matching interval name
                            const split = splitTimes.find(s =>
                                s.interval_name === intervalName ||
                                s.name === intervalName
                            );
                            if (split) {
                                return split.formatted_time || split.time || '';
                            }
                        }
                        return ''; // No split time found - return empty (not dash)
                    }

                    // Handle special case for full_name
                    if (attr === 'full_name' || attr === 'athlete_last_name,athlete_first_name') {
                        // CRITICAL FIX: DO NOT add flags here - flags are added in createResultRow()
                        // Adding flags here causes them to be escaped by escapeHtml()

                        // Always format as "Nazwisko Imię"
                        if (result.last_name || result.first_name) {
                            const lastName = (result.last_name || '').trim();
                            const firstName = (result.first_name || '').trim();
                            return lastName + (lastName && firstName ? ' ' : '') + firstName;
                        }
                        // Fallback to full_name if no first/last name available
                        if (result.full_name) {
                            return result.full_name;
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

                    // CRITICAL: Handle birth_year - extract year from date if needed
                    if (attr === 'birth_year' || attr === 'birthdate' || attr === 'athlete_birthdate') {
                        let birthValue = result[attr] || result.birth_year || result.birthdate || result.athlete_birthdate;
                        console.log('🔴 BIRTH_YEAR DEBUG:', {
                            attr: attr,
                            birthValue: birthValue,
                            'result[attr]': result[attr],
                            'result.birth_year': result.birth_year,
                            'result.birthdate': result.birthdate,
                            'result.athlete_birthdate': result.athlete_birthdate
                        });
                        if (birthValue) {
                            // If it's a full date (YYYY-MM-DD or similar), extract year
                            if (typeof birthValue === 'string' && birthValue.includes('-')) {
                                const year = birthValue.split('-')[0];
                                if (year && year.length === 4) {
                                    console.log('🔴 BIRTH_YEAR EXTRACTED FROM DATE:', year);
                                    return year;
                                }
                            }
                            // If it's already just a year (number or 4-digit string), return it
                            if (typeof birthValue === 'number' || (typeof birthValue === 'string' && birthValue.length === 4)) {
                                console.log('🔴 BIRTH_YEAR DIRECT:', birthValue);
                                return birthValue;
                            }
                        }
                        console.log('🔴 BIRTH_YEAR NOT FOUND - returning "-"');
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
                const club = row.find('.col-club').text().toLowerCase();
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

                // Search filter - search by name, bib number, OR club name
                if (searchTerm && !name.includes(searchTerm) && !bib.includes(searchTerm) && !club.includes(searchTerm)) {
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

            // CRITICAL: Hide empty split_time columns after filtering
            this.updateSplitTimeColumnVisibility();
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

        updateSplitTimeColumnVisibility: function() {
            // Hide split_time columns that have no data for currently visible rows
            if (!this.columns || this.columns.length === 0) {
                console.log('⚠️ No columns configured, skipping column visibility update');
                return; // No dynamic columns configured
            }

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            const thead = tbody.closest('table').find('thead');

            console.log('🔍 Checking split_time column visibility for', this.columns.length, 'columns');

            // For each column, check if it's a split_time column and if any visible row has data
            this.columns.forEach((column) => {
                const isSplitTimeColumn = column.api_attributes &&
                    column.api_attributes.some(attr => attr.startsWith('split_time:'));

                if (!isSplitTimeColumn) {
                    return; // Not a split_time column, keep visible
                }

                const columnClass = 'col-' + column.id;
                console.log('🔍 Checking split_time column:', column.id, 'class:', columnClass);

                // Check if any visible row has data for this split_time column
                let hasData = false;
                tbody.find('tr:visible').each(function() {
                    const cell = $(this).find('.' + columnClass);
                    const cellText = cell.text().trim();
                    // Check if cell has actual time data (not empty, not '-')
                    if (cellText && cellText !== '-' && cellText !== '') {
                        hasData = true;
                        console.log('✅ Column', columnClass, 'has data:', cellText);
                        return false; // Break loop
                    }
                });

                // Show/hide column header and all cells
                if (hasData) {
                    console.log('✅ Showing column:', columnClass);
                    thead.find('.' + columnClass).show();
                    tbody.find('.' + columnClass).show();
                } else {
                    console.log('❌ Hiding empty column:', columnClass);
                    thead.find('.' + columnClass).hide();
                    tbody.find('.' + columnClass).hide();
                }
            });

            console.log('✅ Column visibility update complete');
        },

        showAllSplitTimeColumns: function() {
            // Show all split_time columns when no distance filter is active
            if (!this.columns || this.columns.length === 0) {
                return;
            }

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            const thead = tbody.closest('table').find('thead');

            // Show all split_time columns
            this.columns.forEach((column) => {
                const isSplitTimeColumn = column.api_attributes &&
                    column.api_attributes.some(attr => attr.startsWith('split_time:'));

                if (isSplitTimeColumn) {
                    const columnClass = 'col-' + column.id;
                    thead.find('.' + columnClass).show();
                    tbody.find('.' + columnClass).show();
                }
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
                $('<option>').val('').text('Open')
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
            let flagHtml = '';
            if (participant.country && typeof CountryFlags !== 'undefined') {
                flagHtml = CountryFlags.getFlag(participant.country);
                if (flagHtml) {
                    flagHtml = flagHtml + ' '; // Add space after flag
                }
            }

            html += '<h2>' + flagHtml + this.escapeHtml(participant.full_name) + '</h2>';

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
                        // CRITICAL FIX: Skip "Overall" bracket - it duplicates "Miejsce Open"
                        if (bracketName === 'Overall' && position == participant.position) {
                            return; // Skip - already shown as "Miejsce Open"
                        }

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
            // Use detailed_splits which has all the enriched data (pace, distance, etc.)
            const splitsData = participant.detailed_splits && participant.detailed_splits.length > 0
                ? participant.detailed_splits
                : participant.split_times;

            if (splitsData && splitsData.length > 0) {
                html += '<div class="chronotrack-details-section chronotrack-splits-full-width">';
                html += '<h3>Międzyczasy</h3>';
                html += '<table class="chronotrack-splits-table">';

                // Table header
                html += '<thead>';
                html += '<tr>';
                html += '<th>Punkt</th>';
                html += '<th>Dystans</th>';
                html += '<th>Czas</th>';
                html += '<th>Miejsce</th>';
                html += '<th>Tempo</th>';
                html += '</tr>';
                html += '</thead>';

                html += '<tbody>';

                // Split times rows - track previous values for trend arrows
                let previousPosition = 0;
                let previousPaceSeconds = 0;
                let fullCoursePace = null;  // Store pace from Full Course for Meta row

                splitsData.forEach((split, index) => {
                    console.log('🔴 SPLIT:', split);

                    // Use data directly from API
                    const intervalName = split.checkpoint_name || split.interval_name;
                    const formattedTime = split.checkpoint_time || split.formatted_time;
                    const position = split.checkpoint_position || split.rank || split.position;

                    // CRITICAL: Try multiple field names for distance (detailed_splits uses cumulative_distance_km)
                    let distanceKm = parseFloat(split.cumulative_distance_km) || parseFloat(split.distance_km) || 0;
                    console.log('📏 DISTANCE DEBUG:', {
                        'checkpoint': intervalName,
                        'cumulative_distance_km (raw)': split.cumulative_distance_km,
                        'distance_km (raw)': split.distance_km,
                        'distanceKm (parsed)': distanceKm
                    });

                    const segmentPace = split.segment_pace;
                    const averagePace = split.average_pace;  // Average pace from start (from API)
                    const paceUnit = split.pace_unit || 'min/km';
                    const showPace = split.show_pace !== 0;  // show_pace = 0 means hide pace

                    // Skip "Full Course" - it's the same as Meta (net time)
                    // But save its pace to use in Meta row
                    if (intervalName === 'Full Course') {
                        const paceToUse = averagePace || segmentPace || split.pace || split.formatted_pace;
                        if (paceToUse && paceToUse !== '-') {
                            fullCoursePace = {
                                value: paceToUse,
                                unit: paceUnit
                            };
                            console.log('💾 Saved Full Course pace for Meta:', fullCoursePace);
                        }
                        return;  // Skip rendering this row
                    }

                    if (intervalName && formattedTime) {

                        // Interval name WITHOUT distance (distance goes to separate column)
                        let intervalLabel = this.escapeHtml(intervalName);

                        // Distance in separate column
                        let distanceHtml = '-';
                        if (distanceKm > 0) {
                            // Numeric value - format it
                            distanceHtml = distanceKm.toFixed(2) + ' km';
                        }

                        // Use AVERAGE pace from API (pace from start to this checkpoint - results_pace from API)
                        // Fallback to segment pace if average not available
                        let paceDisplay = '-';
                        let paceValue = '-';
                        const paceToUse = averagePace || segmentPace;  // Prefer average pace from API

                        if (paceToUse && paceToUse !== '-' && showPace) {
                            // Convert HH:MM:SS to MM:SS if needed
                            paceValue = this.escapeHtml(paceToUse);
                            paceValue = this.formatPaceTime(paceValue);

                            // Handle different unit cases
                            if (paceUnit === 'none' || paceUnit === '-') {
                                // Don't show pace
                                paceDisplay = '-';
                            } else if (paceUnit === 'km/h') {
                                // User wants speed in km/h
                                if (paceValue.includes(':')) {
                                    // Value is in pace format (MM:SS), convert to speed
                                    const speed = this.paceToSpeed(paceValue);
                                    paceDisplay = speed !== '-' ? speed + ' km/h' : '-';
                                } else {
                                    // Value is already a speed number
                                    paceDisplay = paceValue + ' km/h';
                                }
                            } else {
                                // Default: show as pace (min/km)
                                if (paceValue.includes(':')) {
                                    // Value is already in pace format (MM:SS)
                                    paceDisplay = paceValue + ' min/km';
                                } else {
                                    // Value is a speed, convert to pace
                                    const pace = this.speedToPace(paceValue);
                                    paceDisplay = pace !== '-' ? pace + ' min/km' : '-';
                                }
                            }
                        }

                        // Position with trend arrow
                        let positionHtml = '-';
                        if (position && position > 0) {
                            positionHtml = position.toString();

                            if (previousPosition > 0) {
                                if (position < previousPosition) {
                                    // Better position (moved up)
                                    const gain = previousPosition - position;
                                    positionHtml += ' <span class="trend-up" title="Awansował o ' + gain + '">▲</span>';
                                } else if (position > previousPosition) {
                                    // Worse position (moved down)
                                    const loss = position - previousPosition;
                                    positionHtml += ' <span class="trend-down" title="Spadł o ' + loss + '">▼</span>';
                                } else {
                                    // Same position
                                    positionHtml += ' <span class="trend-same">-</span>';
                                }
                            }
                            previousPosition = position;
                        }

                        // Pace with trend arrow (compare pace in seconds)
                        let paceHtml = paceDisplay;
                        if (paceDisplay !== '-') {
                            // Parse pace to seconds (extract time value before unit)
                            // segmentPace is like "5:12 min/km", we need just "5:12"
                            const paceTimeOnly = paceValue; // Use the formatted time value (before unit was added)
                            const paceSeconds = this.parsePaceToSeconds(paceTimeOnly);

                            if (previousPaceSeconds > 0 && paceSeconds > 0) {
                                if (paceSeconds < previousPaceSeconds) {
                                    // Faster pace (better)
                                    paceHtml += ' <span class="trend-up" title="Szybciej">▲</span>';
                                } else if (paceSeconds > previousPaceSeconds) {
                                    // Slower pace (worse)
                                    paceHtml += ' <span class="trend-down" title="Wolniej">▼</span>';
                                } else {
                                    // Same pace
                                    paceHtml += ' <span class="trend-same">-</span>';
                                }
                            }
                            previousPaceSeconds = paceSeconds;
                        }

                        html += '<tr>';
                        html += '<td>' + intervalLabel + '</td>';
                        html += '<td class="chronotrack-distance">' + distanceHtml + '</td>';
                        html += '<td class="chronotrack-time">' + this.escapeHtml(formattedTime) + '</td>';
                        html += '<td class="chronotrack-position">' + positionHtml + '</td>';
                        html += '<td class="chronotrack-pace">' + paceHtml + '</td>';
                        html += '</tr>';
                    }
                });

                // Add META (finish line) at the end
                // For META row, show AVERAGE pace (from start to finish)
                // CRITICAL: Get total distance from participant.distance (race distance), NOT from last split
                let finishDistanceKm = 0;
                let metaDistanceHtml = '-';

                // First, try to get distance from participant.distance (race distance)
                if (participant.distance) {
                    finishDistanceKm = this.parseDistanceToKm(participant.distance);
                    if (finishDistanceKm > 0) {
                        metaDistanceHtml = finishDistanceKm.toFixed(2) + ' km';
                    }
                }

                // Fallback: if no distance from participant, try last split
                if (finishDistanceKm === 0 && splitsData.length > 0) {
                    const lastSplit = splitsData[splitsData.length - 1];
                    // Check for cumulative_distance_km (from detailed_splits) or distance_km (from split_times)
                    const lastSplitDistance = lastSplit.cumulative_distance_km || lastSplit.distance_km;
                    if (lastSplitDistance && lastSplitDistance > 0) {
                        finishDistanceKm = parseFloat(lastSplitDistance);
                        metaDistanceHtml = finishDistanceKm.toFixed(2) + ' km';
                    } else if (lastSplit.distance_m && lastSplit.distance_m > 0) {
                        finishDistanceKm = lastSplit.distance_m / 1000;
                        metaDistanceHtml = finishDistanceKm.toFixed(2) + ' km';
                    }
                }

                // Calculate META pace (average pace from start to finish)
                // PRIORITY: Use pace from Full Course (net time pace from API)
                let finishPace = '-';
                if (fullCoursePace) {
                    // Use pace from Full Course split (this is the net time pace)
                    let paceValue = this.escapeHtml(fullCoursePace.value);
                    paceValue = this.formatPaceTime(paceValue);
                    const paceUnit = fullCoursePace.unit || 'min/km';

                    if (paceUnit === 'none' || paceUnit === '-') {
                        finishPace = '-';
                    } else if (paceUnit === 'km/h') {
                        if (paceValue.includes(':')) {
                            const speed = this.paceToSpeed(paceValue);
                            finishPace = speed !== '-' ? speed + ' km/h' : '-';
                        } else {
                            finishPace = paceValue + ' km/h';
                        }
                    } else {
                        if (paceValue.includes(':')) {
                            finishPace = paceValue + ' min/km';
                        } else {
                            const pace = this.speedToPace(paceValue);
                            finishPace = pace !== '-' ? pace + ' min/km' : '-';
                        }
                    }
                    console.log('✅ Using Full Course pace for Meta:', finishPace);
                } else if (participant.pace || participant.formatted_pace) {
                    // Fallback: use participant pace
                    let paceValue = this.escapeHtml(participant.pace || participant.formatted_pace);
                    paceValue = this.formatPaceTime(paceValue);

                    // Check if this is time-based pace (MM:SS) or speed (number)
                    if (paceValue.includes(':')) {
                        // Time-based pace (e.g., "4:58" in min/km)
                        finishPace = paceValue + ' min/km';
                    } else {
                        // Speed-based (e.g., "12.5 km/h")
                        finishPace = paceValue + ' km/h';
                    }
                } else if (finishDistanceKm > 0 && participant.finish_time) {
                    // Fallback: calculate from finish time and distance
                    const calculatedPace = this.calculateAveragePace(participant.finish_time, finishDistanceKm);
                    if (calculatedPace !== '-') {
                        // Calculated pace is always in min/km format (MM:SS)
                        finishPace = calculatedPace + ' min/km';
                    }
                }

                // META row: show position and pace with trends
                let metaPositionHtml = '-';
                if (participant.position && participant.position > 0) {
                    metaPositionHtml = participant.position.toString();

                    if (previousPosition > 0) {
                        if (participant.position < previousPosition) {
                            const gain = previousPosition - participant.position;
                            metaPositionHtml += ' <span class="trend-up" title="Awansował o ' + gain + '">▲</span>';
                        } else if (participant.position > previousPosition) {
                            const loss = participant.position - previousPosition;
                            metaPositionHtml += ' <span class="trend-down" title="Spadł o ' + loss + '">▼</span>';
                        } else {
                            metaPositionHtml += ' <span class="trend-same">-</span>';
                        }
                    }
                }

                html += '<tr class="chronotrack-finish-row">';
                html += '<td><strong>Meta</strong></td>';
                html += '<td class="chronotrack-distance"><strong>' + metaDistanceHtml + '</strong></td>';
                html += '<td class="chronotrack-time"><strong>' + this.escapeHtml(participant.finish_time) + '</strong></td>';
                html += '<td class="chronotrack-position"><strong>' + metaPositionHtml + '</strong></td>';
                html += '<td class="chronotrack-pace"><strong>' + finishPace + '</strong></td>';
                html += '</tr>';

                html += '</tbody>';
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

            // Count participants for each distance
            const distanceCounts = {};
            this.distances.forEach((distance) => {
                const count = this.allResults.filter(r => r.distance === distance).length;
                distanceCounts[distance] = count;
            });

            // Sort distances by participant count (descending - largest first)
            const sortedDistances = this.distances.slice().sort((a, b) => {
                return (distanceCounts[b] || 0) - (distanceCounts[a] || 0);
            });

            console.log('📏 Distances sorted by count:', sortedDistances.map(d => `${d} (${distanceCounts[d]})`));

            // Auto-select first distance if nothing selected
            const wasEmpty = !this.selectedDistance;
            let autoSelected = false;
            if (!this.selectedDistance && sortedDistances.length > 0) {
                this.selectedDistance = sortedDistances[0];
                autoSelected = true;
                console.log('📏 Auto-selected first distance (largest):', this.selectedDistance);
            }

            // Add buttons for each distance (no "Wszystkie" button)
            sortedDistances.forEach((distance) => {
                const btn = $('<button>')
                    .addClass('chronotrack-distance-filter-btn')
                    .addClass(this.selectedDistance === distance ? 'active' : '')
                    .attr('data-distance', distance)
                    .text(distance + ' (' + (distanceCounts[distance] || 0) + ')');
                container.append(btn);
            });

            // Add "Generuj PDF" button if there's a selected distance
            // CRITICAL: Fixed width prevents layout shift when text changes to "Generowanie PDF..."
            if (this.selectedDistance) {
                const pdfBtn = $('<button>')
                    .addClass('chronotrack-generate-pdf-btn')
                    .html('📄 Generuj PDF')
                    .attr('data-distance', this.selectedDistance)
                    .css({
                        'margin-left': 'auto',  // Push to right edge with flexbox
                        'min-width': '180px',   // Fixed width prevents jumping
                        'background': '#0066cc',
                        'color': '#fff',
                        'border': '1px solid #0066cc',
                        'padding': '8px 16px',
                        'border-radius': '4px',
                        'cursor': 'pointer',
                        'font-size': '14px',
                        'white-space': 'nowrap'  // Prevent text wrapping
                    });
                container.append(pdfBtn);
            }

            // CRITICAL FIX: If we auto-selected a distance, apply the filter NOW
            // This ensures only the largest distance is shown initially
            if (autoSelected) {
                console.log('🔄 Auto-selected distance - applying filter now:', this.selectedDistance);
                this.filterResults();
            }
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

            // Filter results and update statistics for this distance
            this.filterResults();  // This also calls updateSplitTimeColumnVisibility()
            this.updateStats();  // CRITICAL: Update statistics for selected distance
        },

        checkEventStatusAndStartRefresh: function() {
            const status = chronotrackData.eventStatus || 'live';
            const eventDate = chronotrackData.eventDate;

            console.log('📊 Event status:', status, 'Event date:', eventDate);
            console.log('📊 Full chronotrackData:', chronotrackData);

            // Handle different event statuses
            if (status === 'completed') {
                // Event is completed - load from DATABASE (fast, no API calls)
                // Results should already be saved during live event
                console.log('🏁 Event completed - loading from database (no API refresh)');
                $('.chronotrack-live-text').text('ZAWODY ZAKOŃCZONE').css('color', '#856404');
                $('.chronotrack-live-indicator').css('background', '#fff3cd');

                // Load once from database - NO auto-refresh
                this.loadResults(this.currentView);
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
            console.log('▶️▶️▶️ Event is LIVE - starting auto-refresh NOW ▶️▶️▶️');
            console.log('▶️ Current status:', status);
            $('.chronotrack-live-text').text('NA ŻYWO').css('color', '#dc3545');
            $('.chronotrack-live-indicator').css('background', '');
            this.startAutoRefresh();
            console.log('✅ Auto-refresh started! Interval ID:', this.refreshInterval);
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
            // Fetch from API every 15 seconds for live updates
            const interval = 15000; // 15 seconds
            this.currentInterval = interval;

            console.log('▶️ Starting auto-refresh with interval:', interval + 'ms (15s API fetch)');
            console.log('▶️ Interval object before clear:', this.refreshInterval);

            // Clear any existing interval first
            if (this.refreshInterval) {
                console.log('⚠️ Clearing existing interval before starting new one');
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }

            // CRITICAL FIX: Initial AGGRESSIVE load - load cache AND fetch from API immediately
            console.log('📥 Initial AGGRESSIVE load - fetching from cache AND API...');
            this.loadResults(this.currentView);  // Fast load from database (if available)

            // IMMEDIATELY fetch fresh data from API (don't wait 15s!)
            setTimeout(() => {
                console.log('🚀 AGGRESSIVE: FULL FETCH (entries + results) on initial load');
                this.refreshFromAPI('full'); // Full mode for first fetch
            }, 1000); // Wait 1 second after cache load, then fetch from API

            // Fetch fresh data from API every 15 seconds
            console.log('⏰ Setting up interval to fetch from API every', interval, 'ms');
            this.refreshInterval = setInterval(() => {
                console.log('🔄 Auto-refresh interval triggered - LIVE MODE (only results)');
                this.refreshFromAPI('live');  // Live mode: only results, cached personal data
            }, interval);

            console.log('✅ Auto-refresh interval set! Interval ID:', this.refreshInterval);
            console.log('✅ Next refresh will happen in', interval / 1000, 'seconds');
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
                this.refreshFromAPI('live');  // Live mode: only results (times/positions)
            }, finalInterval);

            // Keep full check at 60 seconds (check for new participants)
            this.fullCheckInterval = setInterval(() => {
                console.log('🔄 Full check (60s interval) - checking for new participants');
                this.refreshFromAPI('full');  // Full mode: check for new participants
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

        updateStats: function() {
            if (!this.allResults || this.allResults.length === 0) {
                // Hide stats if no results
                $('#chronotrack-stats').hide();
                return;
            }

            // CRITICAL: Filter by selected distance
            let filteredResults = this.allResults;
            if (this.selectedDistance) {
                filteredResults = this.allResults.filter(r => r.distance === this.selectedDistance);
            }

            const started = filteredResults.length; // All results for this distance = all who started

            // Count finished (have finish time and it's not empty/dash)
            const finished = filteredResults.filter(r => {
                const time = r.finish_time || r.net_time;
                return time && time !== '-' && time !== '00:00:00' && time !== '';
            }).length;

            // On course = started - finished (started but not finished yet)
            const onCourse = started - finished;

            console.log('📊 Stats calculated for distance "' + (this.selectedDistance || 'ALL') + '":', {started, finished, onCourse});

            // Update UI - changed from stat-registered to stat-started
            $('#stat-started').text(started);
            $('#stat-on-course').text(onCourse);
            $('#stat-finished').text(finished);

            // Show stats
            $('#chronotrack-stats').show();
        },

        showLoading: function() {
            $('.chronotrack-loading-row').show();
        },

        hideLoading: function() {
            $('.chronotrack-loading-row').hide();
        },

        sortByColumn: function(column) {
            console.log('🔄 Sorting by column:', column);

            if (!this.allResults || this.allResults.length === 0) {
                console.warn('⚠️ No results to sort!');
                return;
            }

            // Toggle sort direction if clicking same column
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }

            // Update header indicators
            $('.chronotrack-results-table thead th.sortable').removeClass('sort-asc sort-desc');
            $('.chronotrack-results-table thead th[data-column="' + column + '"]')
                .addClass('sort-' + this.sortDirection);

            // Helper function to extract value for sorting
            const getSortValue = (result, columnName) => {
                // CRITICAL: Handle split_time:PK, split_time:PK2, etc.
                if (columnName && columnName.startsWith && columnName.startsWith('split_time:')) {
                    const intervalName = columnName.substring(11); // Remove "split_time:" prefix
                    let splitTimes = result.split_times;

                    // Parse JSON if needed
                    if (typeof splitTimes === 'string') {
                        try {
                            splitTimes = JSON.parse(splitTimes);
                        } catch (e) {
                            return '';
                        }
                    }

                    // Find matching interval
                    if (splitTimes && Array.isArray(splitTimes)) {
                        const split = splitTimes.find(s =>
                            s.interval_name === intervalName || s.name === intervalName
                        );
                        if (split) {
                            return split.formatted_time || split.time || '';
                        }
                    }
                    return '';
                }

                // Try direct access first
                if (result[columnName] !== null && result[columnName] !== undefined && result[columnName] !== '') {
                    return result[columnName];
                }

                // CRITICAL: Try alternative field names for common columns
                const alternatives = {
                    'entry_bib': ['bib_number', 'entry_bib'],
                    'bib_number': ['bib_number', 'entry_bib'],
                    'overall_place': ['position', 'overall_place'],
                    'position': ['position', 'overall_place'],
                    'athlete_last_name,athlete_first_name': ['full_name'],
                    'full_name': ['full_name']
                };

                if (alternatives[columnName]) {
                    for (let i = 0; i < alternatives[columnName].length; i++) {
                        const alt = alternatives[columnName][i];
                        if (result[alt] !== null && result[alt] !== undefined && result[alt] !== '') {
                            return result[alt];
                        }
                    }
                }

                return '';
            };

            // Sort allResults
            const direction = this.sortDirection === 'asc' ? 1 : -1;
            this.allResults.sort((a, b) => {
                let valA = getSortValue(a, column);
                let valB = getSortValue(b, column);

                // CRITICAL: Handle split_time columns (already extracted above)
                if (column && column.startsWith && column.startsWith('split_time:')) {
                    valA = this.parseTimeToSeconds(valA);
                    valB = this.parseTimeToSeconds(valB);
                }
                // Handle numeric columns
                else if (['bib_number', 'entry_bib', 'position', 'overall_place',
                          'category_position', 'division_place', 'gender_position',
                          'sex_place', 'age', 'athlete_age', 'interval_position'].includes(column)) {
                    valA = (valA !== null && valA !== undefined && valA !== '' && valA !== '-') ? parseInt(valA) : Infinity;
                    valB = (valB !== null && valB !== undefined && valB !== '' && valB !== '-') ? parseInt(valB) : Infinity;
                }
                // Handle time columns
                else if (column === 'finish_time' || column === 'net_time' || column === 'formatted_time') {
                    valA = this.parseTimeToSeconds(valA);
                    valB = this.parseTimeToSeconds(valB);
                }
                // CRITICAL: Handle name sorting by LAST NAME (not first name)
                else if (column === 'full_name' || column === 'athlete_last_name,athlete_first_name') {
                    // Sort by last name first, then first name
                    const lastNameA = (a.last_name || '').trim();
                    const firstNameA = (a.first_name || '').trim();
                    const lastNameB = (b.last_name || '').trim();
                    const firstNameB = (b.first_name || '').trim();

                    // Polish alphabet collation
                    const compareResult = this.comparePolish(lastNameA, lastNameB);
                    if (compareResult !== 0) {
                        valA = 0;
                        valB = compareResult;
                    } else {
                        // Last names equal, compare first names
                        valA = 0;
                        valB = this.comparePolish(firstNameA, firstNameB);
                    }
                }
                // Handle other string columns
                else {
                    const strA = (valA || '').toString().trim();
                    const strB = (valB || '').toString().trim();

                    // Empty values go to end
                    if (!strA && strB) return 1;
                    if (strA && !strB) return -1;

                    // Polish alphabet collation
                    valA = 0;
                    valB = this.comparePolish(strA, strB);
                }

                if (valA < valB) return -1 * direction;
                if (valA > valB) return 1 * direction;
                return 0;
            });

            // Re-render results with forceRebuild=true to rebuild table in sorted order
            console.log('✅ Sorted', this.allResults.length, 'results, rebuilding table...');
            this.renderResults(this.allResults, true);
        },

        parseTimeToSeconds: function(timeStr) {
            if (!timeStr || timeStr === '-') return 999999;
            const parts = timeStr.split(':');
            if (parts.length === 3) {
                // Use parseFloat for seconds to preserve milliseconds (e.g., "43.420")
                return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60 + parseFloat(parts[2]);
            }
            return 999999;
        },

        /**
         * Parse pace from mm:ss format to seconds
         * Used for comparing pace values
         */
        /**
         * Format pace time from HH:MM:SS or MM:SS to MM:SS
         * Converts "00:05:21" to "5:21", keeps "5:12" as "5:12"
         */
        formatPaceTime: function(paceStr) {
            if (!paceStr || paceStr === '-') return '-';

            const parts = paceStr.split(':');

            // If HH:MM:SS format (3 parts)
            if (parts.length === 3) {
                const hours = parseInt(parts[0]);
                const minutes = parseInt(parts[1]);
                const seconds = parseInt(parts[2]);

                // Convert hours to minutes
                const totalMinutes = hours * 60 + minutes;

                // Return as MM:SS
                return totalMinutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            }

            // If MM:SS format (2 parts), return as-is but ensure seconds have 2 digits
            if (parts.length === 2) {
                const minutes = parseInt(parts[0]);
                const seconds = parseInt(parts[1]);
                return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            }

            // Otherwise return original
            return paceStr;
        },

        parsePaceToSeconds: function(paceStr) {
            if (!paceStr || paceStr === '-') return 0;
            const parts = paceStr.split(':');
            if (parts.length === 2) {
                return parseInt(parts[0]) * 60 + parseInt(parts[1]);
            }
            return 0;
        },

        /**
         * Convert pace (MM:SS min/km) to speed (km/h)
         * Example: "5:00" -> 12.0 km/h
         */
        paceToSpeed: function(paceStr) {
            if (!paceStr || paceStr === '-' || !paceStr.includes(':')) return '-';

            const parts = paceStr.split(':');
            if (parts.length !== 2) return '-';

            const minutes = parseInt(parts[0]);
            const seconds = parseInt(parts[1]);

            // Convert to decimal minutes
            const decimalMinutes = minutes + (seconds / 60);

            if (decimalMinutes === 0) return '-';

            // Speed = 60 / pace (minutes per km)
            const speed = 60 / decimalMinutes;

            // Format to 1 decimal place
            return speed.toFixed(1);
        },

        /**
         * Convert speed (km/h) to pace (MM:SS min/km)
         * Example: 12.0 -> "5:00"
         */
        speedToPace: function(speedValue) {
            if (!speedValue || speedValue <= 0) return '-';

            // Parse if it's a string
            const speed = typeof speedValue === 'string' ? parseFloat(speedValue) : speedValue;
            if (isNaN(speed) || speed <= 0) return '-';

            // Pace (min/km) = 60 / speed (km/h)
            const decimalMinutes = 60 / speed;

            const minutes = Math.floor(decimalMinutes);
            const seconds = Math.round((decimalMinutes - minutes) * 60);

            // Format as MM:SS
            return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        },

        /**
         * Parse distance from string format "5 km" or "500 m" to kilometers
         */
        parseDistanceToKm: function(distanceStr) {
            if (!distanceStr) return 0;

            // Try to extract km value (e.g., "5 km" -> 5.0)
            const kmMatch = distanceStr.match(/(\d+(?:\.\d+)?)\s*km/i);
            if (kmMatch) {
                return parseFloat(kmMatch[1]);
            }

            // Try to extract m value (e.g., "500 m" -> 0.5)
            const mMatch = distanceStr.match(/(\d+(?:\.\d+)?)\s*m/i);
            if (mMatch) {
                return parseFloat(mMatch[1]) / 1000;
            }

            return 0;
        },

        /**
         * Calculate average pace (min/km) from time and distance
         * Returns formatted pace in mm:ss format
         */
        calculateAveragePace: function(timeStr, distanceKm) {
            if (!timeStr || !distanceKm || distanceKm <= 0) {
                return '-';
            }

            const seconds = this.parseTimeToSeconds(timeStr);
            if (seconds === 999999 || seconds <= 0) {
                return '-';
            }

            // Calculate pace in seconds per km
            const paceSeconds = seconds / distanceKm;

            // Convert to min:sec format (mm:ss)
            const paceMin = Math.floor(paceSeconds / 60);
            const paceSec = Math.round(paceSeconds % 60);

            return paceMin + ':' + (paceSec < 10 ? '0' : '') + paceSec;
        },

        /**
         * Compare strings with Polish alphabet collation
         * Polish alphabet order: A Ą B C Ć D E Ę F G H I J K L Ł M N Ń O Ó P R S Ś T U W Y Z Ź Ż
         */
        comparePolish: function(strA, strB) {
            // Polish alphabet mapping
            const polishOrder = {
                'a': 1, 'ą': 2, 'b': 3, 'c': 4, 'ć': 5, 'd': 6, 'e': 7, 'ę': 8,
                'f': 9, 'g': 10, 'h': 11, 'i': 12, 'j': 13, 'k': 14, 'l': 15,
                'ł': 16, 'm': 17, 'n': 18, 'ń': 19, 'o': 20, 'ó': 21, 'p': 22,
                'q': 23, 'r': 24, 's': 25, 'ś': 26, 't': 27, 'u': 28, 'v': 29,
                'w': 30, 'x': 31, 'y': 32, 'z': 33, 'ź': 34, 'ż': 35
            };

            const a = strA.toLowerCase();
            const b = strB.toLowerCase();

            const minLen = Math.min(a.length, b.length);

            for (let i = 0; i < minLen; i++) {
                const orderA = polishOrder[a[i]] || a.charCodeAt(i);
                const orderB = polishOrder[b[i]] || b.charCodeAt(i);

                if (orderA !== orderB) {
                    return orderA - orderB;
                }
            }

            // If all characters match, shorter string comes first
            return a.length - b.length;
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
                    nonce: chronotrackData.nonce,
                    event_id: this.eventId,
                    distance: this.selectedDistance
                },
                success: (response) => {
                    console.log('✅ PDF generation response:', response);

                    if (response.success) {
                        // Open PDF in new tab for preview (user can download from there)
                        window.open(response.data.download_url, '_blank');

                        console.log('✅ PDF opened in new tab:', response.data.download_url);
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
