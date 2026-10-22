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
     * A running job whose task is still queued is left to Moodle, even when the task carries
     * the full payload rather than just the job id.
     *
     * @covers ::find_section_creation_task
     * @covers ::fail_orphaned_running_jobs
     */
    public function test_running_job_with_a_task_is_left_alone(): void {
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
        $this->assertSame(0, job_status_helper::fail_orphaned_running_jobs());
        $this->assertSame('running', $this->job($jobid)->status);
    }

    /**
     * A job left running with no task is marked failed, and nothing is created for it.
     *
     * @covers ::fail_orphaned_running_jobs
     */
    public function test_running_job_without_a_task_is_marked_failed(): void {
        $jobid = $this->make_job($this->owner->id, 'running', time() - 600);

        $this->assertSame(1, job_status_helper::fail_orphaned_running_jobs());

        $job = $this->job($jobid);
        $this->assertSame('failed', $job->status);
        $this->assertNotEmpty($job->timecompleted);
        $result = json_decode($job->result, true);
        $this->assertFalse($result['success']);
        $this->assertFalse($result['will_retry']);
        $this->assertSame(
            0,
            $this->count_generated_toplevel(),
            'Failing an interrupted job must not create sections.'
        );
    }

    /**
     * A job that only just started is not treated as lost.
     *
     * @covers ::fail_orphaned_running_jobs
     */
    public function test_recently_started_job_is_not_failed(): void {
        $jobid = $this->make_job($this->owner->id, 'running', time() - 30);

        $this->assertSame(0, job_status_helper::fail_orphaned_running_jobs());
        $this->assertSame('running', $this->job($jobid)->status);
    }

    /**
     * The status endpoint only shows a job to the user who queued it.
     *
     * @covers ::get_job_for_user
     */
    public function test_get_job_for_user_is_owner_only(): void {
        $jobid = $this->make_job($this->owner->id);

        $this->assertEquals($jobid, job_status_helper::get_job_for_user($jobid, $this->owner->id)->id);

        $this->expectException(\moodle_exception::class);
        job_status_helper::get_job_for_user($jobid, $this->otheruser->id);
    }

    /**
     * The owner loses access to their job when they lose the structure capability.
     *
     * @covers ::get_job_for_user
     */
    public function test_get_job_for_user_needs_the_structure_capability(): void {
        global $DB;
        $jobid = $this->make_job($this->owner->id);
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'aiplacement/modgen:managestructure',
            CAP_PREVENT,
            $role,
            \context_course::instance($this->course->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        job_status_helper::get_job_for_user($jobid, $this->owner->id);
    }

    /**
     * An unknown job id is an error, not an empty answer.
     *
     * @covers ::get_job_for_user
     */
    public function test_get_job_for_user_unknown_job(): void {
        $this->expectException(\dml_missing_record_exception::class);
        job_status_helper::get_job_for_user(987654, $this->owner->id);
    }

    /**
     * Only finished jobs carry a result.
     *
     * @covers ::describe_job
     */
    public function test_describe_job_includes_the_result_only_when_finished(): void {
        global $DB;
        $queued = $this->job($this->make_job($this->owner->id));
        $this->assertArrayNotHasKey('result', job_status_helper::describe_job($queued));

        $done = $this->job($this->make_job($this->owner->id, 'completed'));
        $done->result = json_encode(['success' => true, 'messages' => ['x'], 'warnings' => []]);
        $DB->update_record('aiplacement_modgen_jobs', $done);
        $described = job_status_helper::describe_job($this->job($done->id));
        $this->assertSame('completed', $described['status']);
        $this->assertSame(['x'], $described['result']['messages']);
    }

    /**
     * Active jobs are the user's own queued and running ones, oldest first.
     *
     * @covers ::get_active_jobs
     */
    public function test_get_active_jobs_lists_only_own_unfinished_jobs(): void {
        $mine = $this->make_job($this->owner->id, 'running', time() - 60);
        $this->make_job($this->owner->id, 'completed');
        $this->make_job($this->otheruser->id, 'queued');

        $jobs = job_status_helper::get_active_jobs($this->course->id, $this->owner->id);

        $this->assertCount(1, $jobs);
        $this->assertEquals($mine, $jobs[0]['id']);
        $this->assertSame('running', $jobs[0]['status']);
    }

    /**
     * Recent jobs are the user's own finished ones from the last few minutes.
     *
     * @covers ::get_recent_jobs
     */
    public function test_get_recent_jobs_respects_the_window(): void {
        global $DB;
        $recent = $this->make_job($this->owner->id, 'completed');
        $DB->set_field('aiplacement_modgen_jobs', 'timecompleted', time() - 30, ['id' => $recent]);
        $old = $this->make_job($this->owner->id, 'failed');
        $DB->set_field('aiplacement_modgen_jobs', 'timecompleted', time() - 3600, ['id' => $old]);
        $others = $this->make_job($this->otheruser->id, 'completed');
        $DB->set_field('aiplacement_modgen_jobs', 'timecompleted', time() - 30, ['id' => $others]);

        $jobs = job_status_helper::get_recent_jobs($this->course->id, $this->owner->id);

        $this->assertSame([$recent], array_map(fn($job) => (int) $job['id'], $jobs));
    }

    /**
     * Listing a course's jobs needs the structure capability there.
     *
     * @covers ::get_active_jobs
     * @covers ::get_recent_jobs
     */
    public function test_job_lists_need_the_structure_capability(): void {
        $nobody = $this->getDataGenerator()->create_user();

        try {
            job_status_helper::get_active_jobs($this->course->id, $nobody->id);
            $this->fail('A user without the capability must not list active jobs.');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $this->expectException(\required_capability_exception::class);
        job_status_helper::get_recent_jobs($this->course->id, $nobody->id);
    }

    /**
     * Someone who can build from a file or a prompt can follow their own build, even without the
     * structure capability that Quick Add needs.
     *
     * @covers ::can_follow_jobs
     * @covers ::get_job_for_user
     * @covers ::get_active_jobs
     */
    public function test_generate_only_user_can_follow_their_own_jobs(): void {
        $context = \context_course::instance($this->course->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('aiplacement/modgen:generatefromtemplate', CAP_ALLOW, $roleid, $context->id);
        $builder = $this->getDataGenerator()->create_user();
        role_assign($roleid, $builder->id, $context->id);
        $jobid = $this->make_job($builder->id, 'running', time() - 60);

        $this->assertTrue(job_status_helper::can_follow_jobs($context, $builder->id));
        $this->assertEquals($jobid, job_status_helper::get_job_for_user($jobid, $builder->id)->id);
        $this->assertCount(1, job_status_helper::get_active_jobs($this->course->id, $builder->id));
        $this->assertFalse(job_status_helper::can_follow_jobs($context, $this->getDataGenerator()->create_user()->id));
    }
}
