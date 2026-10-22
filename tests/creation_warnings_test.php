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

        // A database error is not: it propagates so the transaction rolls back.
        $this->expectException(\dml_exception::class);
        $method->invoke(null, new \dml_write_exception('failed'));
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
