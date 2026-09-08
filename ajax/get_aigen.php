<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint to get AI-generated course module IDs.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

// Validate session.
require_sesskey();

// Get course and context.
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check user is logged in and can view the course.
require_login($course);
require_capability('moodle/course:view', $context);

header('Content-Type: application/json');

try {
    // Get all AI-generated records for this course.
    // Use the same modinfo validation as course_toolbar and aigen_list to ensure consistency.
    $sql = "SELECT ag.id, ag.cmid
              FROM {aiplacement_modgen_aigen} ag
              JOIN {course_modules} cm ON cm.id = ag.cmid
             WHERE ag.courseid = :courseid
               AND cm.deletioninprogress = 0";

    $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);
    $modinfo = get_fast_modinfo($course);

    $validcmids = [];
    foreach ($records as $record) {
        if (!isset($modinfo->cms[$record->cmid])) {
            // Activity no longer exists in modinfo, clean up the record.
            $DB->delete_records('aiplacement_modgen_aigen', ['id' => $record->id]);
        } else {
            $validcmids[] = (int)$record->cmid;
        }
    }

    echo json_encode([
        'success' => true,
        'cmids' => $validcmids,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
