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

use aiplacement_modgen\local\structure_write_failed_exception;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * New Theme(s) builds that fail part way leave nothing behind.
 *
 * The failure is injected by an event observer that throws an Error when a chosen section is
 * created, as a broken format plugin would (the event manager swallows Exceptions from observers,
 * not Errors). New Week(s) builds are covered in every format by core_format_build_test.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\theme_builder
 */
final class build_failure_rollback_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var int Sections created since the observer was armed. */
    public static int $created = 0;

    /** @var int The section whose creation fails (1 = the first one created, 0 = none). */
    public static int $failat = 0;

    /**
     * Observer: count section creations and fail the chosen one.
     *
     * @param \core\event\course_section_created $event Event.
     */
    public static function section_created(\core\event\course_section_created $event): void {
        self::$created++;
        if (self::$created === self::$failat) {
            throw new \Error('Injected failure creating section ' . self::$created);
        }
    }

    /**
     * An administrator and the failing observer.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Reset by rollback would wrap the test in a transaction, and the build's rollback is what is tested.
        $this->preventResetByRollback();
        $this->setAdminUser();
        self::$created = 0;
        self::$failat = 0;
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\course_section_created',
            'callback' => self::class . '::section_created',
            'internal' => true,
        ]]);
    }

    /**
     * Where a two-theme build can fail: the first theme, a session of its week, the second theme.
     *
     * @return array
     */
    public static function failure_point_provider(): array {
        return [
            'first theme' => [1],
            'a session in the first week' => [4],
            'second theme' => [6],
        ];
    }

    /**
     * Wherever it fails, the build is rolled back, reported as a write failure the task retries,
     * leaves no transaction open, and releases its locks.
     *
     * @dataProvider failure_point_provider
     * @param int $failat Section creation that fails.
     */
    public function test_a_failed_theme_build_rolls_back_completely(int $failat): void {
        global $DB;
        $course = $this->flexsections_course();
        $before = \aiplacement_modgen_structure_snapshot::rows($course->id);
        self::$created = 0; // Creating the course counted its section 0.
        self::$failat = $failat;

        try {
            theme_builder::create_themes($course->id, 2, 1, 0);
            $this->fail('The injected failure must fail the build.');
        } catch (structure_write_failed_exception $e) {
            $this->assertStringContainsString('Injected failure creating section ' . $failat, $e->debuginfo);
            $this->assertTrue(theme_builder::is_transient_failure($e), 'The task retries it.');
        }
        $this->resetDebugging();

        $this->assertSame($failat, self::$created, 'Nothing more was attempted after the failure.');
        $this->assertSame($before, \aiplacement_modgen_structure_snapshot::rows($course->id), 'Nothing was kept.');
        $this->assertFalse($DB->is_transaction_started(), 'The build\'s transaction was closed.');

        self::$failat = 0;
        $this->assertTrue(theme_builder::create_themes($course->id, 1, 1, 0)['success'], 'The locks were released.');
        $this->assert_sound($course->id);
    }

    /**
     * Inside a caller's transaction, a failed build leaves that transaction for the caller to end.
     */
    public function test_inside_a_callers_transaction_the_caller_ends_it(): void {
        global $DB;
        $course = $this->flexsections_course();
        self::$created = 0;
        self::$failat = 2;

        $outer = $DB->start_delegated_transaction();
        try {
            theme_builder::create_themes($course->id, 1, 1, 0);
            $this->fail('The injected failure must fail the build.');
        } catch (structure_write_failed_exception $e) {
            $this->assertSame('sectorcreationfailed', $e->errorcode);
        }
        $this->resetDebugging();

        $this->assertTrue($DB->is_transaction_started(), 'The caller\'s transaction is still open.');
        try {
            $outer->rollback(new \Exception('The caller ends it'));
        } catch (\Exception $ignored) {
            // Rolling back rethrows the reason.
            $this->assertSame('The caller ends it', $ignored->getMessage());
        }
        $this->assertFalse($DB->is_transaction_started());
    }
}
