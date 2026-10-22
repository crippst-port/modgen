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
 * AJAX endpoint to toggle activity visibility.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$visible = required_param('visible', PARAM_BOOL);

// Be logged in with a valid session before anything is looked up.
require_login();
require_sesskey();

// Get the course module, then check the user can access it.
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
$context = context_course::instance($course->id);
require_login($course, false, $cm);

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

require_capability('moodle/course:activityvisibility', $context);

// Set visibility; core rebuilds the course cache.
set_coursemodule_visible($cmid, (int) $visible);

// Return success.
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'cmid' => $cmid,
    'visible' => (bool) $visible,
]);
