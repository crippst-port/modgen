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
 * Ad-hoc task for creating course sections in the background.
 *
 * This task handles large section creation operations that may exceed
 * HTTP timeout limits. Runs via cron without timeout restrictions.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\task;

/**
 * Ad-hoc task for background section creation.
 */
class create_sections_task extends \core\task\adhoc_task {
    /**
     * Get the retry delay for failed tasks.
     *
     * Allow up to 3 retries with 60 second delays between attempts.
     * This handles temporary failures like database locks or race conditions.
     *
     * @return int Delay in seconds before retrying (0 = no retry)
     */
    public function get_fail_delay() {
        // Retry with 60 second delay. Moodle will handle retry limit via attemptsavailable.
        return 60;
    }

    /**
     * Execute the task.
     *
     * Retrieves job data, creates sections, and updates job status.
     */
    public function execute() {
        global $DB, $CFG, $USER;

        $data = $this->get_custom_data();
        $jobid = $data->jobid;

        // Debug logging to help diagnose failures.
        mtrace('Starting create_sections_task for job ID: ' . $jobid);

        // Update job status to running.
        // Note: Don't use MUST_EXIST to avoid race condition where task executes
        // before job record is committed to database in a separate transaction.
        // Instead, retry if job doesn't exist yet.
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);

        if (!$job) {
            // Job record not found - likely a race condition. Wait and retry.
            mtrace('Job record not found yet for job ID ' . $jobid . ' - waiting 2 seconds for database commit');
            sleep(2);
            $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);

            if (!$job) {
                throw new \moodle_exception('Job record not found for job ID ' . $jobid);
            }
        }

        // Idempotency guard: this task is re-run by Moodle on retry (see get_fail_delay())
        // against the same job. The creation actions are not self-undoing, so re-running a
        // job that already finished would duplicate every section it created. If the job is
        // already completed, treat this execution as a no-op success.
        if ($job->status === 'completed') {
            mtrace('Job ID ' . $jobid . ' is already completed - skipping to avoid duplicate sections');
            return;
        }

        // Interrupted-attempt guard: a job found in 'running' at the start of execute()
        // means a PREVIOUS attempt of this task set it running but never reached either
        // 'completed' or the catch block that sets 'failed' — i.e. the process was killed
        // mid-flight (e.g. out of memory / SIGKILL while flexsections reordered a large
        // course). Moodle's adhoc task locking guarantees this task is not running
        // concurrently, so 'running' here is always a dead prior attempt, not a live one.
        //
        // That prior attempt may have committed an unknown number of sections. Re-running
        // would duplicate them (creation is not content-idempotent), and we must not delete
        // sections a user may now be editing. So we stop here: mark the job terminally
        // failed (no further retry) and return normally so Moodle dequeues the task rather
        // than retrying it into an ever-growing, eventually-unopenable course.
        if ($job->status === 'running') {
            mtrace('Job ID ' . $jobid . ' was left in \'running\' by an interrupted prior attempt '
                . '(likely out of memory) - not re-running to avoid duplicating committed sections');
            $job->status = 'failed';
            $job->result = json_encode([
                'success' => false,
                'error' => get_string('jobinterrupted', 'aiplacement_modgen'),
                'will_retry' => false,
            ]);
            $job->timecompleted = time();
            $DB->update_record('aiplacement_modgen_jobs', $job);
            return;
        }

        $job->status = 'running';
        $job->timestarted = time();
        $DB->update_record('aiplacement_modgen_jobs', $job);

        // Set user context to the job creator for proper capability checks.
        // Background tasks run without a user session, but module creation requires
        // the job creator's capabilities to be checked. Save current user to restore later.
        $originaluser = $USER;
        try {
            $jobuser = $DB->get_record('user', ['id' => $job->userid], '*', MUST_EXIST);
            \core\session\manager::set_user($jobuser);
        } catch (\Exception $e) {
            // If user setup fails, log error but continue with system user.
            // This allows task to proceed but may cause capability check failures.
            debugging('Failed to set user context for job ' . $jobid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        try {
            require_once(__DIR__ . '/../../classes/local/theme_builder.php');

            $result = null;

            // Execute the appropriate creation method based on action.
            if ($data->action === 'create_themes') {
                $result = \aiplacement_modgen\local\theme_builder::create_themes(
                    $data->courseid,
                    $data->themecount,
                    $data->weeksperTheme,
                    $data->parentsection,
                    $data->createsummaryactivities ?? true
                );
            } else if ($data->action === 'create_weeks') {
                $result = \aiplacement_modgen\local\theme_builder::create_weeks(
                    $data->courseid,
                    $data->weekcount,
                    $data->parentsection,
                    $data->createsummaryactivities ?? true
                );
            } else if ($data->action === 'create_from_json') {
                // Template upload workflow - decode parameters and create sections.
                require_once(__DIR__ . '/../../classes/local/section_creation_service.php');
                $sectionservice = new \aiplacement_modgen\local\section_creation_service();
                // Convert JSON stdClass to array for type safety.
                // Task custom data stores JSON as stdClass, but service expects array.
                $jsonarray = json_decode(json_encode($data->json), true);
                $creationresult = $sectionservice->create_sections_from_json(
                    $jsonarray,
                    $data->courseid,
                    $data->moduletype,
                    $data->generatethemeintroductions ?? false,
                    $data->createsuggestedactivities ?? false,
                    $data->hideexistingsections ?? false,
                    $data->createsummaryactivities ?? true
                );
                $result = [
                    'success' => true,
                    'messages' => array_column($creationresult['results'], 'message'),
                ];
            } else {
                throw new \moodle_exception('invalidaction', 'aiplacement_modgen');
            }

            // At this point the creation call has RETURNED, which means its work was
            // committed (each action wraps section/activity creation in a single
            // delegated transaction that either fully commits or fully rolls back).
            //
            // Everything below is post-creation housekeeping (context backfill, cache).
            // It must NOT be allowed to flip a committed success into a 'failed' job:
            // doing so would trigger a retry that re-runs creation from scratch and
            // DUPLICATES the already-committed sections (there is no idempotency at the
            // content level, and we must not delete sections a teacher may now be
            // editing). So we mark the job completed first, then do housekeeping as a
            // best-effort step whose failure is logged but never re-thrown.

            // Mark the job completed as the direct consequence of creation succeeding.
            $job->status = 'completed';
            $job->result = json_encode([
                'success' => true,
                'messages' => $result['messages'] ?? [],
            ]);
            $job->timecompleted = time();
            $DB->update_record('aiplacement_modgen_jobs', $job);

            // Best-effort: ensure all course modules have contexts (subsections create
            // course modules). The creation services already do this internally before
            // committing; this is a redundant safety net. Never fatal.
            if (!empty($result['success'])) {
                try {
                    $sql = "SELECT cm.id
                            FROM {course_modules} cm
                            LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                            WHERE cm.course = :courseid AND ctx.id IS NULL";
                    $orphaned = $DB->get_records_sql($sql, [
                        'courseid' => $data->courseid,
                        'contextlevel' => CONTEXT_MODULE,
                    ]);

                    if (!empty($orphaned)) {
                        foreach ($orphaned as $cm) {
                            \context_module::instance($cm->id);
                        }
                    }
                } catch (\Throwable $e) {
                    // Housekeeping failure does not invalidate the committed result.
                    debugging('Post-creation context backfill failed for job ' . $jobid . ': '
                        . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        } catch (\Throwable $e) {
            // Note: Catch Throwable (not just Exception) to handle both Exceptions and Errors (TypeError, etc.).
            //
            // Distinguish PERMANENT failures (retrying cannot possibly help — the
            // request itself is invalid) from TRANSIENT ones (DB lock, race) that
            // Moodle should retry. A section-limit rejection is permanent: the course
            // is too large and nothing changes between attempts, so retrying just
            // fails identically and misleads the user with a "will retry" message.
            $permanentcodes = ['sectionlimitexceeded'];
            $ispermanent = ($e instanceof \moodle_exception) && in_array($e->errorcode, $permanentcodes, true);

            $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);
            if ($job) {
                $job->status = 'failed';
                $job->result = json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'will_retry' => !$ispermanent,
                ]);
                if ($ispermanent) {
                    // Terminal failure: record completion so the UI shows it as done.
                    $job->timecompleted = time();
                }
                $DB->update_record('aiplacement_modgen_jobs', $job);
            }

            mtrace('Section creation task failed for job ' . $jobid . ': ' . $e->getMessage());
            debugging('Section creation task failed: ' . $e->getMessage(), DEBUG_DEVELOPER);

            if ($ispermanent) {
                // Return normally so Moodle dequeues the task instead of retrying a
                // failure that can never succeed.
                return;
            }

            // Transient: re-throw so Moodle's task system retries via get_fail_delay().
            throw $e;
        } finally {
            // Restore original user context to prevent side effects.
            if (isset($originaluser)) {
                \core\session\manager::set_user($originaluser);
            }
        }
    }
}
