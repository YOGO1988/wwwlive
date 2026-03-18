/**
 * ChronoTrack Live Results - Frontend JavaScript v4.1.0
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
        currentInterval: 10000,
        fullCheckInterval: null,
        columns: [],
        distances: [],
        selectedDistance: '',
        allResults: [], // cached results for PDF

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

            this.removeSidebar();
            this.bindEvents();
            this.startAutoRefresh();

            console.log('=== ChronoTrack Live Init END ===');
        },

        removeSidebar: function() {
            const sidebarSelectors = [
                '#secondary', 'aside.sidebar', '.sidebar', '.widget-area',
                '#sidebar', '[id*="sidebar"]',
                '[class*="sidebar"]:not(.chronotrack-distance-filters)',
                'aside:not(.chronotrack-results-container)'
            ];
            sidebarSelectors.forEach(selector => { $(selector).remove(); });
            $('.site-content, .hfeed, #content').css({
                'display': 'block', 'width': '100%', 'max-width': '100%',
                'grid-template-columns': 'none'
            });
            $('#primary, .content-area, article, main').css({
                'width': '100%', 'max-width': '100%', 'flex': '0 0 100%'
            });
        },

        bindEvents: function() {
            // View toggle
            $(document).on('click', '.chronotrack-view-toggle', (e) => {
                const view = $(e.currentTarget).data('view');
                this.switchView(view);
            });

            // Search
            $(document).on('keyup', '#chronotrack-search', () => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => { this.filterResults(); }, 300);
            });

            // Filters
            $(document).on('change', '.chronotrack-filter', () => { this.filterResults(); });

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
                if (e.target === e.currentTarget) { this.closeModal(); }
            });

            // Manual refresh button
            $(document).on('click', '.chronotrack-manual-refresh', (e) => {
                e.preventDefault();
                const btn = $(e.currentTarget);
                btn.css('transform', 'rotate(360deg)');
                setTimeout(() => btn.css('transform', ''), 400);
                this.loadResults(this.currentView);
            });

            // PDF generation
            $(document).on('click', '#chronotrack-generate-pdf', (e) => {
                e.preventDefault();
                this.generatePDF();
            });
        },

        loadResults: function(view) {
            if (this.isLoading) {
                console.log('⏳ Already loading, skipping...');
                return;
            }

            if (this.consecutiveErrors >= this.maxConsecutiveErrors) {
                console.error('❌ Too many consecutive errors, stopping auto-refresh');
                this.stopAutoRefresh();
                return;
            }

            view = view || this.currentView;
            this.isLoading = true;
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
                timeout: 15000,
                success: (response) => {
                    if (response.success) {
                        this.consecutiveErrors = 0;

                        if (response.data.columns && response.data.columns.length > 0) {
                            this.columns = response.data.columns;
                        }

                        if (response.data.distances && response.data.distances.length > 0) {
                            this.distances = response.data.distances;
                            this.renderDistanceButtons();
                        }

                        const results = response.data.results || [];
                        const newCount = response.data.count || 0;

                        if (newCount !== this.lastResultCount) {
                            this.lastResultCount = newCount;
                            this.unchangedCount = 0;
                        } else {
                            this.unchangedCount++;
                        }

                        if (view === 'meta') {
                            this.renderMetaResults(results);
                        } else {
                            this.allResults = results;
                            this.renderResults(results);
                            this.updateStats(results);
                        }

                        this.updateTimestamp();
                        this.populateFilters(results);
                    } else {
                        this.consecutiveErrors++;
                        console.error('❌ AJAX error:', response.data.message);
                    }
                },
                error: (xhr, status, error) => {
                    this.consecutiveErrors++;
                    console.error('❌ AJAX Error:', { xhr, status, error });
                },
                complete: () => {
                    this.isLoading = false;
                    this.hideLoading();
                }
            });
        },

        // ========================
        // Stats
        // ========================
        updateStats: function(results) {
            const total = results.length;
            const finished = results.filter(r => r.finish_time && r.finish_time !== '-').length;
            const ontrack = total - finished;

            $('#chronotrack-stat-started').text(total);
            $('#chronotrack-stat-ontrack').text(ontrack > 0 ? ontrack : 0);
            $('#chronotrack-stat-finished').text(finished);
        },

        // ========================
        // Country Flag
        // ========================
        getCountryFlag: function(countryCode) {
            if (!countryCode) return '';
            const cc = countryCode.toString().toLowerCase().replace(/[^a-z]/g, '');
            if (cc.length !== 2) return '';
            return '<img src="https://flagcdn.com/w20/' + cc + '.png" ' +
                   'srcset="https://flagcdn.com/w40/' + cc + '.png 2x" ' +
                   'alt="' + cc.toUpperCase() + '" ' +
                   'class="chronotrack-flag" width="20" height="14" loading="lazy">';
        },

        // ========================
        // Render Results
        // ========================
        renderResults: function(results) {
            const tbody = $('#chronotrack-results-body');

            if (!results || results.length === 0) {
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak wyników</td></tr>');
                return;
            }

            const existingRows = {};
            tbody.find('tr[data-bib]').each(function() {
                existingRows[$(this).attr('data-bib')] = $(this);
            });

            const processedBibs = new Set();

            results.forEach((result) => {
                const bib = result.bib_number;
                if (processedBibs.has(bib)) return;
                processedBibs.add(bib);

                const newRow = this.createResultRow(result);

                if (existingRows[bib]) {
                    const isVisible = existingRows[bib].is(':visible');
                    if (!isVisible) newRow.hide();
                    existingRows[bib].replaceWith(newRow);
                    delete existingRows[bib];
                } else {
                    tbody.append(newRow);
                }
            });

            $.each(existingRows, function(bib, row) { row.remove(); });
        },

        createResultRow: function(result) {
            const row = $('<tr>')
                .attr('data-result-id', result.id)
                .attr('data-bib', result.bib_number)
                .attr('data-distance', result.distance || '')
                .attr('data-position', result.position || '');

            if (this.columns && this.columns.length > 0) {
                this.columns.forEach((column) => {
                    const value = this.getColumnValue(result, column);
                    const cell = $('<td>').addClass('col-' + column.id);

                    if (column.id === 'full_name' || column.id.includes('name') || column.id.includes('Name')) {
                        // Name column - clickable
                        const flag = this.getCountryFlag(result.country);
                        const nameLink = $('<a>')
                            .attr('href', '#')
                            .addClass('chronotrack-view-details')
                            .attr('data-participant-id', result.participant_id);
                        nameLink.html(flag + '<strong>' + this.escapeHtml(value) + '</strong>');
                        cell.append(nameLink);

                    } else if (column.id === 'city' || column.id.includes('city') || column.id.includes('City') || column.id.includes('Miejscowość')) {
                        // City column - show flag + city
                        const flag = this.getCountryFlag(result.country);
                        cell.html('<span class="chronotrack-city-cell">' + flag + this.escapeHtml(value) + '</span>');

                    } else {
                        cell.text(value);
                    }

                    row.append(cell);
                });
            } else {
                // Fallback hardcoded columns
                row.append($('<td>').addClass('col-position').text(result.position));
                row.append($('<td>').addClass('col-bib').text(result.bib_number));

                const flag = this.getCountryFlag(result.country);
                const nameLink = $('<a>').attr('href', '#')
                    .addClass('chronotrack-view-details')
                    .attr('data-participant-id', result.participant_id)
                    .html(flag + '<strong>' + this.escapeHtml(result.full_name) + '</strong>');
                row.append($('<td>').addClass('col-name').append(nameLink));

                const cityCell = $('<td>').addClass('col-city');
                cityCell.html('<span class="chronotrack-city-cell">' + flag + this.escapeHtml(result.city || '') + '</span>');
                row.append(cityCell);

                row.append($('<td>').addClass('col-club').text(result.club || ''));
                row.append($('<td>').addClass('col-category').text(result.category || ''));
                row.append($('<td>').addClass('col-category-position').text(result.category_position || ''));
                row.append($('<td>').addClass('col-gender-position').text(result.gender_position || ''));
                row.append($('<td>').addClass('col-time').text(result.finish_time || ''));
                row.append($('<td>').addClass('col-net-time').text(result.net_time || ''));
            }

            return row;
        },

        getColumnValue: function(result, column) {
            if (column.api_attributes && column.api_attributes.length > 0) {
                for (let i = 0; i < column.api_attributes.length; i++) {
                    const attr = column.api_attributes[i];
                    if (attr === 'full_name' || attr === 'athlete_last_name,athlete_first_name') {
                        if (result.full_name) return result.full_name;
                        if (result.last_name || result.first_name) {
                            return (result.last_name || '') + ' ' + (result.first_name || '');
                        }
                    }
                    if (result.hasOwnProperty(attr) && result[attr] !== null && result[attr] !== '') {
                        return result[attr];
                    }
                }
            }
            return '-';
        },

        // ========================
        // META View
        // ========================
        renderMetaResults: function(results) {
            const tbody = $('#chronotrack-meta-body');
            tbody.empty();

            if (!results || results.length === 0) {
                tbody.html('<tr><td colspan="7" class="chronotrack-no-results">Brak zawodników na mecie</td></tr>');
                return;
            }

            results.forEach((result) => { tbody.append(this.createMetaResultRow(result)); });
        },

        createMetaResultRow: function(result) {
            const row = $('<tr>').attr('data-result-id', result.id);
            const finishTime = result.finish_timestamp ?
                new Date(result.finish_timestamp).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '-';

            row.append($('<td>').addClass('col-finish-time').text(finishTime));
            row.append($('<td>').addClass('col-bib').text(result.bib_number));

            const flag = this.getCountryFlag(result.country);
            const nameLink = $('<a>').attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id)
                .html(flag + '<strong>' + this.escapeHtml(result.full_name) + '</strong>');
            row.append($('<td>').addClass('col-name').append(nameLink));
            row.append($('<td>').addClass('col-category').text(result.category || ''));
            row.append($('<td>').addClass('col-club').text(result.club || ''));
            row.append($('<td>').addClass('col-time').text(result.finish_time || ''));
            row.append($('<td>').addClass('col-position').text(result.position || ''));

            return row;
        },

        // ========================
        // PDF Generation (client-side)
        // ========================
        generatePDF: function() {
            const btn = $('#chronotrack-generate-pdf');
            if (!btn.length) return;

            // Check jsPDF availability
            if (typeof window.jspdf === 'undefined' && typeof jsPDF === 'undefined') {
                alert('Biblioteka PDF nie jest dostępna. Sprawdź połączenie internetowe.');
                return;
            }

            btn.prop('disabled', true).text('Generowanie...');

            try {
                const { jsPDF } = window.jspdf || { jsPDF: window.jsPDF };
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

                const eventName = $('.chronotrack-event-name').text().trim() || 'Wyniki';
                const eventDate = $('.chronotrack-event-date').text().trim() || '';
                const distanceLabel = this.selectedDistance || (this.distances.length > 0 ? this.distances.join(', ') : 'Wszystkie');

                // Title
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(16);
                doc.text(eventName, 14, 14);

                doc.setFont('helvetica', 'normal');
                doc.setFontSize(10);
                doc.setTextColor(100);
                doc.text(eventDate + '   Dystans: ' + distanceLabel, 14, 21);
                doc.text('Wygenerowano: ' + new Date().toLocaleString('pl-PL'), 14, 27);
                doc.setTextColor(0);

                // Collect visible rows from table
                const headers = [];
                const rows = [];

                // Build headers from thead
                $('#chronotrack-results-body').closest('table').find('thead th').each(function() {
                    const text = $(this).text().trim().replace(/\n/g, ' ');
                    if (text) headers.push(text);
                });

                // Build rows from visible tbody rows
                $('#chronotrack-results-body tr:visible').each(function() {
                    const rowData = [];
                    $(this).find('td').each(function() {
                        rowData.push($(this).text().trim());
                    });
                    if (rowData.length > 0 && rowData.join('').trim() !== '') {
                        rows.push(rowData);
                    }
                });

                if (rows.length === 0) {
                    btn.prop('disabled', false).html(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg> Generuj PDF'
                    );
                    alert('Brak wyników do wygenerowania PDF.');
                    return;
                }

                // autoTable
                doc.autoTable({
                    head: [headers],
                    body: rows,
                    startY: 32,
                    styles: {
                        fontSize: 8,
                        cellPadding: 2,
                        overflow: 'linebreak',
                        halign: 'left',
                        valign: 'middle',
                    },
                    headStyles: {
                        fillColor: [45, 106, 159],
                        textColor: 255,
                        fontStyle: 'bold',
                        fontSize: 8,
                    },
                    alternateRowStyles: {
                        fillColor: [245, 247, 250],
                    },
                    columnStyles: {
                        0: { cellWidth: 14, halign: 'center' }, // position
                        1: { cellWidth: 14, halign: 'center' }, // bib
                    },
                    margin: { top: 32, left: 14, right: 14 },
                    didDrawPage: function(data) {
                        // Footer
                        const pageCount = doc.internal.getNumberOfPages();
                        doc.setFontSize(8);
                        doc.setTextColor(150);
                        doc.text(
                            'Strona ' + data.pageNumber + ' / ' + pageCount + '  |  yogoevents.pl',
                            data.settings.margin.left,
                            doc.internal.pageSize.height - 8
                        );
                    }
                });

                const safeEventName = eventName.replace(/[^a-zA-Z0-9_\-]/g, '_').substring(0, 40);
                doc.save('wyniki_' + safeEventName + '_' + distanceLabel.replace(/[^a-zA-Z0-9]/g, '') + '.pdf');

            } catch (err) {
                console.error('PDF generation error:', err);
                alert('Błąd generowania PDF: ' + err.message);
            }

            btn.prop('disabled', false).html(
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg> Generuj PDF'
            );
        },

        // ========================
        // View Switching
        // ========================
        switchView: function(view) {
            this.currentView = view;
            $('.chronotrack-view-toggle').removeClass('active');
            $('.chronotrack-view-toggle[data-view="' + view + '"]').addClass('active');
            $('.chronotrack-view').removeClass('active');
            $('.chronotrack-view-' + view).addClass('active');
            this.loadResults(view);
        },

        // ========================
        // Filters
        // ========================
        filterResults: function() {
            const searchTerm = $('#chronotrack-search').val().toLowerCase();
            const category = $('#chronotrack-category-filter').val();
            const gender = $('#chronotrack-gender-filter').val();
            const distance = this.selectedDistance;

            const tbody = this.currentView === 'meta' ?
                $('#chronotrack-meta-body') : $('#chronotrack-results-body');

            tbody.find('tr').each(function() {
                const row = $(this);
                const name = row.find('[class*="col-name"], [class*="col-full_name"]').text().toLowerCase();
                const bib = row.find('[class*="col-bib"]').text().toLowerCase();
                const rowCategory = row.find('[class*="col-category"]').first().text();
                const rowGender = row.attr('data-gender') || '';
                const rowDistance = row.attr('data-distance') || '';

                let show = true;

                if (searchTerm && !name.includes(searchTerm) && !bib.includes(searchTerm)) show = false;
                if (category && rowCategory !== category) show = false;
                if (gender && rowGender !== gender) show = false;
                if (distance && rowDistance !== distance) show = false;

                row.toggle(show);
            });
        },

        populateFilters: function(results) {
            const categories = new Set();
            results.forEach((result) => {
                if (result.category) {
                    // Clean up category names: strip "(wszystkie)", "(all)"
                    let cat = result.category
                        .replace(/\s*\(wszystkie\)/gi, '')
                        .replace(/\s*\(all\)/gi, '')
                        .trim();
                    if (cat) categories.add(cat);
                }
            });

            const categorySelect = $('#chronotrack-category-filter');
            const currentValue = categorySelect.val();

            categorySelect.find('option:not(:first)').remove();
            Array.from(categories).sort().forEach((category) => {
                categorySelect.append($('<option>').val(category).text(category));
            });
            categorySelect.val(currentValue);
        },

        // ========================
        // Participant Details
        // ========================
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
                error: () => {
                    alert('Błąd ładowania danych zawodnika.');
                }
            });
        },

        renderParticipantDetails: function(participant) {
            const flag = this.getCountryFlag(participant.country);

            let html = '<div class="chronotrack-participant-details">';
            html += '<h2>' + flag + this.escapeHtml(participant.full_name) + '</h2>';
            html += '<div class="chronotrack-details-grid">';

            html += '<div class="chronotrack-details-section">';
            html += '<h3>Podstawowe informacje</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Numer startowy</th><td>' + this.escapeHtml(participant.bib_number) + '</td></tr>';
            html += '<tr><th>Wiek</th><td>' + (participant.age || '-') + '</td></tr>';
            html += '<tr><th>Płeć</th><td>' + (participant.gender === 'M' ? 'Mężczyzna' : participant.gender === 'F' ? 'Kobieta' : this.escapeHtml(participant.gender)) + '</td></tr>';
            html += '<tr><th>Miejscowość</th><td>' + flag + this.escapeHtml(participant.city) + '</td></tr>';
            html += '<tr><th>Klub</th><td>' + this.escapeHtml(participant.club || '-') + '</td></tr>';
            html += '<tr><th>Kategoria</th><td>' + this.escapeHtml(participant.category) + '</td></tr>';
            html += '</table></div>';

            html += '<div class="chronotrack-details-section">';
            html += '<h3>Wyniki</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Miejsce Open</th><td class="chronotrack-position">' + (participant.position || '-') + '</td></tr>';
            html += '<tr><th>Miejsce w kategorii</th><td class="chronotrack-position">' + (participant.category_position || '-') + '</td></tr>';
            html += '<tr><th>Miejsce M/K</th><td class="chronotrack-position">' + (participant.gender_position || '-') + '</td></tr>';
            html += '<tr><th>Czas brutto</th><td class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</td></tr>';
            html += '<tr><th>Czas netto</th><td class="chronotrack-time">' + this.escapeHtml(participant.net_time) + '</td></tr>';
            html += '</table></div>';

            html += '</div></div>';

            $('#chronotrack-modal-body').html(html);
        },

        openModal: function() { $('#chronotrack-modal').fadeIn(200); },
        closeModal: function() { $('#chronotrack-modal').fadeOut(200); },

        // ========================
        // Distance Buttons
        // ========================
        renderDistanceButtons: function() {
            const container = $('#chronotrack-distance-filters');
            if (!container.length) return;

            container.empty();

            this.distances.forEach((distance) => {
                // Count results for this distance
                const count = this.allResults.filter(r => r.distance === distance).length;
                const label = distance + (count > 0 ? ' (' + count + ')' : '');
                const btn = $('<button>')
                    .addClass('chronotrack-distance-filter-btn')
                    .addClass(this.selectedDistance === distance ? 'active' : '')
                    .attr('data-distance', distance)
                    .text(label);
                container.append(btn);
            });

            // If multiple distances, add "All" button at start
            if (this.distances.length > 1) {
                const allBtn = $('<button>')
                    .addClass('chronotrack-distance-filter-btn')
                    .addClass(this.selectedDistance === '' ? 'active' : '')
                    .attr('data-distance', '')
                    .text('Wszystkie');
                container.prepend(allBtn);
            }
        },

        selectDistance: function(distance) {
            this.selectedDistance = distance;
            $('.chronotrack-distance-filter-btn').removeClass('active');
            $('.chronotrack-distance-filter-btn[data-distance="' + distance + '"]').addClass('active');
            this.filterResults();
        },

        // ========================
        // Auto Refresh
        // ========================
        startAutoRefresh: function() {
            // Load immediately
            this.loadResults(this.currentView);

            this.refreshInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, 10000);

            this.fullCheckInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, 60000);
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
            if (this.fullCheckInterval) {
                clearInterval(this.fullCheckInterval);
                this.fullCheckInterval = null;
            }
        },

        // ========================
        // UI Helpers
        // ========================
        updateTimestamp: function() {
            const now = new Date();
            const t = now.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            $('#chronotrack-timestamp').text('Aktualizacja: ' + t);
        },

        showLoading: function() { $('.chronotrack-loading').show(); },
        hideLoading: function() { $('.chronotrack-loading').hide(); },

        escapeHtml: function(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        ChronoTrackResults.init();
    });

})(jQuery);
