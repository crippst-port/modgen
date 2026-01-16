<?php
/**
 * Test script to verify date application logic for all layout types.
 * 
 * This script creates test courses with different structures and verifies
 * that dates are applied correctly based on layout detection.
 * 
 * Run from command line:
 * php test_date_logic.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

echo "=== Date Logic Verification Test ===\n\n";

/**
 * Create a test course with a given structure.
 * 
 * @param string $name Course name
 * @param array $structure Array defining the section structure
 * @return int Course ID
 */
function create_test_course($name, $structure) {
    global $DB, $CFG;
    
    // Create course using Moodle API to ensure proper setup
    require_once($CFG->dirroot . '/course/lib.php');
    
    $course = new stdClass();
    $course->fullname = "Test: " . $name;
    $course->shortname = "TEST_" . strtoupper(str_replace(' ', '_', $name)) . '_' . time();
    $course->category = 1;
    $course->format = 'flexsections';
    $course->startdate = strtotime('2025-01-06'); // Monday
    $course->numsections = 0; // We'll add sections manually
    
    $course = create_course($course);
    $courseid = $course->id;
    
    // Create sections based on structure
    $sectionnum = 1;
    $sectionmap = []; // Track section numbers for parent references
    $sectionids = []; // Track section IDs
    
    foreach ($structure as $item) {
        $section = new stdClass();
        $section->course = $courseid;
        $section->section = $sectionnum;
        $section->name = $item['name'];
        $section->summary = '';
        $section->summaryformat = FORMAT_HTML;
        $section->visible = 1;
        $section->parent = 0;
        $section->timemodified = time();
        
        // Set parent if specified (parent field expects section NUMBER, not ID)
        if (!empty($item['parent_name']) && isset($sectionmap[$item['parent_name']])) {
            $section->parent = $sectionmap[$item['parent_name']];
        }
        
        $sectionid = $DB->insert_record('course_sections', $section);
        $sectionmap[$item['name']] = $sectionnum;  // Store section number for parent references
        $sectionids[$item['name']] = $sectionid;   // Store section ID for tracking
        
        $sectionnum++;
    }
    
    // IMPORTANT: Update parent fields AFTER all sections are created
    // because rebuild_course_cache() can reset them
    foreach ($structure as $item) {
        if (!empty($item['parent_name']) && isset($sectionmap[$item['parent_name']])) {
            $sectionid = $sectionids[$item['name']];
            $parentsectionnum = $sectionmap[$item['parent_name']];
            $DB->set_field('course_sections', 'parent', $parentsectionnum, ['id' => $sectionid]);
        }
    }
    
    // Rebuild course cache AFTER setting parents
    rebuild_course_cache($courseid, true);
    
    // Debug: Verify parent relationships were saved
    $modinfo = get_fast_modinfo($courseid);
    $sections = $modinfo->get_section_info_all();
    echo "Debug - Created sections:\n";
    foreach ($sections as $section) {
        if ($section->section > 0) {
            echo "  Section {$section->section}: {$section->name} (parent={$section->parent})\n";
        }
    }
    echo "\n";
    
    return $courseid;
}

/**
 * Test a course structure and verify date application.
 */
function test_layout($testname, $structure, $expected) {
    global $DB;
    
    echo "Testing: {$testname}\n";
    echo str_repeat('-', 60) . "\n";
    
    // Create test course
    $courseid = create_test_course($testname, $structure);
    
    // Detect layout
    $layout = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid);
    echo "Detected layout: {$layout['type']}\n";
    
    // Calculate dates
    $results = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], false);
    
    // Verify expectations
    $passed = true;
    $errors = [];
    
    foreach ($expected as $sectionname => $shouldhavedate) {
        $found = false;
        $hasdate = false;
        
        foreach ($results as $result) {
            if ($result['name'] === $sectionname) {
                $found = true;
                $hasdate = !empty($result['formatted_date']);
                break;
            }
        }
        
        if (!$found && $shouldhavedate) {
            $errors[] = "  ✗ {$sectionname}: Expected in results but not found";
            $passed = false;
        } else if ($found && $hasdate !== $shouldhavedate) {
            $status = $hasdate ? 'HAS date' : 'NO date';
            $expectation = $shouldhavedate ? 'SHOULD have date' : 'should NOT have date';
            $errors[] = "  ✗ {$sectionname}: {$status} but {$expectation}";
            $passed = false;
        } else if ($found) {
            $status = $hasdate ? 'has date ✓' : 'no date ✓';
            echo "  ✓ {$sectionname}: {$status}\n";
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo $error . "\n";
        }
    }
    
    // Cleanup
    delete_course($courseid, false);
    
    echo "\nResult: " . ($passed ? "✅ PASSED" : "❌ FAILED") . "\n\n";
    
    return $passed;
}

// Test 1: Theme-based layout (Theme → Week → Session)
$themebased = [
    ['name' => 'Theme 1'],
    ['name' => 'Week 1', 'parent_name' => 'Theme 1'],
    ['name' => 'Pre-session', 'parent_name' => 'Week 1'],
    ['name' => 'Session', 'parent_name' => 'Week 1'],
    ['name' => 'Post-session', 'parent_name' => 'Week 1'],
    ['name' => 'Week 2', 'parent_name' => 'Theme 1'],
    ['name' => 'Theme 2'],
    ['name' => 'Week 3', 'parent_name' => 'Theme 2'],
];

$expected_themebased = [
    'Theme 1' => false,  // Themes don't get dates in theme-based
    'Theme 2' => false,
    'Week 1' => true,    // Weeks DO get dates
    'Week 2' => true,
    'Week 3' => true,
    // Sessions are skipped entirely
];

$test1 = test_layout('Theme-based Layout', $themebased, $expected_themebased);

// Test 2: Week-based layout (Week → Session)
$weekbased = [
    ['name' => 'Week 1'],
    ['name' => 'Pre-session', 'parent_name' => 'Week 1'],
    ['name' => 'Session', 'parent_name' => 'Week 1'],
    ['name' => 'Week 2'],
    ['name' => 'Pre-session', 'parent_name' => 'Week 2'],
    ['name' => 'Session', 'parent_name' => 'Week 2'],
];

$expected_weekbased = [
    'Week 1' => true,  // Top-level sections with children get dates
    'Week 2' => true,
    // Sessions are skipped
];

$test2 = test_layout('Week-based Layout', $weekbased, $expected_weekbased);

// Test 3: Flat layout (standalone weeks)
$flat = [
    ['name' => 'Week 1'],
    ['name' => 'Week 2'],
    ['name' => 'Week 3'],
    ['name' => 'Week 4'],
];

$expected_flat = [
    'Week 1' => true,  // All top-level sections get dates
    'Week 2' => true,
    'Week 3' => true,
    'Week 4' => true,
];

$test3 = test_layout('Flat Layout', $flat, $expected_flat);

// Test 4: Mixed - ensure weeks without sessions still work
$mixed = [
    ['name' => 'Theme 1'],
    ['name' => 'Week 1', 'parent_name' => 'Theme 1'],
    ['name' => 'Week 2', 'parent_name' => 'Theme 1'],  // No sessions under this one
    ['name' => 'Session', 'parent_name' => 'Week 2'],
];

$expected_mixed = [
    'Theme 1' => false,
    'Week 1' => true,
    'Week 2' => true,
];

$test4 = test_layout('Mixed - Week with sessions', $mixed, $expected_mixed);

// Summary
echo "\n" . str_repeat('=', 60) . "\n";
echo "FINAL RESULTS:\n";
echo str_repeat('=', 60) . "\n";
echo "Theme-based layout: " . ($test1 ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Week-based layout:  " . ($test2 ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Flat layout:        " . ($test3 ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Mixed structure:    " . ($test4 ? "✅ PASSED" : "❌ FAILED") . "\n";

$allpassed = $test1 && $test2 && $test3 && $test4;
echo "\nOVERALL: " . ($allpassed ? "✅ ALL TESTS PASSED" : "❌ SOME TESTS FAILED") . "\n";

exit($allpassed ? 0 : 1);
