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
 * Tests for the Quick Add "create section summary activities" toggle.
 *
 * Quick Add can switch off the learningactivity "section summary" placeholder
 * modules (one per week, one per session). Off they are not created at all; on
 * (the default) behaviour is unchanged. The AI/JSON path is unaffected — it always
 * creates them because they carry real metadata there.
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
 * Summary-activity toggle tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\theme_builder
 */
final class summary_activity_toggle_test extends advanced_testcase {
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
     * Count learningactivity modules in the course.
     *
     * @return int
     */
    private function learningactivity_count(): int {
        $modinfo = get_fast_modinfo($this->course->id);
        $count = 0;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'learningactivity') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count generated week sections (excludes section 0 and Assessments).
     *
     * @return int
     */
    private function week_section_count(): int {
        global $DB;
        $assessments = get_string('assessmentssectionname', 'aiplacement_modgen');
        return $DB->count_records_select(
            'course_sections',
            'course = :c AND section > 0 AND name <> :a',
            ['c' => $this->course->id, 'a' => $assessments]
        );
    }

    /**
     * With the toggle ON (default), create_themes creates the summary modules.
     *
     * @covers ::create_themes
     */
    public function test_themes_with_summaries_creates_modules(): void {
        theme_builder::create_themes($this->course->id, 1, 1, 0, true);

        // 1 theme + 1 week + 3 sessions: a learningactivity on the week and on each
        // of the 3 sessions = 4 modules.
        $this->assertSame(
            4,
            $this->learningactivity_count(),
            'With summaries on, each week and session gets a learningactivity module.'
        );
    }

    /**
     * With the toggle OFF, create_themes creates the sections but no summary modules.
     *
     * @covers ::create_themes
     */
    public function test_themes_without_summaries_creates_no_modules(): void {
        $sectionsbefore = $this->week_section_count();

        theme_builder::create_themes($this->course->id, 1, 1, 0, false);

        $this->assertSame(
            0,
            $this->learningactivity_count(),
            'With summaries off, no learningactivity modules are created.'
        );
        // Sections are still created (theme + week + 3 sessions = 5 new sections).
        $this->assertGreaterThan(
            $sectionsbefore,
            $this->week_section_count(),
            'The section structure is still created when summaries are off.'
        );
    }

    /**
     * The toggle defaults to ON when the argument is omitted (backward compatible).
     *
     * @covers ::create_themes
     */
    public function test_default_preserves_summary_creation(): void {
        theme_builder::create_themes($this->course->id, 1, 1);

        $this->assertSame(
            4,
            $this->learningactivity_count(),
            'Omitting the flag must preserve the original behaviour (summaries created).'
        );
    }

    /**
     * create_weeks honours the toggle too.
     *
     * @covers ::create_weeks
     */
    public function test_weeks_toggle(): void {
        theme_builder::create_weeks($this->course->id, 2, 0, false);
        $this->assertSame(
            0,
            $this->learningactivity_count(),
            'create_weeks with summaries off creates no learningactivity modules.'
        );

        // A fresh course with summaries on: 2 weeks x (week + 3 sessions) = 8 modules.
        $other = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($other->id);
        theme_builder::create_weeks($other->id, 2, 0, true);
        $modinfo = get_fast_modinfo($other->id);
        $count = 0;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'learningactivity') {
                $count++;
            }
        }
        $this->assertSame(
            8,
            $count,
            'create_weeks with summaries on creates a learningactivity per week and session.'
        );
    }

    /**
     * The create-from-file/AI path (create_sections_from_json) honours the toggle:
     * off creates the structure without any summary modules.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_json_service_without_summaries(): void {
        $json = ['themes' => [
            ['title' => 'T', 'summary' => 's', 'weeks' => [
                ['title' => 'W', 'summary' => 'w', 'sessions' => []],
            ]],
        ]];

        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            false,
            false,
            false // createsummaryactivities off.
        );
        $this->resetDebugging();

        $this->assertSame(
            0,
            $this->learningactivity_count(),
            'create_sections_from_json with summaries off creates no learningactivity modules.'
        );
        $this->assertGreaterThan(
            0,
            $this->week_section_count(),
            'The section structure is still created.'
        );
    }

    /**
     * The JSON path defaults to creating summaries (backward compatible) when the
     * flag is omitted.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_json_service_defaults_to_summaries(): void {
        $json = ['themes' => [
            ['title' => 'T', 'summary' => 's', 'weeks' => [
                ['title' => 'W', 'summary' => 'w', 'sessions' => []],
            ]],
        ]];

        // Omit the new flag entirely.
        (new section_creation_service())->create_sections_from_json(
            $json,
            $this->course->id,
            'connected_theme',
            false,
            false,
            false
        );
        $this->resetDebugging();

        $this->assertGreaterThan(
            0,
            $this->learningactivity_count(),
            'Omitting the flag preserves summary-module creation on the JSON path.'
        );
    }
}
