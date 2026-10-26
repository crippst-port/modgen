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
 * Tests for finding loops of parent links.
 *
 * The check used to be a PostgreSQL-only recursive query; it now walks the parent map in PHP,
 * so it behaves the same on every database. These tests pin what it reports.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\integrity_checker;

/**
 * Tests for integrity_checker::find_circular_refs().
 *
 * @covers \aiplacement_modgen\local\integrity_checker
 */
final class circular_refs_detection_test extends advanced_testcase {
    /**
     * Run the private finder on a parent map.
     *
     * @param array $parentmap Section number => parent value.
     * @return array root_section => path
     */
    private function find(array $parentmap): array {
        $method = new \ReflectionMethod(integrity_checker::class, 'find_circular_refs');
        $method->setAccessible(true);
        $found = [];
        foreach ($method->invoke(null, $parentmap) as $row) {
            $found[$row->root_section] = $row->path;
        }
        return $found;
    }

    public function test_a_sound_tree_has_no_loops(): void {
        $this->assertSame([], $this->find([0 => null, 1 => '0', 2 => '1', 3 => '2', 4 => '0']));
    }

    public function test_a_section_that_is_its_own_parent(): void {
        $this->assertSame([3 => '3->3'], $this->find([0 => null, 1 => '0', 3 => '3']));
    }

    public function test_every_section_on_a_loop_is_reported_once(): void {
        $this->assertSame(
            [2 => '2->3->2', 3 => '3->2->3'],
            $this->find([0 => null, 1 => '0', 2 => '3', 3 => '2', 4 => '2'])
        );
    }

    public function test_a_section_hanging_off_a_loop_is_not_itself_on_it(): void {
        $found = $this->find([0 => null, 2 => '3', 3 => '2', 4 => '2']);
        $this->assertArrayNotHasKey(4, $found);
    }

    public function test_missing_empty_and_non_numeric_parents_end_the_walk(): void {
        $this->assertSame([], $this->find([0 => null, 1 => '', 2 => 'abc', 3 => '99', 4 => null]));
    }
}
