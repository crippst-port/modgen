<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../classes/local/ai_service.php');

// Manually set a courseid for testing
$courseid = 2; // Change to a valid course ID
$course = get_course($courseid);

// Test the AI service directly
$test_prompt = "Test: You must format your response as <h3>semantic HTML</h3>";
error_log("Test prompt: " . $test_prompt);

try {
    $result = \aiplacement_modgen\ai_service::analyze_module($test_prompt);
    error_log("AI Result: " . $result);
    echo "Success: " . $result . "\n";
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
?>
