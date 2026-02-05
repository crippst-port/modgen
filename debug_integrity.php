<?php
/**
 * Course database integrity diagnostic script.
 *
 * This script checks for database corruption issues in course sections that could cause
 * core_courseformat_get_state to fail with a 500 error.
 *
 * Access via:
 * https://your-moodle-site.com/ai/placement/modgen/debug_integrity.php?courseid=214
 *
 * Checks for:
 * 1. Invalid parent section relationships
 * 2. Orphaned sections (parent points to non-existent section)
 * 3. Missing course_format_options entries
 * 4. Duplicate section numbers
 * 5. Gaps in section numbering
 * 6. Invalid section data
 *
 * @package     aiplacement_modgen
 * @subpackage  diagnostics
 */

define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Get course ID
$courseid = required_param('courseid', PARAM_INT);
$fix = optional_param('fix', 0, PARAM_INT); // Set to 1 to attempt automatic fixes

// Security: Must be logged in to the course
require_login($courseid, false);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

// Security: Must have course update capability (teachers/managers) OR be site admin
if (!has_capability('moodle/course:update', $context) && !is_siteadmin()) {
    print_error('nopermissions', 'error', '', 'access diagnostics');
}

// Set page context to avoid debugging warnings
global $PAGE;
$PAGE->set_context($context);
$PAGE->set_url('/ai/placement/modgen/debug_integrity.php', ['courseid' => $courseid]);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Course Integrity Check</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }
h1 { color: #333; border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
h2 { color: #0066cc; margin-top: 30px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background: #0066cc; color: white; }
tr:nth-child(even) { background: #f9f9f9; }
.error { color: #d00; font-weight: bold; }
.warning { color: #f60; font-weight: bold; }
.success { color: #090; font-weight: bold; }
.info { color: #06c; }
.section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0066cc; }
.fix-button { background: #0066cc; color: white; padding: 10px 20px; text-decoration: none;
              border-radius: 4px; display: inline-block; margin: 10px 0; }
.fix-button:hover { background: #0052a3; }
pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
</style></head><body><div class='container'>";

echo "<h1>Course Database Integrity Check</h1>";
echo "<p><strong>Course:</strong> " . s($course->fullname) . " (ID: $courseid)</p>";
echo "<p><strong>Format:</strong> " . s($course->format) . "</p>";

$issues = [];
$warnings = [];

// Check 1: Verify course format is flexsections
echo "<div class='section'>";
echo "<h2>1. Course Format Check</h2>";
if ($course->format !== 'flexsections') {
    $issues[] = "Course format is '{$course->format}', expected 'flexsections'";
    echo "<p class='error'>✗ Course is not using flexsections format!</p>";
    echo "<p>The modgen plugin requires flexsections format. Current format: <code>" . s($course->format) . "</code></p>";
} else {
    echo "<p class='success'>✓ Course is using flexsections format</p>";
}
echo "</div>";

// Check 2: Get all sections
echo "<div class='section'>";
echo "<h2>2. Section Structure Analysis</h2>";
$sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
echo "<p>Found " . count($sections) . " sections</p>";

// Check for gaps or duplicates
$sectionnums = array_column($sections, 'section');
$expected = range(0, max($sectionnums));
$missing = array_diff($expected, $sectionnums);
$duplicates = array_diff_key($sectionnums, array_unique($sectionnums));

if (!empty($missing)) {
    $warnings[] = "Missing section numbers: " . implode(', ', $missing);
    echo "<p class='warning'>⚠ Gaps in section numbering: " . implode(', ', $missing) . "</p>";
}

if (!empty($duplicates)) {
    $issues[] = "Duplicate section numbers found";
    echo "<p class='error'>✗ Duplicate section numbers detected!</p>";
}

if (empty($missing) && empty($duplicates)) {
    echo "<p class='success'>✓ Section numbering is sequential with no gaps</p>";
}
echo "</div>";

// Check 3: Validate parent relationships
echo "<div class='section'>";
echo "<h2>3. Parent Relationship Validation</h2>";

$orphaned = [];
$invalid_parents = [];
$missing_parent_option = [];

echo "<table>";
echo "<tr><th>Section #</th><th>Name</th><th>Parent in course_format_options</th><th>Status</th></tr>";

foreach ($sections as $section) {
    // Skip section 0 (doesn't have parent)
    if ($section->section == 0) {
        continue;
    }

    // Get parent from course_format_options
    $parentoption = $DB->get_record('course_format_options', [
        'courseid' => $courseid,
        'sectionid' => $section->id,
        'format' => 'flexsections',
        'name' => 'parent'
    ]);

    $status = '';
    $statusclass = 'success';

    if (!$parentoption) {
        // No parent option - defaults to 0 (top level)
        $missing_parent_option[] = $section->section;
        $status = "No parent option (defaults to 0)";
        $statusclass = 'warning';
        echo "<tr><td>{$section->section}</td><td>" . s($section->name) . "</td>";
        echo "<td class='warning'>Missing</td><td class='$statusclass'>$status</td></tr>";
    } else {
        $parentnum = (int)$parentoption->value;

        // Check if parent section exists
        if ($parentnum > 0) {
            $parentsection = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $parentnum
            ]);

            if (!$parentsection) {
                $orphaned[] = ['section' => $section->section, 'parent' => $parentnum];
                $status = "Parent section $parentnum does not exist!";
                $statusclass = 'error';
                $issues[] = "Section {$section->section} has invalid parent $parentnum";
            } else {
                $status = "Valid (parent: {$parentsection->name})";
            }
        } else {
            $status = "Top-level section (parent = 0)";
        }

        echo "<tr><td>{$section->section}</td><td>" . s($section->name) . "</td>";
        echo "<td>{$parentnum}</td><td class='$statusclass'>$status</td></tr>";
    }
}

echo "</table>";

if (!empty($orphaned)) {
    echo "<p class='error'>✗ Found " . count($orphaned) . " orphaned sections with invalid parents!</p>";
} else {
    echo "<p class='success'>✓ All parent relationships are valid</p>";
}

echo "</div>";

// Check 4: Test core_courseformat_get_state
echo "<div class='section'>";
echo "<h2>4. Test core_courseformat_get_state Web Service</h2>";

try {
    $courseformat = course_get_format($courseid);
    echo "<p class='success'>✓ Course format object created successfully</p>";

    // Try to get course state (this is what the AJAX call does)
    $modinfo = get_fast_modinfo($courseid);
    echo "<p class='success'>✓ get_fast_modinfo() works</p>";

    // Check if stateupdates class exists
    if (class_exists('core_courseformat\\stateupdates')) {
        echo "<p class='success'>✓ stateupdates class exists</p>";

        try {
            $stateupdates = new \core_courseformat\stateupdates($courseformat);
            echo "<p class='success'>✓ stateupdates object created</p>";

            // List available methods (for debugging version differences)
            $methods = get_class_methods($stateupdates);
            echo "<p class='info'>Available methods: " . implode(', ', $methods) . "</p>";

            // Try different method names based on Moodle version
            $statesuccess = false;
            $statedata = null;

            if (method_exists($stateupdates, 'get_state')) {
                $statedata = $stateupdates->get_state();
                $statesuccess = true;
            } else if (method_exists($stateupdates, 'get_export_data')) {
                // Alternative method in some Moodle versions
                $statedata = $stateupdates->get_export_data();
                $statesuccess = true;
            } else if (method_exists($stateupdates, 'export_for_template')) {
                // Another alternative
                global $OUTPUT;
                $statedata = $stateupdates->export_for_template($OUTPUT);
                $statesuccess = true;
            }

            if ($statesuccess) {
                echo "<p class='success'><strong>✓ SUCCESS!</strong> Course state retrieved without errors</p>";
                echo "<p class='info'>The stateupdates class is working. The 500 error is likely caused by something else.</p>";
            } else {
                echo "<p class='warning'>⚠ stateupdates object created but no compatible state method found</p>";
                echo "<p>This Moodle version may use a different API. Check available methods above.</p>";
            }

        } catch (Exception $e) {
            $issues[] = "Failed to get course state: " . $e->getMessage();
            echo "<p class='error'>✗ Error getting course state: " . s($e->getMessage()) . "</p>";
            echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
        } catch (Error $e) {
            $issues[] = "Failed to get course state: " . $e->getMessage();
            echo "<p class='error'>✗ Error getting course state: " . s($e->getMessage()) . "</p>";
            echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
        }

    } else {
        $issues[] = "stateupdates class does not exist - Moodle version issue";
        echo "<p class='error'>✗ stateupdates class does not exist</p>";
        echo "<p>This indicates a Moodle version issue. The course editor requires Moodle 4.1+</p>";
    }

} catch (Exception $e) {
    $issues[] = "Failed to create course format: " . $e->getMessage();
    echo "<p class='error'>✗ Error creating course format: " . s($e->getMessage()) . "</p>";
    echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
}

echo "</div>";

// Check 5: Format options integrity
echo "<div class='section'>";
echo "<h2>5. Format Options Integrity</h2>";

$formatoptions = $DB->get_records('course_format_options', [
    'courseid' => $courseid,
    'format' => 'flexsections'
]);

echo "<p>Found " . count($formatoptions) . " format option records</p>";

// Group by option name
$optionsbysection = [];
foreach ($formatoptions as $opt) {
    if (!isset($optionsbysection[$opt->sectionid])) {
        $optionsbysection[$opt->sectionid] = [];
    }
    $optionsbysection[$opt->sectionid][$opt->name] = $opt->value;
}

$sectionswithoutparent = 0;
foreach ($sections as $section) {
    if ($section->section == 0) continue; // Section 0 doesn't need parent

    if (!isset($optionsbysection[$section->id]) || !isset($optionsbysection[$section->id]['parent'])) {
        $sectionswithoutparent++;
    }
}

if ($sectionswithoutparent > 0) {
    echo "<p class='warning'>⚠ $sectionswithoutparent sections missing parent option in course_format_options</p>";
    $warnings[] = "$sectionswithoutparent sections missing parent option";
} else {
    echo "<p class='success'>✓ All sections have parent option defined</p>";
}

echo "</div>";

// Summary
echo "<div class='section'>";
echo "<h2>Summary</h2>";

if (empty($issues)) {
    echo "<p class='success'><strong>✓ No critical issues found!</strong></p>";
    if (!empty($warnings)) {
        echo "<p class='warning'>Found " . count($warnings) . " warnings (non-critical):</p><ul>";
        foreach ($warnings as $warning) {
            echo "<li class='warning'>$warning</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>Course database structure appears healthy.</p>";
    }
} else {
    echo "<p class='error'><strong>✗ Found " . count($issues) . " critical issues:</strong></p>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li class='error'>$issue</li>";
    }
    echo "</ul>";

    // Offer fix option for orphaned sections
    if (!empty($orphaned) && !$fix) {
        echo "<p><a href='?courseid=$courseid&fix=1' class='fix-button'>Attempt Automatic Fix</a></p>";
        echo "<p class='warning'><strong>Warning:</strong> This will set all orphaned sections to top-level (parent = 0). ";
        echo "Make a database backup before proceeding!</p>";
    }
}

// If fix mode is enabled
if ($fix && !empty($orphaned)) {
    echo "<h2>Automatic Fix Results</h2>";
    $fixed = 0;

    foreach ($orphaned as $orphan) {
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $orphan['section']
        ]);

        if ($section) {
            // Update parent to 0 (top level)
            $parentrecord = $DB->get_record('course_format_options', [
                'courseid' => $courseid,
                'sectionid' => $section->id,
                'format' => 'flexsections',
                'name' => 'parent'
            ]);

            if ($parentrecord) {
                $parentrecord->value = 0;
                $DB->update_record('course_format_options', $parentrecord);
                $fixed++;
                echo "<p class='success'>✓ Fixed section {$orphan['section']}: set parent to 0</p>";
            }
        }
    }

    if ($fixed > 0) {
        rebuild_course_cache($courseid, true, true);
        echo "<p class='success'><strong>Fixed $fixed orphaned sections and rebuilt course cache.</strong></p>";
        echo "<p><a href='?courseid=$courseid'>Re-run diagnostic</a></p>";
    }
}

echo "</div>";

// Additional system info
echo "<div class='section'>";
echo "<h2>System Information</h2>";
echo "<table>";
echo "<tr><th>Item</th><th>Value</th></tr>";
echo "<tr><td>Moodle Version</td><td>" . s($CFG->version) . " (" . s($CFG->release) . ")</td></tr>";
echo "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
echo "<tr><td>Database</td><td>" . s($CFG->dbtype) . "</td></tr>";

// Check flexsections plugin
$pluginman = core_plugin_manager::instance();
$flexplugin = $pluginman->get_plugin_info('format_flexsections');
if ($flexplugin) {
    echo "<tr><td>Flexsections Plugin</td><td>Installed (version: " . s($flexplugin->versiondb) . ")</td></tr>";
} else {
    echo "<tr><td>Flexsections Plugin</td><td class='error'>NOT INSTALLED</td></tr>";
    $issues[] = "Flexsections plugin is not installed";
}

echo "</table>";
echo "</div>";

echo "</div></body></html>";
