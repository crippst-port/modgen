<?php
/**
 * Debug script to show section names and test date removal.
 *
 * Usage: php ai/placement/modgen/docs/debug_section_dates.php <courseid>
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\date_calculator;

// Get course ID from command line.
if ($argc < 2) {
    echo "Usage: php debug_section_dates.php <courseid>\n";
    exit(1);
}

$courseid = intval($argv[1]);

// Get course.
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

echo "\n=== Section Date Analysis for Course: {$course->fullname} (ID: {$courseid}) ===\n\n";

// Get all sections.
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

$total = 0;
$with_dates = 0;
$removable = 0;

echo "Section Analysis:\n";
echo str_repeat("=", 120) . "\n";
printf("%-8s %-50s %-50s %-8s\n", "Section", "Current Name", "After Remove", "Changed?");
echo str_repeat("=", 120) . "\n";

foreach ($sections as $section) {
    if ($section->section == 0) {
        continue; // Skip general section
    }
    
    $total++;
    $current = $section->name ?: "Section {$section->section}";
    $after = date_calculator::remove_existing_date($current);
    $changed = ($after !== $current);
    
    if ($changed) {
        $removable++;
    }
    
    // Detect if section has a date pattern
    $has_date = preg_match('/^[A-Z][a-z]{2}\s+\d{1,2}/', $current) || 
                preg_match('/\([^)]*\d{1,2}[^)]*\)/', $current);
    
    if ($has_date) {
        $with_dates++;
    }
    
    $status = $changed ? "✓ YES" : "✗ No";
    
    printf("%-8d %-50s %-50s %-8s\n", 
        $section->section, 
        strlen($current) > 48 ? substr($current, 0, 45) . '...' : $current,
        strlen($after) > 48 ? substr($after, 0, 45) . '...' : $after,
        $status
    );
}

echo str_repeat("=", 120) . "\n";

// Summary
echo "\n=== SUMMARY ===\n";
echo "Total sections (excluding section 0): {$total}\n";
echo "Sections with date patterns detected: {$with_dates}\n";
echo "Sections that would change with remove_existing_date(): {$removable}\n";
echo "Sections that would NOT change: " . ($total - $removable) . "\n";

// Test the 7 regex patterns
echo "\n=== Testing Date Removal Patterns ===\n";
$test_cases = [
    'Dec 1–7: Introduction',
    'Nov 29 - Dec 5: Advanced Topics',
    'Mon 20 Jan - Fri 24 Jan: Assessment',
    '20/01 - 24/01: Project Work',
    'Jan 20-24: Final Review',
    '(Dec 1-7) Getting Started',
    'Project Work (Nov 15-21)',
    'Dec 28–Jan 3: Holiday Week',
    'Week 1: Getting Started', // Should NOT be removed
    'Introduction to Programming', // Should NOT be removed
    'Theme: Advanced Topics', // Should NOT be removed
];

foreach ($test_cases as $test) {
    $result = date_calculator::remove_existing_date($test);
    $changed = ($result !== $test);
    $symbol = $changed ? "✓" : "✗";
    echo "{$symbol} '{$test}' → '{$result}'\n";
}

echo "\n=== What to Check ===\n";
echo "1. If 'Sections that would change' is 0, there are NO dates to remove\n";
echo "2. Check if your section names match ANY of the 7 patterns above\n";
echo "3. Patterns like 'Week 1:' are NOT considered dates and won't be removed\n";
echo "4. Only actual date prefixes (Dec 1-7, Jan 20-24, etc.) are removed\n";

// Recommend action
if ($removable == 0) {
    echo "\n⚠️  FINDING: Your course has NO sections with removable date patterns!\n";
    echo "This is why 'Remove All Dates' appears to do nothing.\n";
    echo "\nTo test the removal feature:\n";
    echo "1. First use 'Dates for Sections' to APPLY dates to some sections\n";
    echo "2. Then use 'Remove All Dates' to remove them\n";
} else {
    echo "\n✓ FINDING: Your course has {$removable} section(s) with removable dates.\n";
    echo "The 'Remove All Dates' button should work on these sections.\n";
}

echo "\n";
