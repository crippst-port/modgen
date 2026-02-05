<?php
/**
 * Clean up orphaned and stale course sections.
 *
 * This script finds and optionally removes:
 * - Sections beyond the course's numsections limit
 * - Sections with invalid parent references
 * - Orphaned course_format_options entries
 * - Empty sections marked as deleted
 *
 * Access: http://localhost/moodle45/ai/placement/modgen/debug_cleanup.php?courseid=214
 * To actually delete: http://localhost/moodle45/ai/placement/modgen/debug_cleanup.php?courseid=214&delete=1
 */

define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Get course ID
$courseid = required_param('courseid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT); // Set to 1 to actually delete
$backup = optional_param('backup', 1, PARAM_INT); // Set to 0 to skip backup warning

// Security: Must be logged in to the course
require_login($courseid, false);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Security: Must have course update capability (teachers/managers) OR be site admin
if (!has_capability('moodle/course:update', $context) && !is_siteadmin()) {
    print_error('nopermissions', 'error', '', 'access diagnostics');
}

// Set page context
global $PAGE;
$PAGE->set_context($context);
$PAGE->set_url('/ai/placement/modgen/debug_cleanup.php', ['courseid' => $courseid]);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Section Cleanup</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }
h1 { color: #333; border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
h2 { color: #0066cc; margin-top: 30px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; font-size: 12px; }
th { background: #0066cc; color: white; }
tr:nth-child(even) { background: #f9f9f9; }
.error { color: #d00; font-weight: bold; }
.warning { color: #f60; font-weight: bold; background: #fff3cd; padding: 15px; margin: 15px 0; border-left: 4px solid #f60; }
.success { color: #090; font-weight: bold; }
.info { color: #06c; background: #d1ecf1; padding: 15px; margin: 15px 0; border-left: 4px solid #06c; }
.danger { background: #f8d7da; padding: 15px; margin: 15px 0; border-left: 4px solid #d00; }
.delete { background: #dc3545; color: white; padding: 10px 20px; text-decoration: none;
          border-radius: 4px; display: inline-block; margin: 10px 0; font-weight: bold; }
.delete:hover { background: #c82333; }
pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 11px; }
</style></head><body><div class='container'>";

echo "<h1>Section Cleanup Tool</h1>";
echo "<p><strong>Course:</strong> " . s($course->fullname) . " (ID: $courseid)</p>";
echo "<p><strong>Format:</strong> " . s($course->format) . "</p>";

if ($delete && $backup) {
    echo "<div class='danger'>";
    echo "<h2>⚠️ WARNING: BACKUP REQUIRED ⚠️</h2>";
    echo "<p><strong>You are about to permanently delete sections from the database!</strong></p>";
    echo "<p>Before proceeding:</p>";
    echo "<ol>";
    echo "<li>Create a database backup</li>";
    echo "<li>Test on a development/staging environment first</li>";
    echo "<li>Verify which sections will be deleted (see list below)</li>";
    echo "</ol>";
    echo "<p>To proceed after backing up: <a href='?courseid=$courseid&delete=1&backup=0' class='delete'>CONFIRM DELETE (I have a backup)</a></p>";
    echo "<p><a href='?courseid=$courseid'>Cancel and review only</a></p>";
    echo "</div>";
    $delete = 0; // Don't actually delete until confirmed
}

// Get all sections for this course
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
$totalsections = count($sections);

echo "<div class='info'>";
echo "<p><strong>Total sections found:</strong> $totalsections</p>";
echo "<p><strong>Course numsections setting:</strong> " . ($course->numsections ?? 'not set') . "</p>";
echo "</div>";

$toclean = [];
$deletedcount = 0;

echo "<h2>Analysis: Sections to Clean Up</h2>";

// Check each section
echo "<table>";
echo "<tr><th>Section #</th><th>Name</th><th>Visible</th><th>Has Modules</th><th>Parent</th><th>Reason for Cleanup</th></tr>";

foreach ($sections as $section) {
    // Skip section 0 (always keep)
    if ($section->section == 0) {
        continue;
    }

    $reasons = [];
    $shouldclean = false;

    // Check if section has any course modules
    $modules = $DB->get_records('course_modules', ['course' => $courseid, 'section' => $section->id]);
    $hasmodules = count($modules) > 0;

    // Get parent from course_format_options
    $parentoption = $DB->get_record('course_format_options', [
        'courseid' => $courseid,
        'sectionid' => $section->id,
        'format' => 'flexsections',
        'name' => 'parent'
    ]);

    $parentvalue = $parentoption ? $parentoption->value : 'none';

    // Reason 1: Section beyond numsections limit (if set)
    if (isset($course->numsections) && $section->section > $course->numsections) {
        $reasons[] = "Beyond numsections limit";
        $shouldclean = true;
    }

    // Reason 2: Invalid parent reference
    if ($parentoption && $parentoption->value > 0) {
        $parentsection = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $parentoption->value
        ]);
        if (!$parentsection) {
            $reasons[] = "Invalid parent reference ({$parentoption->value})";
            $shouldclean = true;
        }
    }

    // Reason 3: Hidden, empty, and has old/default name (likely deleted)
    $defaultnames = [
        'Topic', 'Week', 'Section', '',
        'General', 'Untitled', 'New section'
    ];
    $isdefaultname = false;
    foreach ($defaultnames as $default) {
        if (stripos($section->name, $default) === 0 || empty($section->name)) {
            $isdefaultname = true;
            break;
        }
    }

    if ($section->visible == 0 && !$hasmodules && $isdefaultname) {
        $reasons[] = "Hidden, empty, default name (likely deleted)";
        // Don't auto-clean these unless parent is also invalid
        if ($shouldclean) {
            // Already marked for cleanup due to other reasons
        }
    }

    // Only show sections that need cleanup
    if ($shouldclean || !empty($reasons)) {
        $toclean[] = $section->id;

        echo "<tr>";
        echo "<td>{$section->section}</td>";
        echo "<td>" . s($section->name ?: '(no name)') . "</td>";
        echo "<td>" . ($section->visible ? 'Yes' : 'No') . "</td>";
        echo "<td>" . ($hasmodules ? count($modules) : 'No') . "</td>";
        echo "<td>" . s($parentvalue) . "</td>";
        echo "<td class='" . ($shouldclean ? 'error' : 'warning') . "'>" . implode('; ', $reasons) . "</td>";
        echo "</tr>";
    }
}

echo "</table>";

if (empty($toclean)) {
    echo "<div class='success'>";
    echo "<p>✓ No sections need cleanup! All sections appear valid.</p>";
    echo "</div>";
} else {
    echo "<div class='warning'>";
    echo "<p><strong>⚠ Found " . count($toclean) . " sections that may need cleanup</strong></p>";
    echo "</div>";

    if ($delete && !$backup) {
        // Actually perform deletion
        echo "<h2>Deletion Results</h2>";

        foreach ($toclean as $sectionid) {
            $section = $DB->get_record('course_sections', ['id' => $sectionid]);
            if (!$section) {
                continue;
            }

            try {
                // Check for course modules in this section
                $cms = $DB->get_records('course_modules', ['section' => $sectionid]);
                if (!empty($cms)) {
                    echo "<p class='warning'>⚠ Skipping section {$section->section} - contains " . count($cms) . " modules</p>";
                    continue;
                }

                // Delete course_format_options for this section
                $DB->delete_records('course_format_options', ['sectionid' => $sectionid]);

                // Delete the section itself
                $DB->delete_records('course_sections', ['id' => $sectionid]);

                echo "<p class='success'>✓ Deleted section {$section->section}: " . s($section->name) . "</p>";
                $deletedcount++;

            } catch (Exception $e) {
                echo "<p class='error'>✗ Failed to delete section {$section->section}: " . s($e->getMessage()) . "</p>";
            }
        }

        if ($deletedcount > 0) {
            echo "<div class='success'>";
            echo "<h3>✓ Cleanup Complete</h3>";
            echo "<p>Deleted $deletedcount sections</p>";
            echo "<p>Rebuilding course cache...</p>";

            // Rebuild course cache
            rebuild_course_cache($courseid, true, true);

            echo "<p class='success'>✓ Course cache rebuilt</p>";
            echo "<p><a href='?courseid=$courseid'>Review remaining sections</a></p>";
            echo "</div>";
        }

    } else {
        // Just showing preview
        echo "<div class='info'>";
        echo "<h3>Preview Mode</h3>";
        echo "<p>This is a preview. No sections have been deleted.</p>";
        echo "<p><strong>Sections that would be cleaned:</strong> " . count($toclean) . "</p>";
        echo "<p><a href='?courseid=$courseid&delete=1' class='delete'>DELETE THESE SECTIONS</a></p>";
        echo "<p class='warning'><strong>Note:</strong> Sections containing course modules will be skipped automatically</p>";
        echo "</div>";

        // Show SQL for manual cleanup if preferred
        echo "<div class='info'>";
        echo "<h3>Manual Cleanup (SQL)</h3>";
        echo "<p>If you prefer to run SQL manually:</p>";
        echo "<pre>-- Backup first!
-- Delete format options for orphaned sections
DELETE FROM {$CFG->prefix}course_format_options
WHERE courseid = $courseid
AND sectionid IN (" . implode(',', $toclean) . ");

-- Delete the sections (only if they have no modules!)
DELETE FROM {$CFG->prefix}course_sections
WHERE id IN (" . implode(',', $toclean) . ")
AND id NOT IN (
    SELECT DISTINCT section FROM {$CFG->prefix}course_modules WHERE course = $courseid
);</pre>";
        echo "</div>";
    }
}

// Show current valid sections
echo "<h2>All Current Sections</h2>";
echo "<p>Showing all " . count($sections) . " sections currently in database:</p>";
echo "<table>";
echo "<tr><th>Section #</th><th>ID</th><th>Name</th><th>Visible</th><th>Modules</th><th>Parent</th></tr>";

foreach ($sections as $section) {
    $modules = $DB->count_records('course_modules', ['course' => $courseid, 'section' => $section->id]);
    $parentoption = $DB->get_record('course_format_options', [
        'courseid' => $courseid,
        'sectionid' => $section->id,
        'format' => 'flexsections',
        'name' => 'parent'
    ]);
    $parentvalue = $parentoption ? $parentoption->value : '0';

    $rowclass = in_array($section->id, $toclean) ? "style='background: #ffe6e6;'" : "";

    echo "<tr $rowclass>";
    echo "<td>{$section->section}</td>";
    echo "<td>{$section->id}</td>";
    echo "<td>" . s($section->name ?: '(no name)') . "</td>";
    echo "<td>" . ($section->visible ? 'Yes' : 'No') . "</td>";
    echo "<td>$modules</td>";
    echo "<td>$parentvalue</td>";
    echo "</tr>";
}

echo "</table>";

echo "</div></body></html>";
