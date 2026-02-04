<?php
// Quick debug script to test move_section
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;

// Create a test course
// Always create a fresh test course
$coursedata = new stdClass();
$coursedata->fullname = 'Test Move Course ' . time();
$coursedata->shortname = 'TESTMOVE' . time();
$coursedata->category = 1;
$coursedata->format = 'topics';
$course = create_course($coursedata);

// Convert to flexsections
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/theme_builder.php');
\aiplacement_modgen\local\theme_builder::ensure_flexsections_format($course->id);
\aiplacement_modgen\local\theme_builder::initialize_core_sections($course->id);

rebuild_course_cache($course->id, true, true);
$course = get_course($course->id, true);

// Get courseformat AFTER conversion
$courseformat = course_get_format($course);
for ($i = 1; $i <= 3; $i++) {
    \aiplacement_modgen\local\theme_builder::create_section_with_parent(
        $course->id,
        $courseformat,
        0,
        "Test Section $i",
        "Summary $i",
        FORMAT_HTML
    );
}

rebuild_course_cache($course->id, true, true);
$course = get_course($course->id, true);

$courseformat = course_get_format($course);
echo "Course format: " . get_class($courseformat) . "\n";
echo "Has move_section: " . (method_exists($courseformat, 'move_section') ? 'YES' : 'NO') . "\n";

// Get sections
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

echo "\nSections BEFORE move:\n";
foreach ($sections as $s) {
    echo "  Position {$s->section}: {$s->name} (id={$s->id}, parent={$s->parent})\n";
}

// Try to move last section to before Assessments (position 1)
$lastsection = end($sections);
$assessments = null;
foreach ($sections as $s) {
    if ($s->name === 'Assessments') {
        $assessments = $s;
        break;
    }
}

echo "\nTrying to move section {$lastsection->section} ('{$lastsection->name}') before Assessments (section {$assessments->section})...\n";

try {
    $result = $courseformat->move_section($lastsection->section, $assessments->section, true);
    echo "move_section returned: " . var_export($result, true) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Refresh and check
rebuild_course_cache($course->id, true, true);
$modinfo = get_fast_modinfo(get_course($course->id, true));
$sections = $modinfo->get_section_info_all();

echo "\nSections AFTER move:\n";
foreach ($sections as $s) {
    echo "  Position {$s->section}: {$s->name} (id={$s->id}, parent={$s->parent})\n";
}
