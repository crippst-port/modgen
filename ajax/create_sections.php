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
 * It supports three actions: creating themes (with optional nested weeks),
 * creating standalone weeks, and adding the Assessments section.
 *
 * Expected POST parameters:
 * - courseid (int): Course ID
 * - action (string): 'create_themes', 'create_weeks' or 'create_assessments'
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
use aiplacement_modgen\local\quick_add_service;

// Require login and valid session.
require_login();

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();
\aiplacement_modgen\local\settings_helper::require_quickadd_enabled();

require_sesskey();

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$action = required_param('action', PARAM_ALPHAEXT); // Action name: 'create_themes' or 'create_weeks' (ALPHAEXT allows underscores).
$parentsection = optional_param('parentsection', 0, PARAM_INT); // Current section to add content within.
// Whether to create the learningactivity "section summary" placeholder modules.
// The Quick Add forms always submit this (advcheckbox, default off); this fallback
// only applies to callers that omit the field entirely.
$createsummaryactivities = optional_param('createsummaryactivities', 0, PARAM_BOOL);

// Verify course access (enrolment, visibility and suspension, not just roles) and permissions.
require_login($courseid);
$context = context_course::instance($courseid);
// Both Quick Add actions share one rule; an unknown action is refused below with the other request errors.
\aiplacement_modgen\local\permissions::require_build_action('create_weeks', $context);
\aiplacement_modgen\local\course_formats::require_supported($courseid);

// Allow extended execution time for large section creation operations.
core_php_time_limit::raise(600);

// Set page context (required by some Moodle functions).
$PAGE->set_context($context);

try {
    // All creation jobs use background processing; the service checks the request and queues it.
    if ($action === 'create_themes') {
        $queued = quick_add_service::queue_themes(
            $courseid,
            (int) $USER->id,
            required_param('themecount', PARAM_INT),
            required_param('weeksperTheme', PARAM_INT),
            $parentsection,
            $createsummaryactivities
        );
    } else if ($action === 'create_weeks') {
        $queued = quick_add_service::queue_weeks(
            $courseid,
            (int) $USER->id,
            required_param('weekcount', PARAM_INT),
            $parentsection,
            $createsummaryactivities
        );
    } else if ($action === 'create_assessments') {
        // Added at the top of the course, so refused from inside a section, as New Theme is.
        if ($parentsection > 0) {
            throw new \moodle_exception('assessmentstoplevelonly', 'aiplacement_modgen');
        }
        // One section, so it is added straight away rather than queued.
        $section = \aiplacement_modgen\local\theme_builder::add_assessments_section($courseid);
        ajax_response::success([
            'message' => get_string('assessmentsadded', 'aiplacement_modgen', $section->name),
        ]);
    } else {
        ajax_response::error(get_string('invalidaction', 'aiplacement_modgen'), 'invalidaction');
    }

    ajax_response::success([
        'queued' => true,
        'jobid' => $queued['jobid'],
        'message' => get_string('jobqueued', 'aiplacement_modgen', $queued['total']),
    ]);
} catch (\moodle_exception $e) {
    ajax_response::error($e->getMessage(), $e->errorcode);
} catch (\Throwable $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
