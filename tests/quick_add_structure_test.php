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

use aiplacement_modgen\local\quick_add_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * What Quick Add builds, checked against the stored course structure.
 *
 * Every test queues the same jobs the browser does, runs them, and then reads
 * course_sections, course_format_options and course_modules directly.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\quick_add_service
 * @covers \aiplacement_modgen\local\theme_builder
 */
final class quick_add_structure_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var \stdClass Course the structures are built in. */
    private $course;

    /**
     * A Flexible sections course and an administrator to run the jobs as.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $this->course = $this->flexsections_course();
    }

    /**
     * Queue and run a themes request.
     *
     * @param int $themes Number of themes.
     * @param int $weeks Weeks per theme.
     * @param bool $activities Whether to create the section summary activities.
     * @param int $parentsection Section the request was made from.
     */
    private function add_themes(int $themes, int $weeks, bool $activities = false, int $parentsection = 0): void {
        global $USER;
        quick_add_service::queue_themes($this->course->id, $USER->id, $themes, $weeks, $parentsection, $activities);
        $this->run_jobs();
    }

    /**
     * Queue and run a weeks request.
     *
     * @param int $weeks Number of weeks.
     * @param bool $activities Whether to create the section summary activities.
     * @param int $parentsection Section to add them within (0 for the top).
     */
    private function add_weeks(int $weeks, bool $activities = false, int $parentsection = 0): void {
        global $USER;
        quick_add_service::queue_weeks($this->course->id, $USER->id, $weeks, $parentsection, $activities);
        $this->run_jobs();
    }

    /**
     * Delete a section the way the course page does: Flexible sections renumbers the parents it points at.
     *
     * @param int $sectionid Section id.
     */
    private function delete_section(int $sectionid): void {
        $info = get_fast_modinfo($this->course->id)->get_section_info_by_id($sectionid, MUST_EXIST);
        course_get_format($this->course)->delete_section_with_children($info);
    }

    /**
     * Section number of the first section with a name.
     *
     * @param string $name Section name.
     * @return int
     */
    private function number_of(string $name): int {
        foreach (\aiplacement_modgen_structure_snapshot::rows($this->course->id) as $row) {
            if ($row['name'] === $name) {
                return $row['section'];
            }
        }
        $this->fail("No section called '$name'.");
    }

    /**
     * One theme with one week: the theme, its week and the week's three sessions.
     */
    public function test_one_theme_with_one_week(): void {
        $this->add_themes(1, 1);

        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * The default text Quick Add puts in the theme and week summaries; sessions get none.
     */
    public function test_default_summaries(): void {
        $this->add_themes(1, 1);

        $themesummary = get_string('defaultthemesummary', 'aiplacement_modgen');
        $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');
        $expected = $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0, $themesummary, $weeksummary);
        $this->assert_structure($this->course->id, $expected);

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $sessions = array_filter($rows, fn($row) => $row['depth'] === 3);
        $this->assertCount(3, $sessions);
        foreach ($sessions as $session) {
            $this->assertSame('', $session['summary']);
        }
    }

    /**
     * Several themes, each with several weeks, in the order they were numbered.
     */
    public function test_several_themes_with_several_weeks(): void {
        $this->add_themes(3, 2);

        $expected = [];
        for ($t = 1; $t <= 3; $t++) {
            $expected = array_merge($expected, $this->theme_rows(
                "Theme $t",
                ["Theme $t - Week 1", "Theme $t - Week 2"],
                0
            ));
        }
        $this->assert_structure($this->course->id, $expected);
        $this->assert_sound($this->course->id);
    }

    /**
     * The largest request the default settings allow still builds a sound structure.
     */
    public function test_largest_default_theme_request(): void {
        set_config('maxquicksections', 4, 'aiplacement_modgen');
        set_config('maxweeksperTheme', 3, 'aiplacement_modgen');

        $this->add_themes(4, 3);

        $rows = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        // 4 themes + 12 weeks + 36 sessions.
        $this->assertCount(4 + 12 + 36, $rows);
        $this->assert_sound($this->course->id);
    }

    /**
     * With the section summary option on, every week and session holds one learning activity.
     */
    public function test_summary_activities_when_requested(): void {
        $this->add_themes(1, 2, true);

        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1', 'Theme 1 - Week 2'], 1)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Standalone weeks sit at the top level, each with its sessions.
     */
    public function test_weeks_only(): void {
        $this->add_weeks(3);

        $expected = [];
        foreach ([1, 2, 3] as $w) {
            $expected = array_merge($expected, $this->week_rows("Week $w", '-', 0));
        }
        $this->assert_structure($this->course->id, $expected);
        $this->assert_sound($this->course->id);
    }

    /**
     * Standalone weeks with the summary option have their activities, and the default week text.
     */
    public function test_weeks_with_summary_activities(): void {
        $this->add_weeks(2, true);

        $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');
        $this->assert_structure($this->course->id, array_merge(
            $this->week_rows('Week 1', '-', 1, $weeksummary),
            $this->week_rows('Week 2', '-', 1, $weeksummary)
        ));
    }

    /**
     * Themes first and then weeks: the weeks come after the themes at the top level.
     */
    public function test_themes_then_weeks(): void {
        $this->add_themes(2, 1);
        $this->add_weeks(2);

        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0),
            $this->theme_rows('Theme 2', ['Theme 2 - Week 1'], 0),
            $this->week_rows('Week 1', '-', 0),
            $this->week_rows('Week 2', '-', 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Weeks first and then themes: the themes come after the weeks.
     */
    public function test_weeks_then_themes(): void {
        $this->add_weeks(1);
        $this->add_themes(1, 2);

        $this->assert_structure($this->course->id, array_merge(
            $this->week_rows('Week 1', '-', 0),
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1', 'Theme 1 - Week 2'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Building again appends: the default names start again at 1 rather than carrying on.
     */
    public function test_building_twice_appends_and_repeats_the_default_names(): void {
        $this->add_themes(1, 1);
        $this->add_themes(1, 1);

        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0),
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * Weeks requested from inside a theme become that theme's last children, one level down.
     */
    public function test_weeks_added_inside_a_theme(): void {
        $this->add_themes(2, 1);
        $this->add_weeks(1, false, $this->number_of('Theme 1'));

        $this->assert_structure($this->course->id, array_merge(
            [$this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0)[0]],
            $this->week_rows('Theme 1 - Week 1', 'Theme 1', 0),
            $this->week_rows('Week 1', 'Theme 1', 0),
            $this->theme_rows('Theme 2', ['Theme 2 - Week 1'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * The target section is remembered by id: renumbering before the job runs does not move the weeks.
     */
    public function test_target_section_survives_renumbering_before_the_job_runs(): void {
        global $DB, $USER;
        $this->add_themes(2, 1);
        \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);
        $target = $this->number_of('Theme 2');
        quick_add_service::queue_weeks($this->course->id, $USER->id, 1, $target, false);

        // Someone removes the Assessments section before the background job runs; every number shifts.
        $assessments = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'name' => get_string('assessmentssectionname', 'aiplacement_modgen'),
        ], '*', MUST_EXIST);
        $this->delete_section((int) $assessments->id);
        $this->run_jobs();

        // The weeks still land in Theme 2, and the deleted Assessments section is not put back.
        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0),
            [$this->theme_rows('Theme 2', ['Theme 2 - Week 1'], 0)[0]],
            $this->week_rows('Theme 2 - Week 1', 'Theme 2', 0),
            $this->week_rows('Week 1', 'Theme 2', 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * A build that would nest sections deeper than Flexible sections allows is refused, and adds
     * nothing; within the limit it goes ahead.
     */
    public function test_builds_respect_the_formats_depth_limit(): void {
        global $DB;
        // The refused build must really roll back, not inside the transaction PHPUnit wraps each test in.
        $this->preventResetByRollback();
        $this->add_themes(1, 1);
        set_config('maxsectiondepth', 3, 'format_flexsections');

        // A week in a theme (depth 2) with its sessions (depth 3) fits.
        $this->add_weeks(1, false, $this->number_of('Theme 1'));
        $this->assertTrue($DB->record_exists('course_sections', ['course' => $this->course->id, 'name' => 'Week 1']));

        // A week in that week would put its sessions at depth 4.
        $before = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $this->add_weeks(1, false, $this->number_of('Week 1'));

        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $this->course->id]));
        $job = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $this->course->id], 'id DESC', '*', 0, 1);
        $this->assertSame('failed', reset($job)->status);
        $this->resetDebugging();
        $this->assert_sound($this->course->id);
    }

    /**
     * Builds never add an Assessments section, and a name the teacher gave section 0 is kept.
     */
    public function test_builds_add_no_assessments_section_and_keep_section_0s_name(): void {
        global $DB;
        $this->add_themes(1, 1);
        $DB->set_field('course_sections', 'name', 'Welcome', ['course' => $this->course->id, 'section' => 0]);

        $this->add_weeks(1);

        $this->assertSame('Welcome', $DB->get_field('course_sections', 'name', ['course' => $this->course->id, 'section' => 0]));
        $this->assertFalse($DB->record_exists('course_sections', [
            'course' => $this->course->id,
            'name' => get_string('assessmentssectionname', 'aiplacement_modgen'),
        ]));
    }

    /**
     * Quick Add's Assessments entry adds the section at the top, straight after section 0, and
     * leaves the rest of the structure as it was.
     */
    public function test_assessments_entry_adds_the_section_at_the_top(): void {
        $this->add_themes(1, 1);

        $section = \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);

        $this->assertSame(1, (int) $section->section);
        $this->assert_structure($this->course->id, array_merge(
            [$this->assessments_row()],
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * The entry sets the course up as other builds do, and the course page shows the new
     * section's name straight away.
     */
    public function test_assessments_entry_sets_up_the_course_and_its_cache(): void {
        global $DB;
        get_fast_modinfo($this->course->id);

        $section = \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);

        $this->assertSame(
            get_string('introductionsectionname', 'aiplacement_modgen'),
            $DB->get_field('course_sections', 'name', ['course' => $this->course->id, 'section' => 0])
        );
        $this->assertSame(1, (int) $section->section);
        $name = get_string('assessmentssectionname', 'aiplacement_modgen');
        $this->assertSame($name, get_fast_modinfo($this->course->id)->get_section_info(1)->name);
        $this->assert_sound($this->course->id);
    }

    /**
     * A job whose course was switched to another format after it was queued fails, and does not
     * switch the course back.
     */
    public function test_a_job_fails_if_the_course_changed_format_after_it_was_queued(): void {
        global $DB, $USER;
        quick_add_service::queue_weeks($this->course->id, $USER->id, 1, 0, false);
        update_course((object) ['id' => $this->course->id, 'format' => 'social']);

        $this->run_jobs();

        $job = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $this->course->id], 'id DESC', '*', 0, 1);
        $this->assertSame('failed', reset($job)->status);
        $this->assertSame('social', $DB->get_field('course', 'format', ['id' => $this->course->id]));
        $this->assertFalse($DB->record_exists('course_sections', ['course' => $this->course->id, 'name' => 'Week 1']));
        $this->resetDebugging();
    }

    /**
     * A course in another format is refused and left as it is: the plugin never changes the format.
     */
    public function test_assessments_entry_refuses_a_course_in_another_format(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['format' => 'social']);
        $before = $DB->count_records('course_sections', ['course' => $course->id]);

        try {
            \aiplacement_modgen\local\theme_builder::add_assessments_section($course->id);
            $this->fail('A course in a format the plugin does not build in must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorformatflexsectionsonly', $e->errorcode);
        }
        $this->assertSame('social', $DB->get_field('course', 'format', ['id' => $course->id]));
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * A course that already has an Assessments section is not given a second one.
     */
    public function test_assessments_entry_refuses_a_second_section(): void {
        global $DB;
        \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);

        try {
            \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);
            $this->fail('A second Assessments section must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('assessmentsexists', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('course_sections', [
            'course' => $this->course->id,
            'name' => get_string('assessmentssectionname', 'aiplacement_modgen'),
        ]));
    }

    /**
     * A top-level section the teacher named in another case counts as the course's Assessments section.
     */
    public function test_assessments_entry_refuses_a_top_level_section_in_another_case(): void {
        global $DB;
        $this->add_themes(1, 1);
        $DB->set_field('course_sections', 'name', 'assessments', ['course' => $this->course->id, 'name' => 'Theme 1']);
        $before = $DB->count_records('course_sections', ['course' => $this->course->id]);

        try {
            \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);
            $this->fail('A course with a top-level "assessments" section must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('assessmentsexists', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * A nested section called Assessments does not stop the entry adding the top-level one.
     */
    public function test_assessments_entry_ignores_a_nested_section_of_that_name(): void {
        global $DB;
        $name = get_string('assessmentssectionname', 'aiplacement_modgen');
        $this->add_themes(1, 1);
        $DB->set_field('course_sections', 'name', $name, ['course' => $this->course->id, 'name' => 'Theme 1 - Week 1']);
        rebuild_course_cache($this->course->id, true);

        $section = \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);

        $this->assertSame(1, (int) $section->section);
        $this->assertSame(2, $DB->count_records('course_sections', ['course' => $this->course->id, 'name' => $name]));
        $this->assert_sound($this->course->id);
    }

    /**
     * The Assessments entry counts against the total section cap like any build.
     */
    public function test_assessments_entry_respects_the_section_cap(): void {
        global $DB;
        $existing = $DB->count_records('course_sections', ['course' => $this->course->id]);
        set_config('maxtotalsections', $existing, 'aiplacement_modgen');

        try {
            \aiplacement_modgen\local\theme_builder::add_assessments_section($this->course->id);
            $this->fail('An Assessments section over the cap must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('sectionlimitexceeded', $e->errorcode);
        }
        $this->assertSame($existing, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }
    /**
     * If the target section is gone when the job runs, the weeks go to the top level instead.
     */
    public function test_weeks_fall_back_to_the_top_level_when_the_target_is_gone(): void {
        global $DB, $USER;
        $this->add_themes(2, 1);
        $theme2 = $DB->get_record('course_sections', ['course' => $this->course->id, 'name' => 'Theme 2'], '*', MUST_EXIST);
        quick_add_service::queue_weeks($this->course->id, $USER->id, 1, (int) $theme2->section, false);
        $this->delete_section((int) $theme2->id);
        $this->run_jobs();

        $this->assert_structure($this->course->id, array_merge(
            $this->theme_rows('Theme 1', ['Theme 1 - Week 1'], 0),
            $this->week_rows('Week 1', '-', 0)
        ));
        $this->assert_sound($this->course->id);
    }

    /**
     * The total section cap is exact: a build that fits is made, one section more is refused and changes nothing.
     */
    public function test_the_section_cap_boundary(): void {
        $this->add_weeks(1);
        $before = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $existing = count($before) + 1;

        // One more week is 4 sections.
        set_config('maxtotalsections', $existing + 3, 'aiplacement_modgen');
        try {
            $this->add_weeks(1);
            $this->fail('A build that is one section over the cap must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('sectionlimitexceeded', $e->errorcode);
        }
        $this->assertSame($before, \aiplacement_modgen_structure_snapshot::rows($this->course->id));

        set_config('maxtotalsections', $existing + 4, 'aiplacement_modgen');
        $this->add_weeks(1);
        $this->assertCount(count($before) + 4, \aiplacement_modgen_structure_snapshot::rows($this->course->id));
        $this->assert_sound($this->course->id);
    }

    /**
     * The jobs leave a complete record: completed, with a message for each section created.
     */
    public function test_job_record_lists_what_was_created(): void {
        global $DB;
        $this->add_themes(1, 1);

        $job = $DB->get_record('aiplacement_modgen_jobs', ['courseid' => $this->course->id], '*', MUST_EXIST);
        $this->assertSame('completed', $job->status);
        $result = json_decode($job->result, true);
        $this->assertTrue($result['success']);
        // The theme, its week and the three sessions.
        $this->assertCount(5, $result['messages']);
        $this->assertSame(get_string('sectioncreated', 'aiplacement_modgen', 'Theme 1'), $result['messages'][0]);
    }
}
