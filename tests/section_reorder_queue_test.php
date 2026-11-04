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

use aiplacement_modgen\event\section_order_checked;
use aiplacement_modgen\event\structure_repaired;
use aiplacement_modgen\local\integrity_checker;
use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\settings_helper;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for the observer and queue that schedule the automatic section renumber.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\local\section_reorder_queue
 * @covers     \aiplacement_modgen\local\section_reorder_observer
 */
final class section_reorder_queue_test extends \advanced_testcase {
    /** @var \stdClass Flexible sections course. */
    private $course;

    /**
     * Create a Flexible sections course with an empty adhoc queue.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        // The observers are not internal, so they only run outside the transaction a test is normally wrapped in.
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->delete_records('task_adhoc');
    }

    public function test_section_create_queues_one_delayed_task(): void {
        $before = time();
        \course_create_section($this->course->id, 0);
        $rows = section_reorder_queue::get_task_rows($this->course->id);
        $this->assertCount(1, $rows);
        $row = array_values($rows)[0];
        $this->assertEmpty($row->userid);
        $this->assertGreaterThanOrEqual($before + 60, (int) $row->nextruntime);
        $this->assertLessThanOrEqual(time() + 60, (int) $row->nextruntime);
    }

    public function test_burst_of_creates_leaves_one_task_moved_later(): void {
        global $DB;
        \course_create_section($this->course->id, 0);
        $row = array_values(section_reorder_queue::get_task_rows($this->course->id))[0];
        $DB->set_field('task_adhoc', 'nextruntime', time() + 5, ['id' => $row->id]);
        \course_create_section($this->course->id, 0);
        \course_create_section($this->course->id, 0);
        $rows = section_reorder_queue::get_task_rows($this->course->id);
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(time() + 50, (int) array_values($rows)[0]->nextruntime);
    }

    public function test_section_delete_queues_task(): void {
        global $DB;
        $num = \course_create_section($this->course->id, 0)->section;
        $DB->delete_records('task_adhoc');
        \course_delete_section($this->course, $num, true);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_two_editors_share_one_task(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        \course_create_section($this->course->id, 0);
        $this->setUser($teacher);
        \course_create_section($this->course->id, 0);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_other_formats_queue_nothing(): void {
        global $DB;
        $topics = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $DB->delete_records('task_adhoc');
        \course_create_section($topics->id, 0);
        $this->assertCount(0, section_reorder_queue::get_task_rows($topics->id));
    }

    public function test_schedule_ignores_started_row(): void {
        global $DB;
        section_reorder_queue::schedule($this->course->id);
        $started = array_values(section_reorder_queue::get_task_rows($this->course->id))[0];
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $started->id]);
        section_reorder_queue::schedule($this->course->id);
        section_reorder_queue::schedule($this->course->id, 120);
        $rows = array_values(section_reorder_queue::get_task_rows($this->course->id));
        $this->assertCount(2, $rows);
        $this->assertEquals($started->nextruntime, $DB->get_field('task_adhoc', 'nextruntime', ['id' => $started->id]));
        $this->assertGreaterThanOrEqual(time() + 119, (int) $rows[1]->nextruntime);
    }

    public function test_switched_off_queues_nothing(): void {
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        \course_create_section($this->course->id, 0);
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    /**
     * Repairs that change parents.
     *
     * @return array
     */
    public static function parent_repair_provider(): array {
        return [
            'setparent' => ['setparent'],
            'setparentall' => ['setparentall'],
            'fixintegrity' => ['fixintegrity'],
            'fixcircular' => ['fixcircular'],
        ];
    }

    /**
     * @dataProvider parent_repair_provider
     * @param string $action Repair action
     */
    public function test_repair_from_admin_tools_queues_task(string $action): void {
        \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, $action, 1, 'admin_tools')->trigger();
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    /**
     * Check Structure renumbers straight after its repairs, so nothing is queued for them.
     *
     * @dataProvider parent_repair_provider
     * @param string $action Repair action
     */
    public function test_repair_on_check_structure_queues_nothing(string $action): void {
        \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, $action, 1, 'check_structure')->trigger();
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_repairs_one_at_a_time_share_one_task(): void {
        foreach ([1, 2, 3] as $ignored) {
            \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, 'fixintegrity', 1, 'admin_tools')->trigger();
        }
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_renumber_does_not_queue_another(): void {
        \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, 'fixsectionorder', 3, 'admin_tools')->trigger();
        \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, 'fixsectionorder', 3, 'adhoctask')
            ->trigger();
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_switched_off_repair_queues_nothing(): void {
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        \aiplacement_modgen\event\structure_repaired::for_course($this->course->id, 'fixintegrity', 1, 'admin_tools')->trigger();
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    /**
     * Make C a child of A, so it should sit before B.
     *
     * @return int[] Section ids of A, B and C
     */
    private function make_misordered(): array {
        global $DB;
        $format = \course_get_format($this->course->id);
        $a = (int) theme_builder::create_theme_section($this->course->id, $format, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $format, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $format, 'C', 'Desc C');
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);
        $DB->delete_records('task_adhoc');
        return array_map(
            fn($num) => (int) $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $num]),
            [$a, $b, $c]
        );
    }

    public function test_renumber_now_renumbers_logs_and_says_what_moved(): void {
        global $DB;
        [$aid, $bid, $cid] = $this->make_misordered();
        $oldb = (int) $DB->get_field('course_sections', 'section', ['id' => $bid]);
        $oldc = (int) $DB->get_field('course_sections', 'section', ['id' => $cid]);
        $sink = $this->redirectEvents();

        $moved = section_reorder_queue::renumber_now($this->course->id);

        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
        $this->assertSame([$oldb => $oldc, $oldc => $oldb], $moved);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof structure_repaired));
        $this->assertCount(1, $events);
        $this->assertSame('fixsectionorder', $events[0]->other['action']);
        $this->assertSame('check_structure', $events[0]->other['via']);
        $this->assertCount(0, section_reorder_queue::get_task_rows($this->course->id));
    }

    public function test_renumber_now_on_an_ordered_course_logs_checked(): void {
        $sink = $this->redirectEvents();
        $this->assertSame([], section_reorder_queue::renumber_now($this->course->id));
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof section_order_checked));
        $this->assertCount(1, $events);
    }

    /**
     * The icon reads the outcome from the log, so it must be there before the process ends: a cron
     * process kept alive between tasks would otherwise show the run before for up to a minute.
     */
    public function test_the_outcome_is_in_the_log_straight_away(): void {
        global $DB;
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 50, 'logstore_standard');
        get_log_manager(true);

        section_reorder_queue::record_outcome(
            $this->course->id,
            ['fixed' => 0, 'checkable' => true, 'blockedby' => null, 'deferred' => false, 'moved' => []],
            'adhoctask'
        );

        $this->assertTrue($DB->record_exists('logstore_standard_log', [
            'courseid' => $this->course->id,
            'eventname' => '\\aiplacement_modgen\\event\\section_order_checked',
        ]));
    }

    public function test_renumber_now_switched_off_does_nothing(): void {
        $this->make_misordered();
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        $this->assertSame([], section_reorder_queue::renumber_now($this->course->id));
        $this->assertNotEmpty(integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    public function test_unsaved_setting_counts_as_on(): void {
        unset_config('enable_autoreorder', 'aiplacement_modgen');
        $this->assertTrue(settings_helper::is_autoreorder_enabled());
        \course_create_section($this->course->id, 0);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }
}
