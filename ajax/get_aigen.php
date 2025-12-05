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
    // Get all AI-generated cmids for this course.
    $cmids = $DB->get_fieldset_select(
        'aiplacement_modgen_aigen',
        'cmid',
        'courseid = :courseid',
        ['courseid' => $courseid]
    );

    echo json_encode([
        'success' => true,
        'cmids' => array_map('intval', $cmids),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
