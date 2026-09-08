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
 * Label activity type handler.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

/**
 * Label activity type for creating label activities.
 */
class label implements activity_type {
    /**
     * Machine-readable identifier for this activity type.
     *
     * @return string
     */
    public static function get_type(): string {
        return 'label';
    }

    /**
     * Language string identifier describing this activity type for display to users.
     *
     * @return string
     */
    public static function get_display_string_id(): string {
        return 'activitytype_label';
    }

    /**
     * Short natural-language description shared with the AI prompt.
     *
     * @return string
     */
    public static function get_prompt_description(): string {
        return 'A Moodle label to display text and information. Can include HTML markup with Bootstrap 4/5 ' .
            'classes for layout purposes (cards, grid layouts, alerts, badges, etc.). Use HTML to create ' .
            'visually structured content sections.';
    }

    /**
     * Create the label activity in the requested course section.
     *
     * @param stdClass $activitydata Raw activity definition returned by the AI response.
     * @param stdClass $course Full course record.
     * @param int $sectionnumber Target section number within the course.
     * @param array $options Additional contextual options.
     * @return array|null Returns an array with 'coursemodule' and 'instance' on success, null otherwise.
     */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        // Extract name and intro, ensuring proper handling.
        $name = trim($activitydata->name ?? '');
        $intro = trim($activitydata->intro ?? '');

        if ($name === '') {
            return null;
        }

        try {
            // Prepare the module information for label.
            $moduleinfo = new stdClass();
            $moduleinfo->course = $course->id;
            $moduleinfo->modulename = 'label';
            $moduleinfo->section = $sectionnumber;
            $moduleinfo->visible = 1;
            $moduleinfo->name = $name;
            $moduleinfo->cmidnumber = '';  // Course module ID number (optional identifier).

            // Label intro - labels use intro as the main content.
            $moduleinfo->introeditor = [
                'text' => $intro,
                'format' => 1,
                'itemid' => 0,
            ];

            // Label-specific fields.
            $moduleinfo->introformat = 1;
            $moduleinfo->showdescription = 1;  // Display description on course page.

            $cm = create_module($moduleinfo);

            return [
                'coursemodule' => $cm->coursemodule,
                'instance' => $cm->instance,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
