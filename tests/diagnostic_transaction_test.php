<?php
// Simple diagnostic script to test transaction behavior
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/phpunit/bootstrap.php');

global $DB;

// Create a test course
$generator = phpunit_util::get_data_generator();
$course = $generator->create_course(['format' => 'flexsections']);

echo "Course created: {$course->id}\n";

// Count initial sections
$sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);
$optionsbefore = $DB->count_records('course_format_options', ['courseid' => $course->id]);

echo "Initial sections: $sectionsbefore\n";
echo "Initial format options: $optionsbefore\n";

// Show all sections
$sections = $DB->get_records('course_sections', ['course' => $course->id], 'section ASC');
foreach ($sections as $section) {
    echo "Section {$section->section} (id={$section->id}): {$section->name}\n";
}

// Show all format options
$options = $DB->get_records('course_format_options', ['courseid' => $course->id]);
echo "\nFormat options: " . count($options) . "\n";
foreach ($options as $option) {
    echo "  Option: {$option->name} = {$option->value} (sectionid={$option->sectionid})\n";
}

// Try to create a section with invalid parent
$courseformat = course_get_format($course);
try {
    \aiplacement_modgen\local\theme_builder::create_section_with_parent(
        $course->id,
        $courseformat,
        999, // Invalid parent
        'Test Section',
        'Test summary',
        FORMAT_PLAIN,
        []
    );
    echo "\nERROR: Should have thrown exception\n";
} catch (\moodle_exception $e) {
    echo "\nCaught expected exception: {$e->getMessage()}\n";
}

// Count after failed attempt
$sectionsafter = $DB->count_records('course_sections', ['course' => $course->id]);
$optionsafter = $DB->count_records('course_format_options', ['courseid' => $course->id]);

echo "\nAfter failed attempt:\n";
echo "Sections: $sectionsafter (was $sectionsbefore, diff: " . ($sectionsafter - $sectionsbefore) . ")\n";
echo "Format options: $optionsafter (was $optionsbefore, diff: " . ($optionsafter - $optionsbefore) . ")\n";

// Check for orphaned options
$orphaned = $DB->count_records_sql(
    "SELECT COUNT(*)
       FROM {course_format_options}
      WHERE courseid = :courseid
        AND sectionid IS NOT NULL
        AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
    ['courseid' => $course->id, 'courseid2' => $course->id]
);
echo "Orphaned format options: $orphaned\n";

echo "\n=== TEST COMPLETED ===\n";
