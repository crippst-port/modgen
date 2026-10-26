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
 * Deleting hidden empty sections must leave a sound course.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\integrity_checker::cleanup_orphaned
 */
final class integrity_cleanup_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var \stdClass Course with two themes of one week each. */
    private $course;

    /**
     * A built course to clean up.
     */
    protected function setUp(): void {
        global $USER;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
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
     * A hidden empty section with no subsections is removed and the sections after it are renumbered.
     */
    public function test_a_hidden_empty_leaf_is_deleted_without_leaving_a_gap(): void {
        $this->hide('Pre-session');

        $result = integrity_checker::cleanup_orphaned($this->course->id);

        $this->assertSame(['deleted' => 1], $result);
        $names = array_column(\aiplacement_modgen_structure_snapshot::rows($this->course->id), 'name');
        $this->assertCount(10, $names, 'One of the eleven sections is gone.');
        $this->assert_sound($this->course->id);
    }

    /**
     * A hidden section with a summary is content a teacher wrote, such as a placeholder for next term:
     * it is neither reported as orphaned nor deleted.
     */
    public function test_a_hidden_section_with_a_summary_is_kept(): void {
        global $DB;
        $id = $this->hide('Pre-session');
        $DB->set_field('course_sections', 'summary', '<p>Reading list for next term</p>', ['id' => $id]);

        $this->assertSame(0, integrity_checker::check($this->course->id)['counts']['orphaned_sections']);
        $this->assertSame(['deleted' => 0], integrity_checker::cleanup_orphaned($this->course->id));
        $this->assertTrue($DB->record_exists('course_sections', ['id' => $id]));
    }

    /**
     * A hidden empty section that still has subsections is left alone: deleting it would orphan them.
     */
    public function test_a_hidden_empty_section_with_children_is_kept(): void {
        $before = \aiplacement_modgen_structure_snapshot::rows($this->course->id);
        $this->hide('Theme 1 - Week 1');

        $result = integrity_checker::cleanup_orphaned($this->course->id);

        $this->assertSame(['deleted' => 0], $result);
        $this->assertCount(count($before), \aiplacement_modgen_structure_snapshot::rows($this->course->id));
        // The plugin still reports the hidden empty section (that is what the cleanup is for), but the
        // structure itself is intact.
        $this->assertSame([], \aiplacement_modgen_structure_snapshot::problems($this->course->id));
    }

    /**
     * With nothing to clean, nothing is changed.
     */
    public function test_nothing_to_clean(): void {
        $before = \aiplacement_modgen_structure_snapshot::rows($this->course->id);

        $this->assertSame(['deleted' => 0], integrity_checker::cleanup_orphaned($this->course->id));
        $this->assertSame($before, \aiplacement_modgen_structure_snapshot::rows($this->course->id));
    }
}
