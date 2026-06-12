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
 * Course structure integrity tests for Module Generator.
 *
 * These tests defend against *corrupted course structures* — the class of database
 * faults that cause flexsections / core_courseformat_get_state to throw a 500 error
 * when a user opens the course editor. They complement the more granular parent-field
 * and transaction tests by:
 *
 *   1. Providing a single reusable integrity auditor (audit_structure()) that asserts
 *      every invariant debug_integrity.php checks for, over the WHOLE course at once.
 *   2. Proving the section creation service never emits a corrupt structure across
 *      realistic, large, and adversarial (malformed AI JSON) inputs.
 *   3. Proving the generated structure survives Moodle's own state rendering
 *      (get_fast_modinfo + core_courseformat\stateupdates) — the real failure surface.
 *   4. Negative-control tests that deliberately inject each corruption type and confirm
 *      the auditor catches it, so the auditor cannot silently rot into a no-op.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\local\section_creation_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Integrity tests for generated course structures.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\section_creation_service
 */
final class structure_integrity_test extends advanced_testcase {

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

    // ------------------------------------------------------------------
    // Reusable integrity auditor.
    // ------------------------------------------------------------------

    /**
     * Audit a course's section structure for every corruption class.
     *
     * Mirrors the checks performed by debug_integrity.php, but as a pure function
     * returning a list of problems instead of rendering HTML. An empty list means
     * the structure is sound.
     *
     * Checks performed:
     *   - Course is in flexsections format.
     *   - No duplicate section numbers.
     *   - No gaps in section numbering (section column must be 0..N contiguous).
     *   - Every non-zero section has exactly one numeric parent value (or defaults to 0).
     *   - No orphaned sections (parent points to a section that exists).
     *   - No section is its own parent.
     *   - No circular parent chains.
     *   - Every course_module has a context (the orphaned-module fault).
     *
     * @param int $courseid Course ID.
     * @return string[] List of human-readable integrity problems (empty == healthy).
     */
    private function audit_structure(int $courseid): array {
        global $DB;

        $problems = [];

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        if ($course->format !== 'flexsections') {
            $problems[] = "Course format is '{$course->format}', expected 'flexsections'.";
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

        // Section numbering: no duplicates, no gaps.
        $sectionnums = array_map('intval', array_column($sections, 'section'));
        $unique = array_unique($sectionnums);
        if (count($unique) !== count($sectionnums)) {
            $counts = array_count_values($sectionnums);
            foreach ($counts as $num => $count) {
                if ($count > 1) {
                    $problems[] = "Duplicate section number {$num} appears {$count} times.";
                }
            }
        }
        if (!empty($sectionnums)) {
            $expected = range(0, max($sectionnums));
            $missing = array_diff($expected, $sectionnums);
            if (!empty($missing)) {
                $problems[] = "Gaps in section numbering: " . implode(', ', $missing) . '.';
            }
        }

        // Build a section-number -> parent-number map from course_format_options.
        $parentmap = [];        // sectionnum => parent sectionnum (int).
        $parentoptioncount = []; // sectionid => number of 'parent' rows (must be <= 1).
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue; // Section 0 has no parent.
            }

            $parentrows = $DB->get_records('course_format_options', [
                'courseid'  => $courseid,
                'sectionid' => $section->id,
                'format'    => 'flexsections',
                'name'      => 'parent',
            ]);
            $parentoptioncount[$section->id] = count($parentrows);

            if (count($parentrows) > 1) {
                $problems[] = "Section {$section->section} has " . count($parentrows)
                    . ' parent options (must be exactly one).';
            }

            if (empty($parentrows)) {
                // Missing parent option defaults to top level (0) in flexsections — tolerated.
                $parentmap[(int)$section->section] = 0;
                continue;
            }

            $value = reset($parentrows)->value;
            if (!is_numeric($value)) {
                $problems[] = "Section {$section->section} has non-numeric parent value '{$value}'.";
                continue;
            }
            $parentmap[(int)$section->section] = (int)$value;
        }

        // Existence of section numbers for orphan checking.
        $existingnums = array_flip($sectionnums);

        foreach ($parentmap as $sectionnum => $parentnum) {
            if ($parentnum === 0) {
                continue; // Top level.
            }
            // Self-parent.
            if ($parentnum === $sectionnum) {
                $problems[] = "Section {$sectionnum} is its own parent.";
                continue;
            }
            // Orphan: parent does not exist.
            if (!isset($existingnums[$parentnum])) {
                $problems[] = "Section {$sectionnum} has orphaned parent {$parentnum} (no such section).";
            }
        }

        // Circular parent chains (walk each section up to the root).
        foreach ($parentmap as $sectionnum => $unused) {
            $visited = [];
            $current = $sectionnum;
            while ($current !== 0 && isset($parentmap[$current])) {
                if (isset($visited[$current])) {
                    $problems[] = "Circular parent chain detected involving section {$sectionnum}.";
                    break;
                }
                $visited[$current] = true;
                $current = $parentmap[$current];
            }
        }

        // Orphaned course modules (no context) — the integrity-warning fault during cache rebuild.
        $orphanedmodules = $DB->count_records_sql(
            "SELECT COUNT(cm.id)
               FROM {course_modules} cm
          LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
              WHERE cm.course = :courseid AND ctx.id IS NULL",
            ['courseid' => $courseid, 'contextlevel' => CONTEXT_MODULE]
        );
        if ($orphanedmodules > 0) {
            $problems[] = "{$orphanedmodules} course module(s) have no context.";
        }

        return $problems;
    }

    /**
     * Assert that a course passes the full integrity audit.
     *
     * @param int $courseid Course ID.
     * @param string $context Description for failure messages.
     */
    private function assert_structure_sound(int $courseid, string $context = ''): void {
        $problems = $this->audit_structure($courseid);
        $this->assertSame([], $problems,
            ($context !== '' ? "[$context] " : '') . "Corrupted course structure:\n  - "
            . implode("\n  - ", $problems));
    }

    /**
     * Assert that Moodle's own course-state rendering succeeds for the course.
     *
     * This is the operation that 500s on a corrupt flexsections structure when a
     * user opens the editor (core_courseformat_get_state). If the generated
     * structure is sound, this must not throw.
     *
     * @param int $courseid Course ID.
     */
    private function assert_course_state_renders(int $courseid): void {
        rebuild_course_cache($courseid, true, true);

        // get_fast_modinfo() walks the full section tree; corrupt parents blow up here.
        $modinfo = get_fast_modinfo($courseid);
        $this->assertNotEmpty($modinfo->get_section_info_all(),
            'Course should expose at least section 0 via modinfo.');

        if (!class_exists('core_courseformat\\stateupdates')) {
            // Older Moodle without the reactive state API — modinfo check above is sufficient.
            return;
        }

        $format = course_get_format($courseid);
        $stateupdates = new \core_courseformat\stateupdates($format);

        // Request the full course state — exactly what the AJAX editor endpoint does.
        $format->set_sections_preference('contentcollapsed', []);
        $stateupdates->add_course_put();
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $stateupdates->add_section_put($sectioninfo->id);
        }

        $updates = $stateupdates->jsonSerialize();
        $this->assertNotEmpty($updates,
            'core_courseformat state must serialise without error for a sound structure.');
    }

    // ------------------------------------------------------------------
    // Positive tests: the service must never emit corruption.
    // ------------------------------------------------------------------

    /**
     * A typical theme/week/session structure must be sound and renderable.
     *
     * @covers ::create_sections_from_json
     */
    public function test_generated_theme_structure_is_sound(): void {
        $json = [
            'themes' => [
                [
                    'title' => 'Theme A',
                    'summary' => 'Intro to A',
                    'weeks' => [
                        ['title' => 'Week A1', 'summary' => 'w', 'sessions' => [
                            ['phase' => 'pre', 'description' => 'p', 'activities' => []],
                            ['phase' => 'session', 'description' => 's', 'activities' => []],
                            ['phase' => 'post', 'description' => 'q', 'activities' => []],
                        ]],
                        ['title' => 'Week A2', 'summary' => 'w', 'sessions' => []],
                    ],
                ],
                [
                    'title' => 'Theme B',
                    'summary' => 'Intro to B',
                    'weeks' => [
                        ['title' => 'Week B1', 'summary' => 'w', 'sessions' => []],
                    ],
                ],
            ],
        ];

        (new section_creation_service())->create_sections_from_json(
            $json, $this->course->id, 'connected_theme', false, false, false
        );

        $this->assert_structure_sound($this->course->id, 'theme structure');
        $this->assert_course_state_renders($this->course->id);
    }

    /**
     * A connected_weekly structure must be sound and renderable.
     *
     * @covers ::create_sections_from_json
     */
    public function test_generated_weekly_structure_is_sound(): void {
        $json = [
            'sections' => [
                ['title' => 'Week 1', 'summary' => 'Summary 1', 'outline' => [['text' => 'a'], ['text' => 'b']]],
                ['title' => 'Week 2', 'summary' => 'Summary 2'],
                ['title' => 'Week 3', 'summary' => 'Summary 3'],
            ],
        ];

        (new section_creation_service())->create_sections_from_json(
            $json, $this->course->id, 'connected_weekly', false, false, false
        );

        $this->assert_structure_sound($this->course->id, 'weekly structure');
        $this->assert_course_state_renders($this->course->id);
    }

    /**
     * Hiding + reordering existing sections must not corrupt the structure.
     *
     * The reorder path (hide_and_reorder_sections) moves sections around, which is
     * exactly where section-number gaps or broken parents historically appeared.
     *
     * @covers ::create_sections_from_json
     */
    public function test_hide_and_reorder_preserves_integrity(): void {
        // Pre-existing top-level sections that will be hidden and shuffled past.
        $this->getDataGenerator()->create_course_section(['course' => $this->course->id, 'section' => 1]);
        $this->getDataGenerator()->create_course_section(['course' => $this->course->id, 'section' => 2]);

        $json = [
            'themes' => [
                ['title' => 'New Theme 1', 'summary' => 's', 'weeks' => [
                    ['title' => 'W1', 'summary' => 'w', 'sessions' => []],
                ]],
                ['title' => 'New Theme 2', 'summary' => 's', 'weeks' => []],
            ],
        ];

        (new section_creation_service())->create_sections_from_json(
            $json, $this->course->id, 'connected_theme', false, false, true // hideexistingsections.
        );

        $this->assert_structure_sound($this->course->id, 'after hide & reorder');
        $this->assert_course_state_renders($this->course->id);
    }

    /**
     * Repeated generate cycles on the same course must never accumulate corruption.
     *
     * @covers ::create_sections_from_json
     */
    public function test_repeated_generation_cycles_stay_sound(): void {
        $service = new section_creation_service();

        for ($cycle = 1; $cycle <= 4; $cycle++) {
            $json = [
                'themes' => [
                    ['title' => "Cycle {$cycle} Theme", 'summary' => 's', 'weeks' => [
                        ['title' => "Cycle {$cycle} Week", 'summary' => 'w', 'sessions' => []],
                    ]],
                ],
            ];
            $service->create_sections_from_json(
                $json, $this->course->id, 'connected_theme', false, false, false
            );
            $this->assert_structure_sound($this->course->id, "after cycle {$cycle}");
        }

        $this->assert_course_state_renders($this->course->id);
    }

    /**
     * A large structure must remain sound (mass-volume corruption regression).
     *
     * @covers ::create_sections_from_json
     */
    public function test_large_structure_stays_sound(): void {
        $themes = [];
        for ($t = 1; $t <= 6; $t++) {
            $weeks = [];
            for ($w = 1; $w <= 6; $w++) {
                $weeks[] = ['title' => "T{$t}W{$w}", 'summary' => 'w', 'sessions' => []];
            }
            $themes[] = ['title' => "Theme {$t}", 'summary' => 's', 'weeks' => $weeks];
        }

        (new section_creation_service())->create_sections_from_json(
            ['themes' => $themes], $this->course->id, 'connected_theme', false, false, false
        );

        $this->assert_structure_sound($this->course->id, 'large structure');
        $this->assert_course_state_renders($this->course->id);
    }

    // ------------------------------------------------------------------
    // Adversarial inputs: malformed AI JSON must degrade gracefully, never corrupt.
    // ------------------------------------------------------------------

    /**
     * Malformed AI JSON must not produce a corrupt structure.
     *
     * AI output is untrusted: themes may be non-arrays, miss titles, carry nested
     * garbage, or use absurd values. The service must skip/sanitise bad entries and
     * leave the course in a sound, renderable state regardless.
     *
     * @dataProvider malformed_json_provider
     * @covers ::create_sections_from_json
     *
     * @param array $json The malformed structure.
     * @param string $moduletype Module type to process as.
     */
    public function test_malformed_json_never_corrupts(array $json, string $moduletype): void {
        try {
            (new section_creation_service())->create_sections_from_json(
                $json, $this->course->id, $moduletype, false, false, false
            );
        } catch (\Throwable $e) {
            // A clean rejection is an acceptable outcome — the contract is "no corruption",
            // not "always succeed". What must NOT happen is a half-written structure.
            // Catch \Throwable (not just moodle_exception): adversarial input may surface
            // any exception type, and the integrity guarantee must hold regardless.
            $this->assertNotEmpty($e->getMessage());
        }

        // Some adversarial inputs (e.g. over-long titles) legitimately emit developer
        // debugging() notices on the rejection path. Those are expected here; the
        // contract under test is structural integrity, not silence.
        $this->resetDebugging();

        // Whether it succeeded or threw, the course must still be sound and renderable.
        $this->assert_structure_sound($this->course->id, 'malformed: ' . $moduletype);
        $this->assert_course_state_renders($this->course->id);
    }

    /**
     * Data provider of malformed / adversarial AI structures.
     *
     * @return array<string, array{0: array, 1: string}>
     */
    public static function malformed_json_provider(): array {
        $longtitle = str_repeat('X', 5000);

        return [
            'empty themes array' => [
                ['themes' => []], 'connected_theme',
            ],
            'theme is not an array' => [
                ['themes' => ['not-an-array', 42, null]], 'connected_theme',
            ],
            'theme missing title' => [
                ['themes' => [['summary' => 'no title here', 'weeks' => []]]], 'connected_theme',
            ],
            'theme with blank title' => [
                ['themes' => [['title' => '   ', 'summary' => 's', 'weeks' => []]]], 'connected_theme',
            ],
            'weeks is wrong type' => [
                ['themes' => [['title' => 'T', 'summary' => 's', 'weeks' => 'oops']]], 'connected_theme',
            ],
            'week is not an array' => [
                ['themes' => [['title' => 'T', 'summary' => 's', 'weeks' => [null, 7, 'bad']]]], 'connected_theme',
            ],
            'sessions wrong type' => [
                ['themes' => [['title' => 'T', 'summary' => 's', 'weeks' => [
                    ['title' => 'W', 'summary' => 'w', 'sessions' => 'nope'],
                ]]]], 'connected_theme',
            ],
            'absurdly long title' => [
                ['themes' => [['title' => $longtitle, 'summary' => 's', 'weeks' => []]]], 'connected_theme',
            ],
            'html injection in title' => [
                ['themes' => [['title' => '<script>alert(1)</script>', 'summary' => '<b>x</b>', 'weeks' => []]]],
                'connected_theme',
            ],
            'weekly sections not arrays' => [
                ['sections' => ['x', 1, null, ['title' => 'OK', 'summary' => 's']]], 'connected_weekly',
            ],
            'weekly outline garbage' => [
                ['sections' => [['title' => 'W', 'summary' => 's', 'outline' => 'not-a-list']]], 'connected_weekly',
            ],
            'wrong key for module type' => [
                // 'sections' supplied but processed as connected_theme (expects 'themes').
                ['sections' => [['title' => 'W', 'summary' => 's']]], 'connected_theme',
            ],
            'completely empty payload' => [
                [], 'connected_theme',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Negative controls: the auditor must actually catch each corruption type.
    // ------------------------------------------------------------------

    /**
     * The auditor must flag an orphaned section (parent -> non-existent section).
     */
    public function test_auditor_catches_orphaned_section(): void {
        global $DB;

        $format = course_get_format($this->course->id);
        $num = $format->create_new_section(0, null);
        $section = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $num], '*', MUST_EXIST);

        // Point parent at a section number that does not exist.
        $DB->insert_record('course_format_options', (object) [
            'courseid'  => $this->course->id,
            'format'    => 'flexsections',
            'sectionid' => $section->id,
            'name'      => 'parent',
            'value'     => '9999',
        ]);

        $problems = $this->audit_structure($this->course->id);
        $this->assertNotEmpty($problems, 'Auditor must report the orphaned parent.');
        $this->assertStringContainsString('orphaned parent', implode("\n", $problems));
    }

    /**
     * The auditor must flag a self-referential parent.
     */
    public function test_auditor_catches_self_parent(): void {
        global $DB;

        $format = course_get_format($this->course->id);
        $num = $format->create_new_section(0, null);
        $section = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $num], '*', MUST_EXIST);

        $DB->insert_record('course_format_options', (object) [
            'courseid'  => $this->course->id,
            'format'    => 'flexsections',
            'sectionid' => $section->id,
            'name'      => 'parent',
            'value'     => (string) $num, // Points at itself.
        ]);

        $problems = $this->audit_structure($this->course->id);
        $this->assertStringContainsString('its own parent', implode("\n", $problems));
    }

    /**
     * The auditor must flag a circular parent chain (A -> B -> A).
     */
    public function test_auditor_catches_circular_chain(): void {
        global $DB;

        $format = course_get_format($this->course->id);
        $a = $format->create_new_section(0, null);
        $b = $format->create_new_section(0, null);

        $seca = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $a], '*', MUST_EXIST);
        $secb = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $b], '*', MUST_EXIST);

        // A's parent = B, B's parent = A.
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => $seca->id, 'name' => 'parent', 'value' => (string) $b,
        ]);
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => $secb->id, 'name' => 'parent', 'value' => (string) $a,
        ]);

        $problems = $this->audit_structure($this->course->id);
        $this->assertStringContainsString('Circular parent chain', implode("\n", $problems));
    }

    /**
     * The auditor must flag a non-flexsections course format.
     */
    public function test_auditor_catches_wrong_format(): void {
        global $DB;

        $DB->set_field('course', 'format', 'topics', ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true, true);

        $problems = $this->audit_structure($this->course->id);
        $this->assertStringContainsString("expected 'flexsections'", implode("\n", $problems));
    }

    /**
     * Duplicate parent options must be impossible at the schema level.
     *
     * Conflicting parent rows for one section would make the parent value
     * non-deterministic and could crash the reactive state builder. The
     * course_format_options table carries a UNIQUE index on
     * (courseid, format, sectionid, name), so this corruption cannot be
     * written in the first place. This test pins that guarantee: if a future
     * schema change drops the constraint, this test fails and the auditor's
     * duplicate-parent check (which would otherwise be dead code) starts
     * earning its keep.
     */
    public function test_duplicate_parent_options_blocked_by_schema(): void {
        global $DB;

        $format = course_get_format($this->course->id);
        $num = $format->create_new_section(0, null);
        $section = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'section' => $num], '*', MUST_EXIST);

        // First parent row may or may not already exist from create_new_section;
        // ensure exactly one is present, then prove a second cannot be inserted.
        $DB->delete_records('course_format_options', [
            'courseid' => $this->course->id, 'sectionid' => $section->id, 'name' => 'parent',
        ]);
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => $section->id, 'name' => 'parent', 'value' => '0',
        ]);

        $this->expectException(\dml_exception::class);
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course->id, 'format' => 'flexsections',
            'sectionid' => $section->id, 'name' => 'parent', 'value' => '0',
        ]);
    }

    /**
     * The auditor must flag a course module with no context.
     */
    public function test_auditor_catches_module_without_context(): void {
        global $DB;

        // Create a real module, then delete its context to simulate the orphaned-module fault.
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $context = \context_module::instance($page->cmid);
        $context->delete();

        $problems = $this->audit_structure($this->course->id);
        $this->assertStringContainsString('no context', implode("\n", $problems));
    }

    /**
     * Control: a freshly initialised flexsections course must be sound (auditor not over-eager).
     */
    public function test_auditor_passes_clean_course(): void {
        theme_builder::initialize_core_sections($this->course->id);
        rebuild_course_cache($this->course->id, true, true);

        $this->assert_structure_sound($this->course->id, 'clean initialised course');
    }
}
