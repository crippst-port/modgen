<?php
/**
 * Clear all sections from course 2 (except section 0).
 * 
 * This removes all course modules and sections, useful for testing.
 * 
 * Usage: docker-compose exec moodle php /var/www/html/ai/placement/modgen/docs/clear_course_sections.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$courseid = 2;

echo "Clearing sections from course {$courseid}...\n";

// Get course
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Get all sections except section 0
$sections = $DB->get_records_select('course_sections', 'course = ? AND section > 0', [$courseid]);

echo "Found " . count($sections) . " sections to remove\n";

// Delete course modules first
$modulecount = 0;
foreach ($sections as $section) {
    // Get all course modules in this section
    if (!empty($section->sequence)) {
        $cmids = explode(',', $section->sequence);
        foreach ($cmids as $cmid) {
            if (empty($cmid)) {
                continue;
            }
            
            try {
                // Use Moodle's function to properly delete the module
                course_delete_module($cmid);
                $modulecount++;
                echo ".";
            } catch (Exception $e) {
                echo "\nWarning: Could not delete module {$cmid}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Delete the section
    $DB->delete_records('course_sections', ['id' => $section->id]);
}

echo "\n";
echo "Deleted {$modulecount} course modules\n";
echo "Deleted " . count($sections) . " sections\n";

// Clean up any format options for deleted sections
$DB->delete_records_select('course_format_options', 
    "courseid = ? AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = ?)",
    [$courseid, $courseid]
);

// Clean up any orphaned aiplacement_modgen_aigen records
$orphanedcount = $DB->count_records_select('aiplacement_modgen_aigen',
    "courseid = ? AND cmid NOT IN (SELECT id FROM {course_modules} WHERE course = ?)",
    [$courseid, $courseid]
);

if ($orphanedcount > 0) {
    $DB->delete_records_select('aiplacement_modgen_aigen',
        "courseid = ? AND cmid NOT IN (SELECT id FROM {course_modules} WHERE course = ?)",
        [$courseid, $courseid]
    );
    echo "Cleaned up {$orphanedcount} orphaned tracking records\n";
}

// Rebuild course cache
rebuild_course_cache($courseid, true);
echo "Rebuilt course cache\n";

// Reset section 0 to default state
$section0 = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0], '*', MUST_EXIST);
$DB->update_record('course_sections', [
    'id' => $section0->id,
    'name' => null,
    'summary' => '',
    'summaryformat' => FORMAT_HTML,
    'sequence' => '',
]);
echo "Reset section 0 to default state\n";

echo "\n✅ Course {$courseid} cleared successfully!\n";
echo "Only section 0 remains (empty and reset to default)\n";
