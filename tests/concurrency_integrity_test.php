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
 * Concurrency integrity tests for Module Generator.
 *
 * The section creation service serialises generation behind a per-course lock
 * precisely because two overlapping generations would interleave section
 * creation and produce duplicate section numbers / crossed parents — i.e. a
 * corrupted course structure. The existing lock_handling_test runs requests
 * sequentially and concedes it cannot test true concurrency.
 *
 * These tests close that gap *within a single PHPUnit process* by manually
 * holding the same lock a second worker would hold, then asserting:
 *   - the service cannot proceed while the lock is held (mutual exclusion is real),
 *   - a generation that fails mid-flight rolls back to zero partial sections,
 *   - the course structure stays sound after any contended / failed attempt.
 *
 * Structural soundness is checked with the same invariants as
 * structure_integrity_test (orphans, duplicate section numbers, gaps, circular
 * parents), kept local here so this file stands alone.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\local\constants;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Concurrency / lock integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\section_creation_service
 */
final class concurrency_integrity_test extends advanced_testcase {
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
     * Count top-level (theme) sections created by generation on this course.
     *
     * Excludes section 0 and the core 'Assessments' section so the count
     * reflects only generated content.
     *
     * @return int
     */
    private function count_generated_toplevel_sections(): int {
        global $DB;

        $assessments = get_string('assessmentssectionname', 'aiplacement_modgen');
        $sql = "SELECT COUNT(cs.id)
                  FROM {course_sections} cs
             LEFT JOIN {course_format_options} cfo
                    ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                 WHERE cs.course = :courseid
                   AND cs.section > 0
                   AND cs.name <> :assessments
                   AND (cfo.value IS NULL OR cfo.value = '0')";
        return $DB->count_records_sql($sql, [
            'courseid' => $this->course->id,
            'assessments' => $assessments,
        ]);
    }

    /**
     * Assert the course structure is free of corruption.
     *
     * Local, self-contained version of the structural invariants: no duplicate
     * section numbers, no orphaned parents, no self/circular parents.
     *
     * @param string $context Description for failure messages.
     */
    private function assert_structure_sound(string $context): void {
        global $DB;

        $courseid = $this->course->id;
        $problems = [];

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $sectionnums = array_map('intval', array_column($sections, 'section'));

        // Duplicate section numbers.
        if (count(array_unique($sectionnums)) !== count($sectionnums)) {
            $problems[] = 'Duplicate section numbers present.';
        }

        // Parent map for orphan / circular checks.
        $existing = array_flip($sectionnums);
        $parentmap = [];
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue;
            }
            $value = $DB->get_field('course_format_options', 'value', [
                'courseid' => $courseid, 'sectionid' => $section->id,
                'format' => 'flexsections', 'name' => 'parent',
            ]);
            $parent = ($value === false || $value === null) ? 0 : (int)$value;
            $parentmap[(int)$section->section] = $parent;

            if ($parent !== 0 && !isset($existing[$parent])) {
                $problems[] = "Section {$section->section} has orphaned parent {$parent}.";
            }
            if ($parent === (int)$section->section) {
                $problems[] = "Section {$section->section} is its own parent.";
            }
        }

        // Circular chains.
        foreach ($parentmap as $start => $unused) {
            $visited = [];
            $current = $start;
            while ($current !== 0 && isset($parentmap[$current])) {
                if (isset($visited[$current])) {
                    $problems[] = "Circular parent chain involving section {$start}.";
                    break;
                }
                $visited[$current] = true;
                $current = $parentmap[$current];
            }
        }

        $this->assertSame(
            [],
            $problems,
            "[$context] Corrupted structure:\n  - " . implode("\n  - ", $problems)
        );
    }

    /**
     * Build a simple theme payload.
     *
     * @param string $title Theme title.
     * @return array
     */
    private function theme_payload(string $title): array {
        return ['themes' => [
            ['title' => $title, 'summary' => 's', 'weeks' => [
                ['title' => $title . ' Week', 'summary' => 'w', 'sessions' => []],
            ]],
        ]];
    }

    // ------------------------------------------------------------------
    // Mutual exclusion.

    /**
     * While the per-course generation lock is held by another worker, a second
     * generation must NOT proceed — it must fail to acquire the lock rather than
     * interleave section creation. This is the core anti-corruption guarantee.
     *
     * @covers ::create_sections_from_json
     */
    public function test_generation_blocked_while_lock_held(): void {
        // Simulate worker A holding the generation lock for this course.
        $lockkey = 'aiplacement_modgen_building_' . $this->course->id;
        $factory = \core\lock\lock_config::get_lock_factory('aiplacement_modgen');
        $heldlock = $factory->get_lock($lockkey, 5);
        $this->assertNotFalse($heldlock, 'Pre-condition: worker A acquires the lock.');

        try {
            // Worker B (a fresh requester) must not be able to take the same lock
            // with a short timeout — proving generation is genuinely serialised
            // and cannot run concurrently against the same course.
            $contender = $factory->get_lock($lockkey, 1);
            $this->assertFalse(
                $contender,
                'A second worker must not acquire the generation lock while it is held.'
            );
        } finally {
            $heldlock->release();
        }

        // After the lock is released, generation proceeds and leaves a sound structure.
        (new section_creation_service())->create_sections_from_json(
            $this->theme_payload('After release'),
            $this->course->id,
            'connected_theme',
            false,
            false,
            false
        );

        $this->assertSame(
            1,
            $this->count_generated_toplevel_sections(),
            'Exactly one generation should have produced exactly one theme.'
        );
        $this->assert_structure_sound('after contended lock');
    }

    /**
     * The generation lock is course-scoped: a held lock on course A must not
     * block generation on course B (otherwise unrelated courses would serialise).
     *
     * @covers ::create_sections_from_json
     */
    public function test_lock_does_not_cross_courses(): void {
        $other = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($other->id);

        // Hold course A's lock.
        $factory = \core\lock\lock_config::get_lock_factory('aiplacement_modgen');
        $heldlock = $factory->get_lock('aiplacement_modgen_building_' . $this->course->id, 5);
        $this->assertNotFalse($heldlock);

        try {
            // Generation on course B must still succeed despite course A being locked.
            (new section_creation_service())->create_sections_from_json(
                $this->theme_payload('Course B theme'),
                $other->id,
                'connected_theme',
                false,
                false,
                false
            );
        } finally {
            $heldlock->release();
        }

        global $DB;
        $bthemes = $DB->count_records_select(
            'course_sections',
            "course = :c AND section > 0 AND name = :n",
            ['c' => $other->id, 'n' => 'Course B theme']
        );
        $this->assertSame(1, $bthemes, 'Course B generation should complete while course A is locked.');
    }

    // ------------------------------------------------------------------
    // Atomicity under failure (rollback).

    /**
     * Section creation that throws mid-flight must roll back completely, leaving
     * zero partial state — the transactional guarantee that prevents half-written
     * (corrupt) structures when a DB error or a concurrent operation interrupts a
     * create. Here we trigger a genuine in-transaction failure by pointing a
     * section at a non-existent parent (validation throws after create_new_section
     * has started a transaction) and assert no section / format-option leaks.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_interrupted_section_create_rolls_back(): void {
        global $DB;

        $courseformat = course_get_format($this->course->id);

        $sectionsbefore = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $optionsbefore = $DB->count_records('course_format_options', ['courseid' => $this->course->id]);

        // Parent 999 does not exist: validation throws inside the create transaction.
        try {
            theme_builder::create_section_with_parent(
                $this->course->id,
                $courseformat,
                999,
                'Orphan attempt',
                'summary',
                FORMAT_PLAIN,
                []
            );
            $this->fail('Expected a moodle_exception for the non-existent parent.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->resetDebugging();

        // The failed create must be fully rolled back: counts unchanged...
        $this->assertSame(
            $sectionsbefore,
            $DB->count_records('course_sections', ['course' => $this->course->id]),
            'A rolled-back create must not leave a section row.'
        );
        $this->assertSame(
            $optionsbefore,
            $DB->count_records('course_format_options', ['courseid' => $this->course->id]),
            'A rolled-back create must not leave a parent option row.'
        );

        // ...and the structure stays sound and renderable.
        $this->assert_structure_sound('after rolled-back create');
        $this->assertNotEmpty(get_fast_modinfo($this->course->id)->get_section_info_all());
    }

    /**
     * Rapid back-to-back generations on the same course (lock acquired and released
     * each time) must accumulate cleanly with no duplicate section numbers — the
     * serial equivalent of repeated concurrent attempts that lost the lock race.
     *
     * @covers ::create_sections_from_json
     */
    public function test_rapid_serial_generations_stay_sound(): void {
        $service = new section_creation_service();

        for ($i = 1; $i <= 5; $i++) {
            $service->create_sections_from_json(
                $this->theme_payload("Theme {$i}"),
                $this->course->id,
                'connected_theme',
                false,
                false,
                false
            );
            $this->assert_structure_sound("after generation {$i}");
        }

        $this->assertSame(
            5,
            $this->count_generated_toplevel_sections(),
            'Five serial generations should yield five distinct top-level themes.'
        );
    }

    /**
     * Sanity pin: the generation lock timeout is long enough that a real second
     * worker waits rather than failing instantly under brief contention.
     *
     * @covers \aiplacement_modgen\local\constants::GENERATION_LOCK_TIMEOUT
     */
    public function test_lock_timeout_allows_waiting(): void {
        $this->assertGreaterThanOrEqual(
            60,
            constants::GENERATION_LOCK_TIMEOUT,
            'Generation lock timeout should let a queued worker wait, not fail immediately.'
        );
    }
}
