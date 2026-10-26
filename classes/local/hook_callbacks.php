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

namespace aiplacement_modgen\local;

/**
 * Callbacks for core hooks.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Remove a course's structure jobs along with the course.
     *
     * Jobs hold user data (who asked, and the structure they asked for). The privacy provider finds
     * them through their course, so jobs left behind by a deleted course could no longer be exported
     * or deleted on request.
     *
     * @param \core_course\hook\before_course_deleted $hook The hook
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        global $DB;
        $DB->delete_records('aiplacement_modgen_jobs', ['courseid' => $hook->course->id]);
    }
}
