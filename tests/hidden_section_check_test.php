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
use aiplacement_modgen\local\quick_add_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Hidden empty sections are not reported as broken structure.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\integrity_checker::check
 */
final class hidden_section_check_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var \stdClass Course with two themes of one week each. */
    private $course;

    /**
     * A built course to check.
     */
    protected function setUp(): void {
        global $USER;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $this->course = $this->flexsections_course();
        quick_add_service::queue_themes($this->course->id, $USER->id, 2, 1, 0, false);
        $this->run_jobs();
    }

    /**
     * Hide a section by name.
     *
     * @param string $name Section name (the first one with that name).
     * @return int Section id.
     */
    private function hide(string $name): int {
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'name' => $name], '*', IGNORE_MULTIPLE);
        $DB->set_field('course_sections', 'visible', 0, ['id' => $section->id]);
        rebuild_course_cache($this->course->id, true);
        return (int) $section->id;
    }

    /**
     * A hidden empty section is a teacher's placeholder, not damage: it is not reported.
     */
    public function test_a_hidden_empty_section_is_not_an_issue(): void {
        $id = $this->hide('Pre-session');
        $diag = integrity_checker::check($this->course->id);
        $this->assertFalse($diag['has_issues']);
        $this->assertArrayNotHasKey('orphaned_sections', $diag['counts']);
        $row = array_values(array_filter($diag['sections'], fn($s) => (int) $s->id === $id))[0];
        $this->assertSame([], $row->row_issues);
    }

    /**
     * Nor is one moved under a hidden parent, which set_parent() hides to match.
     */
    public function test_an_empty_section_reparented_under_a_hidden_parent_is_not_an_issue(): void {
        global $DB;
        $this->hide('Theme 2');
        $theme2 = (int) $DB->get_field('course_sections', 'section', ['course' => $this->course->id, 'name' => 'Theme 2']);
        $new = \course_create_section($this->course->id, 0);
        $this->assertTrue(integrity_checker::set_parent($this->course->id, (int) $new->section, $theme2)['success']);
        $this->assertEquals(0, $DB->get_field('course_sections', 'visible', ['id' => $new->id]));
        $this->assertFalse(integrity_checker::check($this->course->id)['has_issues']);
    }
}
