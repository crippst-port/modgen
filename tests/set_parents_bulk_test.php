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
 * Tests for integrity_checker::set_parents_bulk().
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
 * Tests the "Fix All" bulk-apply action used by check_structure.php's reparented-sections
 * flash table.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class set_parents_bulk_test extends advanced_testcase {
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
    }

    /**
     * Applies every pair in the batch and reports how many succeeded.
     *
     * @covers ::set_parents_bulk
     */
    public function test_set_parents_bulk_applies_all_valid_pairs(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);
        $sectionnumt = theme_builder::create_theme_section($this->course->id, $courseformat, 'T', 'Desc T');
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $sectionnumb = theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');

        $result = integrity_checker::set_parents_bulk(
            $this->course->id,
            [$sectionnuma, $sectionnumb],
            [$sectionnumt, $sectionnumt]
        );

        $this->assertEquals(2, $result['applied']);
        $this->assertEquals(0, $result['failed']);

        foreach ([$sectionnuma, $sectionnumb] as $secnum) {
            $section = $DB->get_record('course_sections', [
                'course' => $this->course->id, 'section' => $secnum,
            ], '*', MUST_EXIST);
            $parentvalue = $DB->get_field('course_format_options', 'value', [
                'sectionid' => $section->id, 'name' => 'parent',
            ]);
            $this->assertEquals((string) $sectionnumt, $parentvalue);
        }
    }

    /**
     * A failing pair is counted but doesn't stop the rest of the batch from being applied.
     *
     * @covers ::set_parents_bulk
     */
    public function test_set_parents_bulk_counts_failures_without_blocking_others(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);
        $sectionnumt = theme_builder::create_theme_section($this->course->id, $courseformat, 'T', 'Desc T');
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $sectionnumb = theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');

        // B's pair is invalid (self-parent) and sits between two valid pairs, to prove a
        // failure in the middle of the batch doesn't short-circuit the rest.
        $result = integrity_checker::set_parents_bulk(
            $this->course->id,
            [$sectionnuma, $sectionnumb, $sectionnumt],
            [$sectionnumt, $sectionnumb, 0]
        );

        $this->assertEquals(2, $result['applied']);
        $this->assertEquals(1, $result['failed']);

        $arow = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnuma,
        ], '*', MUST_EXIST);
        $aparent = $DB->get_field('course_format_options', 'value', [
            'sectionid' => $arow->id, 'name' => 'parent',
        ]);
        $this->assertEquals((string) $sectionnumt, $aparent, 'A should still have been reparented');

        $brow = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumb,
        ], '*', MUST_EXIST);
        $bparent = $DB->get_field('course_format_options', 'value', [
            'sectionid' => $brow->id, 'name' => 'parent',
        ]);
        $this->assertEquals('0', $bparent, 'B\'s invalid self-parent pair should not have applied');
    }

    /**
     * A pair whose index is missing from $newparents (a malformed/truncated submission) is
     * skipped rather than counted as applied or failed.
     *
     * @covers ::set_parents_bulk
     */
    public function test_set_parents_bulk_skips_pairs_missing_from_newparents(): void {
        $courseformat = \course_get_format($this->course->id);
        $sectionnumt = theme_builder::create_theme_section($this->course->id, $courseformat, 'T', 'Desc T');
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $result = integrity_checker::set_parents_bulk(
            $this->course->id,
            [$sectionnuma],
            [] // No matching newparent for index 0.
        );

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(0, $result['failed']);
    }
}
