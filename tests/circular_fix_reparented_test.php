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
 * Tests for integrity_checker::fix_circular()'s reparented-sections reporting.
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
final class circular_fix_reparented_test extends advanced_testcase {
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
     * @covers ::fix_circular
     */
    public function test_fix_circular_reports_reparented_sections(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        // Build two sections via the normal API: A at top-level, B as a child of A.
        $sectionnuma = theme_builder::create_theme_section(
            $this->course->id, $courseformat, 'Section A', 'Desc A'
        );
        $sectionb = theme_builder::create_section_with_parent(
            $this->course->id, $courseformat, $sectionnuma, 'Section B', 'Desc B', FORMAT_PLAIN
        );

        $sectiona = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnuma,
        ], '*', MUST_EXIST);

        // Corrupt the structure directly: point A's parent at B, creating a cycle A -> B -> A.
        // This deliberately bypasses theme_builder's own circular-reference guard, which only
        // protects the normal creation path — fix_circular() has to cope with raw corruption
        // that never went through that guard at all.
        $DB->set_field('course_format_options', 'value', (string) $sectionb->section, [
            'sectionid' => $sectiona->id,
            'name'      => 'parent',
        ]);

        $result = integrity_checker::fix_circular($this->course->id);

        $this->assertGreaterThan(0, $result['fixed'], 'Expected at least one section to be fixed');
        $this->assertArrayHasKey('reparented', $result, 'fix_circular() must return a reparented key');

        $reparentednums = array_column($result['reparented'], 'section');
        sort($reparentednums);

        $expectednums = [$sectionnuma, $sectionb->section];
        sort($expectednums);

        // Both A and B loop back on themselves when walked from their own starting point, so
        // both are independently detected and reset to top-level by the current algorithm.
        $this->assertEquals($expectednums, $reparentednums);

        foreach ($result['reparented'] as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('section', $entry);
            $this->assertArrayHasKey('name', $entry);
        }

        // Confirm the DB actually reflects top-level parents for both sections now.
        foreach ([$sectiona->id, $sectionb->id] as $sectionid) {
            $parentvalue = $DB->get_field('course_format_options', 'value', [
                'sectionid' => $sectionid, 'name' => 'parent',
            ]);
            $this->assertEquals('0', $parentvalue);
        }
    }

    /**
     * @covers ::fix_circular
     */
    public function test_fix_circular_reparented_empty_when_no_cycles(): void {
        $courseformat = \course_get_format($this->course->id);

        theme_builder::create_theme_section($this->course->id, $courseformat, 'Section A', 'Desc A');

        $result = integrity_checker::fix_circular($this->course->id);

        $this->assertEquals(0, $result['fixed']);
        $this->assertSame([], $result['reparented']);
    }
}
