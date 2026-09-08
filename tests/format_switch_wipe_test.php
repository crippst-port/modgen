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
 * Regression test: integrity_checker::check() detects the course-format round-trip wipe.
 *
 * When a course is switched away from flexsections and back, core Moodle's update_course()
 * deletes every course_format_options row for the old format (course/lib.php), including each
 * section's 'parent' row. Switching back to flexsections leaves every non-root section with no
 * 'parent' row at all, and the format's own default ('0') makes the course look like it was
 * always flat. This is real, unrecoverable loss of the stored hierarchy, distinct from an
 * isolated missing-parent row (e.g. a raw import gap), and integrity_checker::check() must flag
 * it via 'possible_format_switch_wipe' rather than folding it silently into missing_parents.
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
 * Tests for the format-switch wipe detection in integrity_checker::check().
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class format_switch_wipe_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /** @var \core_courseformat\base Course format instance. */
    private $courseformat;

    /**
     * Set up a flexsections course with a small nested structure for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);
        $this->courseformat = \course_get_format($this->course->id);

        // Normalise the ambient topics->flexsections conversion state first, same as
        // fix_integrity_all_issues_test, so the wipe simulated below is the only corruption
        // present.
        integrity_checker::fix_integrity($this->course->id);

        $top = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Top',
            'Desc'
        );
        theme_builder::create_section_with_parent(
            $this->course->id,
            $this->courseformat,
            $top,
            'Child',
            'Desc',
            FORMAT_HTML
        );
        theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Other top',
            'Desc'
        );
    }

    /**
     * Simulate core's update_course() format-switch cleanup: delete every 'parent' format
     * option row for the course, exactly as course/lib.php does for the whole old format.
     */
    private function wipe_all_parent_rows(): void {
        global $DB;

        $sectionids = $DB->get_fieldset_select(
            'course_sections',
            'id',
            'course = ? AND section > 0',
            [$this->course->id]
        );
        [$insql, $inparams] = $DB->get_in_or_equal($sectionids);
        $DB->delete_records_select(
            'course_format_options',
            "sectionid $insql AND name = 'parent'",
            $inparams
        );
    }

    /**
     * A wholesale wipe (every non-root section missing its parent row) is flagged.
     *
     * @covers ::check
     */
    public function test_wholesale_wipe_is_flagged(): void {
        global $DB;

        $this->wipe_all_parent_rows();

        $nonrootcount = $DB->count_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$this->course->id]
        );

        $diag = integrity_checker::check($this->course->id);

        $this->assertTrue($diag['possible_format_switch_wipe']);
        $this->assertSame($nonrootcount, $diag['counts']['missing_parents']);
        $this->assertTrue($diag['has_issues']);
    }

    /**
     * A single isolated missing-parent row is not mistaken for a wholesale wipe.
     *
     * @covers ::check
     */
    public function test_isolated_missing_parent_is_not_flagged(): void {
        global $DB;

        $onesection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => 1,
        ], '*', MUST_EXIST);
        $DB->delete_records('course_format_options', [
            'sectionid' => $onesection->id, 'name' => 'parent',
        ]);

        $diag = integrity_checker::check($this->course->id);

        $this->assertFalse($diag['possible_format_switch_wipe']);
        $this->assertSame(1, $diag['counts']['missing_parents']);
        $this->assertTrue($diag['has_issues']);
    }

    /**
     * A clean course (no missing parents at all) is never flagged.
     *
     * @covers ::check
     */
    public function test_clean_course_is_not_flagged(): void {
        $diag = integrity_checker::check($this->course->id);

        $this->assertFalse($diag['possible_format_switch_wipe']);
        $this->assertSame(0, $diag['counts']['missing_parents']);
    }

    /**
     * A single-section course losing its one parent row is not flagged as a wipe: with only
     * one non-root section, "all missing" and "one missing" are indistinguishable from an
     * isolated gap, so the >= 2 gate deliberately withholds the wipe warning here.
     *
     * @covers ::check
     */
    public function test_single_section_course_missing_parent_is_not_flagged(): void {
        global $DB;

        $solocourse = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($solocourse->id);
        integrity_checker::fix_integrity($solocourse->id);
        $soloformat = \course_get_format($solocourse->id);
        theme_builder::create_theme_section($solocourse->id, $soloformat, 'Only section', 'Desc');

        $onlysection = $DB->get_record('course_sections', [
            'course' => $solocourse->id, 'section' => 1,
        ], '*', MUST_EXIST);
        $DB->delete_records('course_format_options', [
            'sectionid' => $onlysection->id, 'name' => 'parent',
        ]);

        $diag = integrity_checker::check($solocourse->id);

        $this->assertFalse($diag['possible_format_switch_wipe']);
        $this->assertSame(1, $diag['counts']['missing_parents']);
    }
}
