<?php
/**
 * PDF Generator for ChronoTrack Live Results
 * Based on generator_offline.py logic
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load TCPDF library if available
$tcpdf_path = dirname(__FILE__) . '/../lib/tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) {
    require_once $tcpdf_path;
}

// Only define custom TCPDF class if TCPDF is loaded
if (class_exists('TCPDF')) {
    /**
     * Custom TCPDF class with automatic footer on every page
     */
    class ChronoTrack_PDF extends TCPDF {

        public function Footer() {
            // Position at 15 mm from bottom
            $this->SetY(-15);

            // Set font
            $this->SetFont('dejavusans', '', 7);
            $this->SetTextColor(0, 0, 0);

            // Footer text on left
            $footer_text = 'Wygenerował: YO&GO Events - Twój pomiar czasu www.yogoevents.pl';
            $this->Cell(190, 5, $footer_text, 0, 0, 'L');

            // Page number on right
            $page_num = 'Strona ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages();
            $this->Cell(87, 5, $page_num, 0, 0, 'R');
        }
    }
}

class ChronoTrack_PDF_Generator {

    /**
     * YOGO Events logo URL (always on right side)
     */
    const YOGO_LOGO_URL = 'https://yogoevents.pl/wp-content/uploads/2017/09/poziom-kolor3.jpg';

    /**
     * Generate PDF for specific distance
     *
     * @param int $event_id ChronoTrack Event ID
     * @param string $distance Distance name to filter results
     * @return string|WP_Error Path to generated PDF or error
     */
    public function generate_pdf($event_id, $distance) {
        // Try Python generator first (better quality)
        $python_result = $this->generate_pdf_python($event_id, $distance);
        if (!is_wp_error($python_result)) {
            return $python_result;
        }

        // Fallback to PHP TCPDF if Python fails
        error_log("PDF: Python generator failed, falling back to TCPDF: " . $python_result->get_error_message());
        return $this->generate_pdf_tcpdf($event_id, $distance);
    }

    /**
     * Generate PDF using Python ReportLab (RECOMMENDED - better quality)
     */
    private function generate_pdf_python($event_id, $distance) {
        // Check if Python 3 is available
        $python_path = exec('which python3');
        if (empty($python_path)) {
            return new WP_Error('python_not_found', 'Python 3 not found on server');
        }

        // Get event data
        $db = chronotrack_live_results()->db;
        $event = $db->get_event($event_id);

        if (!$event) {
            return new WP_Error('event_not_found', __('Event not found.', 'chronotrack-live'));
        }

        // Get results
        $results = $db->get_results($event_id);
        if (empty($results)) {
            return new WP_Error('no_results', __('No results found for this event.', 'chronotrack-live'));
        }

        // Filter by distance
        $filtered_results = array_filter($results, function($result) use ($distance) {
            return isset($result->distance) && $result->distance === $distance;
        });

        if (empty($filtered_results)) {
            return new WP_Error('no_results_distance', __('No results found for this distance.', 'chronotrack-live'));
        }

        // Get column configuration
        $columns = $db->get_event_columns($event_id, true);

        // Prepare data for Python
        $python_data = array(
            'event_name' => $event->event_name,
            'event_date' => date_i18n('d.m.Y', strtotime($event->event_date)),
            'location' => $event->event_location ?? '',
            'distance' => $distance,
            'columns' => array(),
            'results' => array(),
            'output_path' => '',
            'event_logo_url' => $event->event_logo_url ?? '',
        );

        // Add columns
        foreach ($columns as $col) {
            $python_data['columns'][] = array(
                'name' => $col->column_name,
                'api_attributes' => $col->api_attributes ?? array(),
            );
        }

        // Add results
        foreach ($filtered_results as $result) {
            $result_data = array();

            // Convert object to array
            foreach ($result as $key => $value) {
                $result_data[$key] = $value;
            }

            // Parse split_times if it's a JSON string
            if (isset($result_data['split_times']) && is_string($result_data['split_times'])) {
                $result_data['split_times'] = json_decode($result_data['split_times'], true);
            }

            $python_data['results'][] = $result_data;
        }

        // Generate filename
        $filename = $this->get_filename($event, $distance);
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/chronotrack-pdfs/';

        // Create directory
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $python_data['output_path'] = $pdf_dir . $filename;

        // Call Python script
        $script_path = CHRONOTRACK_LIVE_PLUGIN_DIR . 'scripts/generate_pdf.py';
        $json_data = json_encode($python_data);

        $command = sprintf(
            '%s %s 2>&1',
            escapeshellarg($python_path),
            escapeshellarg($script_path)
        );

        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // Write JSON to stdin
            fwrite($pipes[0], $json_data);
            fclose($pipes[0]);

            // Read output
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($process);

            if ($return_value === 0) {
                $result = json_decode($output, true);
                if ($result && isset($result['success']) && $result['success']) {
                    return $result['pdf_path'];
                } else {
                    $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
                    return new WP_Error('python_generation_failed', $error_msg);
                }
            } else {
                error_log("PDF Python Error: " . $errors);
                return new WP_Error('python_execution_failed', "Python script failed: $errors");
            }
        }

        return new WP_Error('python_process_failed', 'Failed to start Python process');
    }

    /**
     * Generate PDF using PHP TCPDF (FALLBACK)
     */
    private function generate_pdf_tcpdf($event_id, $distance) {
        // Check if TCPDF is available
        if (!$this->load_tcpdf()) {
            return new WP_Error('tcpdf_missing', __('TCPDF library not found. Please install TCPDF.', 'chronotrack-live'));
        }

        // Get event data (get_event searches by ChronoTrack event_id)
        $db = chronotrack_live_results()->db;
        $event = $db->get_event($event_id);

        if (!$event) {
            return new WP_Error('event_not_found', __('Event not found.', 'chronotrack-live'));
        }

        // Get results for this distance
        $results = $db->get_results($event_id);

        if (empty($results)) {
            return new WP_Error('no_results', __('No results found for this event.', 'chronotrack-live'));
        }

        // Filter by distance
        $filtered_results = array_filter($results, function($result) use ($distance) {
            return isset($result->distance) && $result->distance === $distance;
        });

        if (empty($filtered_results)) {
            return new WP_Error('no_results_distance', __('No results found for this distance.', 'chronotrack-live'));
        }

        // Get column configuration
        $columns = $db->get_event_columns($event_id, true);

        // Generate PDF
        try {
            $pdf_path = $this->create_pdf($event, $distance, $filtered_results, $columns);
            return $pdf_path;
        } catch (Exception $e) {
            return new WP_Error('pdf_generation_failed', $e->getMessage());
        }
    }

    /**
     * Create PDF file
     */
    private function create_pdf($event, $distance, $results, $columns) {
        error_log("PDF: Starting PDF generation for event {$event->event_name}, distance {$distance}");
        error_log("PDF: Results count: " . count($results));

        // Increase memory limit for PDF generation
        $current_limit = ini_get('memory_limit');
        error_log("PDF: Current memory limit: {$current_limit}");
        @ini_set('memory_limit', '256M');

        // Increase execution time
        @set_time_limit(300);

        try {
            // Create new PDF document (Landscape A4) with custom footer
            error_log("PDF: Creating TCPDF instance");

            // Use custom class if available, otherwise fallback to TCPDF
            if (class_exists('ChronoTrack_PDF')) {
                $pdf = new ChronoTrack_PDF('L', 'mm', 'A4', true, 'UTF-8', false);
                $use_custom_footer = true;
            } else {
                $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
                $use_custom_footer = false;
            }

            // Disable TCPDF errors to prevent PHP warnings from breaking PDF
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
            $pdf->SetAutoPageBreak(TRUE, 15); // 15mm bottom margin for footer

            // Set document information
            $pdf->SetCreator('YO&GO Events - ChronoTrack Live Results');
            $pdf->SetAuthor('YO&GO Events');
            $pdf->SetTitle($event->event_name . ' - ' . $distance);
            $pdf->SetSubject('Wyniki zawodów');

            // Remove default header but KEEP custom footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter($use_custom_footer);  // Enable footer only if custom class available

            // Set margins
            $pdf->SetMargins(10, 28, 10); // left, top, right
            $pdf->SetAutoPageBreak(true, 15); // bottom margin

            // Set font for Polish characters
            error_log("PDF: Setting font");
            $pdf->SetFont('dejavusans', '', 8);

            // Add a page
            error_log("PDF: Adding first page");
            $pdf->AddPage();

            // Add custom header with logos
            error_log("PDF: Adding header");
            $this->add_header($pdf, $event, $distance);

            // Add results table (includes footer)
            error_log("PDF: Adding results table");
            $this->add_results_table($pdf, $results, $columns);
        } catch (Exception $e) {
            error_log("PDF: CRITICAL ERROR during PDF creation: " . $e->getMessage());
            error_log("PDF: Stack trace: " . $e->getTraceAsString());
            throw $e; // Re-throw to be caught by generate_pdf()
        }

        // Footer is now added inside add_results_table() at the bottom of the last page

        // Generate filename
        $filename = $this->get_filename($event, $distance);
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/chronotrack-pdfs/';

        // Create directory if it doesn't exist
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $filepath = $pdf_dir . $filename;

        // Output PDF to file
        $pdf->Output($filepath, 'F');

        return $filepath;
    }

    /**
     * Add custom header with event info and logos
     */
    private function add_header($pdf, $event, $distance) {
        // Get current Y position
        $y = $pdf->GetY();

        // Event name (orange, bold)
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 102, 0); // #FF6600 orange
        // Use mb_strtoupper for proper UTF-8 handling (Piątka → PIĄTKA, not PIąTKA)
        $pdf->Cell(0, 6, mb_strtoupper($event->event_name, 'UTF-8'), 0, 1, 'L');

        // Subtitle: distance, location, date (black)
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetTextColor(0, 0, 0);

        $event_date = date_i18n('d.m.Y', strtotime($event->event_date));
        $location = $event->event_location ?? '';

        $subtitle = sprintf('Wyniki OPEN | %s | %s | %s', $distance, $location, $event_date);
        $pdf->Cell(0, 5, $subtitle, 0, 1, 'L');

        // Add logos in top right corner
        $page_width = $pdf->getPageWidth();
        $right_margin = 10;

        // Logo 1 (YOGO) - rightmost position
        // CRITICAL: Match header height (max 12mm to fit in header area)
        try {
            $logo1_height = 12; // Match header height
            $logo1_width = 26;  // Proportional width (approx 2.2:1 ratio)
            $logo1_x = $page_width - $right_margin - $logo1_width;
            $logo1_y = 10; // Align with header text

            $this->add_logo($pdf, self::YOGO_LOGO_URL, $logo1_x, $logo1_y, $logo1_width, $logo1_height);
        } catch (Exception $e) {
            error_log("PDF: Failed to add YOGO logo, continuing without it: " . $e->getMessage());
        }

        // Logo 2 (Event logo) - left of YOGO logo
        if (!empty($event->event_logo_url)) {
            try {
                $logo2_height = 12; // Match header height
                $logo2_width = 26;  // Proportional width
                $logo2_x = $logo1_x - $logo2_width - 5; // 5mm gap between logos
                $logo2_y = 10; // Align with header text

                $this->add_logo($pdf, $event->event_logo_url, $logo2_x, $logo2_y, $logo2_width, $logo2_height);
            } catch (Exception $e) {
                error_log("PDF: Failed to add event logo, continuing without it: " . $e->getMessage());
            }
        }

        // Add some space after header
        $pdf->Ln(3);
    }

    /**
     * Add logo to PDF
     */
    private function add_logo($pdf, $url, $x, $y, $width, $height) {
        try {
            if (empty($url)) {
                return; // Skip if no URL
            }

            error_log("PDF: Attempting to download logo from: {$url}");

            // Download image to temp file
            $temp_file = download_url($url);

            if (is_wp_error($temp_file)) {
                error_log('PDF: Failed to download logo: ' . $temp_file->get_error_message());
                return; // Continue without logo
            }

            if (!file_exists($temp_file)) {
                error_log('PDF: Temp file not found after download');
                return;
            }

            // Get image type (with error handling in case exif extension missing)
            if (function_exists('exif_imagetype')) {
                $image_type = exif_imagetype($temp_file);
                if ($image_type === false) {
                    error_log('PDF: Invalid image file');
                    @unlink($temp_file);
                    return;
                }
            }

            error_log("PDF: Adding logo to PDF at position ({$x}, {$y})");

            // Add image to PDF
            $pdf->Image($temp_file, $x, $y, $width, $height, '', '', '', false, 300, '', false, false, 0);

            // Clean up temp file
            @unlink($temp_file);

            error_log("PDF: Logo added successfully");

        } catch (Exception $e) {
            error_log('PDF: Error adding logo to PDF: ' . $e->getMessage());
            error_log('PDF: Stack trace: ' . $e->getTraceAsString());
            // Continue without logo - don't break PDF generation
        }
    }

    /**
     * Add results table using HTML (better rendering, no empty pages)
     */
    private function add_results_table($pdf, $results, $columns) {
        // Calculate dynamic column widths in mm
        $col_widths = $this->calculate_column_widths($columns, 277); // 297mm - 20mm margins

        // CRITICAL: Set explicit column widths on BOTH headers AND data cells
        $width_style = '';
        foreach ($col_widths as $index => $width_mm) {
            $width_style .= 'col' . $index . ' { width: ' . round($width_mm, 2) . 'mm; } ';
        }

        // Build HTML table with explicit column widths
        // CRITICAL: Only horizontal borders (top/bottom), NO vertical borders
        $html = '<style>
            table {
                border-collapse: collapse;
                width: 100%;
                font-size: 7pt;
                table-layout: fixed;
            }
            ' . $width_style . '
            th {
                background-color: #FF6600;
                color: #FFFFFF;
                font-weight: bold;
                text-align: center;
                padding: 3px 2px;
                border-top: 1px solid #000000;
                border-bottom: 1px solid #000000;
                line-height: 1.2;
            }
            td {
                text-align: center;
                padding: 2px 1px;
                border-top: 0.5px solid #CCCCCC;
                border-bottom: 0.5px solid #CCCCCC;
                line-height: 1.3;
            }
            tr {
                page-break-inside: avoid !important;
            }
        </style>';

        $html .= '<table nobr="true" cellspacing="0" cellpadding="2">';

        // Table header with explicit widths
        $html .= '<thead><tr>';
        foreach ($columns as $index => $col) {
            $html .= '<th class="col' . $index . '" style="width:' . round($col_widths[$index], 2) . 'mm;">' .
                     htmlspecialchars($col->column_name, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead>';

        // Table body with STRIPED ROWS
        $html .= '<tbody>';
        $row_number = 0;
        foreach ($results as $row_index => $result) {
            $row_number++;
            // STRIPED ROWS: odd=white, even=gray
            $bgcolor = ($row_number % 2 == 1) ? '#FFFFFF' : '#F5F5F5';

            $html .= '<tr nobr="true" bgcolor="' . $bgcolor . '">';
            foreach ($columns as $index => $col) {
                $value = $this->get_column_value($result, $col);
                // CRITICAL: Apply same width to data cells as headers
                $html .= '<td class="col' . $index . '" style="width:' . round($col_widths[$index], 2) . 'mm;">' .
                         htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';

        // Keep auto page break enabled for multi-page tables
        // Footer is automatically added by ChronoTrack_PDF::Footer() on every page
        $pdf->SetAutoPageBreak(true, 20); // 20mm bottom margin for footer

        // Write HTML table
        $pdf->writeHTML($html, true, false, true, false, '');

        // Footer is added automatically by TCPDF on all pages (no manual footer needed)
    }

    /**
     * Get value for a column from result object
     * Uses same logic as chronotrack-live.js getColumnValue()
     */
    private function get_column_value($result, $column) {
        // Get API attributes array (same as JS)
        $attributes = !empty($column->api_attributes) ? $column->api_attributes : [];

        // Try each attribute in order until we find a value
        foreach ($attributes as $attr) {
            // CRITICAL: Handle split_time:IntervalName attributes
            if (strpos($attr, 'split_time:') === 0) {
                $interval_name = substr($attr, 11); // Remove 'split_time:' prefix

                if (!empty($result->split_times)) {
                    $split_times = is_string($result->split_times)
                        ? json_decode($result->split_times, true)
                        : $result->split_times;

                    if (is_array($split_times)) {
                        foreach ($split_times as $split) {
                            if (isset($split['interval_name']) && $split['interval_name'] === $interval_name) {
                                // Return formatted time if available, otherwise raw time
                                if (!empty($split['formatted_time'])) {
                                    return $split['formatted_time'];
                                } elseif (!empty($split['time'])) {
                                    return $split['time'];
                                }
                            }
                        }
                    }
                }
                // If no split time found, continue to next attribute
                continue;
            }

            // Handle special case for full_name
            if ($attr === 'full_name' || $attr === 'athlete_last_name,athlete_first_name') {
                $last_name = isset($result->last_name) ? trim($result->last_name) : '';
                $first_name = isset($result->first_name) ? trim($result->first_name) : '';

                if ($last_name || $first_name) {
                    return $last_name . ($last_name && $first_name ? ' ' : '') . $first_name;
                }

                if (isset($result->full_name)) {
                    return $result->full_name;
                }
            }

            // Handle pace/tempo (formatted_pace or pace_formatted or pace)
            if ($attr === 'formatted_pace' || $attr === 'pace_formatted' || $attr === 'pace') {
                // Try formatted_pace first
                if (!empty($result->formatted_pace)) {
                    return $result->formatted_pace;
                }
                // Then pace_formatted
                if (!empty($result->pace_formatted)) {
                    return $result->pace_formatted;
                }
                // Then raw pace
                if (!empty($result->pace)) {
                    return $result->pace;
                }
                // If no pace found, continue to next attribute
                continue;
            }

            // Handle category_position with bracket_positions fallback
            if ($attr === 'category_position' || $attr === 'division_place' || $attr === 'results_division_rank') {
                $cat_position = isset($result->{$attr}) ? $result->{$attr} : null;

                // If category_position is 0 or empty, try bracket_positions
                if ((!$cat_position || $cat_position == 0) && isset($result->bracket_positions) && is_array($result->bracket_positions)) {
                    // Try to find position in bracket_positions
                    foreach ($result->bracket_positions as $bracket => $position) {
                        if ($position > 0) {
                            return $position;
                        }
                    }
                }

                // Return category_position if > 0
                if ($cat_position && $cat_position > 0) {
                    return $cat_position;
                }
            }

            // Try direct attribute - accept 0 as valid value
            if (isset($result->{$attr}) && $result->{$attr} !== null && $result->{$attr} !== '') {
                return $result->{$attr};
            }
        }

        // Return empty string for blank cells (NOT dash)
        return '';
    }

    /**
     * Calculate optimal column widths based on headers
     * Based on logic from generator_offline.py
     */
    private function calculate_column_widths($columns, $available_width) {
        $proportions = array();

        foreach ($columns as $col) {
            $name_lower = strtolower($col->column_name);

            // Numbers and positions - narrow
            if (preg_match('/(msc|mce|nr|start|kat)/i', $name_lower)) {
                $proportions[] = 0.5;
            }
            // Times, pace, and split times - medium
            else if (preg_match('/(czas|tempo|min|km|brutto|netto|pk|pk\d|meta)/i', $name_lower)) {
                $proportions[] = 0.9;
            }
            // Names - wider
            else if (preg_match('/(nazwisko|imię|imie)/i', $name_lower)) {
                $proportions[] = 1.5;
            }
            // Clubs and locations - wider
            else if (preg_match('/(miejscowość|klub)/i', $name_lower)) {
                $proportions[] = 1.4;
            }
            // Default
            else {
                $proportions[] = 1.0;
            }
        }

        // Calculate actual widths
        $total_proportion = array_sum($proportions);
        $widths = array();

        foreach ($proportions as $proportion) {
            $widths[] = ($proportion / $total_proportion) * $available_width;
        }

        return $widths;
    }

    /**
     * Generate filename for PDF
     */
    private function get_filename($event, $distance) {
        $event_name = sanitize_file_name($event->event_name);
        $distance_name = sanitize_file_name($distance);
        $timestamp = date('Ymd_His');

        return sprintf('wyniki_%s_%s_%s.pdf', $event_name, $distance_name, $timestamp);
    }

    /**
     * Load TCPDF library
     */
    private function load_tcpdf() {
        $tcpdf_path = CHRONOTRACK_LIVE_PLUGIN_DIR . 'lib/tcpdf/tcpdf.php';

        error_log('TCPDF: Checking path: ' . $tcpdf_path);
        error_log('TCPDF: File exists: ' . (file_exists($tcpdf_path) ? 'YES' : 'NO'));
        error_log('TCPDF: CHRONOTRACK_LIVE_PLUGIN_DIR: ' . CHRONOTRACK_LIVE_PLUGIN_DIR);

        if (!file_exists($tcpdf_path)) {
            error_log('TCPDF: File NOT found at: ' . $tcpdf_path);
            return false;
        }

        try {
            require_once $tcpdf_path;
            error_log('TCPDF: Successfully loaded');

            // Check if TCPDF class is now available
            if (!class_exists('TCPDF')) {
                error_log('TCPDF: Class TCPDF not found after require!');
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log('TCPDF: Exception loading: ' . $e->getMessage());
            return false;
        }
    }
}
