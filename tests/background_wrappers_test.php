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

use aiplacement_modgen\local\section_reorder_observer;
use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\task\fail_orphaned_jobs_task;

/**
 * Tests for the scheduled task that fails lost jobs, and the renumber observer's guards.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\task\fail_orphaned_jobs_task
 * @covers \aiplacement_modgen\local\section_reorder_observer
 */
final class background_wrappers_test extends \advanced_testcase {
    /**
     * An administrator and an empty adhoc queue.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('task_adhoc');
    }

    /**
     * Run the orphaned jobs task and return what it printed.
     *
     * @return string
     */
    private function run_orphan_task(): string {
        ob_start();
        try {
            (new fail_orphaned_jobs_task())->execute();
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function test_the_task_is_registered_with_a_name(): void {
        $task = \core\task\manager::get_scheduled_task(fail_orphaned_jobs_task::class);

        $this->assertInstanceOf(fail_orphaned_jobs_task::class, $task);
        $this->assertSame(get_string('failorphanedjobs', 'aiplacement_modgen'), $task->get_name());
    }

    public function test_a_lost_job_is_failed_and_reported(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('aiplacement_modgen');
        $lost = $generator->create_job([
            'courseid' => $course->id, 'userid' => get_admin()->id, 'status' => 'running',
            'timecreated' => time() - HOURSECS, 'timestarted' => time() - HOURSECS,
        ]);
        $recent = $generator->create_job([
            'courseid' => $course->id, 'userid' => get_admin()->id, 'status' => 'running',
            'timecreated' => time(), 'timestarted' => time(),
        ]);

        $this->assertSame("Marked 1 interrupted job(s) as failed\n", $this->run_orphan_task());

        $this->assertSame('failed', $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $lost->id]));
        $recentstatus = $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $recent->id]);
        $this->assertSame('running', $recentstatus, 'A job still in its grace period is left alone.');
    }

    public function test_with_nothing_lost_the_task_says_nothing(): void {
        $this->assertSame('', $this->run_orphan_task());
    }

    public function test_a_section_change_on_the_site_page_queues_nothing(): void {
        $event = \core\event\course_section_created::create_from_section(\course_create_section(SITEID, 0));

        section_reorder_observer::section_changed($event);

        $this->assertSame([], section_reorder_queue::get_task_rows(SITEID));
    }

    public function test_a_course_that_has_gone_is_not_an_error_for_the_edit(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $event = event\structure_repaired::for_course($course->id, 'fixintegrity', 1, 'admin_tools');
        delete_course($course, false);

        section_reorder_observer::structure_repaired($event);

        $this->assertDebuggingCalled();
        $this->assertSame([], section_reorder_queue::get_task_rows($course->id));
    }
}
