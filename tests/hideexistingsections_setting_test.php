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
 * Unit tests for the site switch behind the "Hide existing sections" option.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\settings_helper;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests that the option is off unless asked for, and ignored when off.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\settings_helper
 */
final class hideexistingsections_setting_test extends advanced_testcase {
    /**
     * Reset the settings_helper request cache between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        settings_helper::clear_cache();
    }

    /**
     * Clear the cache again so a value set here cannot leak into another test.
     */
    protected function tearDown(): void {
        settings_helper::clear_cache();
        parent::tearDown();
    }

    /**
     * A site that has never touched the setting does not offer the option.
     *
     * get_config() returns false for an unset key, which must read as off - the install
     * default is written by the settings page, so a plugin upgraded in place would
     * otherwise inherit "on" from an absent value.
     *
     * @covers ::is_hideexistingsections_enabled
     */
    public function test_unset_setting_reads_as_disabled(): void {
        unset_config('enable_hideexistingsections', 'aiplacement_modgen');
        settings_helper::clear_cache();

        $this->assertFalse(settings_helper::is_hideexistingsections_enabled());
    }

    /**
     * The switch reads back both ways once set.
     *
     * @covers ::is_hideexistingsections_enabled
     */
    public function test_setting_reads_back(): void {
        set_config('enable_hideexistingsections', 1, 'aiplacement_modgen');
        settings_helper::clear_cache();
        $this->assertTrue(settings_helper::is_hideexistingsections_enabled());

        set_config('enable_hideexistingsections', 0, 'aiplacement_modgen');
        settings_helper::clear_cache();
        $this->assertFalse(settings_helper::is_hideexistingsections_enabled());
    }

    /**
     * With the site switch off, an existing section stays visible even when the flag is
     * passed - the case a hidden form field or a crafted request produces.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_flag_is_ignored_when_the_site_switch_is_off(): void {
        global $DB;

        set_config('enable_hideexistingsections', 0, 'aiplacement_modgen');
        settings_helper::clear_cache();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        theme_builder::ensure_flexsections_format($course->id);

        $existingid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 2]);
        $DB->set_field('course_sections', 'visible', 1, ['id' => $existingid]);

        (new section_creation_service())->create_sections_from_json(
            ['sections' => [['title' => 'Generated week', 'summary' => '']]],
            (int) $course->id,
            'weekly',
            false,
            false,
            true, // Ask to hide existing sections; the site switch must override this.
            false
        );

        $this->assertEquals(
            1,
            $DB->get_field('course_sections', 'visible', ['id' => $existingid]),
            'The pre-existing section must stay visible while the site switch is off.'
        );
    }

    /**
     * With the switch on, the same call does hide the existing section.
     *
     * Guards against the gate being implemented as an unconditional disable.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_flag_is_honoured_when_the_site_switch_is_on(): void {
        global $DB;

        set_config('enable_hideexistingsections', 1, 'aiplacement_modgen');
        settings_helper::clear_cache();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        theme_builder::ensure_flexsections_format($course->id);

        $existingid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 2]);
        $DB->set_field('course_sections', 'visible', 1, ['id' => $existingid]);

        (new section_creation_service())->create_sections_from_json(
            ['sections' => [['title' => 'Generated week', 'summary' => '']]],
            (int) $course->id,
            'weekly',
            false,
            false,
            true,
            false
        );

        $this->assertEquals(
            0,
            $DB->get_field('course_sections', 'visible', ['id' => $existingid]),
            'The pre-existing section should be hidden once the site switch is on.'
        );
    }
}
