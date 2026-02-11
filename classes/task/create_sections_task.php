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

defined('MOODLE_INTERNAL') || die();

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

        // Debug logging to help diagnose failures
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
                    $data->parentsection
                );
            } else if ($data->action === 'create_weeks') {
                $result = \aiplacement_modgen\local\theme_builder::create_weeks(
                    $data->courseid,
                    $data->weekcount,
                    $data->parentsection
                );
            } else if ($data->action === 'create_from_json') {
                // Template upload workflow - decode parameters and create sections.
                require_once(__DIR__ . '/../../classes/local/section_creation_service.php');
                $section_service = new \aiplacement_modgen\local\section_creation_service();
                // Convert JSON stdClass to array for type safety.
                // Task custom data stores JSON as stdClass, but service expects array.
                $jsonarray = json_decode(json_encode($data->json), true);
                $creation_result = $section_service->create_sections_from_json(
                    $jsonarray,
                    $data->courseid,
                    $data->moduletype,
                    $data->generatethemeintroductions ?? false,
                    $data->createsuggestedactivities ?? false,
                    $data->hideexistingsections ?? false
                );
                $result = [
                    'success' => true,
                    'messages' => array_column($creation_result['results'], 'message')
                ];
            } else {
                throw new \moodle_exception('invalidaction', 'aiplacement_modgen');
            }

            // Ensure all course modules have contexts (subsections create course modules)
            // Only check if we actually created sections with modules
            if (!empty($result['success'])) {
                $sql = "SELECT cm.id
                        FROM {course_modules} cm
                        LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                        WHERE cm.course = :courseid AND ctx.id IS NULL";
                $orphaned = $DB->get_records_sql($sql, [
                    'courseid' => $data->courseid,
                    'contextlevel' => CONTEXT_MODULE
                ]);
                
                if (!empty($orphaned)) {
                    foreach ($orphaned as $cm) {
                        \context_module::instance($cm->id);
                    }
                }
            }

            // Update job status to completed with results.
            $job->status = 'completed';
            $job->result = json_encode([
                'success' => true,
                'messages' => $result['messages'] ?? []
            ]);
            $job->timecompleted = time();
            $DB->update_record('aiplacement_modgen_jobs', $job);

        } catch (\Throwable $e) {
            // Update job status - mark as failed for retry or final failure.
            // Note: Catch Throwable (not just Exception) to handle both Exceptions and Errors (TypeError, etc.).
            $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);
            if ($job) {
                // Mark as failed - Moodle's task system will handle retries via get_fail_delay().
                $job->status = 'failed';
                $job->result = json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'will_retry' => true
                ]);
                // Don't set timecompleted - job may still retry
                $DB->update_record('aiplacement_modgen_jobs', $job);
            }

            // Log the error for debugging before re-throwing.
            mtrace('Section creation task failed for job ' . $jobid . ': ' . $e->getMessage());
            mtrace('Stack trace: ' . $e->getTraceAsString());
            debugging('Section creation task failed: ' . $e->getMessage(), DEBUG_DEVELOPER);

            // Re-throw the exception so Moodle task system can handle retries.
            throw $e;
        } finally {
            // Restore original user context to prevent side effects.
            if (isset($originaluser)) {
                \core\session\manager::set_user($originaluser);
            }
        }
    }
}
