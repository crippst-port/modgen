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
use aiplacement_modgen\event\structure_repaired;
use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\section_reorder_status;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for the section reorder status lookup behind the assistant bar icon.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\local\section_reorder_status
 */
final class section_reorder_status_test extends \advanced_testcase {
    /** @var \stdClass Flexible sections course. */
    private $course;

    /**
     * Create a course, with the standard log writing straight to the database.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->delete_records('task_adhoc');
        $DB->delete_records('logstore_standard_log');
    }

    /**
     * Log a renumber outcome.
     *
     * @param string $action structure_repaired action
     * @param int $changed Sections changed
     * @param string $via Where it ran from
     */
    private function log_repair(string $action = 'fixsectionorder', int $changed = 3, string $via = 'adhoctask'): void {
        structure_repaired::for_course($this->course->id, $action, $changed, $via)->trigger();
    }

    public function test_nothing_to_report_is_null(): void {
        $this->assertNull(section_reorder_status::get_for_course($this->course->id));
    }

    public function test_queued(): void {
        section_reorder_queue::schedule($this->course->id);
        $row = array_values(section_reorder_queue::get_task_rows($this->course->id))[0];
        $status = section_reorder_status::get_for_course($this->course->id);
        $this->assertSame('queued', $status['state']);
        $this->assertEquals($row->nextruntime, $status['time']);
        $this->assertTrue($status['isqueued']);
        $this->assertStringContainsString(userdate($status['time'], get_string('strftimetime', 'langconfig')), $status['label']);
    }

    public function test_running_beats_queued(): void {
        global $DB;
        section_reorder_queue::schedule($this->course->id);
        $row = array_values(section_reorder_queue::get_task_rows($this->course->id))[0];
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $row->id]);
        section_reorder_queue::schedule($this->course->id);
        $this->assertSame('running', section_reorder_status::get_for_course($this->course->id)['state']);
    }

    public function test_renumbered_from_task(): void {
        $this->log_repair();
        $this->assertSame('renumbered', section_reorder_status::get_for_course($this->course->id)['state']);
    }

    public function test_renumbered_from_check_structure_counts(): void {
        $this->log_repair('fixsectionorder', 2, 'check_structure');
        $this->assertSame('renumbered', section_reorder_status::get_for_course($this->course->id)['state']);
    }

    public function test_zero_change_repair_is_ignored(): void {
        $this->log_repair('fixsectionorder', 0);
        $this->assertNull(section_reorder_status::get_for_course($this->course->id));
    }

    public function test_other_repair_action_is_ignored(): void {
        $this->log_repair('fixcircular');
        $this->assertNull(section_reorder_status::get_for_course($this->course->id));
    }

    public function test_renumbered_expires_after_an_hour(): void {
        global $DB;
        $this->log_repair();
        $DB->set_field('logstore_standard_log', 'timecreated', time() - 3601);
        $this->assertNull(section_reorder_status::get_for_course($this->course->id));
    }

    public function test_blocked(): void {
        section_order_blocked::for_course($this->course->id, 'brokenhierarchy')->trigger();
        $status = section_reorder_status::get_for_course($this->course->id);
        $this->assertSame('blocked', $status['state']);
        $this->assertStringContainsString('check_structure.php', $status['url']);
        $this->assertTrue($status['isblocked']);
    }

    public function test_newer_renumber_clears_blocked(): void {
        global $DB;
        section_order_blocked::for_course($this->course->id, 'brokenhierarchy')->trigger();
        $DB->set_field('logstore_standard_log', 'timecreated', time() - 10);
        $this->log_repair();
        $this->assertSame('renumbered', section_reorder_status::get_for_course($this->course->id)['state']);
    }

    public function test_queued_beats_blocked(): void {
        section_order_blocked::for_course($this->course->id, 'brokenhierarchy')->trigger();
        section_reorder_queue::schedule($this->course->id);
        $this->assertSame('queued', section_reorder_status::get_for_course($this->course->id)['state']);
    }

    public function test_admin_can_view(): void {
        $this->assertTrue(section_reorder_status::can_view());
    }

    public function test_teacher_cannot_view(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertFalse(section_reorder_status::can_view());
    }

    public function test_nobody_can_view_when_switched_off(): void {
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        $this->assertFalse(section_reorder_status::can_view());
    }
}
