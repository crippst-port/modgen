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
 * Regressions for delayed creation, exhausted retries and summary preservation.
 *
 * @package aiplacement_modgen
 * @copyright 2026 University of Portsmouth
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use aiplacement_modgen\local\job_status_helper;
use aiplacement_modgen\local\quick_add_service;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/interleaving_lock_factory.php');

/**
 * Exercise actual builds and queue records, with deterministic lock interleavings.
 *
 * @covers \aiplacement_modgen\task\create_sections_task
 * @covers \aiplacement_modgen\local\theme_builder
 * @covers \aiplacement_modgen\local\section_creation_service
 * @covers \aiplacement_modgen\local\job_status_helper
 */
final class job_execution_regression_test extends \advanced_testcase {
    /**
     * Set up an isolated test database and an administrator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Queued jobs recheck the switches queueing needed, so open every route.
        set_config('enabled', 1, 'aiplacement_modgen');
        set_config('enable_quickadd', 1, 'aiplacement_modgen');
        set_config('enable_ai', 1, 'aiplacement_modgen');
        set_config('enable_structurefromfile', 1, 'aiplacement_modgen');
    }

    /**
     * Clear any callback left behind by a failed assertion.
     */
    protected function tearDown(): void {
        interleaving_lock_factory::$beforelock = null;
        parent::tearDown();
    }

    /**
     * Make a course with the initial sections already in place.
     *
     * @return \stdClass Course
     */
    private function course(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        theme_builder::prepare_course_for_build($course->id);
        return $course;
    }

    /**
     * Execute a queued task without writing cron output into the test report.
     *
     * @param int $jobid Job ID
     */
    private function execute_job(int $jobid): void {
        $task = job_status_helper::find_section_creation_task($jobid);
        $this->assertNotNull($task);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A retained task with no attempts remaining must not keep its job waiting.
     */
    public function test_exhausted_task_is_terminal(): void {
        global $DB, $USER;
        $course = $this->course();
        $id = job_status_helper::queue_creation_job($course->id, $USER->id, 'create_weeks', ['weekcount' => 1]);
        $DB->update_record('aiplacement_modgen_jobs', (object) [
            'id' => $id,
            'status' => 'failed',
            'timestarted' => time() - 600,
            'result' => json_encode(['error' => 'Lock timed out', 'will_retry' => true]),
        ]);
        $task = job_status_helper::find_section_creation_task($id);
        $DB->set_field('task_adhoc', 'attemptsavailable', 0, ['id' => $task->get_id()]);

        $this->assertSame(1, job_status_helper::fail_orphaned_running_jobs());
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $id]);
        $result = json_decode($job->result, true);
        $this->assertSame('failed', $job->status);
        $this->assertNotEmpty($job->timecompleted);
        $this->assertFalse($result['will_retry']);
        $this->assertSame('Lock timed out', $result['error']);
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $task->get_id()]));
        $this->assertSame(0, job_status_helper::fail_orphaned_running_jobs());
    }

    /**
     * Every build route must recheck capacity after another build takes the remaining space.
     *
     * @dataProvider build_routes
     * @param string $route Build route
     * @param int $projected Number of sections created by the route
     */
    public function test_section_limit_rechecked_after_wait(string $route, int $projected): void {
        global $DB, $CFG;
        $course = $this->course();
        $before = $DB->count_records('course_sections', ['course' => $course->id]);
        set_config('maxtotalsections', $before + $projected, 'aiplacement_modgen');
        $CFG->lock_factory = interleaving_lock_factory::class;
        interleaving_lock_factory::$beforelock = function () use ($course): void {
            theme_builder::create_weeks($course->id, 1, 0, false);
        };

        try {
            if ($route === 'themes') {
                theme_builder::create_themes($course->id, 1, 1, 0, false);
            } else if ($route === 'weeks') {
                theme_builder::create_weeks($course->id, 1, 0, false);
            } else {
                (new section_creation_service())->create_sections_from_json(
                    ['sections' => [['title' => 'Must not be created']]],
                    $course->id,
                    'connected_weekly',
                    false
                );
            }
            $this->fail('The second build must refuse to exceed the section limit.');
        } catch (\moodle_exception $e) {
            $this->assertSame('sectionlimitexceeded', $e->errorcode);
        }
        $this->assertSame($before + 4, $DB->count_records('course_sections', ['course' => $course->id]));
        // The rejection must also release its locks.
        $locks = theme_builder::acquire_structure_locks($course->id, 0);
        theme_builder::release_structure_locks($locks);
    }

    /**
     * Build routes and their projected counts.
     *
     * @return array
     */
    public static function build_routes(): array {
        return ['themes' => ['themes', 5], 'weeks' => ['weeks', 4], 'json' => ['json', 4]];
    }

    /**
     * A queued parent ID must not change identity while the task waits for its lock.
     *
     * @dataProvider parent_changes
     * @param bool $deleted Whether the parent is deleted rather than moved
     */
    public function test_parent_id_survives_wait_for_lock(bool $deleted): void {
        global $DB, $CFG, $USER;
        $course = $this->course();
        $format = course_get_format($course);
        $a = theme_builder::create_section_with_parent($course->id, $format, 0, 'Parent A', '', FORMAT_PLAIN);
        $b = theme_builder::create_section_with_parent($course->id, $format, 0, 'Parent B', '', FORMAT_PLAIN);
        $queued = quick_add_service::queue_weeks($course->id, $USER->id, 1, $a->section, false);
        $CFG->lock_factory = interleaving_lock_factory::class;
        interleaving_lock_factory::$beforelock = function () use ($format, $course, $a, $b, $deleted): void {
            if ($deleted) {
                $format->delete_section_with_children(get_fast_modinfo($course)->get_section_info_by_id($a->id));
            } else {
                $format->move_section($b->section, 0, $a->section);
            }
        };

        $this->execute_job($queued['jobid']);
        $week = $DB->get_record('course_sections', [
            'course' => $course->id,
            'name' => get_string('defaultstandaloneweekname', 'aiplacement_modgen', 1),
        ], '*', MUST_EXIST);
        $parent = $DB->get_field('course_format_options', 'value', ['sectionid' => $week->id, 'name' => 'parent']);
        if ($deleted) {
            $this->assertSame(0, (int) $parent);
        } else {
            $actual = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => $parent]);
            $this->assertSame((int) $a->id, (int) $actual);
        }
        $this->assertSame('completed', $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $queued['jobid']]));
    }

    /**
     * Changes to the parent while a task waits for its lock.
     *
     * @return array
     */
    public static function parent_changes(): array {
        return ['renumbered' => [false], 'deleted' => [true]];
    }

    /**
     * The summary must survive when its replacement activity is disabled or unavailable.
     *
     * @dataProvider summary_options
     * @param bool $requested Whether summary activities were requested
     * @param bool $available Whether the optional module is enabled
     */
    public function test_summary_survives_without_replacement(bool $requested, bool $available): void {
        global $DB;
        $course = $this->course();
        if (!$available) {
            $DB->set_field('modules', 'visible', 0, ['name' => 'learningactivity']);
        }
        (new section_creation_service())->create_sections_from_json([
            'sections' => [[
                'title' => 'My week', 'summary' => 'Summary shown in the preview',
                'learningactivity_metadata' => ['instructions' => 'Activity instructions'],
            ]],
        ], $course->id, 'connected_weekly', $requested);
        $summary = $DB->get_field('course_sections', 'summary', ['course' => $course->id, 'name' => 'My week']);
        $this->assertStringContainsString('Summary shown in the preview', $summary);
        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * Ways a summary activity can be omitted.
     *
     * @return array
     */
    public static function summary_options(): array {
        return ['not requested' => [false, true], 'module disabled' => [true, false]];
    }

    /**
     * A successfully created activity still replaces the week summary with its instructions.
     */
    public function test_created_activity_replaces_summary(): void {
        global $DB;
        if (!\aiplacement_modgen\activitytype\learningactivity::is_available()) {
            $this->markTestSkipped('mod_learningactivity is not available.');
        }
        $course = $this->course();
        (new section_creation_service())->create_sections_from_json([
            'sections' => [[
                'title' => 'My week', 'summary' => 'Summary shown in the preview',
                'learningactivity_metadata' => ['instructions' => 'Activity instructions'],
            ]],
        ], $course->id, 'connected_weekly', true);
        $week = $DB->get_record('course_sections', ['course' => $course->id, 'name' => 'My week'], '*', MUST_EXIST);
        $this->assertSame('', $week->summary);
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'learningactivity']);
        $cm = $DB->get_record('course_modules', ['section' => $week->id, 'module' => $moduleid], '*', MUST_EXIST);
        $instructions = $DB->get_field('learningactivity', 'instructions', ['id' => $cm->instance]);
        $this->assertStringContainsString('Activity instructions', $instructions);
    }

    /**
     * Instructions that validation reduces to nothing must not cost the week its summary.
     */
    public function test_markup_only_instructions_keep_summary(): void {
        global $DB;
        if (!\aiplacement_modgen\activitytype\learningactivity::is_available()) {
            $this->markTestSkipped('mod_learningactivity is not available.');
        }
        $course = $this->course();
        (new section_creation_service())->create_sections_from_json([
            'sections' => [[
                'title' => 'My week', 'summary' => 'Summary shown in the preview',
                'learningactivity_metadata' => ['instructions' => '<p> </p>'],
            ]],
        ], $course->id, 'connected_weekly', true);
        $week = $DB->get_record('course_sections', ['course' => $course->id, 'name' => 'My week'], '*', MUST_EXIST);
        $this->assertStringContainsString('Summary shown in the preview', $week->summary);
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'learningactivity']);
        $this->assertTrue($DB->record_exists('course_modules', ['section' => $week->id, 'module' => $moduleid]));
    }

    /**
     * A section that does not exist cannot be queued as a parent: the task would have only a number to go on.
     */
    public function test_unknown_parent_is_refused_when_queued(): void {
        global $DB, $USER;
        $course = $this->course();
        $missing = (int) $DB->get_field('course_sections', 'MAX(section)', ['course' => $course->id]) + 5;
        try {
            quick_add_service::queue_weeks($course->id, $USER->id, 1, $missing, false);
            $this->fail('An unknown parent section must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidparentsection', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('aiplacement_modgen_jobs'));
    }

    /**
     * A job queued with an id never falls back to its section number, which may name another section by now.
     */
    public function test_pinned_job_ignores_stale_parent_number(): void {
        global $DB, $USER;
        $course = $this->course();
        $other = theme_builder::create_section_with_parent($course->id, course_get_format($course), 0, 'Other', '', FORMAT_PLAIN);
        $jobid = job_status_helper::queue_creation_job($course->id, $USER->id, 'create_weeks', [
            'weekcount' => 1,
            'parentsection' => $other->section,
            'parentsectionid' => null,
            'createsummaryactivities' => false,
        ]);

        $this->execute_job($jobid);
        $week = $DB->get_record('course_sections', [
            'course' => $course->id,
            'name' => get_string('defaultstandaloneweekname', 'aiplacement_modgen', 1),
        ], '*', MUST_EXIST);
        $parent = $DB->get_field('course_format_options', 'value', ['sectionid' => $week->id, 'name' => 'parent']);
        $this->assertSame(0, (int) $parent);
        $this->assertSame('completed', $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $jobid]));
    }

    /**
     * A queued job is refused if its owner lost course access, or its route was switched off, since queueing.
     *
     * @dataProvider revoked_access
     * @param string $change What changes after queueing
     */
    public function test_queued_job_rechecks_access_and_switches(string $change): void {
        global $DB;
        $course = $this->course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);
        $jobid = job_status_helper::queue_creation_job($course->id, $user->id, 'create_weeks', [
            'weekcount' => 1, 'createsummaryactivities' => false,
        ]);

        if ($change === 'suspended') {
            $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $user->id]);
        } else {
            set_config('enable_quickadd', 0, 'aiplacement_modgen');
        }
        accesslib_clear_all_caches_for_unit_testing();

        $before = $DB->count_records('course_sections', ['course' => $course->id]);
        $this->execute_job($jobid);
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $course->id]));
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);
        $this->assertSame('failed', $job->status);
        $this->assertFalse(json_decode($job->result, true)['will_retry']);
        $this->assertDebuggingCalled();
    }

    /**
     * Changes after queueing that must stop a job.
     *
     * @return array
     */
    public static function revoked_access(): array {
        return ['enrolment suspended' => ['suspended'], 'quick add switched off' => ['quickadd']];
    }

    /**
     * Setting up a new course adds no sections of its own, so a build that exactly fills the limit goes ahead.
     */
    public function test_set_up_adds_no_sections_beyond_the_build(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $before = $DB->count_records('course_sections', ['course' => $course->id]);
        $projected = theme_builder::projected_sections_for_weeks(1);
        set_config('maxtotalsections', $before + $projected, 'aiplacement_modgen');

        theme_builder::create_weeks($course->id, 1, 0, false);

        $this->assertSame($before + $projected, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * Each route checks its own permission again, even if the user retains another build permission.
     *
     * @dataProvider job_permissions
     * @param string $action Job action
     * @param string $capability Permission to revoke
     * @param string $othercapability Unrelated build permission retained
     */
    public function test_revoked_builder_cannot_run_queued_job(
        string $action,
        string $capability,
        string $othercapability
    ): void {
        global $DB;
        $course = $this->course();
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($course->id);
        assign_capability($capability, CAP_ALLOW, $role, $context);
        assign_capability($othercapability, CAP_ALLOW, $role, $context);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        $this->setUser($user);
        $parameters = [
            'weekcount' => 1, 'themecount' => 1, 'weeksperTheme' => 1,
            'json' => ['sections' => [['title' => 'Generated week']]],
            'moduletype' => 'connected_weekly', 'createsummaryactivities' => false,
        ];
        // First prove the route succeeds with just its own permission and the unrelated one.
        $allowedid = job_status_helper::queue_creation_job($course->id, $user->id, $action, $parameters);
        $this->execute_job($allowedid);
        $this->assertSame('completed', $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $allowedid]));

        $jobid = job_status_helper::queue_creation_job($course->id, $user->id, $action, $parameters);
        unassign_capability($capability, $role);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability($capability, $context));
        $before = $DB->count_records('course_sections', ['course' => $course->id]);
        $this->execute_job($jobid);
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $course->id]));
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid]);
        $this->assertSame('failed', $job->status);
        $this->assertNotEmpty($job->timecompleted);
        $this->assertFalse(json_decode($job->result, true)['will_retry']);
        $this->assertDebuggingCalled();
    }

    /**
     * The permissions accepted by Quick Add and the two JSON generation routes.
     *
     * @return array
     */
    public static function job_permissions(): array {
        $manage = 'aiplacement/modgen:managestructure';
        $prompt = 'aiplacement/modgen:generatewithprompt';
        $file = 'aiplacement/modgen:generatefromtemplate';
        return [
            'weeks' => ['create_weeks', $manage, $file],
            'themes' => ['create_themes', $manage, $prompt],
            'file' => ['create_from_json', $file, $manage],
            'prompt' => ['create_from_json', $prompt, $manage],
        ];
    }
}
