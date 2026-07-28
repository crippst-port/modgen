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
 * Regression test: integrity_checker::fix_integrity() against all five issue types at once.
 *
 * Exercises the exact scenario that broke on a live course: section0_with_parent,
 * orphaned_options, invalid_parents, null_parents, and missing_parents corruption present
 * simultaneously. Before this fix, the invalid_parents step's unguarded CAST crashed on the
 * null_parents corruption, and because fix_integrity() had no transaction wrapper, the two
 * fixes that ran before the crash (section0_with_parent, orphaned_options) were left silently
 * half-applied while the rest were skipped entirely.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\integrity_checker;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class fix_integrity_all_issues_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /**
     * Set up a flexsections course for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);

        // Converting from 'topics' format leaves the default topic sections without a
        // 'parent' format option (a flexsections-only concept) until something sets one —
        // normalise that ambient state here so the test below can assert an exact count
        // for the five corruptions it introduces deliberately.
        integrity_checker::fix_integrity($this->course->id);
    }

    /**
     * @covers ::fix_integrity
     */
    public function test_fix_integrity_repairs_all_five_issue_types_in_one_call(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        // 1. section0_with_parent.
        $section0 = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => 0,
        ], '*', MUST_EXIST);
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => $section0->id, 'name' => 'parent', 'value' => '1',
        ]);

        // 2. orphaned_options: points at a section id that does not exist.
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => 999999999, 'name' => 'parent', 'value' => '0',
        ]);

        // 3. invalid_parents: parent points at a nonexistent section number.
        $invalidnum = theme_builder::create_theme_section(
            $this->course->id, $courseformat, 'Invalid Parent Section', 'Desc'
        );
        $invalidsection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $invalidnum,
        ], '*', MUST_EXIST);
        $DB->set_field('course_format_options', 'value', '9999', [
            'sectionid' => $invalidsection->id, 'name' => 'parent',
        ]);

        // 4. null_parents: empty-string parent value.
        $nullnum = theme_builder::create_theme_section(
            $this->course->id, $courseformat, 'Null Parent Section', 'Desc'
        );
        $nullsection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $nullnum,
        ], '*', MUST_EXIST);
        $DB->set_field('course_format_options', 'value', '', [
            'sectionid' => $nullsection->id, 'name' => 'parent',
        ]);

        // 5. missing_parents: no parent format option row at all.
        $missingnum = theme_builder::create_theme_section(
            $this->course->id, $courseformat, 'Missing Parent Section', 'Desc'
        );
        $missingsection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $missingnum,
        ], '*', MUST_EXIST);
        $DB->delete_records('course_format_options', [
            'sectionid' => $missingsection->id, 'name' => 'parent',
        ]);

        // The core assertion: this must not throw despite all five corruptions co-existing.
        $result = integrity_checker::fix_integrity($this->course->id);

        $this->assertSame(5, $result['fixed']);
        $this->assertCount(5, $result['details']);

        // Verify each fix actually landed in the database.
        $this->assertFalse($DB->record_exists('course_format_options', [
            'sectionid' => $section0->id, 'name' => 'parent',
        ]), 'section 0 parent option should be deleted');

        $this->assertFalse($DB->record_exists('course_format_options', [
            'sectionid' => 999999999,
        ]), 'orphaned format option should be deleted');

        $this->assertEquals('0', $DB->get_field('course_format_options', 'value', [
            'sectionid' => $invalidsection->id, 'name' => 'parent',
        ]));

        $this->assertEquals('0', $DB->get_field('course_format_options', 'value', [
            'sectionid' => $nullsection->id, 'name' => 'parent',
        ]));

        $this->assertEquals('0', $DB->get_field('course_format_options', 'value', [
            'sectionid' => $missingsection->id, 'name' => 'parent',
        ]));

        // A second run must find nothing left to fix.
        $rerun = integrity_checker::fix_integrity($this->course->id);
        $this->assertSame(0, $rerun['fixed']);
    }
}
