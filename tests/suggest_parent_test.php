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
 * Tests for integrity_checker::suggest_parent() and the suggestedparent values it feeds into
 * fix_integrity()/fix_circular()'s reparented[] reporting.
 *
 * suggest_parent() is a read-only heuristic (it never writes to the database), so these
 * tests only assert on its return value and on the 'suggestedparent' key of reparented[]
 * entries, never on what fix_integrity()/fix_circular() themselves write (that behaviour is
 * unchanged and already covered by fix_integrity_all_issues_test.php and
 * circular_fix_reparented_test.php).
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
 * Tests the nearest-valid-predecessor heuristic used to suggest a replacement parent.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class suggest_parent_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /**
     * Set up a flexsections course for each test, with every section the course generator
     * created ahead of time (the 'topics' default is 5, per numsections in
     * lib/testing/generator/data_generator.php) deliberately self-parented.
     *
     * Without this, those ambient sections would sit at valid, unbroken parent=0 and could get
     * picked up as false-positive candidates by suggest_parent() in the tests below, making
     * assertions about "nothing valid precedes this section" depend on generator defaults
     * rather than on the scenario each test actually sets up.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);

        $ambient = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0',
            [$this->course->id]
        );
        foreach ($ambient as $section) {
            $this->force_self_parent($section->id, $section->section);
        }
    }

    /**
     * Force a section's 'parent' format option to point at itself, inserting the row if it
     * doesn't exist yet. Bypasses set_parent() deliberately: it rejects self-parents by
     * design, so raw corruption has to go through $DB directly, same as the existing
     * circular-reference and set_parent test suites do.
     *
     * @param int $sectionid course_sections.id
     * @param int $sectionnum Section number (used as both the row's course_format_options
     *   courseid lookup key context and the self-referencing parent value)
     */
    private function force_self_parent(int $sectionid, int $sectionnum): void {
        global $DB;

        $existing = $DB->get_record('course_format_options', [
            'sectionid' => $sectionid, 'name' => 'parent',
        ]);
        if ($existing) {
            $DB->set_field('course_format_options', 'value', (string) $sectionnum, ['id' => $existing->id]);
        } else {
            $DB->insert_record('course_format_options', (object) [
                'courseid'  => $this->course->id,
                'format'    => 'flexsections',
                'sectionid' => $sectionid,
                'name'      => 'parent',
                'value'     => (string) $sectionnum,
            ]);
        }
    }

    /**
     * Suggests the immediately preceding section when that section's own parent link is
     * intact.
     *
     * @covers ::suggest_parent
     */
    public function test_suggest_parent_finds_immediate_valid_predecessor(): void {
        global $DB;

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
        $sectionnumc = theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        $sectionc = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumc,
        ], '*', MUST_EXIST);

        $this->force_self_parent($sectionc->id, $sectionnumc);

        $suggestion = integrity_checker::suggest_parent($this->course->id, $sectionnumc);

        $this->assertSame((int) $sectionb->section, $suggestion, 'Should suggest the section immediately before it');
    }

    /**
     * Skips a broken predecessor and suggests the nearest section before that one whose own
     * link is intact.
     *
     * @covers ::suggest_parent
     */
    public function test_suggest_parent_skips_a_broken_predecessor(): void {
        global $DB;

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
        $sectionnumc = theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        // B must be skipped as a candidate for C.
        $this->force_self_parent($sectionb->id, $sectionb->section);

        $sectionc = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumc,
        ], '*', MUST_EXIST);
        $this->force_self_parent($sectionc->id, $sectionnumc);

        $suggestion = integrity_checker::suggest_parent($this->course->id, $sectionnumc);

        $this->assertSame((int) $sectionnuma, $suggestion, 'Should skip broken B and land on valid A');
    }

    /**
     * Returns null when every section before the target is itself broken.
     *
     * @covers ::suggest_parent
     */
    public function test_suggest_parent_returns_null_when_nothing_valid_precedes(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);
        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $sectiona = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnuma,
        ], '*', MUST_EXIST);
        $this->force_self_parent($sectiona->id, $sectionnuma);

        // setUp() already broke every ambient section before A, and A itself is now broken
        // too, so nothing valid exists anywhere before A.
        $this->assertNull(integrity_checker::suggest_parent($this->course->id, $sectionnuma));
    }

    /**
     * Never proposes a candidate that would create a new cycle, even when an otherwise-valid
     * section exists further back: if the nearest candidate would loop straight back to the
     * target, it must be skipped just like any other broken candidate, not accepted.
     *
     * @covers ::suggest_parent
     */
    public function test_suggest_parent_never_proposes_a_candidate_that_would_cycle(): void {
        global $DB;

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

        $sectiona = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnuma,
        ], '*', MUST_EXIST);

        // Corrupt A to point at B, creating a mutual cycle A <-> B (B already points at A).
        $DB->set_field('course_format_options', 'value', (string) $sectionb->section, [
            'sectionid' => $sectiona->id, 'name' => 'parent',
        ]);

        // B's only possible candidate is A (everything before A was broken in setUp()), and A
        // would loop straight back to B, so nothing safe can be suggested.
        $suggestion = integrity_checker::suggest_parent($this->course->id, $sectionb->section);

        $this->assertNull($suggestion);
    }

    /**
     * fix_circular()'s reparented[] entries carry a suggestedparent computed from the state
     * just before the fix ran, so a run of several broken sections doesn't have each entry's
     * suggestion undermined by an earlier entry in the same batch already having been reset.
     *
     * @covers ::fix_circular
     */
    public function test_fix_circular_reparented_entries_carry_suggestions(): void {
        global $DB;

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
        $sectionnumc = theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        // Corrupt both B and C into self-parents: two broken sections in a row.
        $this->force_self_parent($sectionb->id, $sectionb->section);
        $sectionc = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumc,
        ], '*', MUST_EXIST);
        $this->force_self_parent($sectionc->id, $sectionnumc);

        $result = integrity_checker::fix_circular($this->course->id);

        $bysection = [];
        foreach ($result['reparented'] as $entry) {
            $this->assertArrayHasKey('suggestedparent', $entry);
            $bysection[$entry['section']] = $entry['suggestedparent'];
        }

        // B's only candidate, A, is valid, so B's suggestion is A. C's only candidate below it
        // is B, which is broken, so C must skip past it to A too, not fall back to whatever B's own
        // entry in this same batch gets reset to.
        $this->assertSame((int) $sectionnuma, $bysection[$sectionb->section]);
        $this->assertSame((int) $sectionnuma, $bysection[$sectionnumc]);
    }

    /**
     * fix_integrity()'s invalid_parents reparented[] entries also carry a suggestedparent.
     *
     * @covers ::fix_integrity
     */
    public function test_fix_integrity_reparented_entries_carry_suggestions(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        $sectionnuma = theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $sectionnumb = theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');

        $sectionb = $DB->get_record('course_sections', [
            'course' => $this->course->id, 'section' => $sectionnumb,
        ], '*', MUST_EXIST);

        // Corrupt B: parent points at a section number that does not exist.
        $DB->set_field('course_format_options', 'value', '999999', [
            'sectionid' => $sectionb->id, 'name' => 'parent',
        ]);

        $result = integrity_checker::fix_integrity($this->course->id);

        $entries = array_column($result['reparented'], null, 'section');
        $this->assertArrayHasKey($sectionnumb, $entries);
        $this->assertSame((int) $sectionnuma, $entries[$sectionnumb]['suggestedparent']);

        // The actual write is unchanged: still reset to top-level, per fix_integrity_all_issues_test.
        $this->assertEquals('0', $DB->get_field('course_format_options', 'value', [
            'sectionid' => $sectionb->id, 'name' => 'parent',
        ]));
    }
}
