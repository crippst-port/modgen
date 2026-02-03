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

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('aiplacement/modgen:managestructure', $context);

// Set page context (required by some Moodle functions).
$PAGE->set_context($context);

// Get max sections from config
$maxsections = (int)get_config('aiplacement_modgen', 'maxquicksections') ?: 10;

try {
    require_once(__DIR__ . '/../classes/local/theme_builder.php');

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

        // Create themes within current section.
        $result = \aiplacement_modgen\local\theme_builder::create_themes($courseid, $themecount, $weeksperTheme, $parentsection);

        ajax_response::success([
            'message' => get_string('themescreated', 'aiplacement_modgen', $themecount),
            'messages' => $result['messages'] ?? []
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

        // Create weeks within current section.
        $result = \aiplacement_modgen\local\theme_builder::create_weeks($courseid, $weekcount, $parentsection);

        ajax_response::success([
            'message' => get_string('weekscreated', 'aiplacement_modgen', $weekcount),
            'messages' => $result['messages'] ?? []
        ]);

    } else {
        ajax_response::error('Invalid action', 'invalidaction');
    }

} catch (Exception $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
