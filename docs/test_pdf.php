<?php
// Test PDF generation directly
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/pdflib.php');

try {
    echo "Testing TCPDF PDF generation...\n";
    
    $pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8', false);
    echo "PDF created\n";
    
    $pdf->AddPage();
    echo "Page added\n";
    
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 15, 'Test Module', 0, 1, 'C');
    echo "Title added\n";
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Test Report', 0, 1, 'C');
    echo "Subtitle added\n";
    
    $pdf->Ln(10);
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Test Section', 0, 1);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 5, 'This is a test PDF.');
    echo "Content added\n";
    
    echo "About to generate PDF...\n";
    $output = $pdf->Output('test.pdf', 'S');
    echo "PDF generated, length: " . strlen($output) . " bytes\n";
    
    if (strlen($output) < 100) {
        echo "ERROR: PDF output too small!\n";
    } else {
        echo "SUCCESS: PDF appears valid\n";
        echo "First 50 bytes (should start with PDF marker): ";
        echo substr($output, 0, 50) . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
