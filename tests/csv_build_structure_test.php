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

use aiplacement_modgen\local\job_status_helper;
use aiplacement_modgen\local\json_sanitiser;
use aiplacement_modgen\local\preview_generator;
use aiplacement_modgen\local\quick_add_service;
use aiplacement_modgen\local\template_manager;
use aiplacement_modgen\local\template_processing_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Uploading a CSV and building it, end to end, checked against the stored course structure.
 *
 * The steps match the browser: the file is read into a structure (template_processing_service),
 * the structure is cleaned as it comes back from the approve form (json_sanitiser), the build is
 * queued and run as a background job, and then the sections are read straight from the database.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\template_processing_service
 * @covers \aiplacement_modgen\local\section_creation_service
 * @covers \aiplacement_modgen\local\csv_processing_service
 */
final class csv_build_structure_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var string A theme file with descriptions. */
    private const THEME_CSV = "Theme 1,Foundations\nDescription,About foundations\nWeek 1,Introduction\nDescription,Intro week\n" .
        "Week 2,Methods\nTheme 2,Application\nWeek 3,Practice\n";

    /** @var \stdClass Course the structures are built in. */
    private $course;

    /**
     * A Flexible sections course, an administrator, and AI switched off (so no provider is needed).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enable_ai', 0, 'aiplacement_modgen');
        $this->course = $this->flexsections_course();
    }

    /**
     * Read a draft file into a structure the way the generator page does.
     *
     * @param int $draftitemid Draft area with the upload (0 for none).
     * @param int $templateid Library template chosen in the form (0 for none).
     * @return array The structure, with its detected module type under '_detected_moduletype'.
     */
    private function read(int $draftitemid, int $templateid = 0): array {
        $pdata = (object) [
            'supportingfiles' => $draftitemid,
            'prompt' => '',
            'moduletype' => 'connected_weekly',
            'selected_template_id' => $templateid,
        ];
        return (new template_processing_service())->process_and_generate($pdata, $this->course->id, '');
    }

    /**
     * Approve a structure: clean it, queue the build and run it.
     *
     * @param array $structure Result of read().
     * @param bool $activities Whether to create the section summary activities.
     */
    private function approve(array $structure, bool $activities): void {
        global $USER;
        $type = $structure['_detected_moduletype'];
        unset($structure['_detected_moduletype']);
        job_status_helper::queue_creation_job($this->course->id, $USER->id, 'create_from_json', [
            'json' => json_sanitiser::clean($structure),
            'moduletype' => $type,
            'createsummaryactivities' => $activities,
        ]);
        $this->run_jobs();
    }

    /**
     * Upload a CSV under a name, read it and build it.
     *
     * @param string $filename Upload name.
     * @param string $csv Content.
     * @param bool $activities Whether to create the section summary activities.
     */
    private function build(string $filename, string $csv, bool $activities = false): void {
        $this->approve($this->read($this->draft_file($filename, $csv)['itemid']), $activities);
    }

    /**
     * A theme file builds themes, their weeks and the sessions, with the descriptions from the file.
     */
    public function test_theme_file(): void {
        $this->build('plan.csv', self::THEME_CSV);

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Foundations', ['Introduction', 'Methods'], 0, 'About foundations'),
            $this->theme_rows('Application', ['Practice'], 0)
        ));
        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame('Intro week', $rows[2]['summary']);
        $this->assertSame('', $rows[6]['summary'], 'A week with no description has no summary.');
        $this->assert_sound($this->course->id);
    }

    /**
     * The theme description is kept whether or not AI is switched on: this build did not use AI.
     */
    public function test_theme_description_is_kept_when_ai_is_on(): void {
        set_config('enable_ai', 1, 'aiplacement_modgen');

        $this->build('plan.csv', self::THEME_CSV);

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame('Foundations', $rows[1]['name']);
        $this->assertSame('About foundations', $rows[1]['summary']);
        $this->assertSame('Intro week', $rows[2]['summary']);
        $this->assert_sound($this->course->id);
    }

    /**
     * With the summary option on, each week and session gets a learning activity named for it.
     */
    public function test_theme_file_with_summary_activities(): void {
        $this->build('plan.csv', self::THEME_CSV, true);

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Foundations', ['Introduction', 'Methods'], 1),
            $this->theme_rows('Application', ['Practice'], 1)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * A weekly file builds top-level weeks, each with its sessions; "Section" rows are weeks too.
     */
    public function test_weekly_file(): void {
        $this->build('weeks.csv', "Week 1,Alpha\nDescription,First\nWeek 2,Beta\nSection 3,Gamma\n");

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->week_rows('Alpha', '-', 0, 'First'),
            $this->week_rows('Beta', '-', 0),
            $this->week_rows('Gamma', '-', 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Whatever the file is called, the same structure is built.
     *
     * @return array
     */
    public static function upload_names(): array {
        return [
            'plain name' => ['plan.csv'],
            'name with spaces' => ['My module plan.csv'],
            'brackets and version' => ['Plan (v2) - final.csv'],
            'accents and Japanese' => ["Pl\u{e4}n \u{65e5}\u{672c}\u{8a9e}.csv"],
            'upper case extension' => ['PLAN.CSV'],
            'several dots' => ['module.2026.term1.csv'],
            'a long name' => [str_repeat('long-name-', 20) . '.csv'],
            'no extension' => ['plan'],
            'a text extension' => ['plan.txt'],
        ];
    }

    /**
     * A file name never changes what is built.
     *
     * @dataProvider upload_names
     * @param string $filename Upload name.
     */
    public function test_upload_name_makes_no_difference(string $filename): void {
        $this->build($filename, "Theme 1,Foundations\nWeek 1,Introduction\n");

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Foundations', ['Introduction'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Files exported in other ways build the same thing: BOM, CRLF, Mac line endings, Windows-1252.
     *
     * @return array
     */
    public static function file_configurations(): array {
        $rows = ["Theme 1,Caf\u{e9} studies", 'Week 1,Intro', 'Week 2,Practice'];
        return [
            'LF' => [implode("\n", $rows) . "\n"],
            'CRLF' => [implode("\r\n", $rows) . "\r\n"],
            'carriage returns only' => [implode("\r", $rows) . "\r"],
            'byte order mark' => ["\xEF\xBB\xBF" . implode("\n", $rows) . "\n"],
            'Windows-1252' => [mb_convert_encoding(implode("\n", $rows) . "\n", 'Windows-1252', 'UTF-8')],
            'no final line ending' => [implode("\n", $rows)],
            'quoted values' => [implode("\n", array_map(fn($r) => '"' . str_replace(',', '","', $r) . '"', $rows)) . "\n"],
        ];
    }

    /**
     * Files exported in other ways build the same structure.
     *
     * @dataProvider file_configurations
     * @param string $content File content.
     */
    public function test_file_configurations_build_the_same_structure(string $content): void {
        $this->build('plan.csv', $content);

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows("Caf\u{e9} studies", ['Intro', 'Practice'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Titles are stored as plain text: markup is removed, ampersands, quotes and Unicode are kept.
     */
    public function test_titles_are_stored_as_plain_text(): void {
        $this->build('plan.csv', "Week 1,<b>Bold</b> & beautiful\nWeek 2,<script>alert(1)</script>Safe\n" .
            "Week 3,\"Plan, \"\"do\"\", review\"\nWeek 4,\u{65e5}\u{672c}\u{8a9e} \u{1f600}\n");

        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains('Bold & beautiful', $names);
        $this->assertContains('alert(1)Safe', $names);
        $this->assertContains('Plan, "do", review', $names);
        $this->assertContains("\u{65e5}\u{672c}\u{8a9e} \u{1f600}", $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('<', $name);
        }
        $this->assert_sound($this->course->id);
    }

    /**
     * Descriptions are stored as text too: markup removed, line breaks kept.
     */
    public function test_descriptions_are_stored_as_text(): void {
        $this->build('plan.csv', "Week 1,Alpha\nDescription,\"Line one\nLine <i>two</i>\"\n");

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->assertSame("Line one\nLine two", $rows[1]['summary']);
    }

    /**
     * A section name has a database limit of 255 characters; a longer title must not break the whole build.
     */
    public function test_a_very_long_title_does_not_break_the_build(): void {
        $long = str_repeat('x', 300);
        $this->build('plan.csv', "Week 1,Short\nWeek 2,$long\nWeek 3,Also short\n", true);

        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains('Short', $names);
        $this->assertContains('Also short', $names);
        $longnames = array_filter($names, fn($name) => strpos($name, 'xxxxxxxxxx') === 0);
        $this->assertCount(1, $longnames);
        $this->assertLessThanOrEqual(255, mb_strlen(reset($longnames)));
        $this->assert_sound($this->course->id);
    }

    /**
     * Two files built one after the other append: the second does not replace the first.
     */
    public function test_two_files_append(): void {
        $this->build('one.csv', "Week 1,Alpha\n");
        $this->build('two.csv', "Theme 1,Beta\nWeek 1,Gamma\n");

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->week_rows('Alpha', '-', 0),
            $this->theme_rows('Beta', ['Gamma'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * A file and Quick Add used together: the file's structure first, then the default weeks after it.
     */
    public function test_file_then_quick_add(): void {
        global $USER;
        $this->build('plan.csv', "Theme 1,Foundations\nWeek 1,Introduction\n");
        quick_add_service::queue_weeks($this->course->id, $USER->id, 1, 0, false);
        $this->run_jobs();

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Foundations', ['Introduction'], 0),
            $this->week_rows('Week 1', '-', 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * A bigger file: twelve themes of four weeks each builds a sound structure of the right size.
     */
    public function test_a_larger_file(): void {
        set_config('maxcsvsections', 0, 'aiplacement_modgen');
        $csv = '';
        for ($t = 1; $t <= 12; $t++) {
            $csv .= "Theme $t,Theme title $t\n";
            for ($w = 1; $w <= 4; $w++) {
                $csv .= "Week $w,Week $t.$w\n";
            }
        }

        $this->build('large.csv', $csv);

        // Assessments + 12 themes + 48 weeks + 144 sessions.
        $this->assertCount(1 + 12 + 48 + 144, \aiplacement_modgen_structure_snapshot::rows($this->course->id));
        $this->assert_sound($this->course->id);
    }

    /**
     * The job reports a message for every section the file created.
     */
    public function test_job_messages(): void {
        global $DB;
        $this->build('plan.csv', "Theme 1,Foundations\nWeek 1,Introduction\n");

        $job = $DB->get_record('aiplacement_modgen_jobs', ['courseid' => $this->course->id], '*', MUST_EXIST);
        $this->assertSame('completed', $job->status);
        $this->assertSame('create_from_json', $job->action);
        $result = json_decode($job->result, true);
        $this->assertSame([], $result['warnings']);
        // The theme and the week; sessions are not listed separately for file builds.
        $this->assertContains(get_string('sectioncreated', 'aiplacement_modgen', 'Foundations'), $result['messages']);
        $this->assertContains(get_string('subsectioncreated', 'aiplacement_modgen', 'Introduction'), $result['messages']);
    }

    /**
     * The preview shows the same themes, weeks and descriptions that will be built.
     */
    public function test_preview_matches_what_is_built(): void {
        $structure = $this->read($this->draft_file('plan.csv', self::THEME_CSV)['itemid']);

        $preview = preview_generator::generate($structure, $structure['_detected_moduletype']);

        $this->assertTrue($preview['hasthemes']);
        $this->assertSame(['Foundations', 'Application'], array_column($preview['themes'], 'title'));
        $this->assertSame('About foundations', $preview['themes'][0]['summary']);
        $this->assertSame(['Introduction', 'Methods'], array_column($preview['themes'][0]['weeks'], 'title'));
        $this->assertSame(['Practice'], array_column($preview['themes'][1]['weeks'], 'title'));
    }

    /**
     * A file that would take the course over the total section cap is refused when approved, and if a
     * job is queued anyway it fails without building anything.
     */
    public function test_a_file_over_the_total_cap_builds_nothing(): void {
        global $DB, $USER;
        set_config('maxtotalsections', 8, 'aiplacement_modgen');
        $structure = $this->read($this->draft_file('plan.csv', "Week 1,A\nWeek 2,B\n")['itemid']);
        $before = \aiplacement_modgen_structure_snapshot::rows($this->course->id);

        // The approve step checks this before queuing.
        try {
            \aiplacement_modgen\local\theme_builder::enforce_section_limit(
                $this->course->id,
                \aiplacement_modgen\local\theme_builder::count_projected_sections_from_json(
                    $structure,
                    $structure['_detected_moduletype']
                )
            );
            $this->fail('Two weeks (eight sections) plus Assessments must not fit a cap of eight.');
        } catch (\moodle_exception $e) {
            $this->assertSame('sectionlimitexceeded', $e->errorcode);
        }

        // Even queued directly, the job refuses to build and does not retry.
        $type = $structure['_detected_moduletype'];
        unset($structure['_detected_moduletype']);
        $jobid = job_status_helper::queue_creation_job($this->course->id, $USER->id, 'create_from_json', [
            'json' => $structure, 'moduletype' => $type, 'createsummaryactivities' => false,
        ]);
        $this->run_jobs();
        $this->resetDebugging();

        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('failed', $job->status);
        $this->assertFalse(json_decode($job->result, true)['will_retry']);
        $this->assertSame($before, \aiplacement_modgen_structure_snapshot::rows($this->course->id));
    }

    /**
     * A template chosen from the library builds the same as uploading its file.
     */
    public function test_library_template(): void {
        $templateid = template_manager::create(
            'Standard module',
            'Twelve week plan',
            $this->draft_file('library.csv', "Theme 1,Library theme\nWeek 1,Library week\n")['file']
        );

        $this->approve($this->read(0, $templateid), false);

        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Library theme', ['Library week'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * An uploaded file takes priority over a template picked in the same form.
     */
    public function test_an_upload_beats_a_library_template(): void {
        $templateid = template_manager::create(
            'Standard module',
            '',
            $this->draft_file('library.csv', "Week 1,From the library\n")['file']
        );

        $this->approve($this->read($this->draft_file('mine.csv', "Week 1,From my upload\n")['itemid'], $templateid), false);

        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains('From my upload', $names);
        $this->assertNotContains('From the library', $names);
    }

    /**
     * With two files in the upload area the CSV is used, not the other file.
     */
    public function test_the_csv_is_chosen_from_several_files(): void {
        global $USER;
        $itemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        foreach (['a-notes.txt' => "Week 1,From the text file\n", 'z-plan.csv' => "Week 1,From the CSV\n"] as $name => $content) {
            $fs->create_file_from_string([
                'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
                'itemid' => $itemid, 'filepath' => '/', 'filename' => $name,
            ], $content);
        }

        $this->approve($this->read($itemid), false);

        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains('From the CSV', $names);
        $this->assertNotContains('From the text file', $names);
    }

    /**
     * With AI on, a file with no prompt is still read as a plain CSV: no AI call is made.
     */
    public function test_with_ai_on_a_file_without_a_prompt_is_read_directly(): void {
        set_config('enable_ai', 1, 'aiplacement_modgen');

        $this->build('plan.csv', "Week 1,Alpha\n");

        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertContains('Alpha', $names);
    }

    /**
     * Nothing to read is refused: no upload and no template.
     */
    public function test_no_file_and_no_template_is_refused(): void {
        try {
            $this->read(0);
            $this->fail('There is no file to read.');
        } catch (\moodle_exception $e) {
            $this->assertSame('csvnodraft', $e->errorcode);
        }
    }

    /**
     * A library template whose file has gone is reported, and nothing is built.
     */
    public function test_a_template_with_a_missing_file_is_reported(): void {
        global $DB;
        $templateid = template_manager::create(
            'Broken',
            '',
            $this->draft_file('library.csv', "Week 1,A\n")['file']
        );
        $template = template_manager::get_by_id($templateid);
        get_file_storage()->get_file_by_id($template->fileid)->delete();

        try {
            $this->read(0, $templateid);
            $this->fail('The template file is missing.');
        } catch (\moodle_exception $e) {
            $this->assertSame('templateloaderror', $e->errorcode);
        }
        $this->assertSame([], \aiplacement_modgen_structure_snapshot::rows($this->course->id));
    }
}
