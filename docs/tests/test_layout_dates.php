<?php
/**
 * Test script to verify date application based on layout detection.
 * 
 * Tests that:
 * - Theme-based: Only weeks get dates, NOT themes
 * - Week-based: Top-level sections (themes as weeks) get dates
 * - Flat: All standalone sections get dates
 * 
 * Run from command line:
 * php test_layout_dates.php <courseid>
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

// Get course ID from command line.
$courseid = isset($argv[1]) ? (int)$argv[1] : null;

if (!$courseid) {
    echo "Usage: php test_layout_dates.php <courseid>\n";
    echo "Example: php test_layout_dates.php 2\n";
    echo "\nTest courses:\n";
    echo "  Course 2: Theme-based layout\n";
    echo "  Course 27: Week-based layout\n";
    echo "  Course 28: Flat layout\n";
    exit(1);
}

// Verify course exists.
$course = $DB->get_record('course', ['id' => $courseid]);
if (!$course) {
    echo "Error: Course with ID {$courseid} not found.\n";
    exit(1);
}

echo "=== Date Application by Layout Type ===\n";
echo "Course: {$course->fullname} (ID: {$courseid})\n\n";

// Detect layout.
$layout = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid);

echo "Layout Type: {$layout['type']}\n";
echo "Description: {$layout['description']}\n";
echo "Hierarchy Levels: {$layout['details']['hierarchy_levels']}\n\n";

// Calculate section dates.
$results = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], true);

echo "=== Sections with Dates ===\n";
echo "The following sections will receive dates:\n\n";

$sessionnames = [
    get_string('presession', 'aiplacement_modgen'),
    get_string('session', 'aiplacement_modgen'),
    get_string('postsession', 'aiplacement_modgen')
];

$sectionswithdates = 0;
$themesections = 0;
$weeksections = 0;

foreach ($results as $sectionid => $data) {
    $hasdates = !empty($data['formatted_date']);
    $isparent = $data['is_parent'] ?? false;
    
    if ($hasdates) {
        $sectionswithdates++;
        
        if ($isparent) {
            $themesections++;
            $marker = '📂 THEME';
        } else {
            $weeksections++;
            $marker = '📅 WEEK';
        }
        
        echo sprintf(
            "%s [%2d] %-40s → %s\n",
            $marker,
            $data['section'],
            substr($data['name'], 0, 40),
            $data['formatted_date']
        );
    }
}

echo "\n=== Sections WITHOUT Dates ===\n";
echo "The following sections appear in the form but won't get dates:\n\n";

$sectionswithourdates = 0;

foreach ($results as $sectionid => $data) {
    $hasdates = !empty($data['formatted_date']);
    $isparent = $data['is_parent'] ?? false;
    
    if (!$hasdates) {
        $sectionswithourdates++;
        
        if ($isparent) {
            $marker = '📂 THEME (no dates)';
        } else {
            $marker = '📄 SECTION (no dates)';
        }
        
        echo sprintf(
            "%s [%2d] %s\n",
            $marker,
            $data['section'],
            $data['name']
        );
    }
}

echo "\n=== Summary ===\n";
echo "Layout type: {$layout['type']}\n";
echo "Total sections with dates: {$sectionswithdates}\n";
echo "  - Themes with dates: {$themesections}\n";
echo "  - Weeks with dates: {$weeksections}\n";
echo "Sections without dates: {$sectionswithourdates}\n\n";

// Validation based on layout type.
echo "=== Validation ===\n";
$valid = true;

switch ($layout['type']) {
    case 'theme_based':
        if ($themesections > 0) {
            echo "✅ PASS: {$themesections} theme(s) have date spans (optional to apply)\n";
        } else {
            echo "⚠️  WARNING: No themes found with date spans\n";
        }
        
        if ($weeksections > 0) {
            echo "✅ PASS: {$weeksections} week(s) correctly have dates\n";
        } else {
            echo "⚠️  WARNING: No weeks found with dates\n";
        }
        break;
        
    case 'week_based':
        if ($themesections > 0) {
            echo "✅ PASS: {$themesections} theme(s) (treated as weeks) correctly have dates\n";
        } else {
            echo "⚠️  WARNING: No themes found with dates\n";
        }
        
        if ($weeksections > 0) {
            echo "❌ FAIL: Week-based layout should not have nested weeks\n";
            echo "   Found {$weeksections} week(s) with dates\n";
            $valid = false;
        }
        break;
        
    case 'flat':
        if ($sectionswithdates > 0) {
            echo "✅ PASS: {$sectionswithdates} section(s) correctly have dates\n";
        } else {
            echo "⚠️  WARNING: No sections found with dates\n";
        }
        
        if ($themesections > 0) {
            echo "⚠️  WARNING: Flat layout has theme sections with dates (unexpected)\n";
        }
        break;
}

echo "\nOverall: " . ($valid ? "✅ ALL CHECKS PASSED" : "❌ VALIDATION FAILED") . "\n";
