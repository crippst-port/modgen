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

use aiplacement_modgen\event\structure_job_failed;
use aiplacement_modgen\event\structure_job_queued;
use aiplacement_modgen\event\structure_repaired;
use aiplacement_modgen\event\template_created;
use aiplacement_modgen\event\template_deleted;
use aiplacement_modgen\event\template_moved;
use aiplacement_modgen\event\template_updated;
use aiplacement_modgen\local\job_status_helper;
use aiplacement_modgen\local\template_manager;

/**
 * Tests for the events the plugin raises, so user actions appear in the Moodle log.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\event\structure_job_queued
 * @covers \aiplacement_modgen\event\structure_job_failed
 * @covers \aiplacement_modgen\event\structure_repaired
 * @covers \aiplacement_modgen\local\template_manager
 */
final class events_test extends \advanced_testcase {
    /**
     * Queuing a job is logged against the course and the user, and links to the job page.
     */
    public function test_queuing_a_job_triggers_an_event(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);
        $sink = $this->redirectEvents();

        $jobid = job_status_helper::queue_creation_job($course->id, $user->id, 'create_weeks', ['weekcount' => 2]);

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof structure_job_queued));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($jobid, $event->objectid);
        $this->assertEquals($course->id, $event->courseid);
        $this->assertEquals($user->id, $event->userid);
        $this->assertSame('create_weeks', $event->other['action']);
        $this->assertStringContainsString('jobid=' . $jobid, $event->get_url()->out(false));
        $this->assertNotEmpty($event->get_name());
        $this->assertNotEmpty($event->get_description());
    }

    /**
     * Failing a lost job is logged against the job owner, not the cron user that ran the sweep.
     */
    public function test_failing_a_lost_job_triggers_an_event(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $jobid = $DB->insert_record('aiplacement_modgen_jobs', (object) [
            'courseid' => $course->id, 'userid' => $owner->id, 'action' => 'create_themes', 'status' => 'running',
            'parameters' => '{}', 'timecreated' => time() - 900, 'timestarted' => time() - 900,
        ]);
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        job_status_helper::fail_orphaned_running_jobs();

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof structure_job_failed));
        $this->assertCount(1, $events);
        $this->assertEquals($jobid, $events[0]->objectid);
        $this->assertEquals($owner->id, $events[0]->relateduserid);
        $this->assertEquals(0, $events[0]->other['willretry']);
    }

    /**
     * Template library changes are logged in the system context.
     */
    public function test_template_changes_trigger_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $makefile = fn(string $name) => get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance(get_admin()->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/', 'filename' => $name,
        ], "Theme:,One\n");
        $sink = $this->redirectEvents();

        $first = template_manager::create('First', 'd', $makefile('a.csv'));
        $second = template_manager::create('Second', 'd', $makefile('b.csv'));
        template_manager::update($first, 'First renamed', 'd');
        template_manager::reorder($second, 'up');
        template_manager::delete($first);

        $classes = array_map(fn($e) => get_class($e), $sink->get_events());
        $this->assertEquals(2, count(array_keys($classes, template_created::class)));
        $this->assertContains(template_updated::class, $classes);
        $this->assertContains(template_moved::class, $classes);
        $this->assertContains(template_deleted::class, $classes);

        $deleted = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof template_deleted))[0];
        $this->assertEquals($first, $deleted->objectid);
        $this->assertSame('First renamed', $deleted->other['name']);
        $this->assertEquals(\context_system::instance()->id, $deleted->contextid);
    }

    /**
     * A repair is logged against the course with what was run and how much it changed.
     */
    public function test_repair_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();

        structure_repaired::for_course($course->id, 'fixintegrity', 3)->trigger();

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertSame('fixintegrity', $events[0]->other['action']);
        $this->assertSame(3, $events[0]->other['changed']);
        $this->assertSame('check_structure', $events[0]->other['via']);
        $this->assertStringContainsString('check_structure.php', $events[0]->get_url()->out(false));
    }
}
