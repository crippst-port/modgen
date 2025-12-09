<?php
/**
 * Test script for section date manipulation functionality.
 * 
 * Tests:
 * 1. Adding dates to section titles
 * 2. Removing dates from section titles
 * 3. Handling various date formats
 * 4. Parent/child section relationships
 * 
 * Run from command line:
 * php test_section_dates.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/date_calculator.php');

global $DB;

echo "=== Section Dates Functionality Test ===\n\n";

// Create a test course
$coursedata = new stdClass();
$coursedata->fullname = 'Test Section Dates - ' . date('Y-m-d H:i:s');
$coursedata->shortname = 'test-dates-' . time();
$coursedata->format = 'flexsections';
$coursedata->startdate = time();
$coursedata->category = 1;

$course = create_course($coursedata);
echo "✓ Created test course: {$course->fullname} (ID: {$course->id})\n\n";

$courseformat = course_get_format($course);
$errors = [];
$warnings = [];

// Test 1: Create sections with various names
echo "Test 1: Creating sections with different name formats\n";
$test_names = [
    'Introduction to Programming',
    'Week 1: Getting Started',
    'Advanced Topics',
    'Dec 1–7: Final Review',
    'Project Work (Nov 15-21)',
    'Mon 20 Jan - Fri 24 Jan: Assessment',
];

$created_sections = [];
foreach ($test_names as $index => $name) {
    $sectionnum = $courseformat->create_new_section(0, null);
    $sectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => $sectionnum]);
    
    $DB->update_record('course_sections', [
        'id' => $sectionid,
        'name' => $name,
    ]);
    
    $created_sections[] = [
        'id' => $sectionid,
        'section' => $sectionnum,
        'original_name' => $name,
    ];
    
    echo "  Created section {$sectionnum}: '{$name}'\n";
}
echo "  ✓ Created " . count($created_sections) . " sections\n\n";

// Test 2: Apply dates to sections
echo "Test 2: Applying dates to sections\n";

// Set course start date to a known date for testing
$test_start_date = strtotime('2025-01-06'); // Monday, Jan 6, 2025
$DB->update_record('course', [
    'id' => $course->id,
    'startdate' => $test_start_date,
]);

echo "  Course start date set to: " . date('Y-m-d', $test_start_date) . "\n";

// Calculate dates for sections
$section_ids = array_column($created_sections, 'id');
try {
    $calculated = \aiplacement_modgen\local\date_calculator::calculate_section_dates(
        $course->id,
        $section_ids,
        true // include parents
    );
    
    echo "  ✓ Date calculation completed\n";
    echo "  Calculated dates for " . count($calculated) . " sections\n";
    
    // Apply the dates
    $applied_count = 0;
    foreach ($calculated as $calc) {
        $section = $DB->get_record('course_sections', ['id' => $calc['id']]);
        if ($section && !empty($calc['formatted_date'])) {
            $clean_name = \aiplacement_modgen\local\date_calculator::remove_existing_date($section->name);
            $new_name = $calc['formatted_date'] . ': ' . $clean_name;
            
            $DB->update_record('course_sections', [
                'id' => $calc['id'],
                'name' => $new_name,
                'timemodified' => time(),
            ]);
            
            $applied_count++;
            echo "    Section {$section->section}: '{$section->name}' → '{$new_name}'\n";
        }
    }
    
    echo "  ✓ Applied dates to {$applied_count} sections\n\n";
    
} catch (Exception $e) {
    $errors[] = "Date calculation failed: " . $e->getMessage();
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Verify dates were applied correctly
echo "Test 3: Verifying applied dates\n";
$verification_pass = true;
foreach ($created_sections as $sec) {
    $current = $DB->get_record('course_sections', ['id' => $sec['id']]);
    
    // Check if section now has a date prefix
    $has_date = preg_match('/^[A-Z][a-z]{2}\s+\d{1,2}/', $current->name);
    
    if ($has_date) {
        echo "  ✓ Section {$sec['section']}: '{$current->name}' has date prefix\n";
    } else {
        echo "  ✗ Section {$sec['section']}: '{$current->name}' missing date prefix\n";
        $verification_pass = false;
    }
}

if ($verification_pass) {
    echo "  ✓ All sections have date prefixes\n\n";
} else {
    $errors[] = "Some sections missing date prefixes";
    echo "  ✗ Some sections missing date prefixes\n\n";
}

// Test 4: Test remove_existing_date with various formats
echo "Test 4: Testing date removal patterns\n";
$test_dates_removal = [
    'Dec 1–7: Introduction' => 'Introduction',
    'Nov 29 - Dec 5: Advanced Topics' => 'Advanced Topics',
    'Mon 20 Jan - Fri 24 Jan: Assessment' => 'Assessment',
    '20/01 - 24/01: Project Work' => 'Project Work',
    'Jan 20-24: Final Review' => 'Final Review',
    '(Dec 1-7) Getting Started' => 'Getting Started',
    'Project Work (Nov 15-21)' => 'Project Work',
    'Dec 28–Jan 3: Holiday Week' => 'Holiday Week',
];

$pattern_test_pass = true;
foreach ($test_dates_removal as $input => $expected) {
    $result = \aiplacement_modgen\local\date_calculator::remove_existing_date($input);
    if ($result === $expected) {
        echo "  ✓ '{$input}' → '{$result}'\n";
    } else {
        echo "  ✗ '{$input}' → '{$result}' (expected '{$expected}')\n";
        $pattern_test_pass = false;
        $errors[] = "Date removal pattern failed for: {$input}";
    }
}

if ($pattern_test_pass) {
    echo "  ✓ All date removal patterns working correctly\n\n";
} else {
    echo "  ✗ Some date removal patterns failed\n\n";
}

// Test 5: Remove all dates from sections
echo "Test 5: Removing dates from all sections\n";
$removed_count = 0;
$removal_results = [];

foreach ($created_sections as $sec) {
    $section = $DB->get_record('course_sections', ['id' => $sec['id']]);
    $original_name = $section->name;
    
    // Remove existing date
    $new_name = \aiplacement_modgen\local\date_calculator::remove_existing_date($section->name);
    
    if ($new_name !== $section->name) {
        $DB->update_record('course_sections', [
            'id' => $sec['id'],
            'name' => $new_name,
            'timemodified' => time(),
        ]);
        
        $removed_count++;
        echo "  Removed from section {$sec['section']}: '{$original_name}' → '{$new_name}'\n";
        
        $removal_results[] = [
            'section' => $sec['section'],
            'before' => $original_name,
            'after' => $new_name,
        ];
    }
}

echo "  ✓ Removed dates from {$removed_count} sections\n\n";

// Test 6: Verify dates were fully removed
echo "Test 6: Verifying dates were removed\n";
$clean_verification_pass = true;

foreach ($created_sections as $sec) {
    $current = $DB->get_record('course_sections', ['id' => $sec['id']]);
    
    // Check that section no longer has date prefix
    $has_date = preg_match('/^[A-Z][a-z]{2}\s+\d{1,2}/', $current->name);
    $has_date_parens = preg_match('/\([^)]*\d{1,2}[^)]*\)/', $current->name);
    
    if (!$has_date && !$has_date_parens) {
        echo "  ✓ Section {$sec['section']}: '{$current->name}' has no date\n";
    } else {
        echo "  ✗ Section {$sec['section']}: '{$current->name}' still has date\n";
        $clean_verification_pass = false;
        $errors[] = "Section {$sec['section']} still has date after removal";
    }
}

if ($clean_verification_pass) {
    echo "  ✓ All dates successfully removed\n\n";
} else {
    echo "  ✗ Some dates were not removed\n\n";
}

// Test 7: Test edge cases
echo "Test 7: Testing edge cases\n";
$edge_cases = [
    '' => '',
    'No dates here' => 'No dates here',
    'Just a number 123' => 'Just a number 123',
    '2025' => '2025', // Year alone shouldn't be removed
    'Dec 1–7: Dec 1–7: Double prefix' => 'Dec 1–7: Double prefix', // Should only remove first
];

$edge_case_pass = true;
foreach ($edge_cases as $input => $expected) {
    $result = \aiplacement_modgen\local\date_calculator::remove_existing_date($input);
    if ($result === $expected) {
        echo "  ✓ Edge case: '{$input}' → '{$result}'\n";
    } else {
        echo "  ✗ Edge case: '{$input}' → '{$result}' (expected '{$expected}')\n";
        $edge_case_pass = false;
        $warnings[] = "Edge case failed: {$input}";
    }
}

if ($edge_case_pass) {
    echo "  ✓ All edge cases handled correctly\n\n";
} else {
    echo "  ⚠ Some edge cases behaved unexpectedly\n\n";
}

// Rebuild course cache
rebuild_course_cache($course->id, true);

// Cleanup: Delete test course
echo "\n=== CLEANUP ===\n";
delete_course($course->id, false);
echo "✓ Test course deleted\n";

// Final summary
echo "\n=== TEST SUMMARY ===\n";
echo "Total sections created: " . count($created_sections) . "\n";
echo "Dates applied: {$applied_count}\n";
echo "Dates removed: {$removed_count}\n";
echo "Pattern tests: " . count($test_dates_removal) . "\n";
echo "Edge case tests: " . count($edge_cases) . "\n";

echo "\n=== ERRORS AND WARNINGS ===\n";
if (empty($errors) && empty($warnings)) {
    echo "✓✓✓ NO ERRORS OR WARNINGS ✓✓✓\n";
} else {
    if (!empty($warnings)) {
        echo "\nWARNINGS (" . count($warnings) . "):\n";
        foreach ($warnings as $warning) {
            echo "  ⚠ {$warning}\n";
        }
    }
    if (!empty($errors)) {
        echo "\nERRORS (" . count($errors) . "):\n";
        foreach ($errors as $error) {
            echo "  ✗ {$error}\n";
        }
    }
}

echo "\n=== FINAL RESULT ===\n";
if (empty($errors)) {
    echo "✓✓✓ ALL TESTS PASSED ✓✓✓\n";
    echo "Section date functionality is working correctly!\n";
    exit(0);
} else {
    echo "✗✗✗ TESTS FAILED ✗✗✗\n";
    echo "Found " . count($errors) . " errors during testing.\n";
    exit(1);
}
