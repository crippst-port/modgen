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
use aiplacement_modgen\local\quick_add_service;
use aiplacement_modgen\local\template_processing_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * What Quick Add and the structure builds leave in Custom sections and Weekly sections courses.
 *
 * Each test runs the same jobs the browser queues, then reads the stored course: every section in the
 * order the course page shows it, the subsections each one holds, names, summaries and activities. It is
 * compared with the expected structure in full, and checked against every rule a core course must keep
 * (see aiplacement_modgen_structure_snapshot::problems()) and Moodle's own integrity check.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\quick_add_service
 * @covers \aiplacement_modgen\local\theme_builder
 * @covers \aiplacement_modgen\local\section_creation_service
 */
final class core_structure_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * An administrator to run the jobs as, the placement and its core formats on, and subsections enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        set_config('enable_ai', 0, 'aiplacement_modgen');
        set_config('enable_coreformats', 1, 'aiplacement_modgen');
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        \core_plugin_manager::reset_caches();
    }

    /**
     * Data provider: the two core formats.
     *
     * @return array
     */
    public static function formats_provider(): array {
        return [
            'Custom sections' => ['topics'],
            'Weekly sections' => ['weeks'],
        ];
    }

    /**
     * A course in a core format with no sections but section 0, or the given number.
     *
     * @param string $format topics or weeks.
     * @param int $sections Sections after section 0.
     * @return \stdClass
     */
    private function course(string $format, int $sections = 0): \stdClass {
        return $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => $sections]);
    }

    /**
     * Queue and run a New Week(s) request.
     *
     * @param int $courseid Course ID.
     * @param int $weeks Number of weeks.
     */
    private function add_weeks(int $courseid, int $weeks): void {
        global $USER;
        quick_add_service::queue_weeks($courseid, $USER->id, $weeks, 0, false);
        $this->run_jobs();
    }

    /**
     * Read a CSV the way Structure from file does, then queue and run the build its preview approves.
     *
     * @param int $courseid Course ID.
     * @param string $csv File content.
     * @return \stdClass The job after it has run.
     */
    private function build_from_csv(int $courseid, string $csv): \stdClass {
        $structure = (new template_processing_service())->process_and_generate((object) [
            'supportingfiles' => $this->draft_file('plan.csv', $csv)['itemid'],
            'prompt' => '',
            'moduletype' => 'connected_weekly',
            'selected_template_id' => 0,
        ], $courseid, '');
        $type = $structure['_detected_moduletype'];
        unset($structure['_detected_moduletype']);
        return $this->build_structure($courseid, $structure, $type);
    }

    /**
     * Names of the default weeks New Week(s) adds, 1 to $count.
     *
     * @param int $count How many.
     * @return string[]
     */
    private static function default_weeks(int $count): array {
        return array_map(
            fn($i) => get_string('defaultstandaloneweekname', 'aiplacement_modgen', $i),
            range(1, $count)
        );
    }

    /**
     * Rows for top-level weeks, each followed by its three sessions.
     *
     * @param string[] $weeks Week names.
     * @return array[]
     */
    private function weeks(array $weeks): array {
        $rows = [];
        foreach ($weeks as $week) {
            $rows = array_merge($rows, $this->week_rows($week, '-', 0));
        }
        return $rows;
    }

    /**
     * New Week(s) adds each week as a section followed by its three sessions as subsections.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_new_weeks(string $format): void {
        $course = $this->course($format);

        $this->add_weeks($course->id, 3);

        $this->assert_structure($course->id, $this->weeks(self::default_weeks(3)));
        $this->assert_sound($course->id);
    }

    /**
     * A second New Week(s) adds its weeks after the first's, which are left as they were.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_new_weeks_twice(string $format): void {
        $course = $this->course($format);

        $this->add_weeks($course->id, 2);
        $this->add_weeks($course->id, 1);

        $this->assert_structure($course->id, $this->weeks(array_merge(self::default_weeks(2), self::default_weeks(1))));
        $this->assert_sound($course->id);
    }

    /**
     * The Assessments section goes straight after section 0, whether it is added before or after the weeks.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_assessments_section_goes_first(string $format): void {
        $before = $this->course($format);
        theme_builder::add_assessments_section($before->id);
        $this->add_weeks($before->id, 2);

        $after = $this->course($format);
        $this->add_weeks($after->id, 2);
        theme_builder::add_assessments_section($after->id);

        $expected = array_merge([$this->assessments_row()], $this->weeks(self::default_weeks(2)));
        foreach ([$before, $after] as $course) {
            $this->assert_structure($course->id, $expected);
            $this->assert_sound($course->id);
        }
    }

    /**
     * Weeks go after the course's own sections, which keep their names, activities and subsections.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_existing_content_is_kept(string $format): void {
        $course = $this->course($format, 2);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(1), ['name' => 'Welcome']);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(2), ['name' => 'Reading']);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Handbook']);
        $this->getDataGenerator()->create_module('subsection', ['course' => $course->id, 'section' => 2, 'name' => 'Set texts']);

        $this->add_weeks($course->id, 1);

        $this->assert_structure($course->id, array_merge([
            ['name' => 'Welcome', 'parent' => '-', 'depth' => 1, 'activities' => 1, 'activitynames' => ['Handbook']],
            ['name' => 'Reading', 'parent' => '-', 'depth' => 1, 'activities' => 0],
            ['name' => 'Set texts', 'parent' => 'Reading', 'depth' => 2, 'activities' => 0],
        ], $this->weeks(self::default_weeks(1))));
        $this->assert_sound($course->id);
    }

    /**
     * A weekly CSV file becomes its weeks, in order, each with the description given for it.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_weekly_csv_file(string $format): void {
        $course = $this->course($format);

        $job = $this->build_from_csv($course->id, "Week 1,Alpha\nDescription,First week\nWeek 2,Beta\nSection 3,Gamma\n");

        $this->assertSame('completed', $job->status);
        $this->assert_structure($course->id, array_merge(
            $this->week_rows('Alpha', '-', 0, 'First week'),
            $this->week_rows('Beta', '-', 0),
            $this->week_rows('Gamma', '-', 0)
        ));
        $this->assert_sound($course->id);
    }

    /**
     * A CSV file with themes is refused, and the course is left exactly as it was.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_theme_csv_file_changes_nothing(string $format): void {
        $course = $this->course($format);
        $this->add_weeks($course->id, 1);

        $job = $this->build_from_csv($course->id, "Theme 1,Foundations\nWeek 1,Introduction\n");
        $this->resetDebugging();

        $this->assertSame('failed', $job->status);
        $this->assert_structure($course->id, $this->weeks(self::default_weeks(1)));
        $this->assert_sound($course->id);
    }

    /**
     * A weekly structure from the AI keeps each week's summary and outline, and each session's description.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_ai_weekly_structure(string $format): void {
        $course = $this->course($format);
        $session = fn(string $text) => ['description' => $text];

        $job = $this->build_structure($course->id, ['sections' => [
            [
                'title' => 'Cells',
                'summary' => 'What cells are made of.',
                'outline' => ['Membranes', 'Organelles'],
                'sessions' => [
                    'presession' => $session('Read chapter 1.'),
                    'session' => $session('Microscope lab.'),
                    'postsession' => $session('Quiz on cells.'),
                ],
            ],
            ['title' => 'Tissues', 'summary' => ''],
        ]], 'connected_weekly');

        $this->assertSame('completed', $job->status);
        $presession = get_string('presession', 'aiplacement_modgen');
        $sessionname = get_string('session', 'aiplacement_modgen');
        $postsession = get_string('postsession', 'aiplacement_modgen');
        $this->assert_structure($course->id, array_merge([
            ['name' => 'Cells', 'parent' => '-', 'depth' => 1, 'activities' => 0, 'summary' => 'What cells are made of.'],
            ['name' => $presession, 'parent' => 'Cells', 'depth' => 2, 'summary' => 'Read chapter 1.'],
            ['name' => $sessionname, 'parent' => 'Cells', 'depth' => 2, 'summary' => 'Microscope lab.'],
            ['name' => $postsession, 'parent' => 'Cells', 'depth' => 2, 'summary' => 'Quiz on cells.'],
        ], $this->week_rows('Tissues', '-', 0)));
        $rows = \aiplacement_modgen_structure_snapshot::rows($course->id);
        $this->assertStringContainsString('Organelles', $rows[0]['summary'], 'The outline is kept in the week summary.');
        $this->assert_sound($course->id);
    }

    /**
     * Markup is stripped from names, long names are cut to the 255 characters a section name holds, and
     * each subsection's activity keeps the same name as its section.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_names_are_cleaned_and_cut(string $format): void {
        $course = $this->course($format);
        $long = str_repeat('Long title ', 30);

        $job = $this->build_structure($course->id, ['sections' => [
            ['title' => '<b>Cells</b> & tissues'],
            ['title' => $long],
        ]], 'connected_weekly');

        $this->assertSame('completed', $job->status);
        $this->assert_structure($course->id, array_merge(
            $this->week_rows('Cells & tissues', '-', 0),
            $this->week_rows(\core_text::substr(trim($long), 0, 255), '-', 0)
        ));
        $this->assert_sound($course->id);
    }

    /**
     * A course the teacher has edited since a build (a week deleted with its subsections, another moved)
     * takes a further build cleanly.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_build_after_the_teacher_edits_the_course(string $format): void {
        global $DB;
        $course = $this->course($format, 1);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(1), ['name' => 'Welcome']);
        $this->add_weeks($course->id, 3);
        [$week1, $week2, $week3] = self::default_weeks(3);

        // Delete Week 2, subsections and all, then move Week 3 above Welcome.
        $weekid = fn(string $name) => $DB->get_field('course_sections', 'id', [
            'course' => $course->id, 'name' => $name, 'component' => null,
        ]);
        course_delete_section($course, get_fast_modinfo($course)->get_section_info_by_id($weekid($week2)), true);
        move_section_to($course, get_fast_modinfo($course)->get_section_info_by_id($weekid($week3))->section, 1);
        $this->assert_sound($course->id);

        $this->add_weeks($course->id, 1);

        $this->assert_structure($course->id, array_merge(
            $this->week_rows($week3, '-', 0),
            [['name' => 'Welcome', 'parent' => '-', 'depth' => 1, 'activities' => 0]],
            $this->week_rows($week1, '-', 0),
            $this->week_rows($week1, '-', 0)
        ));
        $this->assert_sound($course->id);
    }

    /**
     * With the section summary option, each week and each session starts with its summary activity.
     *
     * @dataProvider formats_provider
     * @param string $format Course format.
     */
    public function test_summary_activities(string $format): void {
        global $USER;
        if (!\aiplacement_modgen\activitytype\learningactivity::is_available()) {
            $this->markTestSkipped('mod_learningactivity is not installed.');
        }
        $course = $this->course($format);

        quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, true);
        $this->run_jobs();

        $this->assert_structure($course->id, $this->week_rows(self::default_weeks(1)[0], '-', 1));
        $this->assert_sound($course->id);
    }
}
