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
 * The automatic section renumber checked a course and found nothing to change.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\event;

/**
 * Fired when the automatic renumber ran on a course whose sections were already in order.
 *
 * Logged so the site administrator's assistant bar icon can say the run finished, rather than
 * the icon simply disappearing.
 */
class section_order_checked extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Build the event for a course that needed no renumber.
     *
     * Wrapped so the caller (a background task) never has to handle a course that has gone.
     *
     * @param int $courseid Course that was checked
     * @return self|null Event ready to trigger, or null if it could not be built
     */
    public static function for_course(int $courseid): ?self {
        try {
            return self::create([
                'context' => \context_course::instance($courseid),
                'objectid' => $courseid,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return the localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_sectionorderchecked', 'aiplacement_modgen');
    }

    /**
     * Return a description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The automatic section renumber checked course id '{$this->objectid}' and found its sections "
            . "already in hierarchy order, so nothing was changed.";
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set to the course id.');
        }
    }
}
