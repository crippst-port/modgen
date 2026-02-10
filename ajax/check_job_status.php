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
                
                // Get the associated adhoc task to check if it actually failed.
                $taskfailed = false;
                $adhoctask = $DB->get_record('task_adhoc', [
                    'classname' => '\\aiplacement_modgen\\task\\create_sections_task',
                    'customdata' => json_encode(['jobid' => $jobid])
                ]);
                
                // If task doesn't exist or has faildelay set, it failed and will retry.
                if (!$adhoctask || $adhoctask->faildelay > 0) {
                    $taskfailed = true;
                }
                
                // If task truly failed (not just slow), requeue it.
                if ($taskfailed) {
                    // Reset job to queued state for retry.
                    $job->status = 'queued';
                    $job->timestarted = null;
                    $DB->update_record('aiplacement_modgen_jobs', $job);
                    
                    // Re-queue the adhoc task if it doesn't exist.
                    if (!$adhoctask) {
                        $task = new \aiplacement_modgen\task\create_sections_task();
                        
                        // Build custom data based on job action type.
                        $customdata = (object)[
                            'jobid' => $jobid,
                            'action' => $job->action,
                            'courseid' => $job->courseid,
                            'parentsection' => $job->parentsection
                        ];
                        
                        // Add action-specific parameters.
                        if ($job->action === 'create_themes') {
                            $customdata->themecount = $job->themecount;
                            $customdata->weeksperTheme = $job->weeksperTheme;
                        } else if ($job->action === 'create_weeks') {
                            $customdata->weekcount = $job->weekcount;
                        } else if ($job->action === 'create_from_json') {
                            // JSON workflow - decode the stored JSON data.
                            $customdata->json = json_decode($job->jsondata);
                            $customdata->moduletype = $job->moduletype ?? 'learningactivity';
                            $customdata->generatethemeintroductions = $job->generatethemeintroductions ?? false;
                            $customdata->createsuggestedactivities = $job->createsuggestedactivities ?? false;
                            $customdata->hideexistingsections = $job->hideexistingsections ?? false;
                        }
                        
                        $task->set_custom_data($customdata);
                        $task->set_userid($job->userid);
                        \core\task\manager::queue_adhoc_task($task);
                    }
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
