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
 * Tests for integrity_checker::set_parent().
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
 * Tests the manual per-section parent picker used by check_structure.php.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class set_parent_test extends advanced_testcase {
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
     * Reparents a section to a valid, unrelated section.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_moves_section_to_chosen_parent(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $sectionnumb = theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');

        $result = integrity_checker::set_parent($this->course->id, $sectionnumb, $sectionnuma);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);

        $sectionb = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumb,
        ], '*', MUST_EXIST);
        $parentvalue = $DB->get_field('course_format_options', 'value', [
            'sectionid' => $sectionb->id, 'name' => 'parent',
        ]);
        $this->assertEquals((string) $sectionnuma, $parentvalue);
    }

    /**
     * Resolves a self-parent by pointing the section at top-level.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_resolves_self_parent_to_top_level(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $sectiona = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnuma,
        ], '*', MUST_EXIST);

        // Corrupt A into a self-parent.
        $DB->set_field('course_format_options', 'value', (string) $sectionnuma, [
            'sectionid' => $sectiona->id, 'name' => 'parent',
        ]);

        $result = integrity_checker::set_parent($this->course->id, $sectionnuma, 0);
        $this->assertTrue($result['success']);

        $parentvalue = $DB->get_field('course_format_options', 'value', [
            'sectionid' => $sectiona->id, 'name' => 'parent',
        ]);
        $this->assertEquals('0', $parentvalue);
    }

    /**
     * Refuses to let a section become its own parent.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_rejects_self_parent(): void {
        $courseformat = \course_get_format($this->course->id);
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $result = integrity_checker::set_parent($this->course->id, $sectionnuma, $sectionnuma);

        $this->assertFalse($result['success']);
        $this->assertEquals('selfparent', $result['error']);
    }

    /**
     * Refuses to give section 0 a parent.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_rejects_section_zero(): void {
        $courseformat = \course_get_format($this->course->id);
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $result = integrity_checker::set_parent($this->course->id, 0, $sectionnuma);

        $this->assertFalse($result['success']);
        $this->assertEquals('section0', $result['error']);
    }

    /**
     * Refuses a parent section number that doesn't exist in the course.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_rejects_unknown_parent(): void {
        $courseformat = \course_get_format($this->course->id);
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $result = integrity_checker::set_parent($this->course->id, $sectionnuma, 999);

        $this->assertFalse($result['success']);
        $this->assertEquals('parentnotfound', $result['error']);
    }

    /**
     * Refuses a move that would create a new cycle: A is currently the parent of B, so making
     * A a child of B (directly or transitively) must be rejected rather than silently looping.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_rejects_move_that_would_create_cycle(): void {
        $courseformat = \course_get_format($this->course->id);

        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $sectionb = theme_builder::create_section_with_parent(
            $this->course->id,
            $courseformat,
            $sectionnuma,
            'B',
            'Desc B',
            FORMAT_PLAIN
        );
        $sectionc = theme_builder::create_section_with_parent(
            $this->course->id,
            $courseformat,
            $sectionb->section,
            'C',
            'Desc C',
            FORMAT_PLAIN
        );

        // A -> B -> C already. Trying to set A's parent to C (a descendant of A) would loop.
        $result = integrity_checker::set_parent($this->course->id, $sectionnuma, $sectionc->section);

        $this->assertFalse($result['success']);
        $this->assertEquals('wouldcreatecycle', $result['error']);
    }

    /**
     * Refuses a section number that doesn't exist in the course.
     *
     * @covers ::set_parent
     */
    public function test_set_parent_rejects_unknown_section(): void {
        $result = integrity_checker::set_parent($this->course->id, 999, 0);

        $this->assertFalse($result['success']);
        $this->assertEquals('sectionnotfound', $result['error']);
    }
}
