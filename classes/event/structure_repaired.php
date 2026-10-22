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
 * A course structure repair action was run.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\event;

/**
 * A course structure repair action (Check Structure or the admin tools) was run.
 */
class structure_repaired extends \core\event\base {
    /**
     * Create the event for a repair.
     *
     * @param int $courseid Course that was repaired.
     * @param string $action Repair action, for example fixintegrity or setparent.
     * @param int $changed How many sections the repair changed.
     * @param string $via Where it was run from: check_structure or admin_tools.
     * @return self
     */
    public static function for_course(int $courseid, string $action, int $changed, string $via = 'check_structure'): self {
        return self::create([
            'context' => \context_course::instance($courseid),
            'other' => ['action' => $action, 'changed' => $changed, 'via' => $via],
        ]);
    }

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Get the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventstructure_repaired', 'aiplacement_modgen');
    }

    /**
     * Get a description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' ran the '{$this->other['action']}' structure repair in the course with id " .
            "'{$this->courseid}', changing {$this->other['changed']} section(s).";
    }

    /**
     * Get the Check Structure page for the course.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $this->courseid, 'check' => 1]);
    }

    /**
     * Check the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['action', 'changed', 'via'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }

    /**
     * Other data holds no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
