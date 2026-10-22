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
 * Helpers for background job status handling.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Small, testable helpers for the job status AJAX endpoint.
 */
class job_status_helper {
    /**
     * Check whether a user owns a job.
     *
     * @param \stdClass $job Job record.
     * @param int $userid User id.
     * @return bool
     */
    public static function user_can_view_job(\stdClass $job, int $userid): bool {
        return (int)$job->userid === $userid;
    }

    /**
     * Find a queued or failed adhoc section-creation task for a job.
     *
     * Moodle stores task custom data as the full JSON payload, not just {"jobid": N},
     * so an exact DB lookup by partial customdata misses legitimate pending tasks.
     *
     * @param int $jobid Job id.
     * @return \core\task\adhoc_task|null Matching pending task, if any.
     */
    public static function find_section_creation_task(int $jobid): ?\core\task\adhoc_task {
        $tasks = \core\task\manager::get_adhoc_tasks('\\aiplacement_modgen\\task\\create_sections_task');
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            if (!empty($data->jobid) && (int)$data->jobid === $jobid) {
                return $task;
            }
        }
        return null;
    }

    /**
     * Create a job record and queue its ad-hoc task as one unit.
     *
     * Both writes sit in a single transaction, so a failure while queuing cannot leave a
     * `queued` job behind that no task will ever pick up, and the task cannot start before
     * the job row is committed.
     *
     * @param int $courseid Course the sections are created in.
     * @param int $userid User the job runs as.
     * @param string $action Job action: create_themes, create_weeks or create_from_json.
     * @param array $parameters Action parameters, stored on the job and passed to the task.
     * @return int Job id.
     */
    public static function queue_creation_job(int $courseid, int $userid, string $action, array $parameters): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $jobid = $DB->insert_record('aiplacement_modgen_jobs', (object) [
                'courseid' => $courseid,
                'userid' => $userid,
                'action' => $action,
                'status' => 'queued',
                'parameters' => json_encode($parameters),
                'timecreated' => time(),
            ]);

            $task = new \aiplacement_modgen\task\create_sections_task();
            $task->set_custom_data((object) ([
                'jobid' => $jobid,
                'courseid' => $courseid,
                'action' => $action,
            ] + $parameters));
            $task->set_userid($userid);
            \core\task\manager::queue_adhoc_task($task);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $jobid;
    }

    /**
     * Queue a minimal recovery task when a running job has lost its adhoc task.
     *
     * The recovery task intentionally carries only the job id. When it starts, the
     * create_sections_task interrupted-attempt guard sees the stale 'running' job
     * and marks it failed without re-running non-idempotent section creation.
     *
     * @param \stdClass $job Job record.
     * @return bool True if a recovery task was queued.
     */
    public static function queue_missing_task_recovery(\stdClass $job): bool {
        if (self::find_section_creation_task((int)$job->id)) {
            return false;
        }

        $task = new \aiplacement_modgen\task\create_sections_task();
        $task->set_custom_data((object)['jobid' => (int)$job->id]);
        $task->set_userid((int)$job->userid);

        return (bool)\core\task\manager::queue_adhoc_task($task, true);
    }
}
