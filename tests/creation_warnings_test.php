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

use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\structure_write_failed_exception;
use aiplacement_modgen\local\theme_builder;

/**
 * Tests for how creation problems are handled and shown.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\section_creation_service
 */
final class creation_warnings_test extends \advanced_testcase {
    /**
     * Database errors abort the build; other failures only become warnings.
     */
    public function test_only_non_database_errors_are_recoverable(): void {
        $method = new \ReflectionMethod(section_creation_service::class, 'rethrow_unless_recoverable');
        $method->setAccessible(true);

        // A non-database failure is recoverable: nothing is thrown.
        $method->invoke(null, new \moodle_exception('invalidaction', 'aiplacement_modgen'));
        $method->invoke(null, new \RuntimeException('boom'));
        $this->addToAssertionCount(2);

        // A section write that rolled back its own transaction is not either.
        try {
            $method->invoke(null, new structure_write_failed_exception('Week 1'));
            $this->fail('A failed section write must abort the build.');
        } catch (structure_write_failed_exception $e) {
            $this->addToAssertionCount(1);
        }

        // A database error is not: it propagates so the transaction rolls back.
        $this->expectException(\dml_exception::class);
        $method->invoke(null, new \dml_write_exception('failed'));
    }

    /**
     * A section write that fails part way inside a build reports a failure that ends the build,
     * because its rollback has already doomed the build's own transaction. A problem found before
     * anything is written stays an ordinary, recoverable error and leaves the build committable.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_failed_write_inside_a_build_is_fatal_but_validation_is_not(): void {
        global $DB;
        $this->resetAfterTest();
        // The test needs real transactions to roll back, not the one PHPUnit wraps each test in.
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $format = course_get_format($course);

        $outer = $DB->start_delegated_transaction();

        // Found before writing: the parent does not exist.
        try {
            theme_builder::create_section_with_parent($course->id, $format, 999, 'Orphan', '', FORMAT_HTML, [], false);
            $this->fail('A missing parent must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertNotInstanceOf(structure_write_failed_exception::class, $e);
        }

        // The build can carry on: a later section is created and the whole thing commits.
        theme_builder::create_section_with_parent($course->id, $format, 0, 'Week 1', '', FORMAT_HTML, [], false);
        $outer->allow_commit();
        $this->assertTrue($DB->record_exists('course_sections', ['course' => $course->id, 'name' => 'Week 1']));

        // Failing part way, after the section row has been written.
        $failingformat = new class ($format) {
            /** @var \core_courseformat\base The real format. */
            private $format;

            /**
             * Constructor.
             *
             * @param \core_courseformat\base $format The real format.
             */
            public function __construct($format) {
                $this->format = $format;
            }

            /**
             * Pass through to the real format.
             *
             * @param string $name Method name.
             * @param array $args Arguments.
             * @return mixed
             */
            public function __call($name, $args) {
                return $this->format->$name(...$args);
            }

            /**
             * Create the section for real, so there is something to roll back.
             *
             * @param int $parent Parent section number.
             * @param int|null $before Section to insert before.
             * @return int
             */
            public function create_new_section($parent, $before) {
                return $this->format->create_new_section($parent, $before);
            }

            /**
             * Fail after the section row exists.
             *
             * @param array $data Format options.
             */
            public function update_section_format_options($data) {
                throw new \moodle_exception('invalidaction', 'aiplacement_modgen');
            }
        };

        $outer = $DB->start_delegated_transaction();
        try {
            theme_builder::create_section_with_parent($course->id, $failingformat, 0, 'Week 2', '', FORMAT_HTML, [], false);
            $this->fail('A write that fails part way must be reported.');
        } catch (structure_write_failed_exception $e) {
            $this->assertSame('sectorcreationfailed', $e->errorcode);
            $this->assertDebuggingCalled();
        }
        theme_builder::rollback_quietly($outer, $e);
        $this->assertFalse($DB->record_exists('course_sections', ['course' => $course->id, 'name' => 'Week 2']));
    }

    /**
     * A job that finished with problems shows them, and does not look like a clean success.
     */
    public function test_job_status_template_shows_warnings(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');
        $output = $PAGE->get_renderer('core');

        $base = [
            'jobid' => 1,
            'isqueued' => false,
            'isrunning' => false,
            'iscompleted' => true,
            'isfailed' => false,
            'messages' => [['text' => 'Section created: Week 1']],
        ];

        $withwarnings = $output->render_from_template(
            'aiplacement_modgen/job_status_page',
            $base + ['haswarnings' => true, 'warnings' => [['text' => 'The week "Week 2" could not be created: boom']]]
        );
        $this->assertStringContainsString(get_string('jobcompletedwarnings', 'aiplacement_modgen'), $withwarnings);
        $this->assertStringContainsString('could not be created: boom', $withwarnings);
        $this->assertStringContainsString('Section created: Week 1', $withwarnings);
        $this->assertStringNotContainsString(get_string('jobstatus_completed', 'aiplacement_modgen'), $withwarnings);

        $clean = $output->render_from_template('aiplacement_modgen/job_status_page', $base);
        $this->assertStringContainsString(get_string('jobstatus_completed', 'aiplacement_modgen'), $clean);
        $this->assertStringNotContainsString(get_string('jobcompletedwarnings', 'aiplacement_modgen'), $clean);
    }
}
