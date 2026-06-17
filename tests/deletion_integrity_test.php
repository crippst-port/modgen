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
 * Deletion & cleanup integrity tests for Module Generator.
 *
 * Generation is only half the lifecycle. When a teacher deletes a generated
 * section or activity (the normal Moodle way), two integrity hazards appear:
 *
 *   1. Structural: leftover course_format_options 'parent' rows pointing at a
 *      section that no longer exists — i.e. orphaned references that 500 the
 *      flexsections editor, the exact fault the admin integrity tooling hunts for.
 *   2. Referential: the plugin's own aiplacement_modgen_aigen tracker table
 *      holding rows for cmids that have been deleted. The course_module_deleted
 *      event observer (-> aigen_tracker::remove_marker) is supposed to prevent
 *      this. Nothing currently proves the observer actually fires and cleans up.
 *
 * These tests delete content the way a teacher does (course_delete_module /
 * course_delete_section_async-equivalent via core APIs) and then assert no
 * orphaned structure and no stale tracker rows remain, including after a
 * delete-then-regenerate cycle.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\aigen_tracker;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Deletion & cleanup integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\event\observer
 */
final class deletion_integrity_test extends advanced_testcase {

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
     * Find orphaned parent options: 'parent' rows whose value points at a section
     * number that no longer exists in this course.
     *
     * @return int[] List of section ids carrying an orphaned parent reference.
     */
    private function find_orphaned_parent_options(): array {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $this->course->id]);
        $existingnums = array_flip(array_map('intval', array_column($sections, 'section')));

        $parentrows = $DB->get_records('course_format_options', [
            'courseid' => $this->course->id,
            'format'   => 'flexsections',
            'name'     => 'parent',
        ]);

        $orphaned = [];
        foreach ($parentrows as $row) {
            $parent = (int)$row->value;
            if ($parent !== 0 && !isset($existingnums[$parent])) {
                $orphaned[] = (int)$row->sectionid;
            }
        }
        return $orphaned;
    }

    /**
     * Build a theme payload with activities so tracker rows get created.
     *
     * @return array
     */
    private function payload_with_activities(): array {
        // Sessions must be phase-keyed ('session' => [...]), not a positional list,
        // for create_session_activities() to place and track the activities.
        return ['themes' => [
            ['title' => 'Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Week', 'summary' => 'w', 'sessions' => [
                    'session' => [
                        'description' => 'd',
                        'activities' => [
                            ['type' => 'label', 'name' => 'Intro label', 'intro' => 'hello'],
                        ],
                    ],
                ]],
            ]],
        ]];
    }

    // ------------------------------------------------------------------
    // Observer-driven tracker cleanup.
    // ------------------------------------------------------------------

    /**
     * Deleting a tracked module must fire course_module_deleted and remove its
     * tracker row — proving the observer is wired up and runs.
     *
     * @covers ::course_module_deleted
     * @covers \aiplacement_modgen\aigen_tracker::remove_marker
     */
    public function test_module_deletion_cleans_tracker(): void {
        // Create a real module and mark it as AI-generated.
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        aigen_tracker::mark_as_aigenerated($label->cmid, $this->course->id);
        $this->assertTrue(aigen_tracker::is_aigenerated($label->cmid),
            'Pre-condition: module is tracked as AI-generated.');

        // Delete it the way Moodle does — this fires course_module_deleted.
        course_delete_module($label->cmid);

        $this->assertFalse(aigen_tracker::is_aigenerated($label->cmid),
            'Tracker row must be removed by the course_module_deleted observer.');
    }

    /**
     * Editing a tracked module fires course_module_updated, which clears its
     * AI-generated marker (the activity is no longer pristine AI output).
     *
     * @covers ::course_module_updated
     */
    public function test_module_update_clears_marker(): void {
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        aigen_tracker::mark_as_aigenerated($label->cmid, $this->course->id);

        // Trigger a course_module_updated event via the core edit path.
        $cm = get_coursemodule_from_id('label', $label->cmid, 0, false, MUST_EXIST);
        \core\event\course_module_updated::create_from_cm($cm)->trigger();

        $this->assertFalse(aigen_tracker::is_aigenerated($label->cmid),
            'Editing a module must clear its AI-generated marker.');
    }

    /**
     * After deleting a generated module, the tracker must hold no row referencing
     * its cmid — no dangling references survive a real generate-then-delete flow.
     *
     * @covers ::course_module_deleted
     */
    public function test_generated_then_deleted_leaves_no_stale_tracker_rows(): void {
        global $DB;

        (new section_creation_service())->create_sections_from_json(
            $this->payload_with_activities(),
            $this->course->id, 'connected_theme', true, true, false
        );
        $this->resetDebugging();

        $trackedcmids = aigen_tracker::get_aigenerated_cmids($this->course->id);
        $this->assertNotEmpty($trackedcmids,
            'Pre-condition: generation with activities should produce tracked cmids '
            . '(otherwise this test is vacuous).');

        // Delete every tracked module and confirm the observer cleans up each row.
        foreach ($trackedcmids as $cmid) {
            if ($DB->record_exists('course_modules', ['id' => $cmid])) {
                course_delete_module($cmid);
            }
        }

        $remaining = aigen_tracker::get_aigenerated_cmids($this->course->id);
        foreach ($remaining as $cmid) {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]),
                "Tracker row for cmid {$cmid} survives but its module was deleted (stale reference).");
        }
    }

    // ------------------------------------------------------------------
    // Structural integrity after section deletion.
    // ------------------------------------------------------------------

    /**
     * Deleting a generated section must not leave child sections orphaned — their
     * 'parent' option must not keep pointing at a section number that no longer
     * exists, which would 500 the editor.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_deleting_parent_section_leaves_no_orphans(): void {
        global $DB;

        // Generate a theme -> week structure.
        (new section_creation_service())->create_sections_from_json(
            ['themes' => [
                ['title' => 'Theme to delete', 'summary' => 's', 'weeks' => [
                    ['title' => 'Child week', 'summary' => 'w', 'sessions' => []],
                ]],
            ]],
            $this->course->id, 'connected_theme', false, false, false
        );
        $this->resetDebugging();

        // Find the theme (top-level) section and its child week.
        $theme = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'name' => 'Theme to delete'], '*', MUST_EXIST);

        // Delete the parent theme section via the core API (cascades children).
        course_delete_section($this->course->id, $theme->section, true);
        rebuild_course_cache($this->course->id, true, true);

        $orphans = $this->find_orphaned_parent_options();
        $this->assertSame([], $orphans,
            'Deleting a parent section must not leave orphaned parent references: '
            . implode(', ', $orphans));

        // The editor state must still render after the deletion.
        $modinfo = get_fast_modinfo($this->course->id);
        $this->assertNotEmpty($modinfo->get_section_info_all());
    }

    /**
     * A delete-then-regenerate cycle must not accumulate orphaned references or
     * corrupt the structure — the common teacher workflow of "scrap it and try again".
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_delete_and_regenerate_cycle_stays_sound(): void {
        global $DB;

        $service = new section_creation_service();

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            // Generate.
            $service->create_sections_from_json(
                ['themes' => [
                    ['title' => "Cycle {$cycle} Theme", 'summary' => 's', 'weeks' => [
                        ['title' => "Cycle {$cycle} Week", 'summary' => 'w', 'sessions' => []],
                    ]],
                ]],
                $this->course->id, 'connected_theme', false, false, false
            );
            $this->resetDebugging();

            // Delete every generated top-level theme section.
            $themes = $DB->get_records_select('course_sections',
                "course = :c AND section > 0 AND " . $DB->sql_like('name', ':pat'),
                ['c' => $this->course->id, 'pat' => 'Cycle%']);
            foreach ($themes as $theme) {
                // Re-read: section numbers shift as siblings are removed.
                if ($DB->record_exists('course_sections', ['id' => $theme->id])) {
                    $current = $DB->get_record('course_sections', ['id' => $theme->id]);
                    course_delete_section($this->course->id, $current->section, true);
                }
            }
            rebuild_course_cache($this->course->id, true, true);

            $this->assertSame([], $this->find_orphaned_parent_options(),
                "Cycle {$cycle}: no orphaned parent references after delete.");
        }

        // Final render check.
        $this->assertNotEmpty(get_fast_modinfo($this->course->id)->get_section_info_all());
    }

    /**
     * remove_marker on an unknown cmid is a harmless no-op (idempotent cleanup) —
     * the observer must never error when a delete event arrives for an untracked
     * module.
     *
     * @covers \aiplacement_modgen\aigen_tracker::remove_marker
     */
    public function test_remove_marker_idempotent_for_untracked_cmid(): void {
        // No exception, returns cleanly even though nothing is tracked.
        $result = aigen_tracker::remove_marker(999999);
        $this->assertIsBool($result);
        $this->assertFalse(aigen_tracker::is_aigenerated(999999));
    }
}
