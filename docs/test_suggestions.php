<?php
/**
 * Test script for suggestion generation debugging
 * Usage: php test_suggestions.php <courseid> [section]
 */

// Setup Moodle environment
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    die("Error: config.php not found at $configpath\n");
}
require_once($configpath);

// Verify CLI context
if (!defined('CLI_SCRIPT') && !isset($argv)) {
    die("This script must be run from the command line\n");
}

// Get parameters from command line
$courseid = isset($argv[1]) ? (int)$argv[1] : 1;
$section = isset($argv[2]) ? (int)$argv[2] : 0;

echo "Testing suggestion generation for Course ID: $courseid, Section: $section\n";
echo str_repeat("=", 80) . "\n\n";

require_login();

// Load the ai_service
$aisvcpath = __DIR__ . '/../classes/local/ai_service.php';
if (!file_exists($aisvcpath)) {
    die("Error: ai_service.php not found at $aisvcpath\n");
}
require_once($aisvcpath);

// Load registry for activity metadata
$regpath = __DIR__ . '/../classes/activitytype/registry.php';
if (!file_exists($regpath)) {
    die("Error: registry.php not found at $regpath\n");
}
require_once($regpath);

// Build section map
$modinfo = get_fast_modinfo($courseid);
$sectionmap = [];

$sections = $modinfo->get_section_info_all();
foreach ($sections as $s) {
    $sectionmap[] = [
        'section' => $s->section,
        'name' => !empty($s->name) ? $s->name : get_string('sectionname', 'moodle', $s->section),
        'summary' => $s->summary ?? '',
    ];
}

// Filter to specific section if requested
if (!empty($section) && is_int($section) && $section > 0) {
    $filtered = array_values(array_filter($sectionmap, function($s) use ($section) {
        return (int)$s['section'] === (int)$section;
    }));
    if (!empty($filtered)) {
        $sectionmap = $filtered;
    }
}

echo "Section Map (" . count($sectionmap) . " sections):\n";
foreach ($sectionmap as $s) {
    echo "  Section {$s['section']}: {$s['name']}\n";
    echo "    Summary: " . (empty($s['summary']) ? "(empty)" : substr($s['summary'], 0, 50) . "...") . "\n";
}
echo "\n";

// Call the suggestion generator
echo "Calling generate_suggestions_from_map()...\n";
echo str_repeat("-", 80) . "\n";

$result = \aiplacement_modgen\local\ai_service::generate_suggestions_from_map($sectionmap, $courseid);

echo "\nResult:\n";
echo "  success: " . ($result['success'] ? 'true' : 'false') . "\n";
if (isset($result['error'])) {
    echo "  error: {$result['error']}\n";
}
if (isset($result['suggestions'])) {
    echo "  suggestions count: " . count($result['suggestions']) . "\n";
    foreach ($result['suggestions'] as $i => $s) {
        echo "\n  Suggestion " . ($i + 1) . ":\n";
        echo "    ID: {$s['id']}\n";
        echo "    Activity: {$s['activity']->type} - {$s['activity']->name}\n";
        echo "    Rationale: " . substr($s['rationale'], 0, 60) . (strlen($s['rationale']) > 60 ? '...' : '') . "\n";
        echo "    Laurillard: {$s['laurillard_type']}\n";
        echo "    Supported: " . ($s['supported'] ? 'yes' : 'no') . "\n";
    }
}
if (isset($result['raw'])) {
    echo "\n\nRaw AI Response (first 500 chars):\n";
    echo str_repeat("-", 80) . "\n";
    echo substr($result['raw'], 0, 500) . "\n";
    if (strlen($result['raw']) > 500) {
        echo "... (" . (strlen($result['raw']) - 500) . " more characters)\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Test complete.\n";
