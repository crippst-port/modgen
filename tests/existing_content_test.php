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

use aiplacement_modgen\local\existing_content;
use aiplacement_modgen\local\quick_add_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * What counts as content already in a module, for the warning on the file and prompt forms.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\existing_content
 */
final class existing_content_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /**
     * An administrator, with the placement switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
    }

    /**
     * Whether the warning is added to a form for a course.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    private function warns(int $courseid): bool {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $mform = new \MoodleQuickForm('test', 'post', '');
        existing_content::add_warning($mform, $courseid);
        return count($mform->_elements) > 0;
    }

    /**
     * A new module with many empty sections, named or not, and its Announcements forum is empty.
     */
    public function test_a_new_module_with_many_empty_sections_is_empty(): void {
        global $DB;
        foreach (['topics', 'flexsections'] as $format) {
            $course = $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => 12]);
            $DB->set_field('course_sections', 'name', 'Week 3', ['course' => $course->id, 'section' => 3]);
            $DB->set_field('course_sections', 'summary', 'To be written', ['course' => $course->id, 'section' => 4]);
            $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'news']);

            $this->assertFalse(existing_content::has_activities($course->id), $format);
            $this->assertFalse(existing_content::has_nested_sections($course->id), $format);
            $this->assertFalse($this->warns($course->id), $format);
        }
    }

    /**
     * Any other activity or resource is content.
     */
    public function test_an_activity_is_content(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 4]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 2]);

        $this->assertTrue(existing_content::has_activities($course->id));
        $this->assertFalse(existing_content::has_nested_sections($course->id));
        $this->assertTrue($this->warns($course->id));
    }

    /**
     * A structure already built is content, even with no activities in it.
     */
    public function test_a_built_structure_is_content(): void {
        global $USER;
        $course = $this->flexsections_course();
        quick_add_service::queue_themes($course->id, $USER->id, 1, 1, 0, false);
        $this->run_jobs();

        $this->assertFalse(existing_content::has_activities($course->id));
        $this->assertTrue(existing_content::has_nested_sections($course->id));
        $this->assertTrue($this->warns($course->id));
    }

    /**
     * Parent options left over from when the course used Flexible sections do not count.
     */
    public function test_leftover_parent_options_from_another_format_do_not_count(): void {
        global $USER;
        $course = $this->flexsections_course();
        quick_add_service::queue_themes($course->id, $USER->id, 1, 1, 0, false);
        $this->run_jobs();
        update_course((object) ['id' => $course->id, 'format' => 'topics']);

        // Switching format keeps the sections but they are no longer nested.
        $this->assertFalse(existing_content::has_nested_sections($course->id));
    }
}
