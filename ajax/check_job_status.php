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
 * AJAX endpoint for checking background job status.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\job_status_helper;

// Require login and valid session.
require_login();

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

require_sesskey();

// Get parameters - jobid is optional, courseid + recent/active flags for checking jobs.
$jobid = optional_param('jobid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$recent = optional_param('recent', 0, PARAM_INT);
$active = optional_param('active', 0, PARAM_INT);

try {
    // Jobs lost with their task are failed by a schedule, so this poll never repairs anything.
    if ($jobid) {
        $job = job_status_helper::get_job_for_user($jobid, (int) $USER->id);
        ajax_response::success(job_status_helper::describe_job($job));
    } else if ($courseid && $active) {
        ajax_response::success(['jobs' => job_status_helper::get_active_jobs($courseid, (int) $USER->id)]);
    } else if ($courseid && $recent) {
        ajax_response::success(['jobs' => job_status_helper::get_recent_jobs($courseid, (int) $USER->id)]);
    } else {
        throw new moodle_exception('invalidparameters', 'aiplacement_modgen');
    }
} catch (\Throwable $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
