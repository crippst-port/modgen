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

/**
 * Tests for the preview shown before a structure is approved.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\preview_generator
 */
final class preview_generator_test extends \advanced_testcase {
    public function test_a_weekly_structure_lists_its_weeks_in_order(): void {
        $preview = preview_generator::generate(['sections' => [
            'a' => ['title' => 'Rivers', 'summary' => 'Water'],
            'b' => 'not a section',
            'c' => ['title' => 'Deltas'],
        ]], 'connected_weekly');

        $this->assertTrue($preview['hasweeks']);
        $this->assertFalse($preview['hasthemes']);
        $this->assertSame('connected_weekly', $preview['structure']);
        $this->assertSame(['a', 'c'], array_column($preview['weeks'], 'key'));
        $this->assertSame([1, 2], array_column($preview['weeks'], 'number'));
        $this->assertSame(['Water', ''], array_column($preview['weeks'], 'summary'));
        $this->assertSame(0, $preview['themecount']);
        $this->assertSame(2, $preview['weekcount']);
    }

    public function test_a_themed_structure_numbers_weeks_across_themes(): void {
        $preview = preview_generator::generate(['themes' => [
            ['title' => 'Water', 'summary' => 'About water', 'weeks' => [['title' => 'Rivers'], 'x', ['title' => 'Lakes']]],
            'not a theme',
            ['title' => 'Land', 'weeks' => [['title' => 'Hills']]],
            ['title' => 'Empty'],
        ]], 'theme');

        $this->assertTrue($preview['hasthemes']);
        $this->assertSame(['Water', 'Land', 'Empty'], array_column($preview['themes'], 'title'));
        $this->assertSame([1, 2, 3], array_column($preview['themes'], 'number'));
        $this->assertSame(['0', '2', '3'], array_column($preview['themes'], 'key'));
        $this->assertSame([true, true, false], array_column($preview['themes'], 'hasweeks'));
        $this->assertSame(['0_0', '0_2'], array_column($preview['themes'][0]['weeks'], 'key'));
        $this->assertSame([3], array_column($preview['themes'][1]['weeks'], 'number'));
        $this->assertSame(3, $preview['themecount']);
        $this->assertSame(3, $preview['weekcount']);
    }

    public function test_missing_titles_get_a_placeholder_and_an_empty_raw_title(): void {
        $preview = preview_generator::generate(['themes' => [['weeks' => [['summary' => 'S']]]]], 'connected_theme');

        $theme = $preview['themes'][0];
        $this->assertSame(get_string('themefallback', 'aiplacement_modgen'), $theme['title']);
        $this->assertSame('', $theme['rawtitle']);
        $this->assertSame(get_string('themefallback', 'aiplacement_modgen'), $theme['placeholder']);
        $week = $theme['weeks'][0];
        $this->assertSame(get_string('weekfallback', 'aiplacement_modgen'), $week['title']);
        $this->assertSame('', $week['rawtitle']);
    }

    public function test_values_that_are_not_text_are_shown_as_empty(): void {
        $preview = preview_generator::generate(['sections' => [
            ['title' => ['nested'], 'summary' => ['also nested']],
            ['title' => 42],
        ]], 'weekly');

        $this->assertSame('', $preview['weeks'][0]['rawtitle']);
        $this->assertSame('', $preview['weeks'][0]['summary']);
        $this->assertSame('42', $preview['weeks'][1]['title']);
    }

    public function test_markup_is_left_for_the_template_to_escape(): void {
        $preview = preview_generator::generate(['sections' => [['title' => '<b>C# & "Rust"</b>']]], 'weekly');

        $this->assertSame('<b>C# & "Rust"</b>', $preview['weeks'][0]['title']);
    }

    public function test_sessions_are_shown_in_order_with_their_activities(): void {
        $preview = preview_generator::generate(['sections' => [[
            'title' => 'Rivers',
            'sessions' => [
                'postsession' => ['activities' => [['name' => 'Reflect', 'type' => 'forum']]],
                'presession' => [['name' => 'Read', 'type' => 'page'], 'skip me', ['type' => 'url']],
                'session' => ['description' => 'Lecture'],
                'other' => ['activities' => [['name' => 'Ignored']]],
            ],
        ]]], 'weekly');

        $week = $preview['weeks'][0];
        $this->assertTrue($week['hassessions']);
        $this->assertTrue($week['hasactivities']);
        $this->assertSame(['presession', 'session', 'postsession'], array_column($week['sessions'], 'type'));
        $this->assertSame(get_string('presession', 'aiplacement_modgen'), $week['sessions'][0]['label']);
        $this->assertSame(
            [['name' => 'Read', 'type' => 'page'], ['name' => '', 'type' => 'url']],
            $week['sessions'][0]['activities']
        );
        $this->assertSame([], $week['sessions'][1]['activities'], 'A description is not an activity list.');
        $this->assertSame([['name' => 'Reflect', 'type' => 'forum']], $week['sessions'][2]['activities']);
    }

    public function test_sessions_given_at_the_top_of_a_week_are_read(): void {
        $preview = preview_generator::generate(['sections' => [[
            'title' => 'Rivers',
            'session' => ['activities' => [['name' => 'Lecture', 'type' => 'page']]],
        ]]], 'weekly');

        $sessions = $preview['weeks'][0]['sessions'];
        $this->assertSame('Lecture', $sessions[1]['activities'][0]['name']);
    }

    public function test_a_session_that_is_not_a_list_has_no_activities(): void {
        $preview = preview_generator::generate(['sections' => [[
            'title' => 'Rivers',
            'sessions' => ['session' => 'Just text'],
        ]]], 'weekly');

        $expected = [['type' => 'session', 'label' => get_string('session', 'aiplacement_modgen'), 'activities' => []]];
        $this->assertSame($expected, $preview['weeks'][0]['sessions']);
    }

    public function test_a_weekly_sections_outline_is_shown_with_its_sessions(): void {
        $preview = preview_generator::generate(['sections' => [[
            'title' => 'Rivers',
            'outline' => ['Read chapter 1', '  ', ['text' => 'Field trip'], ['nottext' => 1], ['text' => ['nested']]],
        ]]], 'connected_weekly');

        $week = $preview['weeks'][0];
        $this->assertTrue($week['hasoutline']);
        $this->assertSame([['text' => 'Read chapter 1'], ['text' => 'Field trip']], $week['outline']);
        $this->assertTrue($week['hasactivities']);
        $this->assertSame(
            ['presession', 'session', 'postsession'],
            array_column($week['sessions'], 'type'),
            'Every week is built with its three sessions.'
        );
    }

    public function test_a_theme_weeks_outline_is_not_shown_as_the_build_does_not_use_it(): void {
        $preview = preview_generator::generate(['themes' => [[
            'title' => 'Water',
            'weeks' => [['title' => 'Rivers', 'outline' => ['Read chapter 1']]],
        ]]], 'connected_theme');

        $this->assertFalse($preview['themes'][0]['weeks'][0]['hasoutline']);
    }

    public function test_the_outline_is_rendered_in_the_preview(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');
        $preview = preview_generator::generate(['sections' => [[
            'title' => 'Rivers',
            'outline' => ['Read <b>chapter</b> 1'],
        ]]], 'connected_weekly');

        $html = $PAGE->get_renderer('core')->render_from_template(
            'aiplacement_modgen/prompt_preview_week',
            $preview['weeks'][0]
        );

        $this->assertStringContainsString(get_string('previewoutline', 'aiplacement_modgen'), $html);
        $this->assertStringContainsString('Read &lt;b&gt;chapter&lt;/b&gt; 1', $html, 'Outline text is escaped.');
    }

    public function test_a_structure_of_the_other_shape_shows_nothing(): void {
        $weekly = preview_generator::generate(['themes' => [['title' => 'T']]], 'weekly');
        $themed = preview_generator::generate(['sections' => [['title' => 'S']]], 'theme');

        foreach ([$weekly, $themed] as $preview) {
            $this->assertFalse($preview['hasthemes']);
            $this->assertFalse($preview['hasweeks']);
            $this->assertSame(0, $preview['weekcount']);
        }
        $this->assertSame(0, preview_generator::generate(['sections' => 'none'], 'weekly')['weekcount']);
    }
}
