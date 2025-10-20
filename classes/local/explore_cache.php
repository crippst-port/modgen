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

/**
 * Cache manager for explore insights.
 *
 * This class handles storing and retrieving AI-generated insights from the database.
 * Insights are cached per course to avoid repeated API calls to the AI service.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

class explore_cache {
    /**
     * Cache table name
     */
    const CACHE_TABLE = 'aiplacement_modgen_cache';

    /**
     * Get cached insights for a course.
     *
     * @param int $courseid The course ID
     * @return array|null The cached insights data, or null if not found or expired
     */
    public static function get($courseid) {
        global $DB;

        $cache = $DB->get_record(self::CACHE_TABLE, ['courseid' => $courseid]);

        if (!$cache) {
            return null;
        }

        // Return the decoded JSON data
        return json_decode($cache->data, true);
    }

    /**
     * Save insights to cache for a course.
     *
     * @param int $courseid The course ID
     * @param array $data The insights data to cache
     * @return bool Success or failure
     */
    public static function set($courseid, $data) {
        global $DB;

        // Check if cache entry exists
        $existing = $DB->get_record(self::CACHE_TABLE, ['courseid' => $courseid]);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->data = json_encode($data);
        $record->timecreated = time();

        if ($existing) {
            $record->id = $existing->id;
            return $DB->update_record(self::CACHE_TABLE, $record);
        } else {
            return $DB->insert_record(self::CACHE_TABLE, $record);
        }
    }

    /**
     * Clear cache for a course.
     *
     * @param int $courseid The course ID
     * @return bool Success or failure
     */
    public static function clear($courseid) {
        global $DB;
        return $DB->delete_records(self::CACHE_TABLE, ['courseid' => $courseid]);
    }

    /**
     * Clear all cache entries.
     *
     * @return bool Success or failure
     */
    public static function clear_all() {
        global $DB;
        return $DB->delete_records(self::CACHE_TABLE);
    }

    /**
     * Check if cache exists for a course.
     *
     * @param int $courseid The course ID
     * @return bool True if cache exists, false otherwise
     */
    public static function exists($courseid) {
        global $DB;
        return $DB->record_exists(self::CACHE_TABLE, ['courseid' => $courseid]);
    }

    /**
     * Get cache timestamp for a course.
     *
     * @param int $courseid The course ID
     * @return int|null The timestamp when cache was created, or null if not found
     */
    public static function get_timestamp($courseid) {
        global $DB;

        $cache = $DB->get_record(self::CACHE_TABLE, ['courseid' => $courseid]);

        if (!$cache) {
            return null;
        }

        return $cache->timecreated;
    }
}
