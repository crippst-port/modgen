<?php
/**
 * Demonstration script showing all three layout types.
 * 
 * This creates test courses to demonstrate:
 * 1. Theme-based layout (themes → weeks → sessions)
 * 2. Week-based layout (themes with sessions directly)
 * 3. Flat layout (standalone weeks)
 * 
 * Run from command line:
 * php demo_layout_types.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');
require_once(__DIR__ . '/../../classes/local/theme_builder.php');

echo "=== Layout Type Demonstration ===\n\n";

// Helper function to create a test course.
function create_test_course($shortname, $fullname) {
    global $DB;
    
    // Check if course already exists.
    $existing = $DB->get_record('course', ['shortname' => $shortname]);
    if ($existing) {
        echo "Using existing course: {$fullname} (ID: {$existing->id})\n";
        return $existing;
    }
    
    $coursedata = new stdClass();
    $coursedata->fullname = $fullname;
    $coursedata->shortname = $shortname;
    $coursedata->format = 'flexsections';
    $coursedata->startdate = time();
    $coursedata->category = 1;
    
    $course = create_course($coursedata);
    echo "Created course: {$fullname} (ID: {$course->id})\n";
    return $course;
}

// Test 1: Theme-based layout (existing course 2).
echo "1. THEME-BASED LAYOUT\n";
echo "   Course ID 2 already demonstrates this structure.\n";
$layout = \aiplacement_modgen\local\date_calculator::detect_course_layout(2);
echo "   Type: {$layout['type']}\n";
echo "   Description: {$layout['description']}\n";
echo "   Hierarchy levels: {$layout['details']['hierarchy_levels']}\n\n";

// Test 2: Create week-based layout (themes with direct sessions).
echo "2. WEEK-BASED LAYOUT\n";
$course2 = create_test_course('test-week-layout', 'Test Week-Based Layout');
$courseid2 = $course2->id;

// Initialize core sections.
\aiplacement_modgen\local\theme_builder::initialize_core_sections($courseid2);

// Create a "theme" but add sessions directly (no intermediate week).
$courseformat2 = course_get_format($course2);
$themesectionnum = $courseformat2->create_new_section(0, null);

global $DB;
$themesectionid = $DB->get_field('course_sections', 'id', 
    ['course' => $courseid2, 'section' => $themesectionnum]);

$DB->update_record('course_sections', [
    'id' => $themesectionid,
    'name' => 'Week 1 Theme',
]);

// Create sessions directly under the theme (skip week level).
\aiplacement_modgen\local\session_creator::create_session_subsections(
    $courseformat2,
    $themesectionnum,
    $courseid2,
    null
);

rebuild_course_cache($courseid2, true);

$layout2 = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid2);
echo "   Type: {$layout2['type']}\n";
echo "   Description: {$layout2['description']}\n";
echo "   Hierarchy levels: {$layout2['details']['hierarchy_levels']}\n\n";

// Test 3: Create flat layout (standalone weeks).
echo "3. FLAT LAYOUT\n";
$course3 = create_test_course('test-flat-layout', 'Test Flat Layout');
$courseid3 = $course3->id;

// Initialize core sections.
\aiplacement_modgen\local\theme_builder::initialize_core_sections($courseid3);

// Create standalone weeks (no themes, no hierarchy).
$courseformat3 = course_get_format($course3);
for ($i = 1; $i <= 3; $i++) {
    $weeksectionnum = $courseformat3->create_new_section(0, null); // Parent = 0 (top level)
    
    $weeksectionid = $DB->get_field('course_sections', 'id', 
        ['course' => $courseid3, 'section' => $weeksectionnum]);
    
    $DB->update_record('course_sections', [
        'id' => $weeksectionid,
        'name' => "Week {$i}",
    ]);
}

rebuild_course_cache($courseid3, true);

$layout3 = \aiplacement_modgen\local\date_calculator::detect_course_layout($courseid3);
echo "   Type: {$layout3['type']}\n";
echo "   Description: {$layout3['description']}\n";
echo "   Hierarchy levels: {$layout3['details']['hierarchy_levels']}\n\n";

echo "=== Summary ===\n";
echo "Course {$courseid2}: {$layout2['type']} - {$layout2['description']}\n";
echo "Course {$courseid3}: {$layout3['type']} - {$layout3['description']}\n";
echo "Course 2: {$layout['type']} - {$layout['description']}\n\n";

echo "You can test these with:\n";
echo "php test_layout_detection.php 2\n";
echo "php test_layout_detection.php {$courseid2}\n";
echo "php test_layout_detection.php {$courseid3}\n";
