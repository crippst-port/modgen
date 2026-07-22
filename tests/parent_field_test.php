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
 * Unit tests for section parent field handling.
 *
 * Tests verify that parent relationships are correctly stored as section NUMBERS
 * in the course_format_options table for flexsections format.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\local\session_creator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Test class for parent field handling in section creation.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class parent_field_test extends advanced_testcase {
    /**
     * Test that theme sections are created with parent=0 (top level).
     */
    public function test_theme_section_has_zero_parent(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $courseformat = \course_get_format($course->id);

        // Convert to flexsections if needed.
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create a theme section.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        // Verify theme section exists.
        $this->assertGreaterThan(0, $themesectionnum, 'Theme section number should be positive');

        // Get section record.
        $section = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $themesectionnum,
        ], '*', MUST_EXIST);

        // Check parent field in course_format_options.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $section->id,
            'name' => 'parent',
        ]);

        if ($parentoption) {
            // Parent should be 0 (top level) for themes.
            $this->assertEquals(
                '0',
                $parentoption->value,
                'Theme section parent should be 0 (top level)'
            );
        } else {
            // If no parent record exists, that's also acceptable (defaults to 0).
            $this->assertTrue(true, 'No parent record means default top level');
        }
    }

    /**
     * Test that week sections are created with correct parent section NUMBER.
     */
    public function test_week_section_has_correct_parent_number(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create a theme section.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        // Create a week section under the theme.
        $weeksectionnum = theme_builder::create_week_section(
            $course->id,
            $courseformat,
            $themesectionnum,
            'Test Week',
            'Week description'
        );

        // Verify week section exists.
        $this->assertGreaterThan(0, $weeksectionnum, 'Week section number should be positive');

        // Get week section record.
        $weeksection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $weeksectionnum,
        ], '*', MUST_EXIST);

        // Check parent field in course_format_options.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $weeksection->id,
            'name' => 'parent',
        ], '*', MUST_EXIST);

        // Parent should be the SECTION NUMBER of the theme (not section ID).
        $this->assertEquals(
            (string)$themesectionnum,
            $parentoption->value,
            'Week section parent should be theme section NUMBER, not ID'
        );

        // Additional check: parent value should NOT be the theme section ID.
        $themesection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $themesectionnum,
        ], '*', MUST_EXIST);

        $this->assertNotEquals(
            (string)$themesection->id,
            $parentoption->value,
            'Parent should be section NUMBER, not section ID'
        );
    }

    /**
     * Test session sections have correct parent section NUMBER.
     */
    public function test_session_section_has_correct_parent_number(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create a theme section.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        // Create a week section under the theme.
        $weeksectionnum = theme_builder::create_week_section(
            $course->id,
            $courseformat,
            $themesectionnum,
            'Test Week',
            'Week description'
        );

        // Create session subsections under the week.
        $sessiondata = [
            'session' => [
                'description' => 'Session 1 description',
                'learningactivity_metadata' => [
                    'name' => 'Session 1',
                    'instructions' => 'Session instructions',
                    'learning_type' => 'lecture',
                    'duration' => 60,
                ],
            ],
        ];

        $sessionmap = session_creator::create_session_subsections(
            $courseformat,
            $weeksectionnum,
            $course->id,
            $sessiondata
        );

        $this->assertNotEmpty($sessionmap, 'Session map should not be empty');
        $this->assertArrayHasKey('session', $sessionmap, 'Session should be in map');

        $sessionsectionnum = $sessionmap['session'];

        // Get session section record.
        $sessionsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $sessionsectionnum,
        ], '*', MUST_EXIST);

        // Check parent field in course_format_options.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $sessionsection->id,
            'name' => 'parent',
        ], '*', MUST_EXIST);

        // Parent should be the SECTION NUMBER of the week (not section ID).
        $this->assertEquals(
            (string)$weeksectionnum,
            $parentoption->value,
            'Session section parent should be week section NUMBER, not ID'
        );
    }

    /**
     * Test complete hierarchy: Theme -> Week -> Session.
     */
    public function test_complete_hierarchy_parent_chain(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create a theme section.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        // Create a week section under the theme.
        $weeksectionnum = theme_builder::create_week_section(
            $course->id,
            $courseformat,
            $themesectionnum,
            'Test Week',
            'Week description'
        );

        // Create session subsections under the week.
        $sessiondata = [
            'session' => [
                'learningactivity_metadata' => [
                    'name' => 'Session 1',
                    'learning_type' => 'workshop',
                ],
            ],
        ];

        $sessionmap = session_creator::create_session_subsections(
            $courseformat,
            $weeksectionnum,
            $course->id,
            $sessiondata
        );

        $sessionsectionnum = $sessionmap['session'];

        // Verify complete parent chain.
        // Theme -> parent should be 0 or not exist.
        $themesection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $themesectionnum,
        ], '*', MUST_EXIST);

        $themeparent = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $themesection->id,
            'name' => 'parent',
        ]);

        if ($themeparent) {
            $this->assertEquals('0', $themeparent->value, 'Theme parent should be 0');
        }

        // Week -> parent should be theme section NUMBER.
        $weeksection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $weeksectionnum,
        ], '*', MUST_EXIST);

        $weekparent = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $weeksection->id,
            'name' => 'parent',
        ], '*', MUST_EXIST);

        $this->assertEquals(
            (string)$themesectionnum,
            $weekparent->value,
            'Week parent should be theme section NUMBER'
        );

        // Session -> parent should be week section NUMBER.
        $sessionsection = $DB->get_record('course_sections', [
            'course' => $course->id,
            'section' => $sessionsectionnum,
        ], '*', MUST_EXIST);

        $sessionparent = $DB->get_record('course_format_options', [
            'courseid' => $course->id,
            'sectionid' => $sessionsection->id,
            'name' => 'parent',
        ], '*', MUST_EXIST);

        $this->assertEquals(
            (string)$weeksectionnum,
            $sessionparent->value,
            'Session parent should be week section NUMBER'
        );
    }

    /**
     * Test that section_info object exposes parent as section number.
     */
    public function test_section_info_parent_property(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create theme and week.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        $weeksectionnum = theme_builder::create_week_section(
            $course->id,
            $courseformat,
            $themesectionnum,
            'Test Week',
            'Week description'
        );

        // Get modinfo and section_info.
        $modinfo = get_fast_modinfo($course->id);
        $weeksectioninfo = $modinfo->get_section_info($weeksectionnum);

        // Verify parent property.
        $this->assertTrue(
            isset($weeksectioninfo->parent),
            'section_info should have parent property'
        );

        $this->assertEquals(
            $themesectionnum,
            $weeksectioninfo->parent,
            'section_info->parent should contain parent section NUMBER'
        );
    }

    /**
     * Test parent field validation helper method.
     */
    public function test_validate_section_parent_helper(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create a theme section.
        $themesectionnum = theme_builder::create_theme_section(
            $course->id,
            $courseformat,
            'Test Theme',
            'Theme description'
        );

        // Create a week section.
        $weeksectionnum = theme_builder::create_week_section(
            $course->id,
            $courseformat,
            $themesectionnum,
            'Test Week',
            'Week description'
        );

        // Validate week has correct parent.
        $valid = theme_builder::validate_section_parent(
            $course->id,
            $weeksectionnum,
            $themesectionnum
        );

        $this->assertTrue($valid, 'Week section should have correct parent');

        // Test invalid parent detection.
        $invalidparent = $weeksectionnum + 999; // Non-existent section.
        $valid = theme_builder::validate_section_parent(
            $course->id,
            $weeksectionnum,
            $invalidparent
        );

        $this->assertFalse($valid, 'Invalid parent should be detected');
    }

    /**
     * Test multiple themes and weeks maintain correct parent relationships.
     */
    public function test_multiple_themes_and_weeks(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create course and convert to flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $courseformat = \course_get_format($course->id);

        // Create 2 themes with 2 weeks each.
        $structure = [];

        for ($t = 1; $t <= 2; $t++) {
            $themesectionnum = theme_builder::create_theme_section(
                $course->id,
                $courseformat,
                "Theme $t",
                "Theme $t description"
            );

            $structure["theme_$t"] = [
                'sectionnum' => $themesectionnum,
                'weeks' => [],
            ];

            for ($w = 1; $w <= 2; $w++) {
                $weeksectionnum = theme_builder::create_week_section(
                    $course->id,
                    $courseformat,
                    $themesectionnum,
                    "Week $w",
                    "Week $w description"
                );

                $structure["theme_$t"]['weeks']["week_$w"] = $weeksectionnum;
            }
        }

        // Verify all parent relationships.
        foreach ($structure as $themekey => $themedata) {
            $themesectionnum = $themedata['sectionnum'];

            // Verify theme parent is 0.
            $themesection = $DB->get_record('course_sections', [
                'course' => $course->id,
                'section' => $themesectionnum,
            ], '*', MUST_EXIST);

            $themeparent = $DB->get_record('course_format_options', [
                'courseid' => $course->id,
                'sectionid' => $themesection->id,
                'name' => 'parent',
            ]);

            if ($themeparent) {
                $this->assertEquals(
                    '0',
                    $themeparent->value,
                    "$themekey parent should be 0"
                );
            }

            // Verify each week's parent is the theme.
            foreach ($themedata['weeks'] as $weekkey => $weeksectionnum) {
                $weeksection = $DB->get_record('course_sections', [
                    'course' => $course->id,
                    'section' => $weeksectionnum,
                ], '*', MUST_EXIST);

                $weekparent = $DB->get_record('course_format_options', [
                    'courseid' => $course->id,
                    'sectionid' => $weeksection->id,
                    'name' => 'parent',
                ], '*', MUST_EXIST);

                $this->assertEquals(
                    (string)$themesectionnum,
                    $weekparent->value,
                    "$weekkey parent should be $themekey section number"
                );
            }
        }
    }
}
