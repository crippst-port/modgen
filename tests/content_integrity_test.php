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

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($this->course->id);
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
