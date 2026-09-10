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

/**
 * Unit tests for the logged post-build renumber failure.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\event\section_renumber_failed;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests that an abandoned renumber is reported rather than lost.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\event\section_renumber_failed
 */
final class section_renumber_failed_event_test extends advanced_testcase {
    /**
     * A failed renumber logs an event naming the course that needs repairing.
     *
     * The failure is induced by removing the course row while its sections and context
     * remain: check_section_order()'s MUST_EXIST lookup then throws from inside
     * fix_section_order(), which is the shape of every real failure here - something goes
     * wrong after the content has already been committed.
     *
     * @covers ::build
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_failed_renumber_is_logged(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseid = (int) $course->id;

        // Warm the context cache before the course row goes, so building the event can still
        // resolve a course context afterwards.
        \context_course::instance($courseid);
        $DB->delete_records('course', ['id' => $courseid]);

        $sink = $this->redirectEvents();
        theme_builder::renumber_into_hierarchy_order($courseid);
        $events = $sink->get_events();
        $sink->close();

        // The developer-mode message is kept alongside the event, not replaced by it.
        $this->assertDebuggingCalledCount(1);

        $logged = array_values(array_filter(
            $events,
            fn($e) => $e instanceof section_renumber_failed
        ));

        $this->assertCount(1, $logged, 'A failed renumber must log exactly one event.');
        $this->assertSame($courseid, (int) $logged[0]->objectid);
        $this->assertSame(\context_course::instance($courseid)->id, (int) $logged[0]->contextid);
        $this->assertNotEmpty($logged[0]->other['reason']);
        $this->assertStringContainsString((string) $courseid, $logged[0]->get_description());
        $this->assertStringContainsString(
            'check_structure.php',
            $logged[0]->get_url()->out(false)
        );
    }

    /**
     * A successful renumber logs nothing: the event means "this course needs repairing".
     *
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_successful_renumber_logs_nothing(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);

        $sink = $this->redirectEvents();
        theme_builder::renumber_into_hierarchy_order($course->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([], array_values(array_filter(
            $events,
            fn($e) => $e instanceof section_renumber_failed
        )));
    }

    /**
     * Reporting a failure never becomes one, even when the course context has gone too.
     *
     * @covers ::build
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_reporting_survives_a_missing_context(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // A course id that never existed: neither the renumber nor the reporting of its
        // failure may throw out of a method the build calls after committing.
        theme_builder::renumber_into_hierarchy_order(-1);

        // Only the failure itself is reported: build() returning null is a handled outcome,
        // not a second failure to announce.
        $this->assertDebuggingCalledCount(1);

        $this->assertNull(section_renumber_failed::build(-1, new \Exception('no course')));
    }
}
