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
 * Deterministic interleaving of work before a build acquires its course lock.
 *
 * @package aiplacement_modgen
 * @copyright 2026 University of Portsmouth
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

/**
 * A real file lock factory with a one-shot callback before the course lock.
 */
class interleaving_lock_factory extends \core\lock\file_lock_factory {
    /** @var callable|null Work completed by a competing request while the build waits. */
    public static $beforelock = null;

    /**
     * Run the competing operation once, then obtain the real lock.
     *
     * @param string $resource Lock key
     * @param int $timeout Timeout in seconds
     * @param int $maxlifetime Maximum lifetime in seconds
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (str_starts_with($resource, 'course_edit_') && self::$beforelock) {
            $callback = self::$beforelock;
            self::$beforelock = null;
            $callback();
        }
        return parent::get_lock($resource, $timeout, $maxlifetime);
    }
}
