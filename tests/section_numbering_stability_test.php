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
 * Section numbers in format_flexsections are positional, not identities: the plugin
 * rewrites them across the whole course on every create, move, delete or merge.
 *
 * These tests cover the places modgen has to hold a target across such a write, and the
 * one place it addresses flexsections' move_section() directly.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;

/**
 * Tests for section numbering stability across flexsections structural writes.
 *
 * @covers \aiplacement_modgen\local\theme_builder
 */
final class section_numbering_stability_test extends advanced_testcase {
    /**
     * Parent of each named section, as {name => parent name}: independent of numbering.
     *
     * @param int $courseid
     * @return array<string,string>
     */
    private function shape(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT cs.id, cs.section, cs.name, cfo.value AS parent
              FROM {course_sections} cs
              LEFT JOIN {course_format_options} cfo
                ON cfo.sectionid = cs.id AND cfo.name = 'parent' AND cfo.courseid = cs.course
             WHERE cs.course = ? ORDER BY cs.section", [$courseid]);
        $namebynum = [];
        foreach ($rows as $r) {
            $namebynum[(int) $r->section] = $r->name ?? '(unnamed)';
        }
        $out = [];
        foreach ($rows as $r) {
            if ($r->name === null) {
                continue;
            }
            $p = ($r->parent === null || $r->parent === '' || !is_numeric($r->parent)) ? 0 : (int) $r->parent;
            $out[$r->name] = $namebynum[$p] ?? '(missing)';
        }
        return $out;
    }

    /**
     * Create a flexsections course with the given number of top-level sections.
     *
     * @param int $numsections Number of top-level sections
     * @return \stdClass
     */
    private function make_course(int $numsections = 6): \stdClass {
        return $this->getDataGenerator()->create_course(
            ['format' => 'flexsections', 'numsections' => $numsections],
            ['createsections' => true]
        );
    }

    /**
     * Quick Add "themes, inside this section" must actually nest them there.
     * create_themes() previously declared $parent and never used it.
     */
    public function test_create_themes_honours_the_parent_section(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->make_course();
        $courseformat = course_get_format($course);

        // A container to add the themes inside.
        $containernum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Container',
            ''
        );
        $containerid = theme_builder::section_id_from_number($course->id, $containernum);

        theme_builder::create_themes($course->id, 2, 1, $containernum, false);

        $containernow = theme_builder::current_section_number($course->id, $containerid);
        $themes = $DB->get_records_sql(
            "SELECT cs.id, cs.name, cfo.value AS parent
              FROM {course_sections} cs
              JOIN {course_format_options} cfo
                ON cfo.sectionid = cs.id AND cfo.name = 'parent' AND cfo.courseid = cs.course
             WHERE cs.course = :courseid AND " . $DB->sql_like('cs.name', ':n'),
            ['courseid' => $course->id, 'n' => 'Theme %']
        );

        // Only the themes themselves - not the weeks nested inside them, whose names also
        // begin "Theme " (e.g. "Theme 1 - Week 1") and which correctly point at their theme.
        $toplevelthemes = array_filter($themes, fn($t) => strpos($t->name, ' - ') === false);

        $this->assertNotEmpty($toplevelthemes, 'Themes should have been created.');
        foreach ($toplevelthemes as $theme) {
            $this->assertSame(
                (int) $containernow,
                (int) $theme->parent,
                "Theme '{$theme->name}' should be nested inside the requested section."
            );
        }

        // And the weeks should hang off their theme, not off the container.
        $themenums = [];
        foreach ($toplevelthemes as $t) {
            $themenums[] = (int) $DB->get_field('course_sections', 'section', ['id' => $t->id]);
        }
        foreach (array_diff_key($themes, $toplevelthemes) as $week) {
            $this->assertContains(
                (int) $week->parent,
                $themenums,
                "Week '{$week->name}' should be nested under one of the themes."
            );
        }
    }

    /**
     * A parent that is deleted between the request and the build falls back to top level
     * rather than attaching to whatever now occupies that number.
     */
    public function test_deleted_parent_falls_back_to_top_level(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->make_course();
        $courseformat = course_get_format($course);

        $doomednum = theme_builder::create_theme_section($course->id, $courseformat, 'Doomed', '');
        $doomedid = theme_builder::section_id_from_number($course->id, $doomednum);

        // The section goes away; another section will slide into its number.
        $si = course_get_format($course)->get_modinfo()->get_section_info($doomednum);
        course_get_format($course)->delete_section_with_children($si);

        $this->assertNull(
            theme_builder::current_section_number($course->id, $doomedid),
            'Sanity check: the pinned section really is gone.'
        );

        // Building with the now-dead id must not attach to the number's new occupant.
        $result = theme_builder::create_weeks($course->id, 1, 0, false);
        $this->assertTrue($result['success']);
    }

    /**
     * The whole point: a build must produce the tree that was asked for, whatever the
     * renumbering did along the way.
     */
    public function test_nested_build_produces_the_requested_tree(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->make_course(2);

        theme_builder::create_themes($course->id, 2, 2, 0, false);
        $shape = $this->shape($course->id);

        // Every week must sit under a theme, and every session under a week.
        $weeks = array_filter($shape, fn($p, $n) => strpos($n, 'Week') !== false, ARRAY_FILTER_USE_BOTH);
        $this->assertNotEmpty($weeks, 'Weeks should have been created.');
        foreach ($weeks as $name => $parent) {
            $this->assertStringContainsString(
                'Theme',
                $parent,
                "Week '$name' should be nested under a theme, found '$parent'."
            );
        }
    }

    /**
     * current_section_number()/section_id_from_number() must survive a real renumber.
     */
    public function test_id_survives_a_renumber_where_a_number_would_not(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->make_course(4);
        $courseformat = course_get_format($course);

        $targetnum = theme_builder::create_theme_section($course->id, $courseformat, 'Target', '');
        $targetid = theme_builder::section_id_from_number($course->id, $targetnum);

        // Force a renumber: move the target to the front of the course.
        course_get_format($course)->move_section($targetnum, 0, 1);

        $nownum = theme_builder::current_section_number($course->id, $targetid);
        $this->assertNotSame(
            $targetnum,
            $nownum,
            'This test is only meaningful if the move actually renumbered the section.'
        );

        global $DB;
        $this->assertSame(
            'Target',
            $DB->get_field('course_sections', 'name', ['course' => $course->id, 'section' => $nownum]),
            'The id must still resolve to the same section after the renumber.'
        );
    }

    /**
     * A Quick Add job carries its target as a section ID, not a number.
     *
     * The number is captured in the browser but the job runs later from cron. Anything that
     * renumbers the course in between - a teacher moving or deleting a section, another
     * job - would repoint a stored number at a different section.
     */
    public function test_queued_job_resolves_its_parent_by_id_after_a_renumber(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->make_course(4);
        $courseformat = course_get_format($course);

        $containernum = theme_builder::create_theme_section($course->id, $courseformat, 'Container', '');
        $containerid = theme_builder::section_id_from_number($course->id, $containernum);

        // The job is queued now, capturing both forms of the target.
        $job = (object) [
            'courseid' => $course->id,
            'userid' => $USER->id,
            'action' => 'create_weeks',
            'status' => 'queued',
            'parameters' => json_encode(['weekcount' => 1, 'parentsection' => $containernum]),
            'timecreated' => time(),
        ];
        $jobid = $DB->insert_record('aiplacement_modgen_jobs', $job);

        // Someone reorders the course before cron gets to it: the container's number shifts
        // and a different section slides into the number the job captured.
        $other = theme_builder::create_theme_section($course->id, course_get_format($course), 'Interloper', '');
        course_get_format($course)->move_section($other, 0, 1);

        $containernow = theme_builder::current_section_number($course->id, $containerid);
        $this->assertNotSame(
            $containernum,
            $containernow,
            'This test is only meaningful if the container actually moved.'
        );
        $occupant = $DB->get_field(
            'course_sections',
            'name',
            ['course' => $course->id, 'section' => $containernum]
        );
        $this->assertNotSame(
            'Container',
            $occupant,
            'A different section should now hold the captured number.'
        );

        $task = new \aiplacement_modgen\task\create_sections_task();
        $task->set_custom_data((object) [
            'jobid' => $jobid,
            'courseid' => $course->id,
            'action' => 'create_weeks',
            'weekcount' => 1,
            'parentsection' => $containernum,
            'parentsectionid' => $containerid,
            'createsummaryactivities' => false,
        ]);
        $task->set_userid($USER->id);
        // The task mtrace()s its progress; capture it so the test isn't marked risky.
        ob_start();
        $task->execute();
        ob_end_clean();

        // The week must be inside the Container, not inside whatever took its old number.
        $week = $DB->get_record_sql(
            "SELECT cs.id, cfo.value AS parent
              FROM {course_sections} cs
              JOIN {course_format_options} cfo
                ON cfo.sectionid = cs.id AND cfo.name = 'parent' AND cfo.courseid = cs.course
             WHERE cs.course = :courseid AND " . $DB->sql_like('cs.name', ':n'),
            ['courseid' => $course->id, 'n' => 'Week %']
        );

        $this->assertNotEmpty($week, 'A week should have been created.');
        $this->assertSame(
            (int) theme_builder::current_section_number($course->id, $containerid),
            (int) $week->parent,
            'The week should be nested in the originally targeted section.'
        );
    }
}
