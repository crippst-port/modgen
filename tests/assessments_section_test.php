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
 * Tests for offering and adding the Assessments section.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Tests for offering and adding the Assessments section.
 *
 * @covers \aiplacement_modgen\local\theme_builder
 */
final class assessments_section_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var \stdClass Test course, Flexible sections with only section 0. */
    private $course;

    /**
     * Set up a new course, not yet built in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $this->course = $this->flexsections_course();
    }

    /**
     * Add a section straight away.
     *
     * @param string $name Section name
     * @param int $parent Parent section number, 0 for the top level
     * @return \stdClass The section record
     */
    private function add_section(string $name, int $parent = 0): \stdClass {
        return theme_builder::create_section_with_parent(
            $this->course->id,
            course_get_format($this->course->id),
            $parent,
            $name,
            '',
            FORMAT_PLAIN
        );
    }

    /**
     * Sections in the course with exactly this name.
     *
     * @param string $name Section name
     * @return int
     */
    private function count_named(string $name): int {
        global $DB;
        return $DB->count_records('course_sections', ['course' => $this->course->id, 'name' => $name]);
    }

    /**
     * A top-level section with the word anywhere in its name, in any case, is found.
     */
    public function test_a_top_level_match_in_any_case_is_found(): void {
        $this->add_section('Week 1');
        $match = $this->add_section('ASSESSMENT Information');

        $found = theme_builder::find_assessments_section($this->course->id);

        $this->assertNotNull($found);
        $this->assertSame((int) $match->id, (int) $found->id);
        $this->assertSame('ASSESSMENT Information', $found->name);
    }

    /**
     * A nested section is not an assessments section, whatever it is called.
     */
    public function test_a_nested_match_is_ignored(): void {
        $theme = $this->add_section('Theme 1');
        $this->add_section('Week 3: Peer assessment', (int) $theme->section);

        $this->assertNull(theme_builder::find_assessments_section($this->course->id));
    }

    /**
     * Section 0 is never the assessments section.
     */
    public function test_section_0_is_ignored(): void {
        global $DB;
        $DB->set_field('course_sections', 'name', 'Assessment', ['course' => $this->course->id, 'section' => 0]);

        $this->assertNull(theme_builder::find_assessments_section($this->course->id));
    }

    /**
     * A course with no such section has none.
     */
    public function test_no_match_is_null(): void {
        $this->add_section('Week 1');

        $this->assertNull(theme_builder::find_assessments_section($this->course->id));
    }
}
