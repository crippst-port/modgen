<?php
/**
 * Test date application on an existing course.
 * 
 * This verifies that the layout-based logic correctly applies dates
 * to sections in courses that already have the proper structure.
 * 
 * Run from command line:
 * php test_existing_course_dates.php <courseid>
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

// Get course ID from command line
$courseid = isset($argv[1]) ? (int)$argv[1] : null;

if (!$courseid) {
    echo "Usage: php test_existing_course_dates.php <courseid>\n";
    echo "Example: php test_existing_course_dates.php 4\n";
    exit(1);
}

// Verify course exists
$course = $DB->get_record('course', ['id' => $courseid]);
if (!$course) {
    echo "Error: Course with ID {$courseid} not found.\n";
    exit(1);
}

echo "=== Testing Date Logic on Existing Course ===\n";
echo "Course: {$course->fullname} (ID: {$courseid})\n";
echo "Format: {$course->format}\n\n";

// Detect layout
$layout = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid);

echo "Detected Layout: {$layout['type']}\n";
echo "Description: {$layout['description']}\n";
echo "Hierarchy Levels: {$layout['details']['hierarchy_levels']}\n";
echo "Has themes: " . ($layout['details']['has_themes'] ? 'Yes' : 'No') . "\n";
echo "Has weeks under themes: " . ($layout['details']['has_weeks_under_themes'] ? 'Yes' : 'No') . "\n";
echo "Top-level sections: {$layout['details']['top_level_sections']}\n\n";

// Calculate dates
echo "=== Calculating Section Dates ===\n\n";
$results = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], false);

// Session names for filtering
$sessionnames = [
    get_string('presession', 'aiplacement_modgen'),
    get_string('session', 'aiplacement_modgen'),
    get_string('postsession', 'aiplacement_modgen')
];

// Group results by whether they have dates
$withdates = [];
$withoutdates = [];

foreach ($results as $sectionid => $data) {
    if (!empty($data['formatted_date'])) {
        $withdates[] = $data;
    } else {
        $withoutdates[] = $data;
    }
}

// Display sections WITH dates
if (!empty($withdates)) {
    echo "Sections that WILL receive dates:\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($withdates as $data) {
        $type = $data['is_parent'] ? 'THEME/PARENT' : 'WEEK';
        printf(
            "[%3d] %-10s %-40s → %s\n",
            $data['section'],
            $type,
            substr($data['name'], 0, 40),
            $data['formatted_date']
        );
    }
    echo "\nTotal: " . count($withdates) . " sections\n\n";
} else {
    echo "⚠️  WARNING: No sections will receive dates!\n\n";
}

// Display sections WITHOUT dates
if (!empty($withoutdates)) {
    echo "Sections that will NOT receive dates:\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($withoutdates as $data) {
        $type = $data['is_parent'] ? 'THEME/PARENT' : 'OTHER';
        printf(
            "[%3d] %-10s %s\n",
            $data['section'],
            $type,
            $data['name']
        );
    }
    echo "\nTotal: " . count($withoutdates) . " sections\n\n";
}

// Verify logic based on detected layout
echo "=== Verification ===\n";
$passed = true;
$errors = [];

switch ($layout['type']) {
    case 'theme_based':
        // Should have: weeks (children) with dates
        // Should NOT have: themes (parents) with dates
        foreach ($results as $data) {
            if ($data['is_parent'] && !empty($data['formatted_date'])) {
                $errors[] = "❌ Theme '{$data['name']}' has dates but should NOT in theme-based layout";
                $passed = false;
            }
            if (!$data['is_parent'] && !in_array($data['name'], $sessionnames) && empty($data['formatted_date'])) {
                $errors[] = "❌ Week '{$data['name']}' has NO dates but SHOULD in theme-based layout";
                $passed = false;
            }
        }
        break;
        
    case 'week_based':
        // Top-level parents should have dates
        foreach ($results as $data) {
            if ($data['is_parent'] && empty($data['formatted_date'])) {
                $errors[] = "❌ Top-level section '{$data['name']}' has NO dates but SHOULD in week-based layout";
                $passed = false;
            }
        }
        break;
        
    case 'flat':
        // All top-level sections should have dates
        // (we can't easily verify this without knowing the full structure)
        if (empty($withdates)) {
            $errors[] = "❌ No sections have dates in flat layout - something is wrong";
            $passed = false;
        }
        break;
}

if ($passed) {
    echo "✅ ALL CHECKS PASSED\n";
    echo "The date logic is working correctly for this {$layout['type']} layout.\n";
} else {
    echo "❌ SOME CHECKS FAILED\n";
    foreach ($errors as $error) {
        echo $error . "\n";
    }
}

echo "\n";
exit($passed ? 0 : 1);
