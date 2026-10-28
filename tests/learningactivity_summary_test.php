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
 * Tests for the optional learningactivity "section summary" modules.
 *
 * mod_learningactivity is not a dependency: the summary modules are added only when it is
 * installed, and the option is not offered otherwise. When they are created, their metadata
 * (which comes from the AI or back from the browser) is validated before it is stored.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\activitytype\learningactivity;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/generator_form.php');

/**
 * Tests for the optional summary modules.
 *
 * @covers \aiplacement_modgen\activitytype\learningactivity
 * @covers \aiplacement_modgen\local\session_creator::create_learningactivity_metadata
 */
final class learningactivity_summary_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /**
     * A flexsections course with the placement and Quick Add on.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!\core_component::get_component_directory('mod_learningactivity')) {
            $this->markTestSkipped('mod_learningactivity is not installed.');
        }
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enabled', 1, 'aiplacement_modgen');
        set_config('enable_quickadd', 1, 'aiplacement_modgen');
        local\settings_helper::clear_cache();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($this->course->id);
    }

    /**
     * The learningactivity instances in the test course.
     *
     * @return \stdClass[]
     */
    private function summaries(): array {
        global $DB;
        return array_values($DB->get_records('learningactivity', ['course' => $this->course->id], 'id'));
    }

    /**
     * Switch mod_learningactivity off, as on a site that does not have it enabled.
     */
    private function disable_module(): void {
        global $DB;
        $DB->set_field('modules', 'visible', 0, ['name' => 'learningactivity']);
    }

    public function test_metadata_from_a_build_is_validated_before_it_is_stored(): void {
        $json = ['sections' => [[
            'title' => 'Week 1',
            'sessions' => [
                'session' => [
                    'learningactivity_metadata' => [
                        'name' => 'Seminar',
                        // Not an icon the module offers, and longer than its 50-character column.
                        'activityicon' => str_repeat('fa-not-a-real-icon-', 4),
                        'learningmode' => 'Asynchronous',
                        'groupactivity' => 'yes',
                    ],
                ],
            ],
        ]]];

        (new section_creation_service())->create_sections_from_json($json, $this->course->id, 'weekly', true);

        $seminar = array_values(array_filter($this->summaries(), fn($la) => $la->name === 'Seminar'));
        $this->assertCount(1, $seminar, 'The build completes and stores the session summary.');
        $this->assertSame('', (string) $seminar[0]->activityicon, 'An icon the module does not offer is dropped.');
        $this->assertSame('1', (string) $seminar[0]->learningmode, 'The learning mode is stored as the module stores it.');
        $this->assertSame('1', (string) $seminar[0]->groupactivity);
    }

    public function test_no_summaries_are_created_without_the_module(): void {
        $this->disable_module();
        $this->assertFalse(learningactivity::is_available());

        theme_builder::create_weeks($this->course->id, 1, 0, true);

        $this->assertSame([], $this->summaries());
    }

    public function test_quick_add_forms_offer_the_option_only_with_the_module(): void {
        $args = ['courseid' => $this->course->id, 'contextid' => \context_course::instance($this->course->id)->id];
        $this->assertStringContainsString('createsummaryactivities', \aiplacement_modgen_output_fragment_form_add_week($args));

        $this->disable_module();

        $this->assertStringNotContainsString('createsummaryactivities', \aiplacement_modgen_output_fragment_form_add_week($args));
        $this->assertStringNotContainsString('createsummaryactivities', \aiplacement_modgen_output_fragment_form_add_theme($args));
        $form = new \aiplacement_modgen_generator_form(null, ['courseid' => $this->course->id]);
        $this->assertStringNotContainsString('createsummaryactivities', $form->render());
    }
}
