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
 * Observer that queues the automatic section renumber.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Reacts to section create and delete events on Flexible sections courses.
 */
class section_reorder_observer {
    /**
     * Queue a renumber when a section is added to or deleted from a Flexible sections course.
     *
     * Never throws: a failure here must not break the teacher's edit.
     *
     * @param \core\event\base $event course_section_created or course_section_deleted
     */
    public static function section_changed(\core\event\base $event): void {
        try {
            if (!settings_helper::is_autoreorder_enabled()) {
                return;
            }
            $courseid = (int) $event->courseid;
            if (empty($courseid) || $courseid === SITEID) {
                return;
            }
            if (get_course($courseid)->format !== 'flexsections') {
                return;
            }
            section_reorder_queue::schedule($courseid);
        } catch (\Throwable $e) {
            debugging('Could not queue the section renumber: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
