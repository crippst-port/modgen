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
 * Helper class for creating session subsections in flexsections format.
 *
 * This class provides shared functionality for creating pre-session, session, 
 * and post-session subsections used by both theme and weekly generation modes.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Session creator helper class.
 */
class session_creator {
    
    /**
     * Create pre-session, session, and post-session subsections under a parent section.
     *
     * @param object $courseformat The course format object (must be flexsections)
     * @param int $parentsectionnum The parent section number to create subsections under
     * @param int $courseid The course ID
     * @param array|null $sessiondata Optional session data with 'presession', 'session', 'postsession' keys
     * @return array Associative array mapping session type to section number ['presession' => N, 'session' => N, 'postsession' => N]
     * @throws \Exception If course format is not flexsections or method is missing
     */
    public static function create_session_subsections($courseformat, $parentsectionnum, $courseid, $sessiondata = null) {
        global $DB;
        
        // Validate course format
        if (!$courseformat || get_class($courseformat) !== 'format_flexsections') {
            throw new \Exception('Course format must be flexsections to create nested subsections');
        }
        
        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \Exception('The flexsections course format is not properly supporting nested sections');
        }
        
        // Define session types with language strings
        $sessiontypes = [
            'presession' => get_string('presession', 'aiplacement_modgen'),
            'session' => get_string('session', 'aiplacement_modgen'),
            'postsession' => get_string('postsession', 'aiplacement_modgen'),
        ];
        
        $sessionsectionmap = [];
        
        foreach ($sessiontypes as $sessionkey => $sessionlabel) {
            // CRITICAL: Always use the original parent section number, not a previously created section
            // create_new_section($parent, $before) where $parent is the parent section number
            // and $before is null to append at the end
            $sessionsectionnum = $courseformat->create_new_section($parentsectionnum, null);
            $sessionsectionmap[$sessionkey] = $sessionsectionnum;
            
            // Get the section ID for database updates
            $sessionsectionid = $DB->get_field('course_sections', 'id', 
                ['course' => $courseid, 'section' => $sessionsectionnum]);
            
            // Prepare section update data
            $sectionupdate = [
                'id' => $sessionsectionid,
                'name' => $sessionlabel,
            ];
            
            // Add description if provided in session data
            if (!empty($sessiondata[$sessionkey]) && is_array($sessiondata[$sessionkey])) {
                $data = $sessiondata[$sessionkey];
                if (!empty($data['description'])) {
                    $sectionupdate['summary'] = format_text($data['description'], FORMAT_HTML);
                    $sectionupdate['summaryformat'] = FORMAT_HTML;
                }
            }
            
            // Update section record
            $DB->update_record('course_sections', $sectionupdate);
            
            // Set session section to NOT appear as a link (collapsed = 0)
            if (method_exists($courseformat, 'update_section_format_options')) {
                $courseformat->update_section_format_options([
                    'id' => $sessionsectionid, 
                    'collapsed' => 0
                ]);
            }
        }
        
        return $sessionsectionmap;
    }
    
    /**
     * Create activities in session subsections.
     *
     * @param array $sessiondata Session data with 'presession', 'session', 'postsession' keys
     * @param array $sessionsectionmap Map of session type to section number
     * @param object $course The course object
     * @param array &$results Results array to append success messages to
     * @param array &$warnings Warnings array to append error messages to
     * @return void
     */
    public static function create_session_activities($sessiondata, $sessionsectionmap, $course, &$results, &$warnings) {
        $sessiontypes = ['presession', 'session', 'postsession'];
        
        foreach ($sessiontypes as $sessionkey) {
            if (empty($sessiondata[$sessionkey]) || !is_array($sessiondata[$sessionkey])) {
                continue;
            }
            
            $data = $sessiondata[$sessionkey];
            $activities = $data['activities'] ?? [];
            
            if (empty($activities) || !is_array($activities)) {
                continue;
            }
            
            $sectionnumber = $sessionsectionmap[$sessionkey] ?? null;
            if ($sectionnumber === null) {
                continue;
            }
            
            $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                $activities,
                $course,
                $sectionnumber
            );
            
            if (!empty($activityoutcome['created'])) {
                $results = array_merge($results, $activityoutcome['created']);
            }
            if (!empty($activityoutcome['warnings'])) {
                $warnings = array_merge($warnings, $activityoutcome['warnings']);
            }
        }
    }
}
