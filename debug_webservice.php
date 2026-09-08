<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Test the exact flow of core_courseformat_get_state web service.
 *
 * This mimics the actual web service to identify which section/module causes the 500 error.
 *
 * Admin-only direct diagnostic endpoint.
 *
 * Access: http://localhost/moodle45/ai/placement/modgen/debug_webservice.php?courseid=214
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Get course ID.
$courseid = required_param('courseid', PARAM_INT);

// Security: direct diagnostics are site-admin only because this page exposes
// low-level course format internals and stack traces.
require_login();
require_capability('moodle/site:config', context_system::instance());

$course = get_course($courseid);
$context = context_course::instance($courseid);

// Set page context to avoid debugging warnings.
global $PAGE;
$PAGE->set_context($context);
$PAGE->set_url('/ai/placement/modgen/debug_webservice.php', ['courseid' => $courseid]);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Web Service Detailed Test</title>";
echo "<style>
body { font-family: monospace; margin: 20px; background: #f5f5f5; }
.container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; }
h1 { color: #333; }
.error { color: #d00; font-weight: bold; background: #fee; padding: 5px; margin: 5px 0; }
.success { color: #090; }
.warning { color: #f60; }
.step { margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #0066cc; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12px; }
th, td { padding: 4px; text-align: left; border: 1px solid #ddd; }
th { background: #0066cc; color: white; }
pre { background: #fee; padding: 10px; overflow-x: auto; font-size: 11px; }
</style></head><body><div class='container'>";

echo "<h1>Detailed Web Service Test: core_courseformat_get_state</h1>";
echo "<p><strong>Course:</strong> " . s($course->fullname) . " (ID: $courseid)</p>";
echo "<hr>";

$errors = [];

try {
    echo "<div class='step'>";
    echo "<h2>Step 1: Get Course Format</h2>";
    $courseformat = course_get_format($courseid);
    echo "<p class='success'>✓ Course format: " . get_class($courseformat) . "</p>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 2: Get Module Info</h2>";
    $modinfo = $courseformat->get_modinfo();
    echo "<p class='success'>✓ Module info retrieved</p>";
    echo "<p>Total sections: " . count($modinfo->get_section_info_all()) . "</p>";
    echo "<p>Total course modules: " . count($modinfo->cms) . "</p>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 3: Get Completion Info</h2>";
    $completioninfo = new \completion_info($course);
    $istrackeduser = $completioninfo->is_tracked_user($USER->id);
    echo "<p class='success'>✓ Completion info created</p>";
    echo "<p>Is tracked user: " . ($istrackeduser ? 'Yes' : 'No') . "</p>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 4: Get Renderer</h2>";
    $renderer = $courseformat->get_renderer($PAGE);
    echo "<p class='success'>✓ Renderer: " . get_class($renderer) . "</p>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 5: Load Output Class Names</h2>";
    $courseclass = $courseformat->get_output_classname('state\\course');
    $sectionclass = $courseformat->get_output_classname('state\\section');
    $cmclass = $courseformat->get_output_classname('state\\cm');
    echo "<p class='success'>✓ Course state class: " . s($courseclass) . "</p>";
    echo "<p class='success'>✓ Section state class: " . s($sectionclass) . "</p>";
    echo "<p class='success'>✓ CM state class: " . s($cmclass) . "</p>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 6: Export Course State</h2>";
    try {
        $coursestate = new $courseclass($courseformat);
        $coursestatedata = $coursestate->export_for_template($renderer);
        echo "<p class='success'>✓ Course state exported successfully</p>";
    } catch (Exception $e) {
        $errors[] = "Course state export failed: " . $e->getMessage();
        echo "<p class='error'>✗ Failed to export course state</p>";
        echo "<pre>" . s($e->getMessage()) . "\n" . s($e->getTraceAsString()) . "</pre>";
    }
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 7: Export Section States</h2>";
    $sections = $modinfo->get_section_info_all();
    $sectioncount = 0;
    $sectionerrors = 0;

    echo "<table>";
    echo "<tr><th>#</th><th>Section Name</th><th>Visible</th><th>Status</th></tr>";

    foreach ($sections as $section) {
        echo "<tr>";
        echo "<td>{$section->section}</td>";
        echo "<td>" . s($section->name ?: "Section {$section->section}") . "</td>";

        $isvisible = $courseformat->is_section_visible($section);
        echo "<td>" . ($isvisible ? 'Yes' : 'No') . "</td>";

        if ($isvisible) {
            try {
                $sectionstate = new $sectionclass($courseformat, $section);
                $sectionstatedata = $sectionstate->export_for_template($renderer);
                echo "<td class='success'>✓ OK</td>";
                $sectioncount++;
            } catch (Exception $e) {
                $sectionerrors++;
                $errors[] = "Section {$section->section} failed: " . $e->getMessage();
                echo "<td class='error'>✗ ERROR: " . s($e->getMessage()) . "</td>";
            } catch (Error $e) {
                $sectionerrors++;
                $errors[] = "Section {$section->section} fatal error: " . $e->getMessage();
                echo "<td class='error'>✗ FATAL: " . s($e->getMessage()) . "</td>";
            }
        } else {
            echo "<td class='warning'>Skipped (not visible)</td>";
        }

        echo "</tr>";
    }

    echo "</table>";
    echo "<p class='success'>✓ Successfully exported $sectioncount sections</p>";
    if ($sectionerrors > 0) {
        echo "<p class='error'>✗ Failed to export $sectionerrors sections</p>";
    }
    echo "</div>";

    echo "<div class='step'>";
    echo "<h2>Step 8: Export Course Module States</h2>";
    $cmcount = 0;
    $cmerrors = 0;

    echo "<table>";
    echo "<tr><th>CM ID</th><th>Type</th><th>Name</th><th>Section</th><th>Status</th></tr>";

    foreach ($modinfo->cms as $cm) {
        if ($cm->is_visible_on_course_page()) {
            echo "<tr>";
            echo "<td>{$cm->id}</td>";
            echo "<td>" . s($cm->modname) . "</td>";
            echo "<td>" . s($cm->name) . "</td>";
            echo "<td>{$cm->sectionnum}</td>";

            try {
                $section = $sections[$cm->sectionnum];
                $cmstate = new $cmclass($courseformat, $section, $cm, $istrackeduser);
                $cmstatedata = $cmstate->export_for_template($renderer);
                echo "<td class='success'>✓ OK</td>";
                $cmcount++;
            } catch (Exception $e) {
                $cmerrors++;
                $errors[] = "CM {$cm->id} ({$cm->modname}) failed: " . $e->getMessage();
                echo "<td class='error'>✗ ERROR: " . s($e->getMessage()) . "</td>";
            } catch (Error $e) {
                $cmerrors++;
                $errors[] = "CM {$cm->id} ({$cm->modname}) fatal error: " . $e->getMessage();
                echo "<td class='error'>✗ FATAL: " . s($e->getMessage()) . "</td>";
            } catch (ArgumentCountError $e) {
                // Handle constructor argument mismatch for different Moodle versions.
                try {
                    // Try without istrackeduser parameter (older Moodle versions).
                    $cmstate = new $cmclass($courseformat, $section, $cm);
                    $cmstatedata = $cmstate->export_for_template($renderer);
                    echo "<td class='success'>✓ OK (compat mode)</td>";
                    $cmcount++;
                } catch (Exception $e2) {
                    $cmerrors++;
                    $errors[] = "CM {$cm->id} ({$cm->modname}) failed: " . $e2->getMessage();
                    echo "<td class='error'>✗ ERROR: " . s($e2->getMessage()) . "</td>";
                }
            }

            echo "</tr>";
        }
    }

    echo "</table>";
    echo "<p class='success'>✓ Successfully exported $cmcount course modules</p>";
    if ($cmerrors > 0) {
        echo "<p class='error'>✗ Failed to export $cmerrors course modules</p>";
    }
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='step'>";
    echo "<h2>FATAL ERROR</h2>";
    echo "<p class='error'>✗ " . s($e->getMessage()) . "</p>";
    echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    $errors[] = "Fatal: " . $e->getMessage();
} catch (Error $e) {
    echo "<div class='step'>";
    echo "<h2>FATAL PHP ERROR</h2>";
    echo "<p class='error'>✗ " . s($e->getMessage()) . "</p>";
    echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    $errors[] = "Fatal PHP Error: " . $e->getMessage();
}

// Summary.
echo "<hr>";
echo "<div class='step'>";
echo "<h2>Summary</h2>";

if (empty($errors)) {
    echo "<p class='success'><strong>✓ ALL TESTS PASSED!</strong></p>";
    echo "<p>The web service flow completed without errors. If you're still seeing 500 errors in the console, they may be:</p>";
    echo "<ul>";
    echo "<li>Intermittent issues (try clearing browser cache)</li>";
    echo "<li>JavaScript errors (not PHP errors)</li>";
    echo "<li>Different course or different user permissions</li>";
    echo "<li>Session or caching issues</li>";
    echo "</ul>";
} else {
    echo "<p class='error'><strong>✗ FOUND " . count($errors) . " ERRORS</strong></p>";
    echo "<p>These errors are likely causing the 500 Internal Server Error:</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li class='error'>" . s($error) . "</li>";
    }
    echo "</ul>";
}

echo "</div>";

echo "</div></body></html>";
