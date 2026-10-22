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

namespace aiplacement_modgen;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Building the structures the AI generator returns (and the approve form passes on), checked
 * against what is stored. No AI provider is needed: these are the JSON shapes the build receives.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\section_creation_service
 * @covers \aiplacement_modgen\local\session_creator
 */
final class generated_structure_build_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var \stdClass Course the structures are built in. */
    private $course;

    /**
     * A Flexible sections course and an administrator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->flexsections_course();
    }

    /**
     * Sessions in the shape the AI is asked for.
     *
     * @param string $prefix Text put in front of each description.
     * @return array
     */
    private function sessions(string $prefix): array {
        return [
            'presession' => ['description' => "$prefix pre", 'activities' => []],
            'session' => ['description' => "$prefix during", 'activities' => []],
            'postsession' => ['description' => "$prefix post", 'activities' => []],
        ];
    }

    /**
     * A themed structure: themes stay empty containers, weeks keep their text, and session
     * descriptions become the session section summaries.
     */
    public function test_themed_structure_with_session_descriptions(): void {
        $json = ['themes' => [[
            'title' => 'Energy',
            'summary' => '',
            'weeks' => [
                ['title' => 'Forces', 'summary' => 'How things move', 'sessions' => $this->sessions('Forces')],
                ['title' => 'Work', 'summary' => 'Doing work', 'sessions' => $this->sessions('Work')],
            ],
        ]]];

        $job = $this->build_structure($this->course->id, $json, 'connected_theme');

        $this->assertSame('completed', $job->status);
        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Energy', ['Forces', 'Work'], 0)
        ));
        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame('', $rows[1]['summary']);
        $this->assertSame('How things move', $rows[2]['summary']);
        $this->assertSame(['Forces pre', 'Forces during', 'Forces post'], array_column(array_slice($rows, 3, 3), 'summary'));
        $this->assertSame(['Work pre', 'Work during', 'Work post'], array_column(array_slice($rows, 7, 3), 'summary'));
        $this->assert_sound($this->course->id);
    }

    /**
     * A weekly structure: each section becomes a top-level week, the outline becomes a list in its
     * summary, and the sessions are added whether or not the AI described them.
     */
    public function test_weekly_structure_with_an_outline(): void {
        $json = ['sections' => [
            [
                'title' => 'Cells',
                'summary' => 'The basics of cells',
                'outline' => ['Structure', 'Function', ['text' => 'Division']],
                'sessions' => $this->sessions('Cells'),
            ],
            ['title' => 'Genetics', 'summary' => 'Inheritance'],
        ]];

        $job = $this->build_structure($this->course->id, $json, 'connected_weekly');

        $this->assertSame('completed', $job->status);
        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->week_rows('Cells', '-', 0),
            $this->week_rows('Genetics', '-', 0)
        ));
        global $DB;
        $cells = $DB->get_record('course_sections', ['course' => $this->course->id, 'name' => 'Cells'], '*', MUST_EXIST);
        $this->assertStringContainsString('The basics of cells', $cells->summary);
        $this->assertStringContainsString('<li>Structure</li>', $cells->summary);
        $this->assertStringContainsString('<li>Function</li>', $cells->summary);
        $this->assertStringContainsString('<li>Division</li>', $cells->summary);
        $this->assertEquals(FORMAT_HTML, $cells->summaryformat);

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame(['Cells pre', 'Cells during', 'Cells post'], array_column(array_slice($rows, 2, 3), 'summary'));
        $this->assertSame(['', '', ''], array_column(array_slice($rows, 6, 3), 'summary'));
        $this->assert_sound($this->course->id);
    }

    /**
     * Section summary activities take their names from the metadata the structure carries.
     */
    public function test_summary_activities_use_the_supplied_names(): void {
        $json = ['themes' => [[
            'title' => 'Energy',
            'weeks' => [[
                'title' => 'Forces',
                'learningactivity_metadata' => ['name' => 'Forces overview'],
                'sessions' => [
                    'presession' => ['description' => '', 'learningactivity_metadata' => ['name' => 'Read first']],
                    'session' => ['description' => ''],
                    'postsession' => ['description' => '', 'learningactivity_metadata' => ['name' => 'Reflect']],
                ],
            ]],
        ]]];

        $this->build_structure($this->course->id, $json, 'connected_theme', true);

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame(['Forces overview'], $rows[2]['activitynames']);
        $this->assertSame(['Read first'], $rows[3]['activitynames']);
        $default = get_string('sessionactivityname', 'aiplacement_modgen', get_string('session', 'aiplacement_modgen'));
        $this->assertSame([$default], $rows[4]['activitynames']);
        $this->assertSame(['Reflect'], $rows[5]['activitynames']);
        $this->assert_sound($this->course->id);
    }

    /**
     * Text from the form is cleaned on the way in: markup in titles and summaries is removed.
     */
    public function test_text_is_cleaned_before_it_is_stored(): void {
        $json = ['themes' => [[
            'title' => 'Safe <script>alert(1)</script>theme',
            'weeks' => [['title' => '<b>Bold</b> week', 'summary' => '<p>Fine</p><script>alert(2)</script>']],
        ]]];

        $this->build_structure($this->course->id, $json, 'connected_theme');

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame('Safe alert(1)theme', $rows[1]['name']);
        $this->assertSame('Bold week', $rows[2]['name']);
        $this->assertStringNotContainsString('script', $rows[2]['summary']);
        $this->assert_sound($this->course->id);
    }

    /**
     * Items that are not what the format expects are skipped; the rest is built.
     */
    public function test_malformed_items_are_skipped(): void {
        $json = ['themes' => [
            'not a theme',
            ['title' => 'Real theme', 'weeks' => ['not a week', ['title' => 'Real week']]],
        ]];

        $job = $this->build_structure($this->course->id, $json, 'connected_theme');

        $this->assertSame('completed', $job->status);
        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Real theme', ['Real week'], 0)
        ));
    }

    /**
     * A theme with no title gets the fallback name; a structure with no themes builds only the Assessments section.
     */
    public function test_untitled_theme_and_empty_structure(): void {
        $job = $this->build_structure($this->course->id, ['themes' => [['weeks' => []]]], 'connected_theme');

        $this->assertSame('completed', $job->status);
        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains(get_string('themefallback', 'aiplacement_modgen'), $names);

        $empty = $this->flexsections_course();
        $this->course = $empty;
        $job = $this->build_structure($empty->id, ['themes' => []], 'connected_theme');
        $this->assertSame('completed', $job->status);
        $this->assert_structure($empty->id, [$this->assessments_row()]);
    }
}
