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
use aiplacement_modgen\local\job_status_helper;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Every event the plugin raises can be logged and shown in the log report.
 *
 * Each event is built through its own factory, triggered, and checked for what the log report
 * reads from it: a name, a description naming the object, a link into the plugin, valid crud and
 * education level, and a refusal when the data it needs is missing.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\event\section_order_blocked
 * @covers \aiplacement_modgen\event\section_order_checked
 * @covers \aiplacement_modgen\event\section_renumber_failed
 * @covers \aiplacement_modgen\event\structure_job_completed
 * @covers \aiplacement_modgen\event\structure_job_failed
 * @covers \aiplacement_modgen\event\structure_job_queued
 * @covers \aiplacement_modgen\event\structure_repaired
 * @covers \aiplacement_modgen\event\template_created
 * @covers \aiplacement_modgen\event\template_deleted
 * @covers \aiplacement_modgen\event\template_moved
 * @covers \aiplacement_modgen\event\template_updated
 */
final class event_contract_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * Each event class, the key it needs in 'other', and the page its link opens.
     *
     * @return array
     */
    public static function event_provider(): array {
        return [
            'section_order_blocked' => [event\section_order_blocked::class, 'blockedby', 'check_structure.php'],
            'section_order_checked' => [event\section_order_checked::class, null, null],
            'section_renumber_failed' => [event\section_renumber_failed::class, 'reason', 'check_structure.php'],
            'structure_job_completed' => [event\structure_job_completed::class, 'action', 'job_status.php'],
            'structure_job_failed' => [event\structure_job_failed::class, 'action', 'job_status.php'],
            'structure_job_queued' => [event\structure_job_queued::class, 'action', 'job_status.php'],
            'structure_repaired' => [event\structure_repaired::class, 'action', 'check_structure.php'],
            'template_created' => [event\template_created::class, 'name', 'manage_templates.php'],
            'template_deleted' => [event\template_deleted::class, 'name', 'manage_templates.php'],
            'template_moved' => [event\template_moved::class, 'name', 'manage_templates.php'],
            'template_updated' => [event\template_updated::class, 'name', 'manage_templates.php'],
        ];
    }

    /**
     * Build an event of $class through its factory.
     *
     * @param string $class Event class.
     * @param \stdClass $course Course.
     * @param \stdClass $job Job record.
     * @return \core\event\base
     */
    private function build(string $class, \stdClass $course, \stdClass $job): \core\event\base {
        switch ($class) {
            case event\section_order_blocked::class:
                return event\section_order_blocked::for_course($course->id, 'missing_parents');
            case event\section_order_checked::class:
                return event\section_order_checked::for_course($course->id);
            case event\section_renumber_failed::class:
                return event\section_renumber_failed::build($course->id, new \RuntimeException('Lock timed out'));
            case event\structure_repaired::class:
                return event\structure_repaired::for_course($course->id, 'fixintegrity', 2);
            case event\structure_job_completed::class:
            case event\structure_job_failed::class:
            case event\structure_job_queued::class:
                return $class::from_job($job);
            default:
                return $class::for_template(7, 'Standard module');
        }
    }

    /**
     * The event triggers and gives the log report what it reads.
     *
     * @dataProvider event_provider
     * @param string $class Event class.
     * @param string|null $required Key that must be set in 'other'.
     * @param string|null $page Page the event links to, or null for none.
     */
    public function test_event_can_be_logged_and_reported(string $class, ?string $required, ?string $page): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $job = (object) ['id' => 42, 'courseid' => $course->id, 'userid' => get_admin()->id, 'action' => 'create_weeks'];

        $sink = $this->redirectEvents();
        $this->build($class, $course, $job)->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf($class, $event);

        $name = $class::get_name();
        $this->assertIsString($name);
        $this->assertStringNotContainsString('[[', $name, 'The name must be a real language string.');

        // The description names what the event is about: its object, or the course when it has none.
        $description = $event->get_description();
        $this->assertStringContainsString("'" . ($event->objectid ?? $event->courseid) . "'", $description);

        $url = $event->get_url();
        if ($page === null) {
            $this->assertNull($url);
        } else {
            $this->assertInstanceOf(\moodle_url::class, $url);
            $this->assertStringEndsWith('/ai/placement/modgen/' . $page, $url->get_path(false));
        }

        $this->assertContains($event->crud, ['c', 'r', 'u', 'd']);
        $this->assertContains(
            $event->edulevel,
            [\core\event\base::LEVEL_OTHER, \core\event\base::LEVEL_TEACHING, \core\event\base::LEVEL_PARTICIPATING]
        );
        if ($event->objectid !== null) {
            $this->assertNotEmpty($event->objecttable, 'An object id needs its table.');
        }
        // Course log restore asks every event for its mappings; core's defaults raise debugging.
        foreach (['get_objectid_mapping', 'get_other_mapping'] as $method) {
            $this->assertSame(
                $class,
                (new \ReflectionMethod($class, $method))->getDeclaringClass()->getName(),
                "$method must be declared for course log restore."
            );
        }
        $this->assertFalse($class::get_other_mapping(), 'Other data holds no ids.');
        if ($event->objecttable === 'course') {
            $this->assertSame(['db' => 'course', 'restore' => 'course'], $class::get_objectid_mapping());
        } else if ($event->objecttable === 'aiplacement_modgen_jobs') {
            // Jobs are not backed up: a restored log must not keep an id that is another job's there.
            $this->assertSame(
                ['db' => 'aiplacement_modgen_jobs', 'restore' => \core\event\base::NOT_MAPPED],
                $class::get_objectid_mapping()
            );
        } else {
            // Template events are logged in the system context, so they are never in a course backup.
            $this->assertFalse($class::get_objectid_mapping());
        }

        if ($required !== null) {
            $this->assertArrayHasKey($required, $event->other);
        }
    }

    /**
     * The events that need a value in 'other'.
     *
     * @return array
     */
    public static function required_data_provider(): array {
        return array_filter(self::event_provider(), fn($row) => $row[1] !== null);
    }

    /**
     * An event built without the data its description needs is refused.
     *
     * @dataProvider required_data_provider
     * @param string $class Event class.
     * @param string|null $required Key that must be set in 'other'.
     */
    public function test_event_without_its_data_is_refused(string $class, ?string $required): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = strpos($class, 'template_') !== false ? \context_system::instance() : \context_course::instance($course->id);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($required, '/') . '/');
        $class::create(['context' => $context, 'objectid' => $course->id, 'relateduserid' => 2, 'other' => []]);
    }

    public function test_course_events_for_a_deleted_course_are_not_built(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        delete_course($course, false);

        $this->assertNull(event\section_order_blocked::for_course($course->id, 'x'));
        $this->assertNull(event\section_order_checked::for_course($course->id));
        $this->assertNull(event\section_renumber_failed::build($course->id, new \Exception('x')));
    }

    public function test_a_long_renumber_failure_reason_is_shortened(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $event = event\section_renumber_failed::build($course->id, new \Exception(str_repeat('é', 5000)));

        $max = (new \ReflectionClassConstant(event\section_renumber_failed::class, 'REASON_MAX_LENGTH'))->getValue();
        $this->assertSame($max, \core_text::strlen($event->other['reason']));
    }

    /**
     * A finished job is logged once, against its owner, with how many messages and warnings it had.
     */
    public function test_a_completed_job_is_logged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $course = $this->flexsections_course();
        $sink = $this->redirectEvents();

        $jobid = job_status_helper::queue_creation_job($course->id, get_admin()->id, 'create_weeks', ['weekcount' => 2]);
        $this->run_jobs();

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof event\structure_job_completed));
        $this->assertCount(1, $events);
        $this->assertEquals($jobid, $events[0]->objectid);
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertEquals(get_admin()->id, $events[0]->relateduserid);
        $this->assertSame('create_weeks', $events[0]->other['action']);
        $this->assertArrayHasKey('messages', $events[0]->other);
        $this->assertArrayHasKey('warnings', $events[0]->other);
    }
}
