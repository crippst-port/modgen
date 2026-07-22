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
 * AJAX endpoint for creating course sections (themes/weeks).
 *
 * This endpoint handles the quick-add creation of course structure via AJAX.
 * It supports two actions: creating themes (with optional nested weeks) and
 * creating standalone weeks.
 *
 * Expected POST parameters:
 * - courseid (int): Course ID
 * - action (string): 'create_themes' or 'create_weeks'
 * - sesskey (string): Session key for CSRF protection
 * - parentsection (int, optional): Parent section number for nested structure
 *
 * For create_themes action:
 * - themecount (int): Number of themes (1-maxquicksections)
 * - weeksperTheme (int): Weeks per theme (1-maxweeksperTheme)
 *
 * For create_weeks action:
 * - weekcount (int): Number of weeks (1-maxquicksections)
 *
 * Returns JSON response:
 * - success (bool): Whether operation succeeded
 * - message (string): Main success/error message
 * - messages (array, optional): Detailed list of created sections
 * - error (string, optional): Error message if success=false
 * - errorcode (string, optional): Error code for localization
 *
 * Requires capabilities:
 * - aiplacement/modgen:managestructure
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\ajax_response;

// Require login and valid session.
require_login();
require_sesskey();

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$action = required_param('action', PARAM_ALPHAEXT); // 'create_themes' or 'create_weeks' (ALPHAEXT allows underscores)
$parentsection = optional_param('parentsection', 0, PARAM_INT); // Current section to add content within
// Whether to create the learningactivity "section summary" placeholder modules.
// The Quick Add forms always submit this (advcheckbox, default off); this fallback
// only applies to callers that omit the field entirely.
$createsummaryactivities = optional_param('createsummaryactivities', 0, PARAM_BOOL);

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('aiplacement/modgen:managestructure', $context);

// Allow extended execution time for large section creation operations.
core_php_time_limit::raise(600);

// Set page context (required by some Moodle functions).
$PAGE->set_context($context);

// Get max sections from config
$maxsections = (int)get_config('aiplacement_modgen', 'maxquicksections') ?: 10;

try {
    require_once(__DIR__ . '/../classes/local/theme_builder.php');

    // All creation jobs now use background processing
    $totalsections = 0;

    if ($action === 'create_themes') {
        // Get theme parameters.
        $themecount = required_param('themecount', PARAM_INT);
        $weeksperTheme = required_param('weeksperTheme', PARAM_INT);
        $maxweeksperTheme = (int)get_config('aiplacement_modgen', 'maxweeksperTheme') ?: 5;

        // Validate.
        if ($themecount < 1 || $themecount > $maxsections) {
            ajax_response::error(
                get_string('invalidthemecount', 'aiplacement_modgen', $maxsections),
                'invalidthemecount'
            );
        }
        if ($weeksperTheme < 1 || $weeksperTheme > $maxweeksperTheme) {
            ajax_response::error(
                get_string('invalidweeksperTheme', 'aiplacement_modgen', $maxweeksperTheme),
                'invalidweeksperTheme'
            );
        }

        // Calculate total sections for display: themes + (themes × weeks × 4 sessions).
        $totalsections = $themecount + ($themecount * $weeksperTheme * 4);

        // Queue as background task.
        $job = new stdClass();
        $job->courseid = $courseid;
        $job->userid = $USER->id;
        $job->action = 'create_themes';
        $job->status = 'queued';
        $job->parameters = json_encode([
            'themecount' => $themecount,
            'weeksperTheme' => $weeksperTheme,
            'parentsection' => $parentsection,
            'createsummaryactivities' => $createsummaryactivities,
        ]);
        $job->timecreated = time();
        $jobid = $DB->insert_record('aiplacement_modgen_jobs', $job);

        // Queue ad-hoc task.
        $task = new \aiplacement_modgen\task\create_sections_task();
        $task->set_custom_data((object)[
            'jobid' => $jobid,
            'courseid' => $courseid,
            'action' => 'create_themes',
            'themecount' => $themecount,
            'weeksperTheme' => $weeksperTheme,
            'parentsection' => $parentsection,
            'createsummaryactivities' => $createsummaryactivities,
        ]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        ajax_response::success([
            'queued' => true,
            'jobid' => $jobid,
            'message' => get_string('jobqueued', 'aiplacement_modgen', $totalsections),
        ]);
    } else if ($action === 'create_weeks') {
        // Get week parameters.
        $weekcount = required_param('weekcount', PARAM_INT);

        // Validate.
        if ($weekcount < 1 || $weekcount > $maxsections) {
            ajax_response::error(
                get_string('invalidweekcount', 'aiplacement_modgen', $maxsections),
                'invalidweekcount'
            );
        }

        // Calculate total sections for display: weeks + (weeks × 3 sessions).
        $totalsections = $weekcount + ($weekcount * 3);

        // Queue as background task.
        $job = new stdClass();
        $job->courseid = $courseid;
        $job->userid = $USER->id;
        $job->action = 'create_weeks';
        $job->status = 'queued';
        $job->parameters = json_encode([
            'weekcount' => $weekcount,
            'parentsection' => $parentsection,
            'createsummaryactivities' => $createsummaryactivities,
        ]);
        $job->timecreated = time();
        $jobid = $DB->insert_record('aiplacement_modgen_jobs', $job);

        // Queue ad-hoc task.
        $task = new \aiplacement_modgen\task\create_sections_task();
        $task->set_custom_data((object)[
            'jobid' => $jobid,
            'courseid' => $courseid,
            'action' => 'create_weeks',
            'weekcount' => $weekcount,
            'parentsection' => $parentsection,
            'createsummaryactivities' => $createsummaryactivities,
        ]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        ajax_response::success([
            'queued' => true,
            'jobid' => $jobid,
            'message' => get_string('jobqueued', 'aiplacement_modgen', $totalsections),
        ]);
    } else {
        ajax_response::error('Invalid action', 'invalidaction');
    }
} catch (Exception $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
