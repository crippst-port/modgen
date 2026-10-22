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

use aiplacement_modgen\local\json_sanitiser;

/**
 * Tests for the untrusted-input handling on approved JSON.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\json_sanitiser
 */
final class json_sanitiser_test extends \advanced_testcase {
    /**
     * Strings are cleaned wherever they sit, including the weekly "sections" shape.
     */
    public function test_sanitiser_cleans_every_shape(): void {
        $dirty = '<script>alert(1)</script>Hello';
        $clean = json_sanitiser::clean([
            'title' => $dirty,
            'sections' => [[
                'title' => $dirty,
                'summary' => '<p>ok</p><script>x</script>',
                'outline' => [$dirty],
                'activities' => [['name' => $dirty, 'type' => 'quiz<b>', 'url' => 'javascript:alert(1)']],
            ]],
            'weekcount' => 3,
        ]);

        $this->assertSame('alert(1)Hello', $clean['title']);
        $this->assertSame('alert(1)Hello', $clean['sections'][0]['title']);
        $this->assertStringNotContainsString('<script', $clean['sections'][0]['summary']);
        $this->assertSame('alert(1)Hello', $clean['sections'][0]['outline'][0]);
        $this->assertSame('alert(1)Hello', $clean['sections'][0]['activities'][0]['name']);
        $this->assertSame('quizb', $clean['sections'][0]['activities'][0]['type']);
        $this->assertSame('', $clean['sections'][0]['activities'][0]['url']);
        $this->assertSame(3, $clean['weekcount']);
    }
}
