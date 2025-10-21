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
 * Forum activity handler for creating Moodle forum modules.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\activitytype;

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates Forum activities for course discussions and collaborative learning.
 */
class forum implements activity_type {
    
    /** @inheritDoc */
    public static function get_type(): string {
        return 'forum';
    }

    /** @inheritDoc */
    public static function get_display_string_id(): string {
        return 'activitytype_forum';
    }

    /** @inheritDoc */
    public static function get_prompt_description(): string {
        return 'A Moodle Forum activity for group discussions and peer interaction. Can be configured as a single simple forum, Q&A forum, or general discussion forum where students can create topics and engage in threaded conversations.';
    }

    /** @inheritDoc */
    public function create(stdClass $activitydata, stdClass $course, int $sectionnumber, array $options = []): ?array {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $name = trim($activitydata->name ?? '');
        
        if ($name === '') {
            error_log('FORUM: Empty name, returning null');
            return null;
        }

        error_log('FORUM: Creating forum activity: ' . $name);
        error_log('FORUM: Course ID: ' . $course->id . ', Section: ' . $sectionnumber);

        $intro = trim($activitydata->intro ?? '');
        $forumtype = trim($activitydata->type ?? 'general');
        $forumtype = $this->normalize_forum_type($forumtype);

        error_log('FORUM: Forum type: ' . $forumtype);

        // Create the forum module
        $moduleinfo = new stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->modulename = 'forum';
        $moduleinfo->section = $sectionnumber;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        
        // Forum intro
        $moduleinfo->introeditor = [
            'text' => $intro,
            'format' => 1,
            'itemid' => 0
        ];
        
        // Forum-specific fields
        $moduleinfo->introformat = 1;
        $moduleinfo->type = $forumtype;  // general, news, qanda
        $moduleinfo->daystokeep = 0;  // Keep all posts
        $moduleinfo->displaywordcount = 0;  // Don't display word count
        $moduleinfo->blockafter = 0;  // No post blocking
        $moduleinfo->blockperiod = 0;  // No block period
        $moduleinfo->trackingtype = 1;  // Optional tracking
        $moduleinfo->allowforcedreadtracking = 0;  // Don't force tracking
        $moduleinfo->maxbytes = 0;  // Use course default file size
        $moduleinfo->maxattachments = 9;  // Allow up to 9 attachments
        $moduleinfo->forcesubscribe = 0;  // Optional subscription
        $moduleinfo->maildigest = 0;  // No digest by default
        $moduleinfo->scale = 0;  // No rating
        $moduleinfo->canposttomygroups = 0;  // Post to all groups accessible to user
        $moduleinfo->cmidnumber = '';  // No custom ID number

        error_log('FORUM: Module info prepared: ' . print_r($moduleinfo, true));

        try {
            error_log('FORUM: Calling create_module');
            $cm = \create_module($moduleinfo);
            error_log('FORUM: create_module succeeded');
            
            $forumid = $cm->instance;
            $cmid = $cm->coursemodule;

            error_log('FORUM: Forum created with ID: ' . $forumid . ', CM ID: ' . $cmid);
            error_log('FORUM: Creation successful');

            return [
                'coursemodule' => $cmid,
                'instance' => $forumid
            ];
        } catch (\Exception $e) {
            error_log('FORUM: Exception caught: ' . $e->getMessage());
            error_log('FORUM: Trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Normalize forum type to valid Moodle forum type.
     *
     * @param string $type Forum type from AI response.
     * @return string One of: 'general', 'eachuser', 'teacher', 'news', 'qanda', 'single'.
     */
    private function normalize_forum_type(string $type): string {
        $type = strtolower(trim($type));
        
        // Map common variations to valid types
        $map = [
            'q&a' => 'qanda',
            'qa' => 'qanda',
            'question' => 'qanda',
            'qanda' => 'qanda',
            'qandquestion' => 'qanda',
            'discussion' => 'general',
            'general' => 'general',
            'thread' => 'general',
            'news' => 'news',
            'announcement' => 'news',
            'single' => 'single',
            'eachuser' => 'eachuser',
            'each' => 'eachuser',
            'teacher' => 'teacher',
        ];
        
        if (isset($map[$type])) {
            return $map[$type];
        }
        
        // Default to general discussion
        return 'general';
    }
}
