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

    /**
     * Asked for, with none in the course: added straight after section 0, even when section 0 has a name.
     */
    public function test_a_build_adds_assessments_after_section_0_when_asked(): void {
        global $DB;
        $DB->set_field('course_sections', 'name', 'Welcome', ['course' => $this->course->id, 'section' => 0]);

        theme_builder::create_weeks($this->course->id, 1, 0, false);

        $assessments = get_string('assessmentssectionname', 'aiplacement_modgen');
        $this->assertSame('1', (string) $DB->get_field('course_sections', 'section', [
            'course' => $this->course->id, 'name' => $assessments,
        ]));
        $this->assertSame('Welcome', $DB->get_field('course_sections', 'name', [
            'course' => $this->course->id, 'section' => 0,
        ]));
    }

    /**
     * Not asked for: none added, but an unnamed section 0 is still named.
     */
    public function test_a_build_leaves_assessments_out_when_not_asked(): void {
        global $DB;

        theme_builder::create_weeks($this->course->id, 1, 0, false, null, false);

        $this->assertSame(0, $this->count_named(get_string('assessmentssectionname', 'aiplacement_modgen')));
        $this->assertSame(
            get_string('introductionsectionname', 'aiplacement_modgen'),
            $DB->get_field('course_sections', 'name', ['course' => $this->course->id, 'section' => 0])
        );
    }

    /**
     * Asked for, but the course already has its own: no second one.
     */
    public function test_an_existing_assessments_section_is_not_duplicated(): void {
        $this->add_section('Assessment Information');

        theme_builder::create_weeks($this->course->id, 1, 0, false);

        $this->assertSame(0, $this->count_named(get_string('assessmentssectionname', 'aiplacement_modgen')));
        $this->assertSame(1, $this->count_named('Assessment Information'));
    }

    /**
     * One added by another build while this one waited for its lock is found, not duplicated.
     */
    public function test_one_added_while_waiting_for_the_lock_is_not_duplicated(): void {
        global $CFG;
        require_once(__DIR__ . '/fixtures/interleaving_lock_factory.php');
        $CFG->lock_factory = interleaving_lock_factory::class;
        interleaving_lock_factory::$beforelock = function (): void {
            $this->add_section(get_string('assessmentssectionname', 'aiplacement_modgen'));
        };

        try {
            theme_builder::create_weeks($this->course->id, 1, 0, false);
        } finally {
            interleaving_lock_factory::$beforelock = null;
        }

        $this->assertSame(1, $this->count_named(get_string('assessmentssectionname', 'aiplacement_modgen')));
    }

    /**
     * The section limit counts the Assessments section only when the build will add it.
     */
    public function test_the_limit_counts_assessments_only_when_it_will_be_added(): void {
        global $DB;
        $existing = $DB->count_records('course_sections', ['course' => $this->course->id]);
        set_config('maxtotalsections', $existing + theme_builder::projected_sections_for_weeks(1), 'aiplacement_modgen');

        // Not asked for: one week fits exactly.
        theme_builder::enforce_section_limit($this->course->id, theme_builder::projected_sections_for_weeks(1), false);

        // Asked for: the Assessments section takes it one over.
        $this->expectException(\moodle_exception::class);
        theme_builder::enforce_section_limit($this->course->id, theme_builder::projected_sections_for_weeks(1), true);
    }

    /**
     * The JSON route takes the choice too.
     */
    public function test_a_structure_build_leaves_assessments_out_when_not_asked(): void {
        (new \aiplacement_modgen\local\section_creation_service())->create_sections_from_json(
            ['sections' => [['title' => 'Week one']]],
            $this->course->id,
            'connected_weekly',
            false,
            false
        );

        $this->assertSame(0, $this->count_named(get_string('assessmentssectionname', 'aiplacement_modgen')));
        $this->assertSame(1, $this->count_named('Week one'));
    }
}
