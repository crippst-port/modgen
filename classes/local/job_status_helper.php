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
     * @param array $parameters Action parameters, passed to the task.
     * @param array|null $summary What to store on the job row instead of $parameters, when they are large
     *                           (the uploaded structure) and the job page only needs a summary.
     * @return int Job id.
     */
    public static function queue_creation_job(
        int $courseid,
        int $userid,
        string $action,
        array $parameters,
        ?array $summary = null
    ): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $jobid = $DB->insert_record('aiplacement_modgen_jobs', (object) [
                'courseid' => $courseid,
                'userid' => $userid,
                'action' => $action,
                'status' => 'queued',
                'parameters' => json_encode($summary ?? $parameters),
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

        \aiplacement_modgen\event\structure_job_queued::from_job((object) [
            'id' => $jobid,
            'courseid' => $courseid,
            'userid' => $userid,
            'action' => $action,
        ])->trigger();

        return $jobid;
    }

    /**
     * Whether a user may follow background jobs in a course.
     *
     * Anyone who can start a build can follow it: Quick Add needs the structure capability, and
     * building from a file or a prompt needs one of the generate capabilities.
     *
     * @param \context $context Course context.
     * @param int $userid User id.
     * @return bool
     */
    public static function can_follow_jobs(\context $context, int $userid): bool {
        return permissions::can_build($context, $userid);
    }

    /**
     * Require that a user may follow background jobs in a course.
     *
     * @param \context $context Course context.
     * @param int $userid User id.
     * @throws \required_capability_exception If they may not.
     */
    public static function require_jobs_access(\context $context, int $userid): void {
        permissions::require_build($context, $userid);
    }

    /**
     * Load a job for a user, checking the user may see it.
     *
     * A job can be seen by the user who queued it, and only while they can still start builds in its
     * course (see can_follow_jobs()).
     *
     * @param int $jobid Job id.
     * @param int $userid User asking.
     * @return \stdClass The job record.
     * @throws \moodle_exception If the job does not exist, or is not visible to the user.
     */
    public static function get_job_for_user(int $jobid, int $userid): \stdClass {
        global $DB;

        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
        self::require_jobs_access(\context_course::instance($job->courseid), $userid);
        if (!self::user_can_view_job($job, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view this job');
        }

        return $job;
    }

    /**
     * The status of one job as returned to the browser.
     *
     * @param \stdClass $job Job record.
     * @return array
     */
    public static function describe_job(\stdClass $job): array {
        $response = [
            'id' => $job->id,
            'status' => $job->status,
            'timecreated' => $job->timecreated,
            'timestarted' => $job->timestarted,
            'timecompleted' => $job->timecompleted,
        ];
        if (in_array($job->status, ['completed', 'failed'])) {
            $response['result'] = json_decode($job->result, true);
        }
        return $response;
    }

    /**
     * The user's queued or running jobs in a course.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return array List of job summaries, oldest first.
     * @throws \required_capability_exception If the user may not manage structure in the course.
     */
    public static function get_active_jobs(int $courseid, int $userid): array {
        global $DB;

        self::require_jobs_access(\context_course::instance($courseid), $userid);

        $jobs = $DB->get_records_select(
            'aiplacement_modgen_jobs',
            'courseid = :courseid AND userid = :userid AND status IN (:queued, :running)',
            ['courseid' => $courseid, 'userid' => $userid, 'queued' => 'queued', 'running' => 'running'],
            'timecreated ASC'
        );

        $summaries = [];
        foreach ($jobs as $job) {
            $summaries[] = [
                'id' => $job->id,
                'status' => $job->status,
                'action' => $job->action,
                'timecreated' => $job->timecreated,
                'timestarted' => $job->timestarted,
            ];
        }
        return $summaries;
    }

    /**
     * The user's jobs in a course that finished recently.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param int $seconds How far back to look.
     * @return array List of job summaries, newest first.
     * @throws \required_capability_exception If the user may not manage structure in the course.
     */
    public static function get_recent_jobs(int $courseid, int $userid, int $seconds = 300): array {
        global $DB;

        self::require_jobs_access(\context_course::instance($courseid), $userid);

        $jobs = $DB->get_records_select(
            'aiplacement_modgen_jobs',
            "courseid = :courseid AND userid = :userid AND timecompleted > :since AND status IN ('completed', 'failed')",
            ['courseid' => $courseid, 'userid' => $userid, 'since' => time() - $seconds],
            'timecompleted DESC'
        );

        $summaries = [];
        foreach ($jobs as $job) {
            $summary = [
                'id' => $job->id,
                'status' => $job->status,
                'timecompleted' => $job->timecompleted,
            ];
            if ($job->result) {
                $summary['result'] = json_decode($job->result, true);
            }
            $summaries[] = $summary;
        }
        return $summaries;
    }

    /**
     * Finish, as failed, unfinished jobs whose ad-hoc task has disappeared.
     *
     * The task is removed from the queue once it succeeds, gives up, or runs out of retries.
     * A job with no task left will therefore never finish:
     * - queued or running: it was interrupted (killed process, task deleted);
     * - failed, waiting for a retry (no timecompleted): Moodle stopped retrying it. Its last
     *   error is kept, and it no longer claims it will be retried.
     * While a matching task exists Moodle owns the retry, so the job is left alone. Sections are
     * never re-created: the job is only reported as failed, because an attempt may have built
     * part of it.
     *
     * @param int $graceseconds How long a job must have been waiting before it can count as lost.
     * @return int Number of jobs marked failed.
     */
    public static function fail_orphaned_running_jobs(int $graceseconds = 300): int {
        global $DB;

        $jobs = $DB->get_records_select(
            'aiplacement_modgen_jobs',
            "(status = 'running' AND timestarted < :cutoff1)
                OR (status = 'queued' AND timecreated < :cutoff2)
                OR (status = 'failed' AND timecompleted IS NULL AND COALESCE(timestarted, timecreated) < :cutoff3)",
            ['cutoff1' => time() - $graceseconds, 'cutoff2' => time() - $graceseconds, 'cutoff3' => time() - $graceseconds]
        );
        if (!$jobs) {
            return 0;
        }

        // One pass over the queue rather than a lookup per job.
        $pending = [];
        foreach (\core\task\manager::get_adhoc_tasks('\\aiplacement_modgen\\task\\create_sections_task') as $task) {
            $data = $task->get_custom_data();
            if (!empty($data->jobid)) {
                $pending[(int) $data->jobid] = true;
            }
        }

        $failed = 0;
        foreach ($jobs as $job) {
            if (isset($pending[(int) $job->id])) {
                continue;
            }
            $lastresult = $job->status === 'failed' ? json_decode((string) $job->result, true) : null;
            $error = $lastresult['error'] ?? get_string('jobinterrupted', 'aiplacement_modgen');
            $job->status = 'failed';
            $job->result = json_encode([
                'success' => false,
                'error' => $error,
                'will_retry' => false,
            ]);
            $job->timecompleted = time();
            $DB->update_record('aiplacement_modgen_jobs', $job);
            \aiplacement_modgen\event\structure_job_failed::from_job($job, [
                'error' => $error,
                'willretry' => 0,
            ])->trigger();
            $failed++;
        }

        return $failed;
    }
}
