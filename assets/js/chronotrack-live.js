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
        currentInterval: 3500, // Start with 3.5 seconds
        columns: [], // Dynamic columns configuration

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

            // Manual refresh button
            $(document).on('click', '.chronotrack-manual-refresh', (e) => {
                e.preventDefault();
                console.log('🔄 Manual refresh triggered');
                this.loadResults(this.currentView);
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

                        const newCount = response.data.count || 0;
                        const hasChanges = newCount !== this.lastResultCount;

                        if (hasChanges) {
                            console.log('🆕 New results detected:', newCount, '(was:', this.lastResultCount + ')');
                            this.lastResultCount = newCount;
                            this.unchangedCount = 0;

                            // Reset to fast interval when there are changes
                            if (this.currentInterval !== 3500) {
                                this.adjustRefreshInterval(3500);
                            }
                        } else {
                            this.unchangedCount++;
                            console.log('⏸️ No changes, count:', this.unchangedCount);

                            // After 10 unchanged checks (~35 seconds), slow down to 60 seconds
                            if (this.unchangedCount >= 10 && this.currentInterval !== 60000) {
                                console.log('⏱️ Slowing refresh to 60 seconds (no changes detected)');
                                this.adjustRefreshInterval(60000);
                            }
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

        renderResults: function(results) {
            console.log('🎨 renderResults called, results:', results ? results.length : 'NULL');
            const tbody = $('#chronotrack-results-body');

            // Clear only if we have new data
            if (!results || results.length === 0) {
                console.log('❌ No results to render');
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak wyników</td></tr>');
                return;
            }

            // Clear existing rows
            tbody.empty();

            // Render each result
            results.forEach((result) => {
                const row = this.createResultRow(result);
                tbody.append(row);
            });

            console.log('✅ Rendered', results.length, 'results');
        },

        createResultRow: function(result) {
            const row = $('<tr>').attr('data-result-id', result.id);

            // Use dynamic columns if available
            if (this.columns && this.columns.length > 0) {
                this.columns.forEach((column) => {
                    const value = this.getColumnValue(result, column);
                    const cell = $('<td>').addClass('col-' + column.id);

                    // Special formatting for full_name
                    if (column.id === 'full_name' || column.id.includes('name')) {
                        cell.html('<strong>' + this.escapeHtml(value) + '</strong>');
                    } else {
                        cell.text(value);
                    }

                    row.append(cell);
                });
            } else {
                // Fallback to hardcoded columns
                row.append($('<td>').addClass('col-position').text(result.position));
                row.append($('<td>').addClass('col-bib').text(result.bib_number));
                row.append($('<td>').addClass('col-name').html(
                    '<strong>' + this.escapeHtml(result.full_name) + '</strong>'
                ));
                row.append($('<td>').addClass('col-category').text(result.category));
                row.append($('<td>').addClass('col-club').text(result.club));
                row.append($('<td>').addClass('col-time').text(result.finish_time));
            }

            // Actions column
            const detailsBtn = $('<a>')
                .attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id)
                .text(chronotrackData.strings.details);

            row.append($('<td>').addClass('col-actions').append(detailsBtn));

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

                    // Try direct attribute
                    if (result.hasOwnProperty(attr) && result[attr] !== null && result[attr] !== '') {
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
            row.append($('<td>').addClass('col-bib').text(result.bib_number));
            row.append($('<td>').addClass('col-name').html(
                '<strong>' + this.escapeHtml(result.full_name) + '</strong>'
            ));
            row.append($('<td>').addClass('col-category').text(result.category));
            row.append($('<td>').addClass('col-club').text(result.club));
            row.append($('<td>').addClass('col-time').text(result.finish_time));
            row.append($('<td>').addClass('col-position').text(result.position));

            // Actions
            const detailsBtn = $('<a>')
                .attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id)
                .text(chronotrackData.strings.details);

            row.append($('<td>').addClass('col-actions').append(detailsBtn));

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

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') :
                $('#chronotrack-results-body');

            tbody.find('tr').each(function() {
                const row = $(this);
                const name = row.find('.col-name').text().toLowerCase();
                const bib = row.find('.col-bib').text().toLowerCase();
                const rowCategory = row.find('.col-category').text();

                let show = true;

                // Search filter
                if (searchTerm && !name.includes(searchTerm) && !bib.includes(searchTerm)) {
                    show = false;
                }

                // Category filter
                if (category && rowCategory !== category) {
                    show = false;
                }

                row.toggle(show);
            });
        },

        populateFilters: function(results) {
            const categories = new Set();

            results.forEach((result) => {
                if (result.category) {
                    categories.add(result.category);
                }
            });

            const categorySelect = $('#chronotrack-category-filter');
            const currentValue = categorySelect.val();

            categorySelect.find('option:not(:first)').remove();
            Array.from(categories).sort().forEach((category) => {
                categorySelect.append(
                    $('<option>').val(category).text(category)
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
            // This will be rendered server-side via AJAX endpoint
            // For now, create a simple details view
            let html = '<div class="chronotrack-participant-details">';
            html += '<h2>' + this.escapeHtml(participant.full_name) + '</h2>';
            html += '<div class="chronotrack-details-grid">';

            // Basic info
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Basic Information</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Bib Number:</th><td>' + this.escapeHtml(participant.bib_number) + '</td></tr>';
            html += '<tr><th>Age:</th><td>' + participant.age + '</td></tr>';
            html += '<tr><th>Gender:</th><td>' + this.escapeHtml(participant.gender) + '</td></tr>';
            html += '<tr><th>City:</th><td>' + this.escapeHtml(participant.city) + '</td></tr>';
            html += '<tr><th>Club:</th><td>' + this.escapeHtml(participant.club) + '</td></tr>';
            html += '<tr><th>Category:</th><td>' + this.escapeHtml(participant.category) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Results
            html += '<div class="chronotrack-details-section">';
            html += '<h3>Results</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Overall Position:</th><td class="chronotrack-position">' + participant.position + '</td></tr>';
            html += '<tr><th>Category Position:</th><td class="chronotrack-position">' + participant.category_position + '</td></tr>';
            html += '<tr><th>Gender Position:</th><td class="chronotrack-position">' + participant.gender_position + '</td></tr>';
            html += '<tr><th>Finish Time (Gross):</th><td class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</td></tr>';
            html += '<tr><th>Net Time:</th><td class="chronotrack-time">' + this.escapeHtml(participant.net_time) + '</td></tr>';
            html += '</table>';
            html += '</div>';

            html += '</div>';
            html += '</div>';

            $('#chronotrack-modal-body').html(html);
        },

        openModal: function() {
            $('#chronotrack-modal').fadeIn(200);
        },

        closeModal: function() {
            $('#chronotrack-modal').fadeOut(200);
        },

        startAutoRefresh: function() {
            // Default 3.5 seconds (between 3-4 as requested)
            const interval = 3500; // Always 3.5 seconds
            this.currentInterval = interval;

            console.log('▶️ Starting auto-refresh with interval:', interval + 'ms');

            this.refreshInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, interval);
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                console.log('⏸️ Stopping auto-refresh');
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        adjustRefreshInterval: function(newInterval) {
            console.log('🔄 Adjusting refresh interval from', this.currentInterval + 'ms to', newInterval + 'ms');
            this.currentInterval = newInterval;

            // Restart timer with new interval
            this.stopAutoRefresh();
            this.refreshInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, newInterval);
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
            $('.chronotrack-loading').show();
        },

        hideLoading: function() {
            $('.chronotrack-loading').hide();
        },

        showError: function(message) {
            console.error('ChronoTrack Error:', message);
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
