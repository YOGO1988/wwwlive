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
            this.loadResults();
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
        },

        loadResults: function(view) {
            view = view || this.currentView;

            this.showLoading();

            const action = view === 'meta' ? 'chronotrack_get_recent_finishers' : 'chronotrack_get_results';

            $.ajax({
                url: chronotrackData.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    event_id: this.eventId,
                    nonce: chronotrackData.nonce
                },
                success: (response) => {
                    if (response.success) {
                        if (view === 'meta') {
                            this.renderMetaResults(response.data.results);
                        } else {
                            this.renderResults(response.data.results);
                        }
                        this.updateTimestamp();
                        this.populateFilters(response.data.results);
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', error);
                    this.showError(chronotrackData.strings.error);
                },
                complete: () => {
                    this.hideLoading();
                }
            });
        },

        renderResults: function(results) {
            const tbody = $('#chronotrack-results-body');
            tbody.empty();

            if (!results || results.length === 0) {
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">' +
                    chronotrackData.strings.noResults + '</td></tr>');
                return;
            }

            results.forEach((result) => {
                const row = this.createResultRow(result);
                tbody.append(row);
            });
        },

        createResultRow: function(result) {
            const row = $('<tr>').attr('data-result-id', result.id);

            row.append($('<td>').addClass('col-position').text(result.position));
            row.append($('<td>').addClass('col-bib').text(result.bib_number));
            row.append($('<td>').addClass('col-name').html(
                '<strong>' + this.escapeHtml(result.full_name) + '</strong>'
            ));
            row.append($('<td>').addClass('col-category').text(result.category));
            row.append($('<td>').addClass('col-club').text(result.club));
            row.append($('<td>').addClass('col-time').text(result.finish_time));

            // Add split times if configured
            if (result.split_times && result.split_times.length > 0) {
                result.split_times.forEach((split) => {
                    if (split.show_in_main) {
                        row.append($('<td>').addClass('col-split').text(split.time));
                    }
                });
            }

            // Actions
            const detailsBtn = $('<a>')
                .attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id)
                .text(chronotrackData.strings.details);

            row.append($('<td>').addClass('col-actions').append(detailsBtn));

            return row;
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
            const interval = chronotrackData.refreshInterval || 5000;

            this.refreshInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, interval);
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
            }
        },

        updateTimestamp: function() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            $('#chronotrack-timestamp').text(timeString);
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
