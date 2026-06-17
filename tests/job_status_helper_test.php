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
 * Job status helper tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\job_status_helper;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\task\create_sections_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for job status ownership and recovery decisions.
 *
 * @coversDefaultClass \aiplacement_modgen\local\job_status_helper
 */
final class job_status_helper_test extends advanced_testcase {

    /** @var \stdClass Test course. */
    private $course;

    /** @var \stdClass Job owner. */
    private $owner;

    /** @var \stdClass Another user enrolled in the same course. */
    private $otheruser;

    /**
     * Set up a course and two users.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);

        $this->owner = $generator->create_user();
        $this->otheruser = $generator->create_user();

        $editingteacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $coursecontext = \context_course::instance($this->course->id);
        $generator->role_assign($editingteacherrole, $this->owner->id, $coursecontext->id);
        $generator->role_assign($editingteacherrole, $this->otheruser->id, $coursecontext->id);
        assign_capability('aiplacement/modgen:managestructure', CAP_ALLOW, $editingteacherrole, $coursecontext->id);
    }

    /**
     * Insert a job row.
     *
     * @param int $userid User id.
     * @param string $status Job status.
     * @param int|null $timestarted Start time.
     * @return int Job id.
     */
    private function make_job(int $userid, string $status = 'queued', ?int $timestarted = null): int {
        global $DB;

        return (int)$DB->insert_record('aiplacement_modgen_jobs', (object)[
            'courseid' => $this->course->id,
            'userid' => $userid,
            'action' => 'create_weeks',
            'status' => $status,
            'parameters' => json_encode(['weekcount' => 1, 'parentsection' => 0]),
            'timecreated' => time() - 600,
            'timestarted' => $timestarted,
        ]);
    }

    /**
     * Fetch a job row.
     *
     * @param int $jobid Job id.
     * @return \stdClass
     */
    private function job(int $jobid): \stdClass {
        global $DB;
        return $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
    }

    /**
     * Count generated top-level sections, excluding section 0 and Assessments.
     *
     * @return int
     */
    private function count_generated_toplevel(): int {
        global $DB;

        $assessments = get_string('assessmentssectionname', 'aiplacement_modgen');
        return $DB->count_records_sql(
            "SELECT COUNT(cs.id)
               FROM {course_sections} cs
          LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = :courseid
                AND cs.section > 0
                AND cs.name <> :assessments
                AND (cfo.value IS NULL OR cfo.value = '0')",
            ['courseid' => $this->course->id, 'assessments' => $assessments]
        );
    }

    /**
     * Run a task while discarding mtrace output.
     *
     * @param create_sections_task $task Task to execute.
     */
    private function run_task(create_sections_task $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Job visibility is owner-only, even when another user has course editing rights.
     *
     * @covers ::user_can_view_job
     */
    public function test_user_can_view_job_is_owner_only(): void {
        $jobid = $this->make_job($this->owner->id);
        $job = $this->job($jobid);

        $this->assertTrue(job_status_helper::user_can_view_job($job, $this->owner->id));
        $this->assertFalse(job_status_helper::user_can_view_job($job, $this->otheruser->id));
    }

    /**
     * Existing adhoc tasks are found even when customdata contains the full payload.
     *
     * This catches the old bug where status polling searched for exactly
     * {"jobid": N}, missing the real task payload and falsely requeueing.
     *
     * @covers ::find_section_creation_task
     * @covers ::queue_missing_task_recovery
     */
    public function test_existing_full_customdata_task_prevents_recovery_requeue(): void {
        global $DB;

        $jobid = $this->make_job($this->owner->id, 'running', time() - 600);
        $task = new create_sections_task();
        $task->set_custom_data((object)[
            'jobid' => $jobid,
            'courseid' => $this->course->id,
            'action' => 'create_weeks',
            'weekcount' => 1,
            'parentsection' => 0,
        ]);
        $task->set_userid($this->owner->id);
        \core\task\manager::queue_adhoc_task($task);

        $this->assertNotNull(job_status_helper::find_section_creation_task($jobid));
        $this->assertFalse(job_status_helper::queue_missing_task_recovery($this->job($jobid)));
        $this->assertEquals(1, $DB->count_records('task_adhoc'));
        $this->assertSame('running', $this->job($jobid)->status);
    }

    /**
     * Missing-task recovery must not turn a stale running job back into a creation retry.
     *
     * The queued recovery task carries only jobid, so execute() marks the stale
     * running job failed via its interrupted-attempt guard and creates no sections.
     *
     * @covers ::queue_missing_task_recovery
     */
    public function test_missing_task_recovery_marks_stale_running_job_failed_without_creation(): void {
        global $DB;

        $jobid = $this->make_job($this->owner->id, 'running', time() - 600);

        $this->assertTrue(job_status_helper::queue_missing_task_recovery($this->job($jobid)));
        $this->assertEquals(1, $DB->count_records('task_adhoc'));

        $task = job_status_helper::find_section_creation_task($jobid);
        $this->assertNotNull($task);
        $customdata = $task->get_custom_data();
        $this->assertEquals((object)['jobid' => $jobid], $customdata);

        $this->run_task($task);
        $this->resetDebugging();

        $job = $this->job($jobid);
        $this->assertSame('failed', $job->status);
        $result = json_decode($job->result, true);
        $this->assertFalse($result['will_retry'] ?? true);
        $this->assertSame(0, $this->count_generated_toplevel(),
            'Recovery must not create sections for an interrupted job.');
    }
}
