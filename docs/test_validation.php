<?php
/**
 * Test validation system with malformed response example
 *
 * Usage: php test_validation.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/ai_service.php');

echo "Testing AI Response Validation System\n";
echo str_repeat("=", 80) . "\n\n";

// Example of malformed response (the sausages example provided)
$malformed_response = [
    "themes" => [
        [
            "title" => "AI Generated Summary",
            "summary" => '{"themes":[{"title":"Historical Development of Sausages","summary":"<div class=\'container\'><div class=\'row\'><div class=\'col-md-12\'><h5>Theme 1: Historical Development of Sausages</h5><p>Explore the origins and evolution of sausages from ancient times to the modern era.</p></div></div></div>","weeks":[{"title":"Week 1: Ancient Times","summary":"<div class=\'container\'><p>Begin with an exploration of sausages in ancient civilizations.</p></div>","activities":[{"type":"url","name":"Ancient Sausages Articles"}]}]}]}'
        ]
    ]
];

// Example of valid response
$valid_response = [
    "themes" => [
        [
            "title" => "Historical Development of Sausages",
            "summary" => "<div class='container'><div class='row'><div class='col-md-12'><h5>Theme 1: Historical Development of Sausages</h5><p>Explore the origins and evolution of sausages.</p></div></div></div>",
            "weeks" => [
                [
                    "title" => "Week 1: Ancient Times",
                    "summary" => "<div class='container'><p>Begin with an exploration of sausages in ancient civilizations.</p></div>",
                    "activities" => [
                        [
                            "type" => "url",
                            "name" => "Ancient Sausages Articles",
                            "externalurl" => "https://example.com"
                        ]
                    ]
                ]
            ]
        ]
    ]
];

// Use reflection to access private validation method
$reflection = new ReflectionClass('aiplacement_modgen\ai_service');
$method = $reflection->getMethod('validate_module_structure');
$method->setAccessible(true);

echo "TEST 1: Malformed Response (double-encoded JSON in summary)\n";
echo str_repeat("-", 80) . "\n";
$result1 = $method->invokeArgs(null, [$malformed_response, 'theme']);
echo "Valid: " . ($result1['valid'] ? 'YES' : 'NO') . "\n";
echo "Error: " . $result1['error'] . "\n\n";

if (!$result1['valid']) {
    echo "✓ PASS: Malformed response correctly detected!\n";
} else {
    echo "✗ FAIL: Malformed response was not detected!\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

echo "TEST 2: Valid Response (properly structured)\n";
echo str_repeat("-", 80) . "\n";
$result2 = $method->invokeArgs(null, [$valid_response, 'theme']);
echo "Valid: " . ($result2['valid'] ? 'YES' : 'NO') . "\n";
echo "Error: " . $result2['error'] . "\n\n";

if ($result2['valid']) {
    echo "✓ PASS: Valid response correctly accepted!\n";
} else {
    echo "✗ FAIL: Valid response was incorrectly rejected!\n";
    echo "   Reason: " . $result2['error'] . "\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Test additional edge cases
echo "TEST 3: Empty themes array\n";
echo str_repeat("-", 80) . "\n";
$empty_response = ["themes" => []];
$result3 = $method->invokeArgs(null, [$empty_response, 'theme']);
echo "Valid: " . ($result3['valid'] ? 'YES' : 'NO') . "\n";
echo "Error: " . $result3['error'] . "\n";
if (!$result3['valid']) {
    echo "✓ PASS: Empty array correctly rejected!\n";
} else {
    echo "✗ FAIL: Empty array should have been rejected!\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

echo "TEST 4: Missing title\n";
echo str_repeat("-", 80) . "\n";
$no_title = [
    "themes" => [
        [
            "summary" => "Some summary",
            "weeks" => []
        ]
    ]
];
$result4 = $method->invokeArgs(null, [$no_title, 'theme']);
echo "Valid: " . ($result4['valid'] ? 'YES' : 'NO') . "\n";
echo "Error: " . $result4['error'] . "\n";
if (!$result4['valid']) {
    echo "✓ PASS: Missing title correctly rejected!\n";
} else {
    echo "✗ FAIL: Missing title should have been rejected!\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

echo "TEST 5: Double-encoded JSON in week summary\n";
echo str_repeat("-", 80) . "\n";
$malformed_week = [
    "themes" => [
        [
            "title" => "Test Theme",
            "summary" => "Valid summary",
            "weeks" => [
                [
                    "title" => "Week 1",
                    "summary" => '{"some":"json","nested":"data"}',
                    "activities" => []
                ]
            ]
        ]
    ]
];
$result5 = $method->invokeArgs(null, [$malformed_week, 'theme']);
echo "Valid: " . ($result5['valid'] ? 'YES' : 'NO') . "\n";
echo "Error: " . $result5['error'] . "\n";
if (!$result5['valid']) {
    echo "✓ PASS: Double-encoded week summary correctly detected!\n";
} else {
    echo "✗ FAIL: Double-encoded week summary was not detected!\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Summary
$tests_passed = 0;
$tests_total = 5;

if (!$result1['valid']) $tests_passed++;
if ($result2['valid']) $tests_passed++;
if (!$result3['valid']) $tests_passed++;
if (!$result4['valid']) $tests_passed++;
if (!$result5['valid']) $tests_passed++;

echo "SUMMARY:\n";
echo "Tests passed: {$tests_passed}/{$tests_total}\n";

if ($tests_passed === $tests_total) {
    echo "\n✓ All tests passed! Validation system is working correctly.\n";
} else {
    echo "\n✗ Some tests failed. Review the validation logic.\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
