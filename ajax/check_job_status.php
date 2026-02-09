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

// Require login and valid session.
require_login();
require_sesskey();

// Get parameters - jobid is optional, courseid + recent flag for checking completed jobs.
$jobid = optional_param('jobid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$recent = optional_param('recent', 0, PARAM_INT);

try {
    if ($jobid) {
        // Check specific job status.
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        // Verify user has access to this course.
        $context = context_course::instance($job->courseid);
        require_capability('aiplacement/modgen:managestructure', $context);

        // Return job status.
        $response = [
            'status' => $job->status,
            'timecreated' => $job->timecreated,
            'timestarted' => $job->timestarted,
            'timecompleted' => $job->timecompleted
        ];

        // Include result if job completed or failed.
        if (in_array($job->status, ['completed', 'failed'])) {
            $result = json_decode($job->result, true);
            $response['result'] = $result;
        }

        ajax_response::success($response);

    } else if ($courseid && $recent) {
        // Check for recently completed jobs in this course (last 5 minutes).
        $context = context_course::instance($courseid);
        require_capability('aiplacement/modgen:managestructure', $context);

        $fiveminutesago = time() - 300;
        $jobs = $DB->get_records_sql(
            "SELECT * FROM {aiplacement_modgen_jobs}
             WHERE courseid = :courseid
             AND userid = :userid
             AND timecompleted > :timecompleted
             AND status IN ('completed', 'failed')
             ORDER BY timecompleted DESC",
            [
                'courseid' => $courseid,
                'userid' => $USER->id,
                'timecompleted' => $fiveminutesago
            ]
        );

        $jobsarray = [];
        foreach ($jobs as $job) {
            $jobdata = [
                'id' => $job->id,
                'status' => $job->status,
                'timecompleted' => $job->timecompleted
            ];
            if ($job->result) {
                $jobdata['result'] = json_decode($job->result, true);
            }
            $jobsarray[] = $jobdata;
        }

        ajax_response::success(['jobs' => $jobsarray]);

    } else {
        throw new moodle_exception('invalidparameters', 'aiplacement_modgen');
    }

} catch (Exception $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
