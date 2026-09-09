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
 * Tests that the AI placements enable/disable switch actually turns this plugin on and off.
 *
 * Core does not enforce placement enablement for us - get_plugins_with_function() filters
 * only for deprecated hook migration - so every entry point consults the switch itself.
 * These tests pin that down at both ends: the UI injects nothing while it is off, and the
 * directly-reachable entry points refuse rather than merely hiding their links.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\settings_helper;
use aiplacement_modgen\local\theme_builder;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');

/**
 * Tests for the AI placement master switch.
 */
final class placement_switch_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /**
     * Set up a course with an editing teacher who holds every modgen capability.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);
    }

    /**
     * Switch the placement on or off the way core's enable_plugin() does.
     *
     * @param bool $on True to enable, false to restore the absent-key (disabled) state.
     */
    private function set_placement(bool $on): void {
        if ($on) {
            set_config('enabled', 1, 'aiplacement_modgen');
        } else {
            unset_config('enabled', 'aiplacement_modgen');
        }
    }

    /**
     * An absent config key must read as disabled - that is core's own convention, since
     * enable_plugin() unsets the key rather than writing 0. Getting this backwards would
     * make a never-configured site read as enabled.
     *
     * @covers \aiplacement_modgen\local\settings_helper::is_placement_enabled
     */
    public function test_absent_config_reads_as_disabled(): void {
        $this->set_placement(false);
        $this->assertFalse(
            settings_helper::is_placement_enabled(),
            'A placement with no stored enabled key must read as disabled'
        );

        $this->set_placement(true);
        $this->assertTrue(
            settings_helper::is_placement_enabled(),
            'A placement with enabled=1 must read as enabled'
        );
    }

    /**
     * The course toolbar fragment is reachable directly over AJAX, so switching the
     * placement off has to close it rather than just removing the link that points at it.
     *
     * @covers ::aiplacement_modgen_output_fragment_course_toolbar
     */
    public function test_fragment_refuses_while_disabled(): void {
        $this->set_placement(false);

        $this->expectException(moodle_exception::class);
        \aiplacement_modgen_output_fragment_course_toolbar([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * The same fragment must still render once the placement is switched back on, so the
     * guard is proved to be the thing gating it rather than some unrelated failure.
     *
     * @covers ::aiplacement_modgen_output_fragment_course_toolbar
     */
    public function test_fragment_renders_while_enabled(): void {
        $this->set_placement(true);

        $html = \aiplacement_modgen_output_fragment_course_toolbar([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);

        $this->assertIsString($html);
    }

    /**
     * With the placement off, the course navigation callback must add nothing at all -
     * this is what makes the toolbar, FAB and nav entries disappear.
     *
     * @covers ::aiplacement_modgen_extend_navigation_course
     */
    public function test_navigation_injects_nothing_while_disabled(): void {
        $this->set_placement(false);

        $node = \navigation_node::create('Test root');
        $before = count($node->get_children_key_list());

        \aiplacement_modgen_extend_navigation_course(
            $node,
            $this->course,
            \context_course::instance($this->course->id)
        );

        $this->assertSame(
            $before,
            count($node->get_children_key_list()),
            'A disabled placement must not add anything to the course navigation'
        );
    }
}
