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
 * Quiz activity type handler.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

defined('MOODLE_INTERNAL') || die();

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Label activity type for creating label activities.
 */
class quiz implements activity_type {
    /** @inheritDoc */
    public static function get_type(): string {
        return 'quiz';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_quiz';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle quiz activity containing the supplied questions and settings. Supports multiple question types and assessment configurations.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        // Extract name and intro, ensuring proper handling
        $name = trim($activitydata->name ?? '');
        $intro = trim($activitydata->intro ?? '');

        if ($name === '') {
            return null;
        }

        try {
            // Prepare the module information for quiz - minimal fields only
            $moduleinfo = new stdClass();
            $moduleinfo->course = $course->id;
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->section = $sectionnumber;
            $moduleinfo->visible = 1;
            $moduleinfo->name = $name;
            $moduleinfo->cmidnumber = ''; // Course module ID number (optional identifier)

            // Quiz intro
            $moduleinfo->introeditor = [
                'text' => $intro,
                'format' => 1,
                'itemid' => 0,
            ];

            // Minimal quiz-specific fields
            $moduleinfo->introformat = 1;
            $moduleinfo->showdescription = 1;  // Display description on course page
            $moduleinfo->preferredbehaviour = 'deferredfeedback';
            $moduleinfo->questionsperpage = 1;
            $moduleinfo->navmethod = 'free';
            $moduleinfo->grade = 10;
            $moduleinfo->timeopen = 0;  // No time restriction
            $moduleinfo->timeclose = 0;  // No time restriction
            $moduleinfo->questiondecimalpoints = -1;  // Default decimal points
            $moduleinfo->decimalpoints = 2;  // Decimal points for grades (0-10, or -1 for default)

            // Required fields that quiz_process_options expects
            $moduleinfo->quizpassword = ''; // Gets converted to password by quiz_process_options
            $moduleinfo->feedbackboundarycount = -1; // Disable feedback processing

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
