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
 * AJAX endpoint the course page asks before a section change: is the course free for it?
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\structure_guard;

$courseid = required_param('courseid', PARAM_INT);

require_login($courseid);

// The master switch (Site admin > AI > AI placements) is part of structure_guard::applies(): a
// page that has outlived it stops guarding, rather than having every change refused.
require_sesskey();

$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

try {
    // Switched off since the page loaded, or no longer Flexible sections: the page stops guarding.
    $guarded = structure_guard::applies($courseid, $context);
    ajax_response::success(['guarded' => $guarded, 'busy' => $guarded && structure_guard::is_busy($courseid)]);
} catch (\Throwable $e) {
    // The page lets the change go ahead when the check fails, so this is not shown to anyone.
    ajax_response::error(get_string('error'), 'exception');
}
