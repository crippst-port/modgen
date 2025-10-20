<?php
// Quick test script to verify quiz class loading
require_once(__DIR__ . '/../../config.php');
require_login();

// Set up page
$PAGE->set_url('/ai/placement/modgen/test_quiz.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Quiz Test');
$PAGE->set_heading('Module Generator Quiz Test');

echo $OUTPUT->header();

// Test the quiz class directly
require_once(__DIR__ . '/classes/activitytype/quiz.php');

echo "<h2>Testing Quiz Class</h2>";

try {
    $quiz = new \aiplacement_modgen\activitytype\quiz();
    echo "<p>✅ Quiz class instantiated successfully</p>";
    
    echo "<p>Type: " . $quiz::get_type() . "</p>";
    echo "<p>Display string: " . $quiz::get_display_string_id() . "</p>";
    echo "<p>Description: " . $quiz::get_prompt_description() . "</p>";
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

// Test the registry
require_once(__DIR__ . '/classes/activitytype/registry.php');

echo "<h2>Testing Registry</h2>";

try {
    $map = \aiplacement_modgen\activitytype\registry::get_supported_activity_metadata();
    echo "<p>✅ Registry loaded successfully</p>";
    echo "<p>Supported activities:</p>";
    echo "<pre>" . print_r($map, true) . "</pre>";
} catch (Exception $e) {
    echo "<p>❌ Registry error: " . $e->getMessage() . "</p>";
}

echo "<h2>Debug Log</h2>";
if (file_exists('/tmp/modgen_debug.log')) {
    echo "<pre>" . htmlspecialchars(file_get_contents('/tmp/modgen_debug.log')) . "</pre>";
} else {
    echo "<p>No debug log found yet</p>";
}

echo $OUTPUT->footer();
?>