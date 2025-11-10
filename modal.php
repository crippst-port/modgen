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
 * Modal controller for the Module Assistant.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('AJAX_SCRIPT') && !empty($_REQUEST['ajax'])) {
    define('AJAX_SCRIPT', true);
}

require_once(__DIR__ . '/../../../config.php');
require_login();

/**
 * Emit a JSON response and terminate execution.
 *
 * @param array $data Response data to encode as JSON.
 */
function aiplacement_modgen_send_json(array $data): void {
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Get course ID from parameters
$courseid = required_param('id', PARAM_INT);

// Verify course exists and user has access
$course = get_course($courseid);
$coursecontext = context_course::instance($courseid);
require_capability('moodle/course:view', $coursecontext);

// Set page context
global $PAGE, $OUTPUT;
$PAGE->set_context($coursecontext);
$PAGE->set_course($course);

// Get request parameters
$ajax = optional_param('ajax', 0, PARAM_BOOL);

if (!$ajax) {
    // Non-AJAX requests should go to the generator form directly
    redirect(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
}

// AJAX request: Return modal content

// Generate URL to the standalone generator form
$generatorurl = new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]);

// Prepare template data
$modaldata = [
    'courseid' => $courseid,
    'generatorurl' => $generatorurl->out(false),
];

// Render modal content
$bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/modal_link', $modaldata);

// Send JSON response with modal content
aiplacement_modgen_send_json([
    'body' => $bodyhtml,
    'footer' => '',
    'refresh' => false,
    'title' => get_string('pluginname', 'aiplacement_modgen'),
]);
