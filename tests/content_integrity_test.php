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
 * Content integrity tests for Module Generator.
 *
 * Structural integrity (no orphaned/duplicate sections) is necessary but not
 * sufficient: a course can be perfectly well-formed yet have the WRONG CONTENT.
 * This suite targets the three testable classes of content corruption (AI output
 * *quality* is explicitly out of scope — that needs eval/review, not unit tests):
 *
 *   1. PLACEMENT FIDELITY: every activity lands under the exact section/phase it was
 *      specified for — nothing leaks into the wrong week, theme, or session phase,
 *      nor into the protected Intro / Assessments core sections.
 *   2. COUNT CONSERVATION: given input describing N activities, exactly N content
 *      modules exist afterwards — no silent duplication, no silent loss.
 *   3. SANITISATION ROUND TRIP: legitimate text survives intact (ampersands,
 *      apostrophes, accents/unicode), HTML/scripts are neutralised to text rather
 *      than dropping the whole field, and the metadata validator's documented
 *      transforms (numeric-only duration, exact-match learning types/modes) behave
 *      as specified so valid content is never silently mangled or discarded.
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
use aiplacement_modgen\local\learningactivity_validator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Content integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\section_creation_service
 */
final class content_integrity_test extends advanced_testcase {
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
     * Names of non-metadata content modules in a section number.
     *
     * Excludes the learningactivity metadata module auto-created in each section.
     *
     * @param int $sectionnumber Section number.
     * @return string[] Sorted content-module names.
     */
    private function content_names(int $sectionnumber): array {
        $modinfo = get_fast_modinfo($this->course->id);
        $names = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ((int)$cm->sectionnum === $sectionnumber && $cm->modname !== 'learningactivity') {
                $names[] = $cm->name;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Total count of content modules of a given modname across the whole course.
     *
     * @param string $modname Module name (e.g. 'label').
     * @return int
     */
    private function total_modules(string $modname): int {
        $modinfo = get_fast_modinfo($this->course->id);
        $count = 0;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === $modname) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Section number for a section by exact name.
     *
     * @param string $name Section name.
     * @return int Section number.
     */
    private function section_number(string $name): int {
        global $DB;
        $section = $DB->get_record(
            'course_sections',
            ['course' => $this->course->id, 'name' => $name],
            '*',
            MUST_EXIST
        );
        return (int)$section->section;
    }

    // ------------------------------------------------------------------
    // 1. Placement fidelity.
    // ------------------------------------------------------------------

    /**
     * Across multiple themes/weeks/phases, every activity lands in exactly the
     * subsection it was specified for, and nowhere else.
     *
     * @covers ::create_sections_from_json
     */
    public function test_activities_placed_exactly_where_specified(): void {
        $json = ['themes' => [
            ['title' => 'Theme One', 'summary' => 's', 'weeks' => [
                ['title' => 'Week 1A', 'summary' => 'w', 'sessions' => [
                    'presession'  => ['activities' => [['type' => 'label', 'name' => 'T1W1-PRE', 'intro' => 'x']]],
                    'session'     => ['activities' => [['type' => 'label', 'name' => 'T1W1-MAIN', 'intro' => 'x']]],
                    'postsession' => ['activities' => [['type' => 'label', 'name' => 'T1W1-POST', 'intro' => 'x']]],
                ]],
            ]],
            ['title' => 'Theme Two', 'summary' => 's', 'weeks' => [
                ['title' => 'Week 2A', 'summary' => 'w', 'sessions' => [
                    'session' => ['activities' => [['type' => 'label', 'name' => 'T2W1-MAIN', 'intro' => 'x']]],
                ]],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            true,
            false
        );
        $this->resetDebugging();
        rebuild_course_cache($this->course->id, true, true);

        // Resolve the two weeks' session maps and assert exact placement.
        $w1 = session_creator::get_session_sections($this->section_number('Week 1A'), $this->course->id);
        $w2 = session_creator::get_session_sections($this->section_number('Week 2A'), $this->course->id);

        $this->assertContains('T1W1-PRE', $this->content_names((int)$w1['presession']));
        $this->assertContains('T1W1-MAIN', $this->content_names((int)$w1['session']));
        $this->assertContains('T1W1-POST', $this->content_names((int)$w1['postsession']));
        $this->assertContains('T2W1-MAIN', $this->content_names((int)$w2['session']));

        // No cross-week or cross-phase leakage: week 1's main activity is not in
        // week 2, and week 2's is not in week 1.
        $this->assertNotContains('T2W1-MAIN', $this->content_names((int)$w1['session']));
        $this->assertNotContains('T1W1-MAIN', $this->content_names((int)$w2['session']));
    }

    /**
     * Generated activities must never be placed into the protected core sections
     * (Introduction section 0, Assessments) — those are reserved and getting
     * content there is a real misplacement corruption.
     *
     * @covers ::create_sections_from_json
     */
    public function test_no_activities_leak_into_core_sections(): void {
        $json = ['themes' => [
            ['title' => 'Content Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Content Week', 'summary' => 'w', 'sessions' => [
                    'session' => ['activities' => [['type' => 'label', 'name' => 'BODY', 'intro' => 'x']]],
                ]],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            true,
            false
        );
        $this->resetDebugging();
        rebuild_course_cache($this->course->id, true, true);

        // Section 0 (Introduction) must hold no generated content modules.
        $this->assertSame(
            [],
            $this->content_names(0),
            'No generated activity may be placed in the Introduction section (0).'
        );

        // Assessments section likewise.
        $assessments = $this->section_number(get_string('assessmentssectionname', 'aiplacement_modgen'));
        $this->assertSame(
            [],
            $this->content_names($assessments),
            'No generated activity may be placed in the Assessments section.'
        );
    }

    // ------------------------------------------------------------------
    // 2. Count conservation.
    // ------------------------------------------------------------------

    /**
     * The number of content modules created equals the number specified in the
     * input — no silent duplication and no silent loss.
     *
     * @covers ::create_sections_from_json
     */
    public function test_activity_count_is_conserved(): void {
        // 3 phases x 2 activities = 6 labels specified.
        $mk = function (string $prefix): array {
            return ['activities' => [
                ['type' => 'label', 'name' => $prefix . '-a', 'intro' => 'x'],
                ['type' => 'label', 'name' => $prefix . '-b', 'intro' => 'x'],
            ]];
        };
        $json = ['themes' => [
            ['title' => 'Count Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Count Week', 'summary' => 'w', 'sessions' => [
                    'presession'  => $mk('pre'),
                    'session'     => $mk('main'),
                    'postsession' => $mk('post'),
                ]],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            true,
            false
        );
        $this->resetDebugging();
        rebuild_course_cache($this->course->id, true, true);

        $this->assertSame(
            6,
            $this->total_modules('label'),
            'Exactly the six specified label activities must exist — no duplication or loss.'
        );
    }

    /**
     * Re-reading the structure after a cache rebuild must not change content counts
     * (idempotent read) — the count is stable, not inflated by re-processing.
     *
     * @covers ::create_sections_from_json
     */
    public function test_counts_stable_across_cache_rebuilds(): void {
        $json = ['themes' => [
            ['title' => 'Stable Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Stable Week', 'summary' => 'w', 'sessions' => [
                    'session' => ['activities' => [
                        ['type' => 'label', 'name' => 'one', 'intro' => 'x'],
                        ['type' => 'label', 'name' => 'two', 'intro' => 'x'],
                    ]],
                ]],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            true,
            false
        );
        $this->resetDebugging();

        rebuild_course_cache($this->course->id, true, true);
        $first = $this->total_modules('label');

        rebuild_course_cache($this->course->id, true, true);
        $second = $this->total_modules('label');

        $this->assertSame(2, $first, 'Two labels specified, two created.');
        $this->assertSame($first, $second, 'Content count must be stable across cache rebuilds.');
    }

    // ------------------------------------------------------------------
    // 3. Sanitisation round trip.
    // ------------------------------------------------------------------

    /**
     * Legitimate punctuation and unicode in titles must survive intact (no spurious
     * mangling), while embedded HTML/script is neutralised to plain text rather than
     * the field being dropped.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_section_titles_round_trip_faithfully(): void {
        global $DB;

        $courseformat = course_get_format($this->course->id);

        // Legitimate content with ampersand, apostrophe, accents — must be preserved.
        $legit = theme_builder::create_section_with_parent(
            $this->course->id,
            $courseformat,
            0,
            "Café & Co — Tom's Naïve Résumé",
            '',
            FORMAT_PLAIN,
            ['collapsed' => 1]
        );
        $legitname = $DB->get_field(
            'course_sections',
            'name',
            ['course' => $this->course->id, 'section' => $legit->section]
        );
        $this->assertSame(
            "Café & Co — Tom's Naïve Résumé",
            $legitname,
            'Ampersands, apostrophes and unicode must round-trip unchanged.'
        );

        // HTML/script in the title must be neutralised to its text, not dropped whole.
        $xss = theme_builder::create_section_with_parent(
            $this->course->id,
            $courseformat,
            0,
            'Week <b>3</b> <script>alert(1)</script>Intro',
            '',
            FORMAT_PLAIN,
            ['collapsed' => 1]
        );
        $xssname = $DB->get_field(
            'course_sections',
            'name',
            ['course' => $this->course->id, 'section' => $xss->section]
        );

        $this->assertStringNotContainsString('<script>', $xssname, 'Script tags must be stripped.');
        $this->assertStringNotContainsString('<b>', $xssname, 'HTML tags must be stripped.');
        $this->assertStringContainsString('Week', $xssname, 'Legitimate words must survive sanitisation.');
        $this->assertStringContainsString('Intro', $xssname, 'Text after stripped tags must survive.');
        $this->assertNotSame('', trim($xssname), 'The whole field must not be dropped.');
    }

    /**
     * The metadata validator preserves valid values and applies its documented
     * transforms — so valid content is neither mangled nor silently discarded, and
     * invalid content is dropped deterministically (not passed through).
     *
     * @covers \aiplacement_modgen\local\learningactivity_validator::validate_metadata
     */
    public function test_metadata_validator_preserves_valid_content(): void {
        // Valid types/modes are the exact Laurillard set (case-sensitive).
        $valid = learningactivity_validator::validate_metadata([
            'name'         => "Designing & Building",
            'instructions' => 'Read chapter 1 then discuss.',
            'duration'     => '90',
            'learningtypes' => 'Acquisition,Discussion',
        ]);

        $this->assertSame(
            'Designing & Building',
            $valid['name'],
            'Valid name with ampersand must be preserved.'
        );
        $this->assertSame(
            'Read chapter 1 then discuss.',
            $valid['instructions'],
            'Valid instructions must be preserved.'
        );
        $this->assertSame(
            '90',
            $valid['duration'],
            'Numeric duration must be preserved as an integer string.'
        );
        $this->assertSame(
            'Acquisition,Discussion',
            $valid['learningtypes'],
            'Valid learning types must be preserved in order.'
        );
    }

    /**
     * The validator drops invalid values deterministically rather than storing
     * garbage: a non-numeric duration becomes null, and unrecognised learning types
     * are removed. This is *intentional* loss; the test pins it so a change is
     * deliberate and the boundary (valid kept / invalid dropped) is explicit.
     *
     * @covers \aiplacement_modgen\local\learningactivity_validator::validate_metadata
     */
    public function test_metadata_validator_drops_invalid_deterministically(): void {
        $result = learningactivity_validator::validate_metadata([
            'name'          => 'Mix',
            'duration'      => '90 minutes', // Non-numeric -> dropped.
            'learningtypes' => 'Acquisition,Lecture,Discussion', // Lecture is not in set -> dropped.
            'learningmode'  => 'Hybrid', // Not in set -> dropped.
        ]);

        $this->assertNull(
            $result['duration'],
            'A non-numeric duration must be dropped (null), not stored verbatim.'
        );
        $this->assertSame(
            'Acquisition,Discussion',
            $result['learningtypes'],
            'Only recognised learning types survive; invalid ones are dropped, valid order kept.'
        );
        $this->assertNull(
            $result['learningmode'],
            'An unrecognised learning mode must be dropped (null).'
        );

        // Sanity: a value that *is* valid in the same call is still kept (drop is
        // selective, not all-or-nothing).
        $this->assertSame('Mix', $result['name']);
    }

    /**
     * Over-length text is truncated to the documented cap rather than rejected or
     * corrupting the store — content is bounded, not lost wholesale.
     *
     * @covers \aiplacement_modgen\local\learningactivity_validator::validate_metadata
     */
    public function test_metadata_validator_truncates_overlong_text(): void {
        $longname = str_repeat('A', 500);
        $result = learningactivity_validator::validate_metadata(['name' => $longname]);

        $this->assertSame(
            255,
            strlen($result['name']),
            'Over-length name must be truncated to 255 chars, not dropped or stored unbounded.'
        );
        $this->assertStringStartsWith(
            'AAAA',
            $result['name'],
            'Truncation keeps the leading content.'
        );
    }
}
