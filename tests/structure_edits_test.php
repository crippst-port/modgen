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
use aiplacement_modgen\local\structure_edits;

/**
 * Tests for the titles and descriptions edited on the structure preview.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\local\structure_edits
 */
final class structure_edits_test extends \basic_testcase {
    /**
     * Two themes, the second with two weeks.
     *
     * @return array
     */
    private function themes(): array {
        return ['themes' => [
            ['title' => 'Rivers', 'summary' => 'Water', 'weeks' => [['title' => 'Source', 'summary' => '']]],
            ['title' => 'Coasts', 'summary' => '', 'weeks' => [
                ['title' => 'Cliffs', 'summary' => 'Rock', 'sessions' => ['session' => ['activities' => []]]],
                ['title' => 'Beaches', 'summary' => ''],
            ]],
        ]];
    }

    public function test_theme_and_week_titles_and_descriptions_are_applied(): void {
        $json = structure_edits::apply(
            $this->themes(),
            ['t1' => 'Seas and coasts', 'w1_0' => 'Sea cliffs'],
            ['t1' => 'Where land meets sea', 'w1_1' => "Sand\nand shingle"]
        );
        $this->assertSame('Seas and coasts', $json['themes'][1]['title']);
        $this->assertSame('Where land meets sea', $json['themes'][1]['summary']);
        $this->assertSame('Sea cliffs', $json['themes'][1]['weeks'][0]['title']);
        $this->assertSame("Sand\nand shingle", $json['themes'][1]['weeks'][1]['summary']);
        // Nothing else moves.
        $this->assertSame('Rivers', $json['themes'][0]['title']);
        $this->assertSame(['session' => ['activities' => []]], $json['themes'][1]['weeks'][0]['sessions']);
    }

    public function test_the_keys_the_preview_gives_are_the_ones_applied(): void {
        $preview = preview_generator::generate($this->themes(), 'connected_theme');
        $week = $preview['themes'][1]['weeks'][1];
        $json = structure_edits::apply($this->themes(), ['w' . $week['key'] => 'Dunes'], []);
        $this->assertSame('Dunes', $json['themes'][1]['weeks'][1]['title']);
        $this->assertSame(3, $week['number']);
        $this->assertSame(2, $preview['themecount']);
        $this->assertSame(3, $preview['weekcount']);
    }

    public function test_a_cleared_title_is_removed_so_the_build_names_the_section(): void {
        $json = structure_edits::apply($this->themes(), ['t0' => '   ', 'w0_0' => ''], []);
        $this->assertArrayNotHasKey('title', $json['themes'][0]);
        $this->assertArrayNotHasKey('title', $json['themes'][0]['weeks'][0]);
    }

    public function test_titles_are_one_line_of_plain_text_no_longer_than_a_section_name(): void {
        $json = structure_edits::apply($this->themes(), ['t0' => "<b>Big</b>\n rivers", 't1' => str_repeat('x', 300)], []);
        $this->assertSame('Big rivers', $json['themes'][0]['title']);
        $this->assertSame(255, \core_text::strlen($json['themes'][1]['title']));
    }

    public function test_a_field_left_as_shown_is_not_touched(): void {
        $json = ['sections' => [['title' => 'Week one', 'summary' => '<p>Intro <em>here</em></p>']]];
        // The browser sends back what it showed, which cleaning would change.
        $this->assertSame($json, structure_edits::apply($json, ['w0' => 'Week one'], ['w0' => 'Intro here']));
    }

    public function test_a_weekly_description_is_escaped_as_it_is_built_as_html(): void {
        $json = ['sections' => [['title' => 'Week one', 'summary' => '']]];
        $json = structure_edits::apply($json, [], ['w0' => "Fish & chips\nby the sea"]);
        $this->assertSame("Fish &amp; chips<br>\nby the sea", $json['sections'][0]['summary']);
    }

    public function test_fields_that_name_nothing_are_ignored(): void {
        $themes = $this->themes();
        $this->assertSame($themes, structure_edits::apply($themes, ['t9' => 'X', 'w0_5' => 'X', 'w0' => 'X', 'zz' => 'X'], []));
        $weekly = ['sections' => [['title' => 'A']]];
        $this->assertSame($weekly, structure_edits::apply($weekly, ['t0' => 'X', 'w3' => 'X'], []));
    }
}
