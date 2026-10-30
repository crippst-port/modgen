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
 * Tests for the per-feature admin toggles: New Theme(s) and New Week(s), Assessments section and Structure from file.
 *
 * Each toggle has to close the feature rather than merely hide its menu entry, since the
 * forms behind them are reachable directly as fragments. These tests pin both ends: the
 * entry point refuses while the feature is off, and still works once it is back on.
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
 * Tests for the New Theme(s) and New Week(s), Assessments section and Structure from file feature toggles.
 */
final class feature_toggle_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /**
     * Set up a course with the placement switched on, so these tests isolate the feature
     * toggles rather than the placement master switch.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('enabled', 1, 'aiplacement_modgen');

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($this->course->id);
    }

    /**
     * Set a feature toggle and drop the settings cache, which memoises per request.
     *
     * @param string $name Setting name, e.g. 'enable_quickadd'.
     * @param int $value 1 to enable, 0 to disable.
     */
    private function set_toggle(string $name, int $value): void {
        set_config($name, $value, 'aiplacement_modgen');
    }

    /**
     * The toggles must read straight through from config, in both directions.
     *
     * @covers \aiplacement_modgen\local\settings_helper::is_quickadd_enabled
     * @covers \aiplacement_modgen\local\settings_helper::is_structurefromfile_enabled
     */
    public function test_toggles_reflect_config(): void {
        $this->set_toggle('enable_quickadd', 1);
        $this->assertTrue(settings_helper::is_quickadd_enabled());

        $this->set_toggle('enable_quickadd', 0);
        $this->assertFalse(settings_helper::is_quickadd_enabled());

        $this->set_toggle('enable_structurefromfile', 1);
        $this->assertTrue(settings_helper::is_structurefromfile_enabled());

        $this->set_toggle('enable_structurefromfile', 0);
        $this->assertFalse(settings_helper::is_structurefromfile_enabled());
    }

    /**
     * The New theme form is a directly reachable fragment, so switching Quick add off has
     * to refuse it rather than just dropping the menu entry that points at it.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_add_theme
     */
    public function test_add_theme_fragment_refuses_while_quickadd_disabled(): void {
        $this->set_toggle('enable_quickadd', 0);

        $this->expectException(moodle_exception::class);
        \aiplacement_modgen_output_fragment_form_add_theme([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * Same for the New week form, which is the other half of Quick add.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_add_week
     */
    public function test_add_week_fragment_refuses_while_quickadd_disabled(): void {
        $this->set_toggle('enable_quickadd', 0);

        $this->expectException(moodle_exception::class);
        \aiplacement_modgen_output_fragment_form_add_week([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * With Quick add back on, the same fragment renders - proving the guard is what gated
     * it above rather than some unrelated failure.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_add_theme
     */
    public function test_add_theme_fragment_renders_while_quickadd_enabled(): void {
        $this->set_toggle('enable_quickadd', 1);

        $html = \aiplacement_modgen_output_fragment_form_add_theme([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);

        $this->assertIsString($html);
    }

    /**
     * The Assessments section has its own switch: off, its form and its request are refused, while New Theme(s)
     * and New Week(s) are unaffected.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_add_assessments
     * @covers \aiplacement_modgen\local\permissions::require_build_action
     */
    public function test_assessments_switch_refuses_only_the_assessments_entry(): void {
        $context = \context_course::instance($this->course->id);
        $this->set_toggle('enable_quickadd', 1);
        $this->set_toggle('enable_assessments', 0);

        \aiplacement_modgen\local\permissions::require_build_action('create_weeks', $context);
        try {
            \aiplacement_modgen\local\permissions::require_build_action('create_assessments', $context);
            $this->fail('The Assessments request must be refused while its switch is off.');
        } catch (moodle_exception $e) {
            $this->assertSame('assessmentsdisabled', $e->errorcode);
        }
        try {
            \aiplacement_modgen_output_fragment_form_add_assessments(['courseid' => $this->course->id]);
            $this->fail('The Assessments form must be refused while its switch is off.');
        } catch (moodle_exception $e) {
            $this->assertSame('assessmentsdisabled', $e->errorcode);
        }
    }

    /**
     * The other way round: with New Theme(s) and New Week(s) off, the Assessments section is still offered.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_add_assessments
     * @covers \aiplacement_modgen\local\permissions::require_build_action
     */
    public function test_assessments_entry_works_while_themes_and_weeks_are_off(): void {
        $context = \context_course::instance($this->course->id);
        $this->set_toggle('enable_quickadd', 0);
        $this->set_toggle('enable_assessments', 1);

        \aiplacement_modgen\local\permissions::require_build_action('create_assessments', $context);
        $this->assertIsString(\aiplacement_modgen_output_fragment_form_add_assessments(['courseid' => $this->course->id]));
        try {
            \aiplacement_modgen\local\permissions::require_build_action('create_themes', $context);
            $this->fail('New Theme(s) must be refused while its switch is off.');
        } catch (moodle_exception $e) {
            $this->assertSame('quickadddisabled', $e->errorcode);
        }
    }

    /**
     * The Structure from prompt form is a directly reachable fragment too, so switching AI
     * off has to refuse it.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_template_from_prompt
     */
    public function test_prompt_fragment_refuses_while_ai_disabled(): void {
        $this->set_toggle('enable_ai', 0);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('aidisabled', 'aiplacement_modgen'));
        \aiplacement_modgen_output_fragment_form_template_from_prompt([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * With AI on, the same fragment renders.
     *
     * @covers ::aiplacement_modgen_output_fragment_form_template_from_prompt
     */
    public function test_prompt_fragment_renders_while_ai_enabled(): void {
        $this->set_toggle('enable_ai', 1);

        $html = \aiplacement_modgen_output_fragment_form_template_from_prompt([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);

        $this->assertIsString($html);
    }

    /**
     * Switching Structure from file off must remove the CSV upload field from the generator
     * form, not merely hide the menu entry that leads to it.
     *
     * @covers \aiplacement_modgen_generator_form
     */
    public function test_generator_form_drops_upload_field_while_structurefromfile_disabled(): void {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/generator_form.php');

        $this->set_toggle('enable_structurefromfile', 1);
        $this->assertTrue(
            $this->form_has_element('supportingfiles'),
            'The CSV upload field should be present while Structure from file is enabled'
        );

        $this->set_toggle('enable_structurefromfile', 0);
        $this->assertFalse(
            $this->form_has_element('supportingfiles'),
            'The CSV upload field must be gone while Structure from file is disabled'
        );
    }

    /**
     * Build the generator form and report whether it defines a given element.
     *
     * moodleform::$_form is protected, so reach it by reflection rather than rendering and
     * pattern-matching HTML, which would pass on any incidental mention of the name.
     *
     * @param string $element Element name to look for.
     * @return bool True if the built form defines that element.
     */
    private function form_has_element(string $element): bool {
        $form = new \aiplacement_modgen_generator_form(null, ['courseid' => $this->course->id]);
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($form)->elementExists($element);
    }
}
