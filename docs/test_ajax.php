<?php
// Test the AJAX endpoint
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

// Simulate a request to the AJAX endpoint
$_GET['courseid'] = 2; // Replace with an actual course ID if needed

// Include and run the AJAX script
ob_start();
try {
    include(__DIR__ . '/ajax/explore_ajax.php');
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
$output = ob_get_clean();

echo "Output:\n";
echo $output . "\n";
?>
