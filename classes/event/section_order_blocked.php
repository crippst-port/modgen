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
 * The automatic section renumber refused to run on a course.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\event;

/**
 * Fired when the automatic renumber could not run because the section hierarchy is broken.
 *
 * Read this event as: course {objectid} needs Check Structure before its sections can be
 * renumbered. The site administrator's assistant bar icon turns to a warning on it.
 *
 * @property-read array $other {
 * @var string $blockedby Why the renumber refused, for example brokenhierarchy.
 * }
 */
class section_order_blocked extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Build the event for a course whose renumber was refused.
     *
     * Wrapped so the caller (a background task) never has to handle a course that has gone.
     *
     * @param int $courseid Course that could not be renumbered
     * @param string $blockedby Why the renumber refused
     * @return self|null Event ready to trigger, or null if it could not be built
     */
    public static function for_course(int $courseid, string $blockedby): ?self {
        try {
            return self::create([
                'context' => \context_course::instance($courseid),
                'objectid' => $courseid,
                'other' => ['blockedby' => $blockedby],
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
        return get_string('event_sectionorderblocked', 'aiplacement_modgen');
    }

    /**
     * Return a description of what happened, for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The automatic section renumber for course id '{$this->objectid}' was refused ("
            . "{$this->other['blockedby']}). Run Check Structure on the course before reorganising it.";
    }

    /**
     * Point the log report at the repair tool for this course.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/ai/placement/modgen/check_structure.php', [
            'id' => $this->objectid,
            'check' => 1,
        ]);
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
        if (!isset($this->other['blockedby'])) {
            throw new \coding_exception('The \'blockedby\' value must be set in other.');
        }
    }

    /**
     * The object is the course, which is mapped on restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'course', 'restore' => 'course'];
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
