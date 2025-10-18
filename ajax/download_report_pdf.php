<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for downloading module exploration report as PDF.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/pdflib.php');

// Get course ID
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('moodle/course:view', $context);

// Check if feature is enabled
if (!get_config('aiplacement_modgen', 'enable_exploration')) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Module exploration is not enabled.';
    die();
}

try {
    // Get POST data with report content
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (empty($data)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'No data provided.';
        die();
    }
    
    // Create PDF using Moodle's PDF class
    $pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Add title page
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, $course->fullname, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Module Learning Insights', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'Generated on ' . userdate(time()), 0, 1, 'C');
    
    $pdf->Ln(10);
    
    // Pedagogical Analysis Section
    if (!empty($data['pedagogical'])) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Pedagogical Approach', 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $text = strip_tags($data['pedagogical']);
        $pdf->MultiCell(0, 5, $text);
        $pdf->Ln(5);
    }
    
    // Learning Types Section
    if (!empty($data['learning_types'])) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, "Laurillard's Learning Types", 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $text = strip_tags($data['learning_types']);
        $pdf->MultiCell(0, 5, $text);
        $pdf->Ln(5);
    }
    
    // Activity Breakdown Section
    if (!empty($data['activities'])) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Activity Breakdown', 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $text = strip_tags($data['activities']);
        $pdf->MultiCell(0, 5, $text);
        $pdf->Ln(5);
    }
    
    // Improvement Suggestions Section
    if (!empty($data['improvements'])) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Improvement Suggestions', 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $text = strip_tags($data['improvements']);
        $pdf->MultiCell(0, 5, $text);
        $pdf->Ln(5);
    }
    
    // Activity Summary Chart Section
    if (!empty($data['chart_data']) && is_array($data['chart_data'])) {
        $chart_data = $data['chart_data'];
        if (!empty($chart_data['labels'])) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 10, 'Learning Types Distribution', 0, 1);
            
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Ln(5);
            
            // Create a simple table for the chart data
            $pdf->SetFillColor(0, 102, 204);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 10);
            
            $pdf->Cell(100, 7, 'Learning Type', 1, 0, 'L', true);
            $pdf->Cell(50, 7, 'Count', 1, 1, 'C', true);
            
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetFillColor(240, 240, 240);
            
            $fill = false;
            for ($i = 0; $i < count($chart_data['labels']); $i++) {
                $label = isset($chart_data['labels'][$i]) ? $chart_data['labels'][$i] : '';
                $count = isset($chart_data['data'][$i]) ? $chart_data['data'][$i] : 0;
                $pdf->Cell(100, 7, $label, 1, 0, 'L', $fill);
                $pdf->Cell(50, 7, (string)$count, 1, 1, 'C', $fill);
                $fill = !$fill;
            }
        }
    }
    
    // Output PDF with filename
    $pdf->Output('module_exploration_report.pdf', 'D');
    die();
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error generating PDF: ' . $e->getMessage();
    error_log('PDF generation error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    die();
}
