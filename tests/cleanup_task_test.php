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

use aiplacement_modgen\task\cleanup_old_jobs_task;

/**
 * Tests for the scheduled cleanup of old jobs and stale AI-generated markers.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\task\cleanup_old_jobs_task
 */
final class cleanup_task_test extends \advanced_testcase {
    /**
     * Markers for deleted activities are removed; markers for live ones are kept.
     */
    public function test_stale_ai_markers_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $DB->insert_record('aiplacement_modgen_aigen', (object) [
            'cmid' => $page->cmid, 'courseid' => $course->id, 'timecreated' => time(),
        ]);
        $DB->insert_record('aiplacement_modgen_aigen', (object) [
            'cmid' => 987654, 'courseid' => $course->id, 'timecreated' => time(),
        ]);

        ob_start();
        (new cleanup_old_jobs_task())->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('aiplacement_modgen_aigen', ['cmid' => $page->cmid]));
        $this->assertFalse($DB->record_exists('aiplacement_modgen_aigen', ['cmid' => 987654]));
    }
}
