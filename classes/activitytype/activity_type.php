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

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract for AI-generated course activity handlers.
 */
interface activity_type {
    /**
     * Machine-readable identifier for the activity type (e.g. 'quiz').
     *
     * @return string
     */
    public static function get_type(): string;

    /**
     * Language string identifier describing the activity type for display to users.
     *
     * @return string
     */
    public static function get_display_string_id(): string;

    /**
     * Short natural-language description shared with the AI prompt explaining what this type creates.
     *
     * @return string
     */
    public static function get_prompt_description(): string;

    /**
     * Attempt to create the activity in the requested course section.
     *
     * @param stdClass $activitydata Raw activity definition returned by the AI response.
     * @param stdClass $course Full course record.
     * @param int $sectionnumber Target section number within the course.
     * @param array $options Additional contextual options.
     * @return array|null Returns an array with 'cmid' and 'message' on success, null otherwise.
     */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array;
}
