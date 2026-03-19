/**
 * ChronoTrack Live Results - Frontend JavaScript
 * Version: 4.0.8
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
        allResults: [],

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
            sidebarSelectors.forEach(selector => $(selector).remove());

            $('.site-content, .hfeed, #content').css({
                'display': 'block', 'width': '100%', 'max-width': '100%',
                'grid-template-columns': 'none'
            });
            $('#primary, .content-area, article, main').css({
                'width': '100%', 'max-width': '100%', 'flex': '0 0 100%'
            });
        },

        bindEvents: function() {
            $(document).on('click', '.chronotrack-view-toggle', (e) => {
                const view = $(e.currentTarget).data('view');
                this.switchView(view);
            });

            $(document).on('keyup', '#chronotrack-search', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.filterResults(), 300);
            });

            $(document).on('change', '.chronotrack-filter', () => this.filterResults());

            $(document).on('click', '.chronotrack-distance-filter-btn', (e) => {
                const distance = $(e.currentTarget).data('distance');
                this.selectDistance(distance);
            });

            $(document).on('click', '.chronotrack-view-details', (e) => {
                e.preventDefault();
                const participantId = $(e.currentTarget).data('participant-id');
                this.showParticipantDetails(participantId);
            });

            $(document).on('click', '.chronotrack-modal-close, .chronotrack-modal', (e) => {
                if (e.target === e.currentTarget) this.closeModal();
            });

            $(document).on('click', '.chronotrack-manual-refresh', (e) => {
                e.preventDefault();
                this.loadResults(this.currentView);
            });

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

            $(document).on('click', '.chronotrack-pdf-btn', (e) => {
                e.preventDefault();
                const distance = $(e.currentTarget).data('distance') || this.selectedDistance || 'all';
                this.generatePDF(distance);
            });
        },

        loadResults: function(view) {
            if (this.isLoading) return;
            if (this.consecutiveErrors >= this.maxConsecutiveErrors) {
                this.stopAutoRefresh();
                this.showError('Zbyt wiele błędów. Odświeżanie zatrzymane.');
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
                timeout: 10000,
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

                        const newCount = response.data.count || 0;
                        if (newCount !== this.lastResultCount) {
                            this.lastResultCount = newCount;
                            this.unchangedCount = 0;
                        } else {
                            this.unchangedCount++;
                        }

                        if (view === 'meta') {
                            this.renderMetaResults(response.data.results);
                        } else {
                            this.allResults = response.data.results;
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
                    let errorMsg = 'Błąd pobierania wyników';
                    if (status === 'timeout') errorMsg = 'Przekroczono limit czasu';
                    else if (xhr.status === 0) errorMsg = 'Brak połączenia z serwerem';
                    this.showError(errorMsg + ' (' + this.consecutiveErrors + '/' + this.maxConsecutiveErrors + ')');
                },
                complete: () => {
                    this.isLoading = false;
                    this.hideLoading();
                }
            });
        },

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

                const existingRow = existingRows[bib];
                if (existingRow) {
                    const isVisible = existingRow.is(':visible');
                    const newRow = this.createResultRow(result);
                    if (!isVisible) newRow.hide();
                    existingRow.replaceWith(newRow);
                    delete existingRows[bib];
                } else {
                    tbody.append(this.createResultRow(result));
                }
            });

            $.each(existingRows, function(bib, row) { row.remove(); });
        },

        createResultRow: function(result) {
            const row = $('<tr>')
                .attr('data-result-id', result.id)
                .attr('data-bib', result.bib_number)
                .attr('data-distance', result.distance || '');

            if (this.columns && this.columns.length > 0) {
                this.columns.forEach((column) => {
                    const value = this.getColumnValue(result, column);
                    const cell = $('<td>').addClass('col-' + column.id);

                    if (column.id === 'full_name' || column.id.includes('name')) {
                        // Flag + name
                        let flagHtml = this.getFlagHtml(result.country);
                        const nameLink = $('<a>')
                            .attr('href', '#')
                            .addClass('chronotrack-view-details')
                            .attr('data-participant-id', result.participant_id);
                        nameLink.html(flagHtml + '<strong>' + this.escapeHtml(value) + '</strong>');
                        cell.append(nameLink);
                    } else if (column.id === 'city' || column.id === 'overall_place' || column.id === 'entry_bib') {
                        cell.text(value);
                    } else if (column.id === 'finish_time' || column.id === 'net_time') {
                        cell.addClass('time-cell').text(value);
                    } else if (column.id === 'category_position' || column.id === 'gender_position') {
                        cell.addClass('pos-cell').text(value);
                    } else {
                        cell.text(value);
                    }

                    row.append(cell);
                });
            } else {
                // Fallback columns
                row.append($('<td>').addClass('col-position').text(result.position));
                row.append($('<td>').addClass('col-bib').text(result.bib_number));

                let flagHtml = this.getFlagHtml(result.country);
                const nameLink = $('<a>')
                    .attr('href', '#')
                    .addClass('chronotrack-view-details')
                    .attr('data-participant-id', result.participant_id);
                nameLink.html(flagHtml + '<strong>' + this.escapeHtml(result.full_name) + '</strong>');
                row.append($('<td>').addClass('col-name').append(nameLink));
                row.append($('<td>').addClass('col-category').text(result.category));
                row.append($('<td>').addClass('col-club').text(result.club));
                row.append($('<td>').addClass('col-time time-cell').text(result.finish_time));
            }

            return row;
        },

        getFlagHtml: function(countryCode) {
            if (!countryCode || countryCode.length !== 2) return '';
            const cc = countryCode.toLowerCase();
            return '<img src="https://flagcdn.com/16x12/' + cc + '.png" ' +
                   'srcset="https://flagcdn.com/32x24/' + cc + '.png 2x" ' +
                   'width="16" height="12" ' +
                   'alt="' + countryCode.toUpperCase() + '" ' +
                   'class="ct-flag" ' +
                   'onerror="this.style.display=\'none\'"> ';
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

        renderMetaResults: function(results) {
            const tbody = $('#chronotrack-meta-body');
            tbody.empty();

            if (!results || results.length === 0) {
                tbody.html('<tr><td colspan="20" class="chronotrack-no-results">Brak zawodników na mecie</td></tr>');
                return;
            }

            results.forEach((result) => tbody.append(this.createMetaResultRow(result)));
        },

        createMetaResultRow: function(result) {
            const row = $('<tr>').attr('data-result-id', result.id);
            const finishTime = new Date(result.finish_timestamp).toLocaleTimeString('pl-PL');

            row.append($('<td>').addClass('col-finish-time').text(finishTime));
            row.append($('<td>').addClass('col-bib').text(result.bib_number));

            let flagHtml = this.getFlagHtml(result.country);
            const nameLink = $('<a>')
                .attr('href', '#')
                .addClass('chronotrack-view-details')
                .attr('data-participant-id', result.participant_id);
            nameLink.html(flagHtml + '<strong>' + this.escapeHtml(result.full_name) + '</strong>');
            row.append($('<td>').addClass('col-name').append(nameLink));
            row.append($('<td>').addClass('col-category').text(result.category));
            row.append($('<td>').addClass('col-club').text(result.club));
            row.append($('<td>').addClass('col-time time-cell').text(result.finish_time));
            row.append($('<td>').addClass('pos-cell').text(result.position));

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
                $('#chronotrack-meta-body') : $('#chronotrack-results-body');

            let visible = 0;
            tbody.find('tr').each(function() {
                const row = $(this);
                const name = row.find('.col-name, .col-full_name').text().toLowerCase();
                const bib = row.find('.col-bib, .col-entry_bib').text().toLowerCase();
                const rowCategory = row.find('.col-category').text();
                const rowDistance = row.attr('data-distance') || '';

                let show = true;
                if (searchTerm && !name.includes(searchTerm) && !bib.includes(searchTerm)) show = false;
                if (category && rowCategory !== category) show = false;
                if (distance && rowDistance !== distance) show = false;

                row.toggle(show);
                if (show) visible++;
            });

            // Update count display
            $('#chronotrack-visible-count').text(visible);
        },

        populateFilters: function(results) {
            const categories = new Set();
            results.forEach((result) => {
                if (result.category) {
                    // Clean up category name - remove redundant suffixes
                    const cleanCat = this.cleanCategoryName(result.category);
                    categories.add(cleanCat);
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

        cleanCategoryName: function(category) {
            // Remove redundant "(wszystkie)" or "(all)" etc. for OPEN categories
            return category
                .replace(/\s*\(wszystkie\s*(kategorie)?\)/i, '')
                .replace(/\s*\(all\s*(categories)?\)/i, '')
                .trim();
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
                error: () => alert(chronotrackData.strings.error)
            });
        },

        renderParticipantDetails: function(participant) {
            const flagHtml = this.getFlagHtml(participant.country);
            let html = '<div class="chronotrack-participant-details">';
            html += '<h2>' + flagHtml + this.escapeHtml(participant.full_name) + '</h2>';
            html += '<div class="chronotrack-details-grid">';

            html += '<div class="chronotrack-details-section">';
            html += '<h3>Podstawowe informacje</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Numer startowy:</th><td><strong>' + this.escapeHtml(participant.bib_number) + '</strong></td></tr>';
            html += '<tr><th>Wiek:</th><td>' + (participant.age || '-') + '</td></tr>';
            html += '<tr><th>Płeć:</th><td>' + this.escapeHtml(participant.gender) + '</td></tr>';
            html += '<tr><th>Miejscowość:</th><td>' + this.escapeHtml(participant.city) + '</td></tr>';
            html += '<tr><th>Klub:</th><td>' + this.escapeHtml(participant.club) + '</td></tr>';
            html += '<tr><th>Kategoria:</th><td>' + this.escapeHtml(participant.category) + '</td></tr>';
            html += '</table></div>';

            html += '<div class="chronotrack-details-section">';
            html += '<h3>Wyniki</h3>';
            html += '<table class="chronotrack-details-table">';
            html += '<tr><th>Miejsce Open:</th><td class="chronotrack-position">' + participant.position + '</td></tr>';
            html += '<tr><th>Miejsce w kategorii:</th><td class="chronotrack-position">' + participant.category_position + '</td></tr>';
            html += '<tr><th>Miejsce M/K:</th><td class="chronotrack-position">' + participant.gender_position + '</td></tr>';
            html += '<tr><th>Czas brutto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.finish_time) + '</td></tr>';
            html += '<tr><th>Czas netto:</th><td class="chronotrack-time">' + this.escapeHtml(participant.net_time) + '</td></tr>';
            html += '</table></div>';

            html += '</div></div>';
            $('#chronotrack-modal-body').html(html);
        },

        openModal: function() { $('#chronotrack-modal').fadeIn(200); },
        closeModal: function() { $('#chronotrack-modal').fadeOut(200); },

        renderDistanceButtons: function() {
            const container = $('#chronotrack-distance-filters');
            if (!container.length) return;
            container.empty();

            const allBtn = $('<button>')
                .addClass('chronotrack-distance-filter-btn' + (this.selectedDistance === '' ? ' active' : ''))
                .attr('data-distance', '')
                .text('Wszystkie');
            container.append(allBtn);

            this.distances.forEach((distance) => {
                const btn = $('<button>')
                    .addClass('chronotrack-distance-filter-btn' + (this.selectedDistance === distance ? ' active' : ''))
                    .attr('data-distance', distance)
                    .text(distance);
                container.append(btn);

                // Add PDF button for each distance
                const pdfBtn = $('<button>')
                    .addClass('chronotrack-pdf-btn')
                    .attr('data-distance', distance)
                    .html('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg> PDF ' + distance);
                container.append(pdfBtn);
            });
        },

        selectDistance: function(distance) {
            this.selectedDistance = distance;
            $('.chronotrack-distance-filter-btn').removeClass('active');
            $('.chronotrack-distance-filter-btn[data-distance="' + distance + '"]').addClass('active');
            this.filterResults();
        },

        /**
         * Generate PDF client-side using jsPDF + autoTable
         * No server call needed - reads data from allResults
         */
        generatePDF: function(distance) {
            console.log('📄 Generating PDF for distance:', distance);

            if (typeof window.jspdf === 'undefined' && typeof jsPDF === 'undefined') {
                alert('Biblioteka PDF nie jest załadowana. Spróbuj odświeżyć stronę.');
                return;
            }

            // Get jsPDF constructor
            const { jsPDF } = window.jspdf || { jsPDF: window.jsPDF };
            if (!jsPDF) {
                alert('Błąd inicjalizacji biblioteki PDF.');
                return;
            }

            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

            // Filter results by distance
            let resultsToExport = this.allResults;
            if (distance && distance !== 'all') {
                resultsToExport = this.allResults.filter(r => r.distance === distance);
            }

            if (!resultsToExport || resultsToExport.length === 0) {
                alert('Brak wyników do wygenerowania PDF.');
                return;
            }

            // Event name from header
            const eventName = $('.chronotrack-event-name').text() || 'Wyniki';
            const eventDate = $('.chronotrack-event-date').text() || '';
            const distanceLabel = distance && distance !== 'all' ? ' - ' + distance : '';
            const title = eventName + distanceLabel;

            // PDF header
            doc.setFontSize(18);
            doc.setTextColor(26, 58, 110);
            doc.text(title, 14, 18);

            doc.setFontSize(10);
            doc.setTextColor(100, 100, 100);
            doc.text(eventDate, 14, 25);
            doc.text('Wygenerowano: ' + new Date().toLocaleString('pl-PL'), 14, 30);
            doc.text('Liczba zawodników: ' + resultsToExport.length, 14, 35);

            // Build table headers and data
            let headers, rows;

            if (this.columns && this.columns.length > 0) {
                headers = this.columns.map(c => c.name);
                rows = resultsToExport.map(result => {
                    return this.columns.map(column => {
                        return String(this.getColumnValue(result, column) || '-');
                    });
                });
            } else {
                headers = ['Mce', 'Nr', 'Nazwisko Imię', 'Kategoria', 'Klub', 'Czas'];
                rows = resultsToExport.map(r => [
                    String(r.position || '-'),
                    String(r.bib_number || '-'),
                    String(r.full_name || '-'),
                    String(r.category || '-'),
                    String(r.club || '-'),
                    String(r.finish_time || '-')
                ]);
            }

            doc.autoTable({
                head: [headers],
                body: rows,
                startY: 40,
                styles: {
                    font: 'helvetica',
                    fontSize: 9,
                    cellPadding: 3,
                    overflow: 'linebreak',
                    valign: 'middle'
                },
                headStyles: {
                    fillColor: [26, 58, 110],
                    textColor: 255,
                    fontStyle: 'bold',
                    fontSize: 9
                },
                alternateRowStyles: {
                    fillColor: [245, 247, 252]
                },
                columnStyles: {
                    0: { halign: 'center', cellWidth: 15 }, // position
                    1: { halign: 'center', cellWidth: 16 }, // bib
                },
                margin: { top: 40, left: 14, right: 14 },
                didParseCell: function(data) {
                    // Highlight top 3
                    if (data.section === 'body' && data.column.index === 0) {
                        const pos = parseInt(data.cell.text[0]);
                        if (pos === 1) data.cell.styles.fillColor = [255, 215, 0];
                        else if (pos === 2) data.cell.styles.fillColor = [192, 192, 192];
                        else if (pos === 3) data.cell.styles.fillColor = [205, 127, 50];
                    }
                }
            });

            // Footer with page numbers
            const pageCount = doc.internal.getNumberOfPages();
            for (let i = 1; i <= pageCount; i++) {
                doc.setPage(i);
                doc.setFontSize(8);
                doc.setTextColor(150);
                doc.text(
                    'Strona ' + i + ' z ' + pageCount + ' | ' + eventName,
                    doc.internal.pageSize.getWidth() / 2,
                    doc.internal.pageSize.getHeight() - 5,
                    { align: 'center' }
                );
            }

            // Save file
            const fileName = (eventName + distanceLabel)
                .replace(/[^a-zA-Z0-9\-_\s]/g, '')
                .replace(/\s+/g, '_')
                .toLowerCase() + '.pdf';
            doc.save(fileName);
            console.log('✅ PDF saved:', fileName);
        },

        startAutoRefresh: function() {
            const interval = 10000;
            this.currentInterval = interval;

            // Load immediately on start
            this.loadResults(this.currentView);

            this.refreshInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, interval);

            this.fullCheckInterval = setInterval(() => {
                this.loadResults(this.currentView);
            }, 60000);
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) { clearInterval(this.refreshInterval); this.refreshInterval = null; }
            if (this.fullCheckInterval) { clearInterval(this.fullCheckInterval); this.fullCheckInterval = null; }
        },

        updateTimestamp: function() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('pl-PL');
            $('#chronotrack-timestamp').text('Aktualizacja: ' + timeString);
        },

        showLoading: function() { $('.chronotrack-loading').show(); },
        hideLoading: function() { $('.chronotrack-loading').hide(); },
        showError: function(message) { console.error('ChronoTrack Error:', message); },

        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        ChronoTrackResults.init();
    });

})(jQuery);
