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

use aiplacement_modgen\local\integrity_checker;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');
/**
 * Two rules that protect data: course-level format options are not "orphans", and generated themes
 * stay empty containers.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\integrity_checker
 */
final class integrity_course_options_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * A course made in the Flexible sections format has course-level options (section id 0), which
     * are not orphans: the check does not report them and the repair does not delete them.
     */
    public function test_course_level_format_options_are_not_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->flexsections_course();
        $courselevel = ['courseid' => $course->id, 'sectionid' => 0, 'format' => 'flexsections'];
        $before = $DB->count_records('course_format_options', $courselevel);
        $this->assertGreaterThan(0, $before, 'A Flexible sections course stores course-level options.');

        $check = integrity_checker::check($course->id);
        $this->assertFalse($check['has_issues']);
        $this->assertSame([], $check['issues']['orphaned_options']);

        integrity_checker::fix_integrity($course->id);

        $this->assertSame($before, $DB->count_records('course_format_options', $courselevel));
    }

    /**
     * Options left behind by a section that no longer exists are still found and removed.
     */
    public function test_options_of_a_deleted_section_are_still_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->flexsections_course();
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $course->id, 'format' => 'flexsections', 'sectionid' => 987654, 'name' => 'parent', 'value' => '0',
        ]);

        $check = integrity_checker::check($course->id);
        $this->assertTrue($check['has_issues']);
        $this->assertCount(1, $check['issues']['orphaned_options']);

        integrity_checker::fix_integrity($course->id);

        $this->assertFalse($DB->record_exists('course_format_options', ['sectionid' => 987654]));
        $this->assertFalse(integrity_checker::check($course->id)['has_issues']);
    }

    /**
     * Generated themes lose any summary; weeks and everything else are untouched.
     */
    public function test_generated_theme_summaries_are_blanked(): void {
        $data = \aiplacement_modgen\local\ai_service::blank_theme_summaries(['themes' => [
            ['title' => 'A', 'summary' => 'Wordy', 'weeks' => [['title' => 'W', 'summary' => 'Keep me']]],
            'not a theme',
            ['title' => 'B'],
        ]]);

        $this->assertSame('', $data['themes'][0]['summary']);
        $this->assertSame('Keep me', $data['themes'][0]['weeks'][0]['summary']);
        $this->assertSame('A', $data['themes'][0]['title']);
        $this->assertSame('not a theme', $data['themes'][1]);
        $this->assertSame('', $data['themes'][2]['summary']);
        $this->assertSame(['title' => 'x'], \aiplacement_modgen\local\ai_service::blank_theme_summaries(['title' => 'x']));
    }
}
