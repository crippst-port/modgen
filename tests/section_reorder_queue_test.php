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

use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\settings_helper;

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

    public function test_unsaved_setting_counts_as_on(): void {
        unset_config('enable_autoreorder', 'aiplacement_modgen');
        $this->assertTrue(settings_helper::is_autoreorder_enabled());
        \course_create_section($this->course->id, 0);
        $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
    }
}
