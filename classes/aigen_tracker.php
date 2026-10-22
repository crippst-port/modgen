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

namespace aiplacement_modgen;

/**
 * Helper class for tracking AI-generated activities.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aigen_tracker {
    /**
     * Record that a course module was AI-generated.
     *
     * @param int $cmid The course module ID
     * @param int $courseid The course ID
     * @return bool True on success
     */
    public static function mark_as_aigenerated(int $cmid, int $courseid): bool {
        global $DB;

        // Check if already marked.
        if ($DB->record_exists('aiplacement_modgen_aigen', ['cmid' => $cmid])) {
            return true;
        }

        $record = new \stdClass();
        $record->cmid = $cmid;
        $record->courseid = $courseid;
        $record->timecreated = time();

        return (bool) $DB->insert_record('aiplacement_modgen_aigen', $record);
    }

    /**
     * Remove the AI-generated marker for a course module.
     *
     * @param int $cmid The course module ID
     * @return bool True if deleted, false if not found
     */
    public static function remove_marker(int $cmid): bool {
        global $DB;

        return $DB->delete_records('aiplacement_modgen_aigen', ['cmid' => $cmid]);
    }

    /**
     * Course module ids of the AI-generated activities that still exist in a course.
     *
     * @param int $courseid The course ID
     * @return int[] Course module ids
     */
    public static function get_course_cmids(int $courseid): array {
        global $DB;

        $sql = "SELECT ag.id, ag.cmid
                  FROM {aiplacement_modgen_aigen} ag
                  JOIN {course_modules} cm ON cm.id = ag.cmid
                 WHERE ag.courseid = :courseid
                   AND cm.deletioninprogress = 0";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        // Markers for activities that no longer exist are removed by the scheduled cleanup.
        $modinfo = get_fast_modinfo($courseid);
        $cmids = [];
        foreach ($records as $record) {
            if (isset($modinfo->cms[$record->cmid])) {
                $cmids[] = (int) $record->cmid;
            }
        }
        return $cmids;
    }

    /**
     * Check if a course module is marked as AI-generated.
     *
     * @param int $cmid The course module ID
     * @return bool True if AI-generated
     */
    public static function is_aigenerated(int $cmid): bool {
        global $DB;

        return $DB->record_exists('aiplacement_modgen_aigen', ['cmid' => $cmid]);
    }
}
