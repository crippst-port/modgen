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

// Be logged in with a valid session before anything is looked up.
require_login();
require_sesskey();

// Get course and context, then check the user can access the course.
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

// The same people who can open the list of AI suggestions (aigen_list.php) can see its markers.
// Not moodle/course:view: that means viewing courses without participating, which teachers lack.
if (
    !has_capability('aiplacement/modgen:generatewithprompt', $context)
    && !has_capability('aiplacement/modgen:generatefromtemplate', $context)
) {
    throw new required_capability_exception($context, 'aiplacement/modgen:generatewithprompt', 'nopermissions', '');
}

header('Content-Type: application/json');

try {
    echo json_encode([
        'success' => true,
        'cmids' => \aiplacement_modgen\aigen_tracker::get_course_cmids($courseid),
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
