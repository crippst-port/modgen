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
 * Regression test: integrity_checker::check() must not crash on an empty-string parent value.
 *
 * The invalid_parents and circular_refs checks both CAST(cfo.value AS INTEGER) without first
 * excluding null/empty values. An empty-string parent — exactly the corruption null_parents is
 * designed to detect — previously made both queries throw a dml exception on Postgres
 * ("invalid input syntax for type integer"), taking down the whole check() call. For
 * circular_refs specifically, that exception was silently swallowed by a catch block, so the
 * page didn't just crash elsewhere — it silently reported zero circular references even when a
 * real cycle existed alongside the null-parent corruption.
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
 * Tests that check() survives non-numeric and empty parent values alongside other corruption.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class integrity_check_non_numeric_parent_test extends advanced_testcase {
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
     * Survives empty parent values alongside invalid and circular references.
     *
     * @covers ::check
     */
    public function test_check_survives_empty_parent_alongside_invalid_and_circular_refs(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        // Section with an empty-string parent — the null_parents corruption.
        $nullparentnum = theme_builder::create_theme_section(
            $this->course->id,
            $courseformat,
            'Null Parent Section',
            'Desc'
        );
        $nullparentsection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $nullparentnum,
        ], '*', MUST_EXIST);
        $DB->set_field('course_format_options', 'value', '', [
            'sectionid' => $nullparentsection->id, 'name' => 'parent',
        ]);

        // Section pointing at a section number that does not exist — the invalid_parents case.
        $invalidparentnum = theme_builder::create_theme_section(
            $this->course->id,
            $courseformat,
            'Invalid Parent Section',
            'Desc'
        );
        $invalidparentsection = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $invalidparentnum,
        ], '*', MUST_EXIST);
        $DB->set_field('course_format_options', 'value', '9999', [
            'sectionid' => $invalidparentsection->id, 'name' => 'parent',
        ]);

        // A genuine two-section mutual cycle, independent of the corruption above.
        $sectiona = theme_builder::create_theme_section(
            $this->course->id,
            $courseformat,
            'Cycle A',
            'Desc'
        );
        $sectionb = theme_builder::create_section_with_parent(
            $this->course->id,
            $courseformat,
            $sectiona,
            'Cycle B',
            'Desc',
            FORMAT_PLAIN
        );
        $sectionarecord = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectiona,
        ], '*', MUST_EXIST);
        $DB->set_field('course_format_options', 'value', (string) $sectionb->section, [
            'sectionid' => $sectionarecord->id, 'name' => 'parent',
        ]);

        // The core assertion: check() must not throw despite the empty-string parent value
        // present alongside both an invalid_parents and a circular_refs corruption.
        $diag = integrity_checker::check($this->course->id);

        $this->assertTrue($diag['has_issues']);
        $this->assertSame(1, $diag['counts']['null_parents']);
        $this->assertSame(1, $diag['counts']['invalid_parents']);

        // The empty-string parent must not have been silently swallowed into "no circular refs
        // found" — the genuine A/B cycle must still be detected.
        $this->assertGreaterThan(0, $diag['counts']['circular_refs']);
        $reportedroots = array_map(
            fn($row) => (int) $row->root_section,
            $diag['issues']['circular_refs']
        );
        $this->assertContains((int) $sectiona, $reportedroots);
        $this->assertContains((int) $sectionb->section, $reportedroots);
    }
}
