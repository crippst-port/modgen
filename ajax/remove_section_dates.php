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
 * AJAX endpoint for removing dates from course sections.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\date_calculator;

// Require login and valid session.
require_login();
require_sesskey();

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$selectedsections = required_param('selectedsections', PARAM_RAW);

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('aiplacement/modgen:managedates', $context);

// Set page context.
$PAGE->set_context($context);

// Acquire course lock.
$lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
$lock = $lockfactory->get_lock('course_edit_' . $courseid, 60);

if (!$lock) {
    ajax_response::error(
        get_string('erroracquiringlock', 'aiplacement_modgen'),
        'lock_failed'
    );
}

try {
    global $DB;

    // Parse selected sections JSON.
    $selectedids = [];
    if (!empty($selectedsections)) {
        $decoded = json_decode($selectedsections, true);
        if (is_array($decoded)) {
            $selectedids = array_map('intval', $decoded);
        }
    }

    if (empty($selectedids)) {
        if ($lock) {
            $lock->release();
        }
        ajax_response::error(
            get_string('nosectionsselected', 'aiplacement_modgen'),
            'no_sections'
        );
    }

    // Remove dates from selected sections.
    $updatedcount = 0;
    $updatedsections = [];

    foreach ($selectedids as $sectionid) {
        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);

        // Remove existing date from name.
        $newname = date_calculator::remove_existing_date($section->name);

        // Only update if the name changed.
        if ($newname !== $section->name) {
            $DB->update_record('course_sections', [
                'id' => $sectionid,
                'name' => $newname,
                'timemodified' => time(),
            ]);

            $updatedcount++;
            $updatedsections[] = [
                'id' => $sectionid,
                'section' => $section->section,
                'name' => $newname,
            ];
        }
    }

    // Rebuild course cache (buffer any output).
    ob_start();
    rebuild_course_cache($courseid, true);
    ob_end_clean();

    // Release lock BEFORE sending response (response calls exit/die).
    if ($lock) {
        $lock->release();
        $lock = null;
    }

    ajax_response::success([
        'updated' => $updatedcount,
        'sections' => $updatedsections,
        'message' => get_string('datesremovedsuccess', 'aiplacement_modgen', $updatedcount),
    ]);
} catch (Exception $e) {
    // Release lock before error response.
    if ($lock) {
        $lock->release();
        $lock = null;
    }
    ajax_response::error($e->getMessage(), 'exception');
}
