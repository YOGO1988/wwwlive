<?php
/**
 * PDF Generator for ChronoTrack Live Results
 * Based on generator_offline.py logic
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChronoTrack_PDF_Generator {

    /**
     * YOGO Events logo URL (always on right side)
     */
    const YOGO_LOGO_URL = 'https://yogoevents.pl/wp-content/uploads/2017/09/poziom-kolor3.jpg';

    /**
     * Generate PDF for specific distance
     *
     * @param int $page_id WordPress Page ID
     * @param string $distance Distance name to filter results
     * @return string|WP_Error Path to generated PDF or error
     */
    public function generate_pdf($page_id, $distance) {
        // Check if TCPDF is available
        if (!$this->load_tcpdf()) {
            return new WP_Error('tcpdf_missing', __('TCPDF library not found. Please install TCPDF.', 'chronotrack-live'));
        }

        // Get event data from WordPress Page ID
        $db = chronotrack_live_results()->db;
        $event = $db->get_event_by_page($page_id);

        if (!$event) {
            return new WP_Error('event_not_found', __('Event not found for this page.', 'chronotrack-live'));
        }

        // Get results for this ChronoTrack Event ID
        $results = $db->get_results($event->event_id);

        if (empty($results)) {
            return new WP_Error('no_results', __('No results found for this event.', 'chronotrack-live'));
        }

        // Filter by distance
        $filtered_results = array_filter($results, function($result) use ($distance) {
            return isset($result->distance) && $result->distance === $distance;
        });

        if (empty($filtered_results)) {
            return new WP_Error('no_results_distance', __('No results found for this distance: ' . $distance, 'chronotrack-live'));
        }

        // Get column configuration
        $columns = $db->get_event_columns($event->event_id, true);

        if (empty($columns)) {
            return new WP_Error('no_columns', __('No columns configured for this event.', 'chronotrack-live'));
        }

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
        // Create new PDF document (Landscape A4)
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('YO&GO Events - ChronoTrack Live Results');
        $pdf->SetAuthor('YO&GO Events');
        $pdf->SetTitle($event->event_name . ' - ' . $distance);
        $pdf->SetSubject('Wyniki zawodów');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(10, 28, 10); // left, top, right
        $pdf->SetAutoPageBreak(true, 15); // bottom margin

        // Set font for Polish characters
        $pdf->SetFont('dejavusans', '', 8);

        // Add a page
        $pdf->AddPage();

        // Add custom header with logos
        $this->add_header($pdf, $event, $distance);

        // Add results table
        $this->add_results_table($pdf, $results, $columns);

        // Add footer
        $this->add_footer($pdf);

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
        $pdf->Cell(0, 6, strtoupper($event->event_name), 0, 1, 'L');

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
        $logo1_width = 80;
        $logo1_height = 35;
        $logo1_x = $page_width - $right_margin - $logo1_width;
        $logo1_y = 10;

        $this->add_logo($pdf, self::YOGO_LOGO_URL, $logo1_x, $logo1_y, $logo1_width, $logo1_height);

        // Logo 2 (Event logo) - left of YOGO logo
        if (!empty($event->event_logo_url)) {
            $logo2_width = 80;
            $logo2_height = 35;
            $logo2_x = $logo1_x - $logo2_width - 5; // 5mm gap between logos
            $logo2_y = 10;

            $this->add_logo($pdf, $event->event_logo_url, $logo2_x, $logo2_y, $logo2_width, $logo2_height);
        }

        // Add some space after header
        $pdf->Ln(3);
    }

    /**
     * Add logo to PDF
     */
    private function add_logo($pdf, $url, $x, $y, $width, $height) {
        try {
            // Download image to temp file
            $temp_file = download_url($url);

            if (is_wp_error($temp_file)) {
                error_log('Failed to download logo: ' . $temp_file->get_error_message());
                return;
            }

            // Get image type
            $image_type = exif_imagetype($temp_file);

            // Add image to PDF
            $pdf->Image($temp_file, $x, $y, $width, $height, '', '', '', false, 300, '', false, false, 0);

            // Clean up temp file
            @unlink($temp_file);

        } catch (Exception $e) {
            error_log('Error adding logo to PDF: ' . $e->getMessage());
        }
    }

    /**
     * Add results table
     */
    private function add_results_table($pdf, $results, $columns) {
        // Prepare table header
        $header = array();
        foreach ($columns as $col) {
            $header[] = $col->column_name;
        }

        // Prepare table data
        $data = array();
        foreach ($results as $result) {
            $row = array();
            foreach ($columns as $col) {
                $row[] = $this->get_column_value($result, $col);
            }
            $data[] = $row;
        }

        // Calculate column widths
        $page_width = $pdf->getPageWidth();
        $usable_width = $page_width - 20; // minus left and right margins
        $col_widths = $this->calculate_column_widths($columns, $usable_width);

        // Table header style
        $pdf->SetFillColor(255, 102, 0); // Orange #FF6600
        $pdf->SetTextColor(255, 255, 255); // White text
        $pdf->SetFont('dejavusans', 'B', 8);

        // Draw header
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        foreach ($header as $i => $col_name) {
            $pdf->MultiCell($col_widths[$i], 7, $col_name, 1, 'C', true, 0, '', '', true, 0, false, true, 7, 'M');
        }
        $pdf->Ln();

        // Table body
        $pdf->SetTextColor(0, 0, 0); // Black text
        $pdf->SetFont('dejavusans', '', 8);

        $fill = false;
        foreach ($data as $row) {
            // Alternate row colors
            if ($fill) {
                $pdf->SetFillColor(211, 211, 211); // Light grey
            } else {
                $pdf->SetFillColor(255, 255, 255); // White
            }

            foreach ($row as $i => $cell) {
                $pdf->MultiCell($col_widths[$i], 6, $cell, 1, 'C', true, 0, '', '', true, 0, false, true, 6, 'M');
            }
            $pdf->Ln();
            $fill = !$fill;
        }
    }

    /**
     * Add footer to PDF
     */
    private function add_footer($pdf) {
        // This will be called by TCPDF's footer mechanism
        // For now, we'll add it manually at the end of content
        $pdf->SetY(-15);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(0, 0, 0);

        // Footer text
        $footer_text = 'Wygenerował: YO&GO Events - Twój pomiar czasu www.yogoevents.pl';
        $pdf->Cell(0, 10, $footer_text, 0, 0, 'L');

        // Page number
        $page_num = 'Strona ' . $pdf->getAliasNumPage() . ' / ' . $pdf->getAliasNbPages();
        $pdf->Cell(0, 10, $page_num, 0, 0, 'R');
    }

    /**
     * Get value for a column from result object
     */
    private function get_column_value($result, $column) {
        $field = $column->api_field;

        // Handle special cases (same as in chronotrack-live.js)
        if ($field === 'full_name') {
            return trim(($result->athlete_last_name ?? '') . ' ' . ($result->athlete_first_name ?? ''));
        }

        // Handle bracket positions
        if (strpos($field, 'category_position') !== false || strpos($field, 'division_place') !== false) {
            // Try to get from bracket_positions if available
            if (isset($result->bracket_positions) && is_array($result->bracket_positions)) {
                foreach ($result->bracket_positions as $bracket => $position) {
                    if ($position > 0) {
                        return $position;
                    }
                }
            }
        }

        return $result->{$field} ?? '';
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
            // Times and pace - medium
            else if (preg_match('/(czas|tempo|min|km|brutto|netto)/i', $name_lower)) {
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

        if (!file_exists($tcpdf_path)) {
            return false;
        }

        require_once $tcpdf_path;
        return true;
    }
}
