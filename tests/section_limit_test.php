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
 * Section-limit guard tests for Module Generator.
 *
 * flexsections section creation is ~O(n^2) in a course's total section count, so a
 * cumulative cap ('maxtotalsections') refuses a generation before it starts if it
 * would push the course past a safe size. The cap lives at the creation entry points
 * (Quick Add create_themes/create_weeks and the JSON service), so it covers every
 * path — including retries — not just the AJAX form.
 *
 * These tests verify the cap is enforced, counts existing + projected sections,
 * is configurable, and (critically) fails fast WITHOUT creating partial content.
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
 * Section-limit guard tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\theme_builder
 */
final class section_limit_test extends advanced_testcase {
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
     * Total sections currently in the course.
     *
     * @return int
     */
    private function total_sections(): int {
        global $DB;
        return $DB->count_records('course_sections', ['course' => $this->course->id]);
    }

    // ------------------------------------------------------------------
    // Tests for enforce_section_limit().

    /**
     * A projected total within the limit is allowed (no exception).
     *
     * @covers ::enforce_section_limit
     */
    public function test_within_limit_is_allowed(): void {
        set_config('maxtotalsections', 100, 'aiplacement_modgen');

        // Existing is small; projecting 50 more stays under 100.
        theme_builder::enforce_section_limit($this->course->id, 50);
        $this->assertTrue(true, 'A within-limit projection must not throw.');
    }

    /**
     * A projected total over the limit is refused.
     *
     * @covers ::enforce_section_limit
     */
    public function test_over_limit_is_refused(): void {
        set_config('maxtotalsections', 100, 'aiplacement_modgen');

        $this->expectException(\moodle_exception::class);
        theme_builder::enforce_section_limit($this->course->id, 500);
    }

    /**
     * The limit counts EXISTING sections too, not just the projection — so repeated
     * generations into one course are bounded, which is what a per-request UI cap
     * misses.
     *
     * @covers ::enforce_section_limit
     */
    public function test_existing_sections_count_towards_limit(): void {
        global $DB;
        $courseformat = course_get_format($this->course->id);

        // Fill the course close to a small limit with real sections.
        for ($i = 0; $i < 8; $i++) {
            theme_builder::create_section_with_parent(
                $this->course->id,
                $courseformat,
                0,
                "Existing {$i}",
                '',
                FORMAT_PLAIN,
                ['collapsed' => 1]
            );
        }
        $existing = $this->total_sections();
        set_config('maxtotalsections', $existing + 3, 'aiplacement_modgen');

        // Projecting just 2 more is fine...
        theme_builder::enforce_section_limit($this->course->id, 2);

        // ...but projecting 10 more exceeds existing + 3.
        $this->expectException(\moodle_exception::class);
        theme_builder::enforce_section_limit($this->course->id, 10);
    }

    /**
     * A non-positive configured value falls back to the built-in default rather than
     * disabling the safety limit.
     *
     * @covers ::enforce_section_limit
     */
    public function test_invalid_config_falls_back_to_default(): void {
        set_config('maxtotalsections', 0, 'aiplacement_modgen');

        // Default is constants::MAX_TOTAL_SECTIONS (300); projecting beyond it must throw.
        $this->expectException(\moodle_exception::class);
        theme_builder::enforce_section_limit($this->course->id, 100000);
    }

    // ------------------------------------------------------------------
    // JSON projection counting.

    /**
     * The JSON projector counts themes + weeks + sessions for connected_theme.
     *
     * @covers ::count_projected_sections_from_json
     */
    public function test_json_projection_counts_theme_structure(): void {
        $json = ['themes' => [
            ['title' => 'T1', 'weeks' => [
                ['title' => 'W1', 'sessions' => [['phase' => 'a'], ['phase' => 'b'], ['phase' => 'c']]],
                ['title' => 'W2', 'sessions' => []],
            ]],
            ['title' => 'T2', 'weeks' => []],
        ]];

        // 2 themes + 2 weeks + 3 sessions = 7.
        $this->assertSame(
            7,
            theme_builder::count_projected_sections_from_json($json, 'connected_theme')
        );
    }

    /**
     * connected_weekly assumes 3 session subsections per section when none listed.
     *
     * @covers ::count_projected_sections_from_json
     */
    public function test_json_projection_counts_weekly_default_sessions(): void {
        $json = ['sections' => [
            ['title' => 'Wk1'],
            ['title' => 'Wk2'],
        ]];

        // 2 sections, each materialising 3 session subsections = 2 + 6 = 8.
        $this->assertSame(
            8,
            theme_builder::count_projected_sections_from_json($json, 'connected_weekly')
        );
    }

    // ------------------------------------------------------------------
    // End-to-end enforcement at the entry points, with no partial writes.

    /**
     * create_themes refuses an over-limit request and creates nothing.
     *
     * @covers ::create_themes
     */
    public function test_create_themes_refuses_and_writes_nothing(): void {
        set_config('maxtotalsections', 20, 'aiplacement_modgen');
        $before = $this->total_sections();

        try {
            // 10 themes x 5 weeks => 10 + 200 = 210 sections, well over 20.
            theme_builder::create_themes($this->course->id, 10, 5);
            $this->fail('Over-limit create_themes should throw.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            $before,
            $this->total_sections(),
            'A refused generation must not create any partial sections.'
        );
    }

    /**
     * The JSON service refuses an over-limit structure and creates nothing.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_json_service_refuses_and_writes_nothing(): void {
        set_config('maxtotalsections', 10, 'aiplacement_modgen');
        $before = $this->total_sections();

        $themes = [];
        for ($t = 1; $t <= 5; $t++) {
            $themes[] = ['title' => "T{$t}", 'summary' => 's', 'weeks' => [
                ['title' => "W{$t}", 'summary' => 'w', 'sessions' => []],
            ]];
        }

        try {
            (new section_creation_service())->create_sections_from_json(
                ['themes' => $themes],
                $this->course->id,
                'connected_theme',
                false,
                false,
                false
            );
            $this->fail('Over-limit JSON generation should throw.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->resetDebugging();

        $this->assertSame(
            $before,
            $this->total_sections(),
            'A refused JSON generation must not create any partial sections.'
        );
    }

    /**
     * A generation that fits the limit still succeeds (the guard is not over-eager).
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_within_limit_generation_succeeds(): void {
        set_config('maxtotalsections', 100, 'aiplacement_modgen');
        $before = $this->total_sections();

        (new section_creation_service())->create_sections_from_json(
            ['themes' => [['title' => 'OK Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'OK Week', 'summary' => 'w', 'sessions' => []],
            ]]]],
            $this->course->id,
            'connected_theme',
            false,
            false,
            false
        );
        $this->resetDebugging();

        $this->assertGreaterThan(
            $before,
            $this->total_sections(),
            'A within-limit generation should create its sections.'
        );
    }
}
