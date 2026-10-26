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
 * Tests that the structure repairs only act on Flexible sections courses.
 *
 * The repairs write Flexible sections parent links and call its API, so on another format
 * they would fatal or write options that format does not have.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\integrity_checker;

/**
 * Tests for the format guard on the integrity repairs.
 *
 * @covers \aiplacement_modgen\local\integrity_checker
 */
final class repair_guards_test extends advanced_testcase {
    /**
     * Each repair, as a callable taking the course id.
     *
     * @return array
     */
    public static function repair_provider(): array {
        return [
            'fix integrity' => [fn(int $courseid) => integrity_checker::fix_integrity($courseid)],
            'clean up' => [fn(int $courseid) => integrity_checker::cleanup_orphaned($courseid)],
            'fix circular' => [fn(int $courseid) => integrity_checker::fix_circular($courseid)],
            'set parent' => [fn(int $courseid) => integrity_checker::set_parent($courseid, 2, 1)],
            'set parents' => [fn(int $courseid) => integrity_checker::set_parents_bulk($courseid, [2], [1])],
        ];
    }

    /**
     * On a Topics course the repairs refuse, and write nothing.
     *
     * @dataProvider repair_provider
     * @param callable $repair The repair to run.
     */
    public function test_repairs_refuse_other_formats(callable $repair): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);
        $before = $DB->count_records('course_format_options', ['courseid' => $course->id]);

        try {
            $repair((int) $course->id);
            $this->fail('A repair must refuse a course that is not in Flexible sections.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorformatnotflexsections', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('course_format_options', ['courseid' => $course->id]));
        $this->assertSame(4, $DB->count_records('course_sections', ['course' => $course->id]));
    }
}
