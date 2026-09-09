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
 * Unit tests for the section numbering order check and renumber repair.
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
 * Tests the flexsections depth-first numbering check and its repair.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\integrity_checker
 */
final class section_order_test extends advanced_testcase {
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
     * Current section number => parent value, for asserting on the whole course at once.
     *
     * @return array<int, int> Section number => parent section number
     */
    private function parent_map_by_number(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT cs.section, cfo.value AS parent
               FROM {course_sections} cs
               LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = ?
              ORDER BY cs.section ASC",
            [$this->course->id]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->section] = (int) $row->parent;
        }
        return $map;
    }

    /**
     * A straight build comes out in hierarchy order.
     *
     * @covers ::check_section_order
     */
    public function test_full_build_is_in_order(): void {
        theme_builder::create_themes($this->course->id, 2, 2, 0, false);

        $check = integrity_checker::check_section_order($this->course->id);

        $this->assertTrue($check['checkable']);
        $this->assertSame([], $check['misordered']);
    }

    /**
     * Building into a course that already has content keeps the numbering in order.
     *
     * @covers ::check_section_order
     */
    public function test_repeated_top_level_builds_stay_in_order(): void {
        theme_builder::create_themes($this->course->id, 2, 1, 0, false);
        theme_builder::create_themes($this->course->id, 1, 1, 0, false);
        theme_builder::create_weeks($this->course->id, 1, 0, false);

        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    /**
     * The case that used to scramble the numbering: adding under a section that is not last.
     *
     * Sections are appended at top level and reparented afterwards, so a subtree added under
     * an earlier theme lands at the end of the course rather than after its parent. The
     * post-build renumber is what puts it back.
     *
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_build_under_an_earlier_section_is_renumbered(): void {
        theme_builder::create_themes($this->course->id, 2, 1, 0, false);

        // Target the FIRST theme, which by now has a whole second theme numbered after it.
        $firsttheme = null;
        foreach (\course_get_format($this->course->id)->get_sections() as $num => $section) {
            if ($section->name === 'Theme 1') {
                $firsttheme = $num;
                break;
            }
        }
        $this->assertNotNull($firsttheme);

        theme_builder::create_themes($this->course->id, 1, 1, $firsttheme, false);

        $check = integrity_checker::check_section_order($this->course->id);
        $this->assertTrue($check['checkable']);
        $this->assertSame([], $check['misordered']);

        // The new subtree really did land under the first theme, immediately after its
        // existing week — not merely renumbered into some other consistent arrangement.
        $sections = \course_get_format($this->course->id)->get_sections();
        $children = [];
        foreach ($sections as $num => $section) {
            if ((int) $section->parent === (int) $firsttheme) {
                $children[] = $num;
            }
        }
        $this->assertCount(2, $children);
        $this->assertSame($firsttheme + 1, $children[0]);
    }

    /**
     * Correct numbering is what keeps section deletion from taking unrelated content with it.
     *
     * format_flexsections::delete_section_with_children() moves the doomed section to the end
     * of the course and then deletes it "and all the following (which can only be its
     * subsections)". That is only true of a canonically numbered course. When the section is
     * already the last top-level one, can_move_section_to() rejects the move as a no-op, so
     * nothing is renumbered and the assumption fails: everything numbered after it is deleted
     * whether it belongs to that section or not. Verified against upstream — deleting a
     * childless section destroyed another theme's subsection.
     *
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_renumber_makes_section_deletion_safe(): void {
        global $DB;

        $format = \course_get_format($this->course->id);

        // The shape append-then-reparent produces: A1 is numbered after B, not after A.
        $a = theme_builder::create_section_with_parent($this->course->id, $format, 0, 'A', '', FORMAT_PLAIN);
        $b = theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            0,
            'B',
            '',
            FORMAT_PLAIN
        );
        $a1 = theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            (int) $a->section,
            'A1',
            '',
            FORMAT_PLAIN
        );

        $this->assertNotEmpty(
            integrity_checker::check_section_order($this->course->id)['misordered'],
            'expected this construction to leave the numbering out of order'
        );

        theme_builder::renumber_into_hierarchy_order($this->course->id);

        // B owns nothing, so deleting it must remove exactly one section.
        $format = \course_get_format($this->course->id);
        $bnum = (int) $DB->get_field('course_sections', 'section', ['id' => $b->id]);
        [$deletedsections] = $format->delete_section_with_children($format->get_section($bnum));

        $this->assertCount(1, $deletedsections);
        $this->assertTrue(
            $DB->record_exists('course_sections', ['id' => $a1->id]),
            'deleting B destroyed a subsection belonging to A'
        );
        $this->assertTrue($DB->record_exists('course_sections', ['id' => $a->id]));
    }

    /**
     * The other half of the deletion hazard: without the renumber, a delete under-deletes.
     *
     * Where test_renumber_makes_section_deletion_safe() covers a delete taking too much, this
     * covers it taking too little. On a chain whose numbers descend (A high, its descendants
     * low), the doomed section is already last, the renumbering move is rejected as a no-op,
     * and the "everything following it" sweep finds nothing — so A is deleted and its two
     * descendants are left behind pointing at a section number that no longer exists. Worse,
     * they stop looking broken as soon as a new section reuses that number: they silently
     * become children of unrelated content. Renumbering first makes the subtree contiguous,
     * so the delete removes exactly it.
     *
     * @covers \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order
     */
    public function test_renumber_makes_subtree_deletion_complete(): void {
        global $DB;

        // Built in reverse, so the chain A -> A1 -> A2 runs downwards in section number.
        $a2 = theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            0,
            'A2',
            '',
            FORMAT_PLAIN
        );
        $a1 = theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            0,
            'A1',
            '',
            FORMAT_PLAIN
        );
        $a = theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            0,
            'A',
            '',
            FORMAT_PLAIN
        );

        $anum = (int) $DB->get_field('course_sections', 'section', ['id' => $a->id]);
        $a1num = (int) $DB->get_field('course_sections', 'section', ['id' => $a1->id]);
        $a2num = (int) $DB->get_field('course_sections', 'section', ['id' => $a2->id]);
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $a1num, $anum)['success']);
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $a2num, $a1num)['success']);

        theme_builder::renumber_into_hierarchy_order($this->course->id);

        $format = \course_get_format($this->course->id);
        $anum = (int) $DB->get_field('course_sections', 'section', ['id' => $a->id]);
        $format->delete_section_with_children($format->get_section($anum));

        // The whole subtree goes, leaving nothing pointing at a section that no longer exists.
        foreach ([$a, $a1, $a2] as $gone) {
            $this->assertFalse(
                $DB->record_exists('course_sections', ['id' => $gone->id]),
                'a descendant survived the deletion of its ancestor'
            );
        }
        $diag = integrity_checker::check($this->course->id);
        $this->assertSame(0, $diag['counts']['invalid_parents']);
        $this->assertSame(0, $diag['counts']['circular_refs']);
    }

    /**
     * A course built purely through the plugin's own helpers is already in order.
     *
     * @covers ::check_section_order
     */
    public function test_freshly_built_course_is_in_order(): void {
        $courseformat = \course_get_format($this->course->id);

        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        theme_builder::create_section_with_parent(
            $this->course->id,
            \course_get_format($this->course->id),
            $a,
            'A1',
            '',
            FORMAT_HTML
        );

        $result = integrity_checker::check_section_order($this->course->id);

        $this->assertTrue($result['checkable']);
        $this->assertSame([], $result['misordered']);
    }

    /**
     * Reparenting via set_parent() leaves the numbering out of order, and the check says so.
     *
     * @covers ::check_section_order
     */
    public function test_reparenting_is_detected_as_out_of_order(): void {
        $courseformat = \course_get_format($this->course->id);

        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        // Nest C under A. C keeps its number, so A's subsection no longer follows A: the
        // order becomes A(a), B(b), C(c) where the hierarchy says it should be A, C, B.
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);

        $result = integrity_checker::check_section_order($this->course->id);

        $this->assertTrue($result['checkable']);
        $this->assertNotEmpty($result['misordered']);

        $expectedbynum = [];
        foreach ($result['misordered'] as $row) {
            $expectedbynum[$row->section] = $row->expected;
        }
        // C should take B's slot and B should shift down by one.
        $this->assertSame($a + 1, $expectedbynum[$c]);
        $this->assertSame($b + 1, $expectedbynum[$b]);
    }

    /**
     * The repair renumbers into hierarchy order while preserving the tree.
     *
     * @covers ::fix_section_order
     */
    public function test_fix_renumbers_and_preserves_hierarchy(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);

        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        $aid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $a]);
        $bid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $b]);
        $cid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $c]);

        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);

        $result = integrity_checker::fix_section_order($this->course->id);
        $this->assertTrue($result['checkable']);
        $this->assertGreaterThan(0, $result['fixed']);

        // C now sits immediately after A, and B follows the pair.
        $this->assertEquals($a, $DB->get_field('course_sections', 'section', ['id' => $aid]));
        $this->assertEquals($a + 1, $DB->get_field('course_sections', 'section', ['id' => $cid]));
        $this->assertEquals($a + 2, $DB->get_field('course_sections', 'section', ['id' => $bid]));

        // The tree itself is untouched: C's parent still resolves to A, under its new number.
        $parents = $this->parent_map_by_number();
        $this->assertSame($a, $parents[$a + 1]);
        $this->assertSame(0, $parents[$a]);
        $this->assertSame(0, $parents[$a + 2]);

        // And the course is now clean by its own check.
        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    /**
     * A parent reference that points at a renumbered section follows it.
     *
     * @covers ::fix_section_order
     */
    public function test_fix_rewrites_parent_references_of_renumbered_sections(): void {
        $courseformat = \course_get_format($this->course->id);

        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');
        $d = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'D', 'Desc D');

        // D hangs off B, and nesting C under A then pushes B down a number, so D's stored
        // parent value has to be rewritten to B's new number rather than left pointing at a
        // section that is now something else entirely.
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $d, $b)['success']);
        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);

        $before = integrity_checker::check_section_order($this->course->id);
        $this->assertNotEmpty($before['misordered']);

        integrity_checker::fix_section_order($this->course->id);

        // Expected traversal: A, C, B, D — every parent link still resolves to the right one.
        $parents = $this->parent_map_by_number();
        $this->assertSame(0, $parents[$a]);          // A top-level.
        $this->assertSame($a, $parents[$a + 1]);     // C under A.
        $this->assertSame(0, $parents[$a + 2]);      // B still top-level, one number lower.
        $this->assertSame($a + 2, $parents[$a + 3]); // D under B, following B's new number.

        $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    }

    /**
     * Running the repair on an already-ordered course writes nothing.
     *
     * @covers ::fix_section_order
     */
    public function test_fix_is_a_noop_when_already_in_order(): void {
        $courseformat = \course_get_format($this->course->id);
        theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');

        $result = integrity_checker::fix_section_order($this->course->id);

        $this->assertTrue($result['checkable']);
        $this->assertSame(0, $result['fixed']);
    }

    /**
     * A broken hierarchy has no single correct order, so neither check nor repair guesses.
     *
     * @covers ::check_section_order
     * @covers ::fix_section_order
     */
    public function test_broken_hierarchy_is_not_judged_or_renumbered(): void {
        global $DB;

        $courseformat = \course_get_format($this->course->id);
        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        $b = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');

        $aid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $a]);
        $bid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $b]);

        // Straight into the database: set_parent() would (rightly) refuse to build this cycle.
        $DB->set_field('course_format_options', 'value', (string) $b, ['sectionid' => $aid, 'name' => 'parent']);
        $DB->set_field('course_format_options', 'value', (string) $a, ['sectionid' => $bid, 'name' => 'parent']);

        $check = integrity_checker::check_section_order($this->course->id);
        $this->assertFalse($check['checkable']);
        $this->assertSame('brokenhierarchy', $check['blockedby']);
        $this->assertSame([], $check['misordered']);

        $sectionsbefore = $DB->get_records_menu(
            'course_sections',
            ['course' => $this->course->id],
            '',
            'id, section'
        );
        $fix = integrity_checker::fix_section_order($this->course->id);
        $this->assertFalse($fix['checkable']);
        $this->assertSame(0, $fix['fixed']);
        $this->assertEquals(
            $sectionsbefore,
            $DB->get_records_menu('course_sections', ['course' => $this->course->id], '', 'id, section')
        );
    }

    /**
     * Non-flexsections courses are out of scope: the numbering rule is the format's.
     *
     * @covers ::check_section_order
     */
    public function test_non_flexsections_course_is_out_of_scope(): void {
        $topics = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 3]);

        $result = integrity_checker::check_section_order($topics->id);

        $this->assertFalse($result['checkable']);
        $this->assertSame('notflexsections', $result['blockedby']);
    }

    /**
     * The order problem surfaces through the shared check() entry point too.
     *
     * @covers ::check
     */
    public function test_check_reports_section_order_issues(): void {
        $courseformat = \course_get_format($this->course->id);

        $a = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'A', 'Desc A');
        theme_builder::create_theme_section($this->course->id, $courseformat, 'B', 'Desc B');
        $c = (int) theme_builder::create_theme_section($this->course->id, $courseformat, 'C', 'Desc C');

        $this->assertTrue(integrity_checker::set_parent($this->course->id, $c, $a)['success']);

        $diag = integrity_checker::check($this->course->id);

        $this->assertTrue($diag['has_issues']);
        $this->assertGreaterThan(0, $diag['counts']['section_order']);

        // The affected rows are flagged in the section detail table.
        $flagged = 0;
        foreach ($diag['sections'] as $section) {
            foreach ($section->row_issues as $issue) {
                if (strpos($issue, 'Out of order') !== false) {
                    $flagged++;
                }
            }
        }
        $this->assertSame($diag['counts']['section_order'], $flagged);
    }
}
