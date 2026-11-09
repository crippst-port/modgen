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
use aiplacement_modgen\output\course_toolbar;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');

/**
 * Tests for the section reorder status icon in the course toolbar.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\output\course_toolbar
 */
final class course_toolbar_reorder_status_test extends \advanced_testcase {
    /** @var \stdClass Flexible sections course. */
    private $course;

    /**
     * Create a course with the placement switched on.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enabled', 1, 'aiplacement_modgen');

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->delete_records('task_adhoc');
    }

    /**
     * Render the toolbar fragment for the test course.
     *
     * @return string
     */
    private function fragment(): string {
        global $PAGE;
        $PAGE->set_url('/course/view.php', ['id' => $this->course->id]);
        $PAGE->set_context(\context_course::instance($this->course->id));
        return aiplacement_modgen_output_fragment_course_toolbar([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    public function test_export_includes_status_when_shown(): void {
        global $PAGE;
        $status = [
            'state' => 'queued', 'time' => time() + 60, 'label' => 'x', 'url' => null,
            'isqueued' => true, 'isrunning' => false, 'isrenumbered' => false, 'isblocked' => false,
        ];
        $toolbar = new course_toolbar($this->course->id, ['checkstructure' => true]);
        $toolbar->set_reorder_status($status);
        $data = $toolbar->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($data->showreorderstatus);
        $this->assertSame('queued', $data->reorderstatus['state']);
    }

    public function test_export_leaves_status_out_by_default(): void {
        global $PAGE;
        $toolbar = new course_toolbar($this->course->id, ['checkstructure' => true]);
        $data = $toolbar->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($data->showreorderstatus);
    }

    public function test_fragment_shows_status_region_for_admin(): void {
        section_reorder_queue::schedule($this->course->id);
        $html = $this->fragment();
        $this->assertStringContainsString('data-region="aiplacement-modgen-reorder-status"', $html);
        $this->assertStringContainsString('fa-clock', $html);
    }

    public function test_fragment_hides_status_for_teacher(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $coursecontextid = \context_course::instance($this->course->id)->id;
        assign_capability('aiplacement/modgen:checkstructure', CAP_ALLOW, $roleid, $coursecontextid);
        $this->setUser($teacher);
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $this->fragment());
    }

    public function test_fragment_hides_status_on_non_flexsections(): void {
        global $DB;
        $DB->set_field('course', 'format', 'topics', ['id' => $this->course->id]);
        \rebuild_course_cache($this->course->id, true);
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $this->fragment());
    }

    public function test_fragment_hides_status_when_switched_off(): void {
        section_reorder_queue::schedule($this->course->id);
        set_config('enable_autoreorder', 0, 'aiplacement_modgen');
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $this->fragment());
    }
}
