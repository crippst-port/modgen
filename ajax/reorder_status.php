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
 * AJAX endpoint the assistant bar polls for the section reorder status.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\section_reorder_status;

$courseid = required_param('courseid', PARAM_INT);

require_login($courseid);

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

require_sesskey();

try {
    // Site administrators only, and only while the automatic renumber is switched on.
    if (!section_reorder_status::can_view($courseid)) {
        throw new required_capability_exception(context_course::instance($courseid), 'moodle/site:config', 'nopermissions', '');
    }
    ajax_response::success(['status' => section_reorder_status::get_for_course($courseid)]);
} catch (\Throwable $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
