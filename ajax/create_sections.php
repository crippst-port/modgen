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
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Log everything for debugging
error_log('=== create_sections.php called ===');
error_log('POST: ' . print_r($_POST, true));
error_log('GET: ' . print_r($_GET, true));
error_log('REQUEST: ' . print_r($_REQUEST, true));

// Require login and valid session.
require_login();
require_sesskey();

// Debug logging - remove after testing
error_log('create_sections.php - POST data: ' . print_r($_POST, true));
error_log('create_sections.php - GET data: ' . print_r($_GET, true));

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$action = required_param('action', PARAM_ALPHAEXT); // 'create_themes' or 'create_weeks' (ALPHAEXT allows underscores)
$parentsection = optional_param('parentsection', 0, PARAM_INT); // Current section to add content within

error_log("create_sections.php - courseid: $courseid, action: $action, parentsection: $parentsection");

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

// Set page context (required by some Moodle functions).
$PAGE->set_context($context);

// Prepare response structure.
$response = [
    'success' => false,
    'message' => '',
    'messages' => [],
    'error' => '',
];

try {
    require_once(__DIR__ . '/../classes/local/theme_builder.php');

    if ($action === 'create_themes') {
        // Get theme parameters.
        $themecount = required_param('themecount', PARAM_INT);
        $weeksperTheme = required_param('weeksperTheme', PARAM_INT);

        // Validate.
        if ($themecount < 1 || $themecount > 10) {
            throw new moodle_exception('invalidcount', 'aiplacement_modgen');
        }
        if ($weeksperTheme < 1 || $weeksperTheme > 10) {
            throw new moodle_exception('invalidcount', 'aiplacement_modgen');
        }

        // Create themes within current section.
        $result = \aiplacement_modgen\local\theme_builder::create_themes($courseid, $themecount, $weeksperTheme, $parentsection);

        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = get_string('themescreated', 'aiplacement_modgen', $themecount);
            $response['messages'] = $result['messages'];
        }

    } else if ($action === 'create_weeks') {
        // Get week parameters.
        $weekcount = required_param('weekcount', PARAM_INT);

        // Validate.
        if ($weekcount < 1 || $weekcount > 10) {
            throw new moodle_exception('invalidcount', 'aiplacement_modgen');
        }

        // Create weeks within current section.
        $result = \aiplacement_modgen\local\theme_builder::create_weeks($courseid, $weekcount, $parentsection);

        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = get_string('weekscreated', 'aiplacement_modgen', $weekcount);
            $response['messages'] = $result['messages'];
        }

    } else {
        throw new moodle_exception('invalidaction', 'error');
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

// Return JSON response.
header('Content-Type: application/json');
echo json_encode($response);
