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

// Get parameters - jobid is optional, courseid + recent/active flags for checking jobs.
$jobid = optional_param('jobid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$recent = optional_param('recent', 0, PARAM_INT);
$active = optional_param('active', 0, PARAM_INT);

/**
 * Find a queued or failed adhoc section-creation task for a job.
 *
 * Moodle stores task custom data as the full JSON payload, not just {"jobid": N},
 * so an exact DB lookup by partial customdata misses legitimate pending tasks.
 *
 * @param int $jobid Job id.
 * @return \core\task\adhoc_task|null Matching pending task, if any.
 */
function aiplacement_modgen_find_section_creation_task(int $jobid): ?\core\task\adhoc_task {
    $tasks = \core\task\manager::get_adhoc_tasks('\\aiplacement_modgen\\task\\create_sections_task');
    foreach ($tasks as $task) {
        $data = $task->get_custom_data();
        if (!empty($data->jobid) && (int)$data->jobid === $jobid) {
            return $task;
        }
    }
    return null;
}

try {
    if ($jobid) {
        // Check specific job status.
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        // Verify user has access to this course.
        $context = context_course::instance($job->courseid);
        require_capability('aiplacement/modgen:managestructure', $context);

        // STUCK JOB DETECTION: If job has been running for more than 5 minutes, consider it stuck.
        // This handles cases where: PHP fatal error, server restart, task timeout, or unexpected crash.
        $stuck = false;
        if ($job->status === 'running' && $job->timestarted) {
            $runningtime = time() - $job->timestarted;
            if ($runningtime > 300) { // 5 minutes
                $stuck = true;

                $adhoctask = aiplacement_modgen_find_section_creation_task($jobid);

                // If a matching task still exists, Moodle owns the retry lifecycle.
                // If it is missing, queue a small recovery task without changing the
                // job back to queued. The task's interrupted-attempt guard sees the
                // stale 'running' status and marks the job failed without re-running
                // non-idempotent section creation.
                if (!$adhoctask) {
                    $task = new \aiplacement_modgen\task\create_sections_task();
                    $task->set_custom_data((object)['jobid' => $jobid]);
                    $task->set_userid($job->userid);
                    \core\task\manager::queue_adhoc_task($task, true);
                }
            }
        }

        // Return job status.
        $response = [
            'id' => $job->id,
            'status' => $job->status,
            'timecreated' => $job->timecreated,
            'timestarted' => $job->timestarted,
            'timecompleted' => $job->timecompleted,
            'stuck' => $stuck
        ];

        // Include result if job completed or failed.
        if (in_array($job->status, ['completed', 'failed'])) {
            $result = json_decode($job->result, true);
            $response['result'] = $result;
        }

        ajax_response::success($response);

    } else if ($courseid && $active) {
        // Check for active jobs (queued or running) in this course.
        $context = context_course::instance($courseid);
        require_capability('aiplacement/modgen:managestructure', $context);

        $jobs = $DB->get_records_select(
            'aiplacement_modgen_jobs',
            'courseid = :courseid AND userid = :userid AND status IN (:queued, :running)',
            [
                'courseid' => $courseid,
                'userid' => $USER->id,
                'queued' => 'queued',
                'running' => 'running'
            ],
            'timecreated ASC'
        );

        $jobsarray = [];
        foreach ($jobs as $job) {
            $jobsarray[] = [
                'id' => $job->id,
                'status' => $job->status,
                'action' => $job->action,
                'timecreated' => $job->timecreated,
                'timestarted' => $job->timestarted
            ];
        }

        ajax_response::success(['jobs' => $jobsarray]);

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
