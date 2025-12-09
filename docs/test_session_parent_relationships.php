<?php
/**
 * Test script to verify that session subsections have correct parent relationships.
 * 
 * This test:
 * 1. Creates a test JSON structure with sessions
 * 2. Simulates section creation using the same logic as prompt.php
 * 3. Verifies that all presession/session/postsession sections have correct parent IDs
 * 
 * Run from command line:
 * php test_session_parent_relationships.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;

echo "=== Session Parent Relationship Test ===\n\n";

// Create a test course
$coursedata = new stdClass();
$coursedata->fullname = 'Test Session Parent Relationships';
$coursedata->shortname = 'test-sessions-' . time();
$coursedata->format = 'flexsections';
$coursedata->startdate = time();
$coursedata->category = 1; // Use default category

$course = create_course($coursedata);
echo "✓ Created test course: {$course->fullname} (ID: {$course->id})\n";

// Get course format
$courseformat = course_get_format($course);
echo "✓ Course format: " . $courseformat->get_format() . "\n\n";

// Test 1: Create a theme section
echo "Test 1: Creating theme section...\n";
$themesectionnum = $courseformat->create_new_section(0, null);
$themesection = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $themesectionnum]);

// Check parent from format_options table
$themeparent = $DB->get_field('course_format_options', 'value', 
    ['courseid' => $course->id, 'sectionid' => $themesection->id, 'name' => 'parent']);
echo "✓ Theme section created: section={$themesectionnum}, id={$themesection->id}, parent={$themeparent} (from format_options)\n";

// Update theme name
$DB->update_record('course_sections', [
    'id' => $themesection->id,
    'name' => 'Test Theme 1',
]);

// Test 2: Create week section under theme (THIS IS WHERE THE BUG WAS)
echo "\nTest 2: Creating week section under theme...\n";
// Create week section at top level first
$weeksectionnum = $courseformat->create_new_section(0, null);
$weeksection = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $weeksectionnum]);

// CRITICAL: Manually set parent relationship using update_section_format_options
$courseformat->update_section_format_options([
    'id' => $weeksection->id,
    'parent' => $themesectionnum
]);

// Check parent from format_options table
$weekparent = $DB->get_field('course_format_options', 'value', 
    ['courseid' => $course->id, 'sectionid' => $weeksection->id, 'name' => 'parent']);
echo "✓ Week section created: section={$weeksectionnum}, id={$weeksection->id}, parent={$weekparent} (from format_options)\n";

// CRITICAL: Verify parent is section NUMBER, not ID
// According to flexsections, parent should be the section NUMBER of the parent
if ($weekparent == $themesectionnum) {
    echo "✓ CORRECT: Week parent ({$weekparent}) matches theme SECTION NUMBER ({$themesectionnum})\n";
} else {
    echo "✗ FAILED: Week parent ({$weekparent}) does NOT match theme SECTION NUMBER ({$themesectionnum})\n";
}// Update week name
$DB->update_record('course_sections', [
    'id' => $weeksection->id,
    'name' => 'Week 1',
]);

// Test 3: Create session subsections using session_creator
echo "\nTest 3: Creating session subsections under week...\n";
try {
    $sessiondata = [
        'presession' => ['description' => 'Pre-session description'],
        'session' => ['description' => 'Session description'],
        'postsession' => ['description' => 'Post-session description'],
    ];
    
    $sessionsectionmap = \aiplacement_modgen\local\session_creator::create_session_subsections(
        $courseformat,
        $weeksectionnum,
        $course->id,
        $sessiondata
    );
    
    echo "✓ Session subsections created: " . json_encode($sessionsectionmap) . "\n";
    
    // Verify parent relationships
    $sessiontypes = ['presession', 'session', 'postsession'];
    $allcorrect = true;
    
    foreach ($sessiontypes as $sessiontype) {
        $sectionnumber = $sessionsectionmap[$sessiontype];
        $sessionsection = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnumber]);
        
        // Get parent from format_options table
        $sessionparent = $DB->get_field('course_format_options', 'value', 
            ['courseid' => $course->id, 'sectionid' => $sessionsection->id, 'name' => 'parent']);
        
        echo "\n  {$sessiontype}:\n";
        echo "    Section number: {$sessionsection->section}\n";
        echo "    Section ID: {$sessionsection->id}\n";
        echo "    Parent (from format_options): {$sessionparent}\n";
        echo "    Name: {$sessionsection->name}\n";
        
        // CRITICAL: Parent should be section NUMBER, not ID
        if ($sessionparent == $weeksectionnum) {
            echo "    ✓ CORRECT: Parent ({$sessionparent}) matches week SECTION NUMBER ({$weeksectionnum})\n";
        } else {
            echo "    ✗ FAILED: Parent ({$sessionparent}) does NOT match week SECTION NUMBER ({$weeksectionnum})\n";
            $allcorrect = false;
        }
    }
    
    echo "\n";
    if ($allcorrect) {
        echo "✓✓✓ ALL TESTS PASSED ✓✓✓\n";
        echo "Parent-child relationships are correctly established!\n";
    } else {
        echo "✗✗✗ SOME TESTS FAILED ✗✗✗\n";
        echo "Parent-child relationships are NOT correctly established!\n";
    }
    
} catch (Exception $e) {
    echo "✗ Failed to create session subsections: " . $e->getMessage() . "\n";
}

// Cleanup: Delete test course
echo "\n\nCleaning up test course...\n";
delete_course($course->id, false);
echo "✓ Test course deleted\n";

echo "\n=== Test Complete ===\n";
