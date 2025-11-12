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
 * Assignment activity handler for creating Moodle assignment modules.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates assignment activities for student work submission.
 */
class assignment implements activity_type {

    /** @inheritDoc */
    public static function get_type(): string {
        return 'assignment';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_assignment';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle assignment activity where students submit work (files, text, or other formats). Ideal for formative and summative assessments, essays, projects, and reflective tasks.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $name = trim($activitydata->name ?? '');

        if ($name === '') {
            return null;
        }

        $intro = trim($activitydata->intro ?? '');

        // Create the assignment module using minimal required fields
        $moduleinfo = new stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->modulename = 'assign';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        $moduleinfo->cmidnumber = '';  // Course module ID number (optional identifier)

        // Assignment intro/description
        $moduleinfo->introeditor = [
            'text' => $intro,
            'format' => 1,
            'itemid' => 0
        ];

        // Assignment-specific fields with sensible defaults
        $moduleinfo->introformat = 1;
        $moduleinfo->alwaysshowdescription = 1;  // Always show description to students
        $moduleinfo->submissiondrafts = 1;  // Allow students to save drafts
        $moduleinfo->sendnotifications = 1;  // Notify teachers of submissions
        $moduleinfo->sendstudentnotifications = 1;  // Notify students of grading
        $moduleinfo->duedate = 0;  // No due date by default
        $moduleinfo->cutoffdate = 0;  // No cutoff date by default
        $moduleinfo->gradingduedate = 0;  // No grading due date
        $moduleinfo->allowsubmissionsfromdate = 0;  // Allow submissions immediately
        $moduleinfo->grade = 100;  // Default grade to 100
        
        // Submission statement and notifications
        $moduleinfo->requiresubmissionstatement = 0;  // Don't require submission statement
        $moduleinfo->sendlatenotifications = 0;  // Don't send late submission notifications
        
        // Team submission settings
        $moduleinfo->teamsubmission = 0;  // Individual submissions (not team)
        $moduleinfo->requireallteammemberssubmit = 0;  // N/A for individual submissions
        
        // Marking settings
        $moduleinfo->blindmarking = 0;  // Don't use blind marking
        $moduleinfo->markingworkflow = 0;  // Don't use marking workflow
        $moduleinfo->markingallocation = 0;  // Don't use marking allocation

        // Submission plugin settings
        $moduleinfo->assignsubmission_onlinetext_enabled = 1; // Enable online text
        $moduleinfo->assignsubmission_file_enabled = 0; // Disable file submissions
        $moduleinfo->assignfeedback_comments_enabled = 1; // Enable feedback comments

        try {
            $cm = \create_module($moduleinfo);

            if (!isset($cm->coursemodule) || !isset($cm->instance)) {
                return null;
            }            return [
                'coursemodule' => $cm->coursemodule,
                'instance' => $cm->instance
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
