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
 * Tests for how text is stored by the section builders and how the CSV template is read.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\csv_parser;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Build-output and CSV template tests.
 *
 * @covers \aiplacement_modgen\local\csv_parser
 * @covers \aiplacement_modgen\local\theme_builder
 * @covers \aiplacement_modgen\local\section_creation_service
 */
final class build_hardening_test extends advanced_testcase {
    /** @var \stdClass Course under test. */
    private $course;

    /**
     * Flexsections course with AI off, so CSV summaries are used.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enable_ai', 0, 'aiplacement_modgen');
        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);
    }

    /**
     * A stored CSV file.
     *
     * @param string $content File content.
     * @return \stored_file
     */
    private function csv(string $content): \stored_file {
        global $USER;
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => 'structure.csv',
        ], $content);
    }

    /**
     * Section record by name.
     *
     * @param string $name Stored name.
     * @return \stdClass
     */
    private function section(string $name): \stdClass {
        global $DB;
        return $DB->get_record('course_sections', ['course' => $this->course->id, 'name' => $name], '*', MUST_EXIST);
    }

    /**
     * What a visitor sees for a section summary.
     *
     * @param \stdClass $section Section record.
     * @return string
     */
    private function rendered(\stdClass $section): string {
        return format_text($section->summary, $section->summaryformat, ['context' => \context_course::instance($this->course->id)]);
    }

    // ------------------------------------------------------------------
    // Text is stored once-escaped, not twice.

    /**
     * Special characters in a CSV theme and week description display as typed.
     */
    public function test_csv_summaries_are_not_double_escaped(): void {
        $csv = "Theme:,Q&A\nDescription:,Fish & chips <i>today</i>\nWeek:,Week one\nDescription:,It's a <b>test</b> & more\n";
        $structure = csv_parser::parse_csv_to_structure($this->csv($csv), 'connected_theme');
        (new section_creation_service())->create_sections_from_json($structure, $this->course->id, 'connected_theme');
        $this->resetDebugging();

        $theme = $this->section('Q&A');
        $this->assertSame('Fish & chips <i>today</i>', $theme->summary, 'Stored as typed.');
        $this->assertSame('Fish &amp; chips &lt;i&gt;today&lt;/i&gt;', $this->rendered($theme));

        $week = $this->section('Week one');
        $this->assertSame("It's a <b>test</b> & more", $week->summary);
        $this->assertStringNotContainsString('&amp;amp;', $this->rendered($week));
        $this->assertStringNotContainsString('&amp;lt;', $this->rendered($week));
    }

    /**
     * Quick Add sections and session summaries are stored as given too.
     */
    public function test_builder_helpers_store_text_as_given(): void {
        $format = course_get_format(get_course($this->course->id, true));
        $num = theme_builder::create_theme_section($this->course->id, $format, 'R&D <b>team</b>', 'Notes & "quotes"');
        $this->resetDebugging();

        $theme = $this->section('R&D team');
        $this->assertSame('Notes & "quotes"', $theme->summary);
        $this->assertEquals(FORMAT_PLAIN, $theme->summaryformat);
        $this->assertEquals($num, $theme->section);
    }

    /**
     * A weekly outline stays real HTML (a list), not escaped tag text.
     */
    public function test_weekly_outline_is_stored_as_html(): void {
        (new section_creation_service())->create_sections_from_json(
            ['sections' => [['title' => 'Week 1', 'summary' => 'Overview', 'outline' => [['text' => 'First & second'], 'Third']]]],
            $this->course->id,
            'connected_weekly'
        );
        $this->resetDebugging();

        $week = $this->section('Week 1');
        $this->assertEquals(FORMAT_HTML, $week->summaryformat);
        $html = $this->rendered($week);
        $this->assertStringContainsString('<li>First &amp; second</li>', $html);
        $this->assertStringContainsString('<li>Third</li>', $html);
        $this->assertStringNotContainsString('&lt;li&gt;', $html);
    }

    // ------------------------------------------------------------------
    // CSV template reading.

    /**
     * A quoted description may span lines and contain commas and quotes.
     */
    public function test_csv_quoted_multiline_description_is_kept_whole(): void {
        $csv = "Theme:,Foundations\r\n"
            . "Description:,\"Line one, with comma\nLine two says \"\"hi\"\"\nWeek: not a real week\"\r\n"
            . "Week:,Real week\r\n";
        $structure = csv_parser::parse_csv_to_structure($this->csv($csv), 'connected_theme');

        $this->assertCount(1, $structure['themes']);
        $this->assertSame(
            "Line one, with comma\nLine two says \"hi\"\nWeek: not a real week",
            $structure['themes'][0]['summary']
        );
        $this->assertSame(['Real week'], array_column($structure['themes'][0]['weeks'], 'title'));
    }

    /**
     * A value of "0" is a value, not an empty cell.
     */
    public function test_csv_value_zero_is_kept(): void {
        $structure = csv_parser::parse_csv_to_structure($this->csv("Theme:,0\nWeek:,0\n"), 'connected_theme');

        $this->assertSame('0', $structure['themes'][0]['title']);
        $this->assertSame('0', $structure['themes'][0]['weeks'][0]['title']);
    }

    /**
     * A UTF-8 byte order mark and Windows line endings are tolerated.
     */
    public function test_csv_bom_and_crlf(): void {
        $structure = csv_parser::parse_csv_to_structure($this->csv("\xEF\xBB\xBFWeek:,One\r\nWeek:,Two\r\n"), 'connected_weekly');

        $this->assertSame(['One', 'Two'], array_column($structure['sections'], 'title'));
    }

    /**
     * A week listed before any theme is reported, not silently dropped.
     */
    public function test_csv_week_before_theme_is_reported(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('before any theme');
        csv_parser::parse_csv_to_structure($this->csv("Week:,Orphan\nTheme:,T\n"), 'connected_theme');
    }

    /**
     * The format is detected from rows, not from text inside a quoted value.
     */
    public function test_csv_format_detection_ignores_quoted_text(): void {
        $weekly = $this->csv("Week:,One\nDescription:,\"Intro\nTheme: not a theme\"\n");
        $this->assertSame('connected_weekly', csv_parser::detect_csv_format($weekly));

        $themed = $this->csv("Theme:,A\nWeek:,One\n");
        $this->assertSame('connected_theme', csv_parser::detect_csv_format($themed));
    }

    /**
     * The CSV section limit: unset means 50, a number is the cap, 0 means unlimited.
     */
    public function test_csv_section_limit_settings(): void {
        $rows = "Theme:,T\n";
        for ($i = 1; $i <= 60; $i++) {
            $rows .= "Week:,W{$i}\n";
        }

        unset_config('maxcsvsections', 'aiplacement_modgen');
        try {
            csv_parser::parse_csv_to_structure($this->csv($rows), 'connected_theme');
            $this->fail('61 sections exceed the default of 50.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('50', $e->getMessage());
        }

        set_config('maxcsvsections', 100, 'aiplacement_modgen');
        $this->assertCount(60, csv_parser::parse_csv_to_structure($this->csv($rows), 'connected_theme')['themes'][0]['weeks']);

        set_config('maxcsvsections', 0, 'aiplacement_modgen');
        $this->assertCount(60, csv_parser::parse_csv_to_structure($this->csv($rows), 'connected_theme')['themes'][0]['weeks']);

        set_config('maxcsvsections', 10, 'aiplacement_modgen');
        $this->expectException(\Exception::class);
        csv_parser::parse_csv_to_structure($this->csv($rows), 'connected_theme');
    }

    // ------------------------------------------------------------------
    // Quick Add limit and release metadata.

    /**
     * The limit that Quick Add now checks before queuing rejects a build that cannot fit.
     */
    public function test_quick_add_limit_rejects_oversized_request(): void {
        set_config('maxtotalsections', 10, 'aiplacement_modgen');

        // 3 themes x 2 weeks = 3 + 3*2*4 = 27 sections.
        $this->expectException(\moodle_exception::class);
        theme_builder::enforce_section_limit($this->course->id, 3 + (3 * 2 * 4));
    }

    /**
     * A request that fits passes the same check.
     */
    public function test_quick_add_limit_allows_request_that_fits(): void {
        set_config('maxtotalsections', 400, 'aiplacement_modgen');

        theme_builder::enforce_section_limit($this->course->id, 3 + (3 * 2 * 4));
        $this->assertTrue(true);
    }

    /**
     * The plugin declares the Moodle version that actually has what it uses.
     */
    public function test_version_requires_moodle_with_ai_placements(): void {
        $plugin = new \stdClass();
        require(dirname(__DIR__) . '/version.php');

        $this->assertGreaterThanOrEqual(2024100700, $plugin->requires, 'The aiplacement plugin type arrived in Moodle 4.5.');
        $this->assertTrue(class_exists(\core_ai\manager::class));
    }

    /**
     * Development files are kept out of release archives.
     */
    public function test_dev_files_are_export_ignored(): void {
        $attributes = file_get_contents(dirname(__DIR__) . '/.gitattributes');

        foreach (['/.plan', '/.github', '/sql', '/Gruntfile.js', '/package.json', '/phpunit.xml'] as $path) {
            $this->assertStringContainsString("{$path} export-ignore", $attributes);
        }
    }
}
