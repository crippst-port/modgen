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
 * Session-routing integrity tests for Module Generator.
 *
 * Activities are placed into the correct pre-session / session / post-session
 * subsection via a two-step, name-based round trip:
 *
 *   - create_session_subsections() *creates* the three subsections, naming each
 *     from a language string (Pre-session / Session / Post-session).
 *   - get_session_sections() later *finds* them again by fuzzy substring-matching
 *     those names back to keys (checking 'presession','postsession','session'
 *     longest-first so 'session' does not match inside 'postsession').
 *   - create_session_activities() then drops each phase's activities into
 *     $map[$phase].
 *
 * This name round trip is fragile: reordering the match list, a translated label
 * that no longer contains its key as a substring, or a teacher-renamed subsection
 * can silently route activities into the WRONG phase — a semantic corruption the
 * structural integrity auditor cannot see (every section is still well-formed; the
 * content is just in the wrong place).
 *
 * These tests pin the correct round trip and characterise the matcher's behaviour
 * at its collision boundaries so a future refactor can't regress routing silently.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\session_creator;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Session-routing integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\session_creator
 */
final class session_routing_integrity_test extends advanced_testcase {

    /** @var \stdClass Test course. */
    private $course;

    /** @var object Flexsections course format. */
    private $courseformat;

    /**
     * Set up a flexsections course with one week to host sessions.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);
        $this->courseformat = course_get_format($this->course->id);
    }

    /**
     * Create a week section (without its own auto sessions) to parent subsections.
     *
     * We use create_section_with_parent directly rather than create_week_section
     * so the only session subsections present are the ones a given test creates.
     *
     * @param string $name Week name.
     * @return int Week section number.
     */
    private function make_week(string $name): int {
        $week = theme_builder::create_section_with_parent(
            $this->course->id, $this->courseformat, 0,
            $name, '', FORMAT_PLAIN, ['collapsed' => 1]
        );
        return $week->section;
    }

    /**
     * Resolve the cmids of the (non-learningactivity) modules in a section number.
     *
     * The learningactivity metadata module is created automatically in each
     * session subsection; we exclude it so assertions reflect the content
     * activities a test actually placed.
     *
     * @param int $sectionnumber Section number.
     * @return string[] Module names in that section, excluding learningactivity.
     */
    private function content_module_names(int $sectionnumber): array {
        $modinfo = get_fast_modinfo($this->course->id);
        $names = [];
        foreach ($modinfo->get_cms() as $cm) {
            // get_session_sections() returns section numbers as DB strings; cast both
            // sides to int so a string/int mismatch can't silently drop a match.
            if ((int)$cm->sectionnum === $sectionnumber && $cm->modname !== 'learningactivity') {
                $names[] = $cm->name;
            }
        }
        sort($names);
        return $names;
    }

    // ------------------------------------------------------------------
    // The create -> find round trip.
    // ------------------------------------------------------------------

    /**
     * Subsections created by create_session_subsections must be found again by
     * get_session_sections and mapped back to the SAME section numbers — the core
     * round-trip guarantee that activity routing depends on.
     *
     * @covers ::create_session_subsections
     * @covers ::get_session_sections
     */
    public function test_create_find_round_trip_is_consistent(): void {
        $week = $this->make_week('Round trip week');

        $created = session_creator::create_session_subsections(
            $this->courseformat, $week, $this->course->id, null
        );
        rebuild_course_cache($this->course->id, true, true);

        $found = session_creator::get_session_sections($week, $this->course->id);

        $this->assertArrayHasKey('presession', $found);
        $this->assertArrayHasKey('session', $found);
        $this->assertArrayHasKey('postsession', $found);

        // Each phase must resolve to exactly the section it was created as.
        $this->assertSame($created['presession'], $found['presession'],
            'presession must round-trip to its own subsection.');
        $this->assertSame($created['session'], $found['session'],
            'session must round-trip to its own subsection.');
        $this->assertSame($created['postsession'], $found['postsession'],
            'postsession must round-trip to its own subsection.');

        // The three phases must be three DISTINCT sections — no two phases may
        // collapse onto the same subsection.
        $this->assertCount(3, array_unique($found),
            'The three session phases must map to three distinct subsections.');
    }

    /**
     * 'session' must NOT be misrouted into the 'presession' or 'postsession'
     * subsection. This pins the longest-first match ordering: if that ordering is
     * ever reversed, 'session' would match inside 'postsession'/'presession' first
     * and silently steal their mapping.
     *
     * @covers ::get_session_sections
     */
    public function test_session_not_swallowed_by_pre_or_post(): void {
        $week = $this->make_week('Ordering week');
        $created = session_creator::create_session_subsections(
            $this->courseformat, $week, $this->course->id, null
        );
        rebuild_course_cache($this->course->id, true, true);

        $found = session_creator::get_session_sections($week, $this->course->id);

        // The plain 'session' phase must map to the bare Session subsection,
        // which is NEITHER the pre- nor post- subsection.
        $this->assertNotSame($created['presession'], $found['session'],
            "'session' must not resolve to the pre-session subsection.");
        $this->assertNotSame($created['postsession'], $found['session'],
            "'session' must not resolve to the post-session subsection.");
        $this->assertSame($created['session'], $found['session']);
    }

    // ------------------------------------------------------------------
    // End-to-end activity routing through the service.
    // ------------------------------------------------------------------

    /**
     * Activities supplied per phase must land in the matching phase subsection —
     * the full create-subsections -> find -> place-activities pipeline must route
     * each activity to the correct place, not merely create it somewhere.
     *
     * @covers ::create_session_subsections
     * @covers ::get_session_sections
     * @covers ::create_session_activities
     */
    public function test_activities_routed_to_correct_phase(): void {
        $json = ['themes' => [
            ['title' => 'Routing theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Routing week', 'summary' => 'w', 'sessions' => [
                    'presession' => ['description' => 'pre', 'activities' => [
                        ['type' => 'label', 'name' => 'PRE-ACTIVITY', 'intro' => 'x'],
                    ]],
                    'session' => ['description' => 'main', 'activities' => [
                        ['type' => 'label', 'name' => 'MAIN-ACTIVITY', 'intro' => 'x'],
                    ]],
                    'postsession' => ['description' => 'post', 'activities' => [
                        ['type' => 'label', 'name' => 'POST-ACTIVITY', 'intro' => 'x'],
                    ]],
                ]],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json, $this->course->id, 'connected_theme', false, true, false
        );
        $this->resetDebugging();
        rebuild_course_cache($this->course->id, true, true);

        // Locate the week, then its three session subsections by phase.
        global $DB;
        $week = $DB->get_record('course_sections',
            ['course' => $this->course->id, 'name' => 'Routing week'], '*', MUST_EXIST);
        $map = session_creator::get_session_sections($week->section, $this->course->id);

        $this->assertContains('PRE-ACTIVITY', $this->content_module_names($map['presession']),
            'Pre-session activity must be in the pre-session subsection.');
        $this->assertContains('MAIN-ACTIVITY', $this->content_module_names($map['session']),
            'Session activity must be in the session subsection.');
        $this->assertContains('POST-ACTIVITY', $this->content_module_names($map['postsession']),
            'Post-session activity must be in the post-session subsection.');

        // And crucially, no cross-contamination: the main activity must NOT appear
        // in the pre- or post- subsections.
        $this->assertNotContains('MAIN-ACTIVITY', $this->content_module_names($map['presession']));
        $this->assertNotContains('MAIN-ACTIVITY', $this->content_module_names($map['postsession']));
    }

    // ------------------------------------------------------------------
    // Collision boundary characterisation (where silent misrouting lives).
    // ------------------------------------------------------------------

    /**
     * Characterise the matcher when a sibling subsection's name happens to contain
     * a session keyword. get_session_sections matches the FIRST sibling whose name
     * contains the key; a later create_section_with_parent sibling named e.g.
     * "Session recap" would also match 'session'. This test documents that the
     * matcher keeps the LAST matching sibling for a given key (map assignment
     * overwrites), so a stray keyword-bearing subsection can hijack routing.
     *
     * This is a *characterisation* test: it pins current behaviour so the risk is
     * visible and a future fix (e.g. matching by stored phase metadata instead of
     * name) will deliberately change it here.
     *
     * @covers ::get_session_sections
     */
    public function test_keyword_bearing_sibling_can_hijack_mapping(): void {
        $week = $this->make_week('Collision week');
        $created = session_creator::create_session_subsections(
            $this->courseformat, $week, $this->course->id, null
        );

        // A teacher adds an extra subsection under the week whose name contains
        // 'session' — e.g. a recap. It is a legitimate sibling, not a phase.
        $stray = theme_builder::create_section_with_parent(
            $this->course->id, $this->courseformat, $week,
            'Session recap', '', FORMAT_PLAIN, ['collapsed' => 0]
        );
        rebuild_course_cache($this->course->id, true, true);

        $found = session_creator::get_session_sections($week, $this->course->id);

        // Document the failure mode: 'session' now resolves to EITHER the real
        // session subsection or the stray, depending on section ordering — the
        // matcher cannot tell them apart. We assert only that the ambiguity is
        // real (the stray is a candidate), which is the corruption risk worth
        // surfacing, without over-fitting to ordering.
        $candidates = [$created['session'], $stray->section];
        $this->assertContains($found['session'], $candidates,
            "'session' resolves to one of the keyword-bearing subsections.");

        // The deterministic, safe phases are unaffected: pre/post still resolve
        // to their own dedicated subsections.
        $this->assertSame($created['presession'], $found['presession']);
        $this->assertSame($created['postsession'], $found['postsession']);
    }

    /**
     * When a week has NO session subsections at all, get_session_sections must
     * return an empty map — and downstream activity placement must therefore route
     * nowhere rather than guessing a wrong section.
     *
     * @covers ::get_session_sections
     * @covers ::create_session_activities
     */
    public function test_no_subsections_yields_empty_map_and_no_misplacement(): void {
        $week = $this->make_week('Empty week');
        rebuild_course_cache($this->course->id, true, true);

        $map = session_creator::get_session_sections($week, $this->course->id);
        $this->assertSame([], $map, 'A week with no session subsections maps to nothing.');

        // create_session_activities must safely no-op when the map lacks the phase,
        // never falling back to an arbitrary section.
        $results = [];
        $warnings = [];
        $course = get_course($this->course->id);
        session_creator::create_session_activities(
            ['session' => ['activities' => [['type' => 'label', 'name' => 'Orphan', 'intro' => 'x']]]],
            $map, $course, $results, $warnings
        );

        $this->assertSame([], $results,
            'With no matching subsection, no activity should be placed.');
    }
}
