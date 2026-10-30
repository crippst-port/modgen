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

use aiplacement_modgen\local\course_formats;
use aiplacement_modgen\local\quick_add_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Builds in Custom sections and Weekly sections: weeks as sections, sessions as core subsections, no themes.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\course_formats
 * @covers \aiplacement_modgen\local\theme_builder
 * @covers \aiplacement_modgen\local\quick_add_service
 * @covers \aiplacement_modgen\local\section_creation_service
 */
final class core_format_build_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * An administrator to run the jobs as, the placement on and subsections enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $this->set_subsections(true);
    }

    /**
     * Switch the Subsection activity on or off.
     *
     * @param bool $enabled Whether it is enabled.
     */
    private function set_subsections(bool $enabled): void {
        \core\plugininfo\mod::enable_plugin('subsection', $enabled ? 1 : 0);
        \core_plugin_manager::reset_caches();
    }

    /**
     * A course in a core format with no sections but section 0.
     *
     * @param string $format topics or weeks.
     * @return \stdClass
     */
    private function core_course(string $format = 'topics'): \stdClass {
        return $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => 0]);
    }

    /**
     * The course's regular sections after section 0, each with the names of its subsections, in course order.
     *
     * @param int $courseid Course ID.
     * @return array Section name => subsection names.
     */
    private function weeks_with_sessions(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $weeks = [];
        foreach ($modinfo->get_listed_section_info_all() as $section) {
            if ($section->section == 0) {
                continue;
            }
            $sessions = [];
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->modname === 'subsection') {
                    $sessions[] = $cm->get_delegated_section_info()->name;
                }
            }
            $weeks[$section->name] = $sessions;
        }
        return $weeks;
    }

    /**
     * The newest job in a course.
     *
     * @param int $courseid Course ID.
     * @return \stdClass
     */
    private function last_job(int $courseid): \stdClass {
        global $DB;
        $jobs = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $courseid], 'id DESC', '*', 0, 1);
        return reset($jobs);
    }

    /**
     * Data provider: the two core formats.
     *
     * @return array
     */
    public static function core_formats_provider(): array {
        return [
            'Custom sections' => ['topics'],
            'Weekly sections' => ['weeks'],
        ];
    }

    /**
     * Quick Add's New Week(s) adds each week as a section holding its three sessions as subsections.
     *
     * @dataProvider core_formats_provider
     * @param string $format Course format.
     */
    public function test_quick_add_weeks_build_sections_with_subsessions(string $format): void {
        global $USER;
        $course = $this->core_course($format);

        quick_add_service::queue_weeks($course->id, $USER->id, 2, 0, false);
        $this->run_jobs();

        $this->assertSame('completed', $this->last_job($course->id)->status);
        $sessions = [
            get_string('presession', 'aiplacement_modgen'),
            get_string('session', 'aiplacement_modgen'),
            get_string('postsession', 'aiplacement_modgen'),
        ];
        $this->assertSame([
            get_string('defaultstandaloneweekname', 'aiplacement_modgen', 1) => $sessions,
            get_string('defaultstandaloneweekname', 'aiplacement_modgen', 2) => $sessions,
        ], $this->weeks_with_sessions($course->id));
        $this->assertSame($format, course_formats::format($course->id));
        $this->assertSame(
            get_string('introductionsectionname', 'aiplacement_modgen'),
            get_fast_modinfo($course->id)->get_section_info(0)->name
        );
    }

    /**
     * Weeks added to a course that already has sections and subsections go after its own sections.
     */
    public function test_weeks_go_after_the_courses_own_sections(): void {
        global $USER;
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, false);
        $this->run_jobs();
        quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, false);
        $this->run_jobs();

        $modinfo = get_fast_modinfo($course->id);
        $names = [];
        foreach ($modinfo->get_listed_section_info_all() as $section) {
            if ($section->section > 0) {
                $names[] = (string) $section->name;
            }
        }
        $week = get_string('defaultstandaloneweekname', 'aiplacement_modgen', 1);
        $this->assertSame(['', '', $week, $week], $names);
        $this->assertCount(6, $modinfo->get_instances_of('subsection'));
    }

    /**
     * A weekly structure from a file or the AI keeps its titles and its sessions' descriptions.
     *
     * @dataProvider core_formats_provider
     * @param string $format Course format.
     */
    public function test_weekly_structure_builds(string $format): void {
        $course = $this->core_course($format);

        $job = $this->build_structure($course->id, [
            'sections' => [
                ['title' => 'Cells', 'summary' => 'What cells are made of.'],
                ['title' => 'Tissues', 'summary' => ''],
            ],
        ], 'connected_weekly');

        $this->assertSame('completed', $job->status);
        $weeks = $this->weeks_with_sessions($course->id);
        $this->assertSame(['Cells', 'Tissues'], array_keys($weeks));
        $this->assertCount(3, $weeks['Cells']);
        $section = get_fast_modinfo($course->id)->get_section_info(1);
        $this->assertStringContainsString('What cells are made of.', $section->summary);
    }

    /**
     * New Theme(s) is refused in a core format, before anything is queued.
     */
    public function test_quick_add_themes_are_refused(): void {
        global $DB, $USER;
        $course = $this->core_course();

        try {
            quick_add_service::queue_themes($course->id, $USER->id, 1, 1, 0, false);
            $this->fail('Themes must be refused in Custom sections.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorthemesneedflexsections', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('aiplacement_modgen_jobs', ['courseid' => $course->id]));
    }

    /**
     * A themes build reached directly is refused and adds nothing.
     */
    public function test_theme_build_is_refused(): void {
        global $DB;
        $course = $this->core_course('weeks');
        $before = $DB->count_records('course_sections', ['course' => $course->id]);

        try {
            theme_builder::create_themes($course->id, 1, 1, 0);
            $this->fail('Themes must be refused in Weekly sections.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorthemesneedflexsections', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * Data provider: structures that need themes, however they say so.
     *
     * @return array
     */
    public static function themed_structures_provider(): array {
        $themes = ['themes' => [['title' => 'Theme', 'summary' => '', 'weeks' => [['title' => 'Week']]]]];
        return [
            'Theme type' => [$themes, 'connected_theme'],
            'Themes under the weekly type' => [$themes + ['sections' => [['title' => 'Week']]], 'connected_weekly'],
        ];
    }

    /**
     * A structure with themes is refused by the job, and adds nothing.
     *
     * @dataProvider themed_structures_provider
     * @param array $json Structure.
     * @param string $moduletype Module type.
     */
    public function test_themed_structure_is_refused(array $json, string $moduletype): void {
        global $DB;
        $course = $this->core_course();
        $before = $DB->count_records('course_sections', ['course' => $course->id]);

        $job = $this->build_structure($course->id, $json, $moduletype);
        $this->resetDebugging();

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString(
            get_string('errorthemesneedflexsections', 'aiplacement_modgen'),
            $job->result
        );
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * The same check refuses themes before a preview is shown or an approved structure queued.
     */
    public function test_structure_check_refuses_themes_only_where_they_cannot_go(): void {
        $core = $this->core_course();
        $flex = $this->flexsections_course();
        $themes = ['themes' => [['title' => 'Theme']]];

        course_formats::require_structure_fits($core->id, 'connected_weekly', ['sections' => [['title' => 'Week']]]);
        course_formats::require_structure_fits($flex->id, 'connected_theme', $themes);
        $this->expectExceptionMessage(get_string('errorthemesneedflexsections', 'aiplacement_modgen'));
        course_formats::require_structure_fits($core->id, 'connected_theme', $themes);
    }

    /**
     * A queued themes job fails if its course is switched to a core format before it runs.
     */
    public function test_themes_job_fails_after_a_switch_to_a_core_format(): void {
        global $DB, $USER;
        $course = $this->flexsections_course();
        quick_add_service::queue_themes($course->id, $USER->id, 1, 1, 0, false);
        update_course((object) ['id' => $course->id, 'format' => 'topics']);

        $this->run_jobs();

        $this->assertSame('failed', $this->last_job($course->id)->status);
        $this->assertSame('topics', course_formats::format($course->id));
        $this->assertFalse($DB->record_exists('course_sections', [
            'course' => $course->id,
            'name' => get_string('defaultthemename', 'aiplacement_modgen', 1),
        ]));
        $this->resetDebugging();
    }

    /**
     * Weeks cannot go inside a section in a core format: their sessions would be two levels down.
     */
    public function test_weeks_inside_a_section_are_refused(): void {
        global $DB, $USER;
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 1]);

        try {
            quick_add_service::queue_weeks($course->id, $USER->id, 1, 1, false);
            $this->fail('A week inside a section must be refused in Custom sections.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorweekstoplevelonly', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('aiplacement_modgen_jobs', ['courseid' => $course->id]));
    }

    /**
     * With subsections switched off, every build in a core format is refused: nothing is built without them.
     */
    public function test_builds_are_refused_while_subsections_are_off(): void {
        global $DB;
        $course = $this->core_course();
        $this->set_subsections(false);

        $this->assertSame('errorsubsectionsdisabled', course_formats::unsupported_reason($course->id));
        try {
            theme_builder::create_weeks($course->id, 1, 0, false);
            $this->fail('A build must be refused while subsections are off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorsubsectionsdisabled', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('course_sections', ['course' => $course->id]));

        // Flexible sections does not use them.
        $this->assertNull(course_formats::unsupported_reason($this->flexsections_course()->id));
    }

    /**
     * A queued job fails if subsections are switched off before it runs, and builds nothing.
     */
    public function test_job_fails_if_subsections_are_switched_off_after_it_was_queued(): void {
        global $DB, $USER;
        $course = $this->core_course();
        quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, false);
        $this->set_subsections(false);

        $this->run_jobs();

        $this->assertSame('failed', $this->last_job($course->id)->status);
        $this->assertSame(1, $DB->count_records('course_sections', ['course' => $course->id]));
        $this->resetDebugging();
    }

    /**
     * A job run by a teacher who may not add Subsection activities fails once, not retried, and builds nothing.
     */
    public function test_job_without_the_subsection_capability_fails_without_retrying(): void {
        global $DB;
        $course = $this->core_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('aiplacement/modgen:managestructure', CAP_ALLOW, $role, \context_course::instance($course->id));
        assign_capability('mod/subsection:addinstance', CAP_PROHIBIT, $role, \context_course::instance($course->id));
        $this->setUser($teacher);

        quick_add_service::queue_weeks($course->id, $teacher->id, 1, 0, false);
        $this->setAdminUser();
        $this->run_jobs();
        $this->resetDebugging();

        $job = $this->last_job($course->id);
        $this->assertSame('failed', $job->status);
        $this->assertEmpty(json_decode($job->result, true)['will_retry'] ?? false);
        $this->assertSame(1, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * Other formats are refused.
     */
    public function test_other_formats_are_refused(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'social']);

        $this->assertSame('errorformatnotsupported', course_formats::unsupported_reason($course->id));
        $this->expectExceptionMessage(get_string('errorformatnotsupported', 'aiplacement_modgen'));
        theme_builder::create_weeks($course->id, 1, 0, false);
    }

    /**
     * A build that would take a core course past the site's section cap is refused: core counts subsections
     * against it, and a course at the cap cannot have sections added by hand.
     */
    public function test_core_section_cap_counts_subsections(): void {
        global $USER;
        set_config('maxsections', 10, 'moodlecourse');
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        // Two sections plus two weeks of four is 10: at the cap, which is allowed.
        quick_add_service::queue_weeks($course->id, $USER->id, 2, 0, false);
        $this->run_jobs();
        $this->assertSame('completed', $this->last_job($course->id)->status);

        try {
            quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, false);
            $this->fail('A build past the site cap must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('coresectionlimitexceeded', $e->errorcode);
        }
    }

    /**
     * The Assessments entry adds its section straight after section 0, once.
     */
    public function test_assessments_section(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);

        $section = theme_builder::add_assessments_section($course->id);

        $this->assertSame(1, (int) $section->section);
        $this->assertSame(get_string('assessmentssectionname', 'aiplacement_modgen'), $section->name);
        $this->expectExceptionMessage(get_string('assessmentsexists', 'aiplacement_modgen', $section->name));
        theme_builder::add_assessments_section($course->id);
    }

    /**
     * A subsection named Assessments is not the course's own Assessments section.
     */
    public function test_assessments_ignores_a_subsection_with_the_name(): void {
        global $USER;
        $course = $this->core_course();
        quick_add_service::queue_weeks($course->id, $USER->id, 1, 0, false);
        $this->run_jobs();
        $session = get_fast_modinfo($course->id)->get_section_info(2);
        $this->assertTrue($session->is_delegated());
        course_update_section($course->id, $session, ['name' => get_string('assessmentssectionname', 'aiplacement_modgen')]);

        $section = theme_builder::add_assessments_section($course->id);

        $this->assertSame(1, (int) $section->section);
    }

    /**
     * The toolbar offers weeks and Assessments at the top of a core course, and no themes or Check Structure.
     */
    public function test_toolbar_entries(): void {
        global $PAGE;
        $course = $this->core_course();
        set_config('enable_quickadd', 1, 'aiplacement_modgen');
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));

        $top = $this->toolbar_html($course->id, 0);
        $this->assertStringContainsString('data-form="add_week"', $top);
        $this->assertStringContainsString('data-form="add_assessments"', $top);
        $this->assertStringNotContainsString('data-form="add_theme"', $top);
        $this->assertStringNotContainsString('check_structure.php', $top);

        // Inside a section there is nothing a core course can take.
        $this->assertStringNotContainsString('data-form="add_week"', $this->toolbar_html($course->id, 1));
    }

    /**
     * The toolbar's HTML as the fragment callback renders it.
     *
     * @param int $courseid Course ID.
     * @param int $currentsection Section the toolbar is shown in.
     * @return string
     */
    private function toolbar_html(int $courseid, int $currentsection): string {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');
        return aiplacement_modgen_output_fragment_course_toolbar([
            'courseid' => $courseid,
            'currentsection' => $currentsection,
        ]);
    }
}
