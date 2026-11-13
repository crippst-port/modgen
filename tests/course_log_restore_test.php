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

use aiplacement_modgen\event;

/**
 * A course whose log holds the plugin's events can be backed up with its logs and restored.
 *
 * Restoring course logs asks each event how to map its ids; an event that does not say raises
 * debugging, which fails this test.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\event\section_order_blocked
 * @covers \aiplacement_modgen\event\section_order_checked
 * @covers \aiplacement_modgen\event\section_renumber_failed
 * @covers \aiplacement_modgen\event\structure_repaired
 * @covers \aiplacement_modgen\event\structure_job_queued
 * @covers \aiplacement_modgen\event\structure_job_failed
 * @covers \aiplacement_modgen\event\structure_job_completed
 */
final class course_log_restore_test extends \advanced_testcase {
    /** @var string[] The events logged against the course whose object is the course itself. */
    private const COURSE_OBJECT_EVENTS = [
        event\section_order_blocked::class,
        event\section_order_checked::class,
        event\section_renumber_failed::class,
    ];

    public function test_course_logs_with_the_plugins_events_restore(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        // Restore keeps only log entries whose users are in the backup, so the events are by a teacher.
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $job = (object) ['id' => 42, 'courseid' => $course->id, 'userid' => $teacher->id, 'action' => 'create_weeks'];
        event\section_order_blocked::for_course($course->id, 'missing_parents')->trigger();
        event\section_order_checked::for_course($course->id)->trigger();
        event\section_renumber_failed::build($course->id, new \RuntimeException('Lock timed out'))->trigger();
        event\structure_repaired::for_course($course->id, 'fixintegrity', 2, 'admin_tools')->trigger();
        event\structure_job_queued::from_job($job)->trigger();
        event\structure_job_failed::from_job($job, ['willretry' => 0])->trigger();
        event\structure_job_completed::from_job($job)->trigger();
        $this->setAdminUser();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        // Logs are only backed up with users.
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('logs')->set_value(true);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_records('logstore_standard_log', ['courseid' => $newcourseid, 'component' => 'aiplacement_modgen']);
        $byevent = [];
        foreach ($restored as $row) {
            $byevent[ltrim($row->eventname, '\\')] = $row;
        }
        foreach (self::COURSE_OBJECT_EVENTS as $class) {
            $this->assertArrayHasKey($class, $byevent, "$class is restored.");
            $this->assertEquals($newcourseid, $byevent[$class]->objectid, "$class points at the restored course.");
        }
        $this->assertArrayHasKey(event\structure_repaired::class, $byevent);

        // Jobs are not backed up, so a restored job event records its job as not mapped, rather than
        // linking to whatever job has the original id on this site.
        $jobevents = [event\structure_job_queued::class, event\structure_job_failed::class, event\structure_job_completed::class];
        foreach ($jobevents as $class) {
            $this->assertArrayHasKey($class, $byevent, "$class is restored.");
            $this->assertEquals(\core\event\base::NOT_MAPPED, $byevent[$class]->objectid, "$class does not keep the job id.");
        }
    }
}
