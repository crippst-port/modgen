<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace aiplacement_modgen\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for removing AI-generated markers when activities are edited.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Handle course module updated event.
     *
     * Remove the AI-generated marker when an activity is edited.
     *
     * @param \core\event\course_module_updated $event The event
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        $cmid = $event->contextinstanceid;

        // Remove the AI-generated marker since the activity has been edited.
        \aiplacement_modgen\aigen_tracker::remove_marker($cmid);
    }

    /**
     * Handle course module deleted event.
     *
     * Clean up the AI-generated marker when an activity is deleted.
     *
     * @param \core\event\course_module_deleted $event The event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        $cmid = $event->contextinstanceid;

        // Remove the marker record.
        \aiplacement_modgen\aigen_tracker::remove_marker($cmid);
    }
}
