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

namespace aiplacement_modgen;

use aiplacement_modgen\event\section_order_blocked;
use aiplacement_modgen\event\section_order_checked;
use aiplacement_modgen\event\section_renumber_failed;
use aiplacement_modgen\event\structure_repaired;
use aiplacement_modgen\local\integrity_checker;
use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\task\reorder_sections_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Task wrapper that makes the quiet-period guard callable from a test.
 */
class testable_reorder_sections_task extends reorder_sections_task {
    /**
     * Expose the guard.
     *
     * @param int $courseid Course ID
     * @param int $startrev cacherev the guard compares against
     * @return callable
     */
    public function guard(int $courseid, int $startrev): callable {
        return $this->beforewrite_guard($courseid, $startrev);
    }
}

/**
 * Tests for the ad hoc task that renumbers a course once it has been quiet.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\task\reorder_sections_task
 */
final class reorder_sections_task_test extends \advanced_testcase {
    /** @var \stdClass Flexible sections course. */
    private $course;

    /**
     * Create a Flexible sections course with an empty adhoc queue, logging edits to the standard log.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // The task reads the time of the course's last edit from the standard log.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->delete_records('task_adhoc');
    }

    /**
     * Make C a child of A, so it should sit before B.
     */
    private function make_misordered(): void {
        global $DB;
        $format = \course_get_format($this->course->id);
        $a = (int) theme_builder::create_theme_section($this->course->id, $format, 'A', 'Desc A');
        theme_builder::create_theme_section($this->course->id, $format, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $format, 'C', 'Desc C');
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);
        $DB->delete_records('task_adhoc');
    }

    /**
     * Pretend the last edit was a while ago, in the log and in the course cache revision.
     *
     * @param int $seconds How long ago
     */
    private function age_edits(int $seconds = 120): void {
        global $DB;
        $DB->set_field('course', 'cacherev', time() - $seconds, ['id' => $this->course->id]);
        $DB->set_field('logstore_standard_log', 'timecreated', time() - $seconds, ['courseid' => $this->course->id]);
    }

    /**
     * Run the task for the test course.
     */
    private function run_task(): void {
        reorder_sections_task::for_course($this->course->id)->execute();
    }

    /**
     * Get the events of a class from a sink.
     *
     * @param \phpunit_event_sink $sink Sink
     * @param string $class Event class
     * @return array
     */
    private function events_of($sink, string $class): array {
        return array_values(array_filter($sink->get_events(), fn($e) => $e instanceof $class));
    }

    public function test_misordered_course_is_renumbered_and_logged(): void {
        $this->make_misordered();
        $this->age_edits();
        $sink = $this->redirectEvents();
        $this->run_task();
        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
        $events = $this->events_of($sink, structure_repaired::class);
        $this->assertCount(1, $events);
        $this->assertSame('adhoctask', $events[0]->other['via']);
        $this->assertCount(0, $this->events_of($sink, section_order_checked::class));
        $this->assertSame('fixsectionorder', $events[0]->other['action']);
    }

    public function test_ordered_course_writes_nothing_and_logs_checked(): void {
        $this->age_edits();
        $sink = $this->redirectEvents();
        $this->run_task();
        $this->assertCount(0, $this->events_of($sink, structure_repaired::class));
        $this->assertCount(0, $this->events_of($sink, section_order_blocked::class));
        $events = $this->events_of($sink, section_order_checked::class);
        $this->assertCount(1, $events);
        $this->assertEquals($this->course->id, $events[0]->objectid);
    }

    public function test_broken_hierarchy_logs_blocked_and_writes_nothing(): void {
        global $DB;
        $format = \course_get_format($this->course->id);
        $a = (int) theme_builder::create_theme_section($this->course->id, $format, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $format, 'B', 'Desc B');
        $aid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $a]);
        $bid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $b]);
        $DB->set_field('course_format_options', 'value', (string) $b, ['sectionid' => $aid, 'name' => 'parent']);
        $DB->set_field('course_format_options', 'value', (string) $a, ['sectionid' => $bid, 'name' => 'parent']);
        $before = $DB->get_records_menu('course_sections', ['course' => $this->course->id], '', 'id, section');
        $this->age_edits();

        $sink = $this->redirectEvents();
        $this->run_task();

        $events = $this->events_of($sink, section_order_blocked::class);
        $this->assertCount(1, $events);
        $this->assertSame('brokenhierarchy', $events[0]->other['blockedby']);
        $this->assertEquals($before, $DB->get_records_menu('course_sections', ['course' => $this->course->id], '', 'id, section'));
    }

    public function test_recent_edit_defers(): void {
        $this->make_misordered();
        $this->run_task();
        $this->assertNotEmpty(integrity_checker::check_section_order($this->course->id)['misordered']);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_cacherev_ahead_of_the_clock_does_not_hold_back_a_quiet_course(): void {
        global $DB;
        $this->make_misordered();
        $this->age_edits();
        // A busy editing session leaves cacherev far ahead of the time; the log says the course is quiet.
        $DB->set_field('course', 'cacherev', time() + 1200, ['id' => $this->course->id]);
        $sink = $this->redirectEvents();
        $this->run_task();
        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
        $this->assertCount(1, $this->events_of($sink, structure_repaired::class));
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_reading_the_course_is_not_an_edit(): void {
        $this->make_misordered();
        $this->age_edits();
        \core\event\course_viewed::create(['context' => \context_course::instance($this->course->id)])->trigger();
        $this->run_task();
        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    public function test_without_the_standard_log_a_fresh_cacherev_defers(): void {
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->make_misordered();
        $this->age_edits();
        \rebuild_course_cache($this->course->id, true);
        $this->run_task();
        $this->assertNotEmpty(integrity_checker::check_section_order($this->course->id)['misordered']);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_without_the_standard_log_an_old_cacherev_runs(): void {
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->make_misordered();
        $this->age_edits();
        $this->run_task();
        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    public function test_beforewrite_guard_refuses_after_cacherev_changes(): void {
        global $DB;
        $this->age_edits();
        $start = (int) $DB->get_field('course', 'cacherev', ['id' => $this->course->id]);
        $guard = (new testable_reorder_sections_task())->guard($this->course->id, $start);
        $this->assertTrue($guard());
        \rebuild_course_cache($this->course->id, true);
        $this->assertFalse($guard());
    }

    public function test_lock_failure_requeues(): void {
        $this->age_edits();
        $task = new class extends reorder_sections_task {
            // phpcs:ignore moodle.Commenting.MissingDocblock.Missing
            protected function run_fix(int $courseid, callable $beforewrite): array {
                throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
            }
        };
        $task->set_custom_data((object) ['courseid' => $this->course->id]);
        $sink = $this->redirectEvents();
        $task->execute();
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
        $this->assertCount(0, $this->events_of($sink, section_renumber_failed::class));
    }

    public function test_other_failure_is_logged_not_thrown(): void {
        $this->age_edits();
        $task = new class extends reorder_sections_task {
            // phpcs:ignore moodle.Commenting.MissingDocblock.Missing
            protected function run_fix(int $courseid, callable $beforewrite): array {
                throw new \RuntimeException('boom');
            }
        };
        $task->set_custom_data((object) ['courseid' => $this->course->id]);
        $sink = $this->redirectEvents();
        $task->execute();
        $this->assertCount(1, $this->events_of($sink, section_renumber_failed::class));
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_deleted_course_is_ignored(): void {
        $id = $this->course->id;
        \delete_course($id, false);
        $sink = $this->redirectEvents();
        reorder_sections_task::for_course($id)->execute();
        $this->assertCount(0, $this->events_of($sink, structure_repaired::class));
        $this->assertCount(0, $this->events_of($sink, section_renumber_failed::class));
    }

    public function test_non_flexsections_course_is_ignored(): void {
        global $DB;
        $DB->set_field('course', 'format', 'topics', ['id' => $this->course->id]);
        \rebuild_course_cache($this->course->id, true);
        $this->age_edits();
        $sink = $this->redirectEvents();
        $this->run_task();
        $this->assertCount(0, $this->events_of($sink, structure_repaired::class));
        $this->assertCount(0, $this->events_of($sink, section_order_blocked::class));
    }

    public function test_switched_off_task_does_nothing(): void {
        $this->make_misordered();
        $this->age_edits();
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        $sink = $this->redirectEvents();
        $this->run_task();
        $this->assertNotEmpty(integrity_checker::check_section_order($this->course->id)['misordered']);
        $this->assertCount(0, $this->events_of($sink, structure_repaired::class));
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }
}
