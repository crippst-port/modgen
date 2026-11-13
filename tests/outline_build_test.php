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

use aiplacement_modgen\local\preview_generator;
use aiplacement_modgen\local\section_creation_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * A weekly section's outline: the lines read from it, what the build stores, and the preview agreeing.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\section_creation_service
 * @covers \aiplacement_modgen\local\preview_generator
 */
final class outline_build_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var array An outline with every kind of entry. */
    private const MIXED_OUTLINE = [
        'Read chapter 1',
        '   ',
        ['text' => ' Field trip '],
        5,
        ['text' => ['not', 'text']],
        ['nottext' => 'x'],
        ['nested', 'list'],
        null,
    ];

    public function test_outline_lines(): void {
        $this->assertSame(['Read chapter 1', 'Field trip', '5'], section_creation_service::outline_lines(self::MIXED_OUTLINE));
        $this->assertSame([], section_creation_service::outline_lines('Not a list'));
        $this->assertSame([], section_creation_service::outline_lines(null));
    }

    /**
     * An outline the build cannot use in full still builds: its usable lines go into the summary as
     * a list, and the rest are left out rather than failing the whole build.
     */
    public function test_a_mixed_outline_builds_and_the_preview_shows_the_same_lines(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $course = $this->flexsections_course();
        $structure = ['sections' => [['title' => 'Rivers', 'summary' => 'Where rivers start', 'outline' => self::MIXED_OUTLINE]]];

        $job = $this->build_structure($course->id, $structure, 'connected_weekly');

        $this->assertSame('completed', $job->status, 'The build finished.');
        $summary = \aiplacement_modgen_structure_snapshot::rows($course->id)[0]['summary'];
        $this->assertStringContainsString('Where rivers start', $summary);
        foreach (['Read chapter 1', 'Field trip', '5'] as $line) {
            $this->assertStringContainsString($line, $summary);
        }
        $this->assertStringNotContainsString('Array', $summary);

        $preview = preview_generator::generate($structure, 'connected_weekly');
        $this->assertSame(
            ['Read chapter 1', 'Field trip', '5'],
            array_column($preview['weeks'][0]['outline'], 'text'),
            'The preview lists the lines the build stored.'
        );
    }

    public function test_an_outline_with_no_usable_lines_adds_no_list(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $course = $this->flexsections_course();

        $this->build_structure(
            $course->id,
            ['sections' => [['title' => 'Rivers', 'outline' => ['  ', ['x' => 1]]]]],
            'connected_weekly'
        );

        $summary = (string) $DB->get_field('course_sections', 'summary', ['course' => $course->id, 'name' => 'Rivers']);
        $this->assertStringNotContainsString('<ul', $summary);
    }
}
