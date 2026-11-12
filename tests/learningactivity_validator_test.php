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

use aiplacement_modgen\local\learningactivity_validator as validator;

/**
 * Tests for cleaning the learning activity metadata the AI or a file supplies.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\learningactivity_validator
 */
final class learningactivity_validator_test extends \advanced_testcase {
    /**
     * The modes and types come from mod_learningactivity's language strings.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!\core_component::get_component_directory('mod_learningactivity')) {
            $this->markTestSkipped('mod_learningactivity is not installed.');
        }
    }

    public function test_the_valid_lists_come_from_the_activity_module(): void {
        $this->assertSame(['Synchronous', 'Asynchronous', 'Not specified'], validator::get_valid_learningmodes());
        $this->assertSame(
            ['Acquisition', 'Collaboration', 'Discussion', 'Investigation', 'Practice', 'Production'],
            validator::get_valid_learningtypes()
        );
        $icons = validator::get_valid_icons();
        $this->assertContains('', $icons, 'No icon is allowed.');
        $this->assertContains('fa-book', $icons);
    }

    public function test_nothing_given_gives_safe_defaults(): void {
        $expected = [
            'name' => '',
            'activityicon' => '',
            'instructions' => '',
            'duration' => null,
            'learningmode' => null,
            'groupactivity' => null,
            'learningtypes' => null,
            'learningoutcomes_weekly' => '',
        ];
        $this->assertSame($expected, validator::validate_metadata([]));
        $this->assertSame($expected, validator::validate_metadata('not metadata'));
        $this->assertSame($expected, validator::validate_metadata(null));
    }

    public function test_an_object_is_read_like_an_array(): void {
        $result = validator::validate_metadata((object) ['name' => 'Lecture', 'activityicon' => 'fa-book']);

        $this->assertSame('Lecture', $result['name']);
        $this->assertSame('fa-book', $result['activityicon']);
    }

    public function test_text_fields_lose_their_markup(): void {
        $result = validator::validate_metadata([
            'name' => '  <script>alert(1)</script>Lecture  ',
            'instructions' => '<p>Read <b>this</b></p>',
            'learningoutcomes_weekly' => "<i>One</i>\nTwo",
            'designnotes' => '<img src=x onerror=alert(1)>Notes',
        ]);

        $this->assertStringNotContainsString('<', $result['name']);
        $this->assertStringContainsString('Lecture', $result['name']);
        $this->assertSame('Read this', $result['instructions']);
        $this->assertSame("One\nTwo", $result['learningoutcomes_weekly']);
        $this->assertSame('Notes', $result['designnotes']);
    }

    public function test_instructions_of_only_markup_are_empty(): void {
        $this->assertSame('', validator::validate_metadata(['instructions' => '<p> </p>'])['instructions']);
    }

    public function test_long_text_is_cut_by_characters_not_bytes(): void {
        $result = validator::validate_metadata([
            'name' => str_repeat('é', 300),
            'instructions' => str_repeat('ü', 70000),
            'learningoutcomes_weekly' => str_repeat('ñ', 70000),
            'designnotes' => str_repeat('ø', 70000),
        ]);

        $this->assertSame(str_repeat('é', 255), $result['name']);
        $this->assertSame(65535, \core_text::strlen($result['instructions']));
        $this->assertSame(65535, \core_text::strlen($result['learningoutcomes_weekly']));
        $this->assertSame(65535, \core_text::strlen($result['designnotes']));
    }

    public function test_only_known_icons_are_kept(): void {
        $this->assertSame('fa-flask', validator::validate_metadata(['activityicon' => ' fa-flask '])['activityicon']);
        $this->assertSame('', validator::validate_metadata(['activityicon' => 'fa-skull'])['activityicon']);
        $this->assertSame('', validator::validate_metadata(['activityicon' => 'FA-FLASK'])['activityicon']);
    }

    /**
     * Durations, and what each becomes.
     *
     * @return array
     */
    public static function duration_provider(): array {
        return [
            'minutes' => ['90', '90'],
            'number' => [45, '45'],
            'padded' => [' 30 ', '30'],
            'decimal' => ['12.7', '12'],
            'zero' => ['0', null],
            'negative' => ['-5', null],
            'words' => ['an hour', null],
        ];
    }

    /**
     * A duration is kept only as a positive whole number.
     *
     * @dataProvider duration_provider
     * @param mixed $given Duration given.
     * @param string|null $expected Duration kept.
     */
    public function test_duration(mixed $given, ?string $expected): void {
        $this->assertSame($expected, validator::validate_metadata(['duration' => $given])['duration']);
    }

    public function test_a_known_learning_mode_is_stored_as_its_index(): void {
        $this->assertSame(0, validator::validate_metadata(['learningmode' => 'Synchronous'])['learningmode']);
        $this->assertSame(2, validator::validate_metadata(['learningmode' => ' Not specified '])['learningmode']);
        $this->assertNull(validator::validate_metadata(['learningmode' => 'synchronous'])['learningmode']);
        $this->assertNull(validator::validate_metadata(['learningmode' => 'Hybrid'])['learningmode']);
    }

    public function test_group_activity_is_a_flag(): void {
        $this->assertSame(1, validator::validate_metadata(['groupactivity' => true])['groupactivity']);
        $this->assertSame(1, validator::validate_metadata(['groupactivity' => 'yes'])['groupactivity']);
        $this->assertSame(0, validator::validate_metadata(['groupactivity' => false])['groupactivity']);
        $this->assertSame(0, validator::validate_metadata(['groupactivity' => '0'])['groupactivity']);
    }

    public function test_learning_types_keep_only_known_types(): void {
        $this->assertSame(
            'Acquisition,Practice',
            validator::validate_metadata(['learningtypes' => 'Acquisition, Lecturing ,Practice'])['learningtypes']
        );
        $this->assertSame(
            'Discussion,Production',
            validator::validate_metadata(['learningtypes' => ['Discussion', ' Production', 'Fun']])['learningtypes']
        );
        $this->assertNull(validator::validate_metadata(['learningtypes' => 'acquisition,Fun'])['learningtypes']);
        $this->assertNull(validator::validate_metadata(['learningtypes' => ''])['learningtypes']);
    }

    public function test_design_notes_are_only_returned_when_given(): void {
        $this->assertArrayNotHasKey('designnotes', validator::validate_metadata(['name' => 'x']));
        $this->assertSame('', validator::validate_metadata(['designnotes' => ''])['designnotes']);
    }
}
