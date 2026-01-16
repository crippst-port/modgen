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
 * Helper class for creating session subsections in flexsections format.
 *
 * This class provides shared functionality for creating pre-session, session, 
 * and post-session subsections used by both theme and weekly generation modes.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

use aiplacement_modgen\activitytype\registry;

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
        
        // Get the parent section ID from the section number
        // flexsections create_new_section() expects the parent SECTION ID, not the section number
        $parentsectionid = null;
        if ($parentsectionnum > 0) {
            $parentsectionid = $DB->get_field('course_sections', 'id', 
                ['course' => $courseid, 'section' => $parentsectionnum]);
        }
        
        // Define session types with language strings
        $sessiontypes = [
            'presession' => get_string('presession', 'aiplacement_modgen'),
            'session' => get_string('session', 'aiplacement_modgen'),
            'postsession' => get_string('postsession', 'aiplacement_modgen'),
        ];
        
        $sessionsectionmap = [];
        
        foreach ($sessiontypes as $sessionkey => $sessionlabel) {
            // Create the section at top level first
            $sessionsectionnum = $courseformat->create_new_section(0, null);
            $sessionsectionmap[$sessionkey] = $sessionsectionnum;
            
            // Get the section ID for database updates
            $sessionsectionid = $DB->get_field('course_sections', 'id', 
                ['course' => $courseid, 'section' => $sessionsectionnum]);
            
            // CRITICAL: Manually set the parent relationship using update_section_format_options
            // The parent value should be the section NUMBER (not ID) of the parent section
            if ($parentsectionnum > 0) {
                $courseformat->update_section_format_options([
                    'id' => $sessionsectionid,
                    'parent' => $parentsectionnum
                ]);
            }
            
            // Prepare section update data
            $sectionupdate = [
                'id' => $sessionsectionid,
                'name' => $sessionlabel,
            ];
            
            // Add description if provided in session data (backward compatible)
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

            // Create learningactivity metadata module at the start of the session.
            // Extract metadata - new structure or backward compatible
            $metadata = [];
            $activityname = $sessionlabel . ' activity'; // Default name
            
            if (!empty($sessiondata[$sessionkey]) && is_array($sessiondata[$sessionkey])) {
                $data = $sessiondata[$sessionkey];
                if (isset($data['learningactivity_metadata']) && is_array($data['learningactivity_metadata'])) {
                    // New structure with separate metadata
                    $metadata = $data['learningactivity_metadata'];
                    // Use custom name if provided, otherwise use default
                    if (!empty($metadata['name'])) {
                        $activityname = $metadata['name'];
                    }
                } else if (!empty($data['description'])) {
                    // Backward compatibility: use description as instructions
                    $metadata['instructions'] = $data['description'];
                }
            }
            
            $sessioncmid = self::create_learningactivity_metadata(
                $courseid,
                $sessionsectionnum,
                'activity',
                $activityname,
                $metadata,
                false  // Don't validate - allows empty metadata from Quick Add
            );
        }
        
        return $sessionsectionmap;
    }

    /**
     * Create a learningactivity module at the start of a session.
     *
     * This is a shared helper to add learning design metadata modules to session subsections.
     *
     * @param int $courseid Course ID
     * @param int $sectionnumber Section number where activity should be created
     * @param string $sectiontype 'activity' for session subsections
     * @param string $name Session name
     * @param array $metadata Learningactivity metadata (instructions, duration, learningmode, etc.)
     * @param bool $validate Whether to validate metadata (true for AI-generated, false for manual/Quick Add)
     * @return int|null CM ID of created activity or null on failure
     */
    private static function create_learningactivity_metadata($courseid, $sectionnumber, $sectiontype, $name, $metadata, $validate = false) {
        global $DB;

        // Get handler
        $handler = registry::get_handler('learningactivity');
        if (!$handler) {
            debugging('learningactivity handler not found', DEBUG_DEVELOPER);
            return null;
        }

        // Get course
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Prepare activity data
        $activitydata = new \stdClass();
        $activitydata->sectiontype = $sectiontype;
        $activitydata->name = $name;

        // Apply metadata - validate only if requested (AI-generated content)
        if ($validate && !empty($metadata)) {
            // Validate and sanitize metadata for AI-generated content
            $validatedmetadata = learningactivity_validator::validate_metadata($metadata);
            foreach ($validatedmetadata as $field => $value) {
                if ($value !== null) {
                    $activitydata->$field = $value;
                }
            }
        } else {
            // Direct merge for manual/Quick Add - skip null/empty values
            foreach ($metadata as $key => $value) {
                // Skip null values and empty strings to avoid issues with learningactivity module
                if ($value !== null && $value !== '') {
                    $activitydata->$key = $value;
                }
            }
        }

        // Create instance
        try {
            $instance = new $handler();
            $result = $instance->create($activitydata, $course, $sectionnumber);

            if ($result && isset($result['cmid'])) {
                return $result['cmid'];
            }
        } catch (\Exception $e) {
            debugging('Failed to create learningactivity: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }
    
    /**
     * Get session section numbers for a given parent week section.
     *
     * Retrieves the section numbers for presession, session, and postsession subsections
     * that are children of the specified parent section.
     *
     * @param int $parentsectionnum The parent section number (week)
     * @param int $courseid The course ID
     * @return array Associative array mapping session type to section number
     */
    public static function get_session_sections($parentsectionnum, $courseid) {
        global $DB;
        
        // Get all child sections of the parent
        $sql = "SELECT cs.section, cs.name
                FROM {course_sections} cs
                JOIN {course_format_options} cfo ON cfo.sectionid = cs.id
                WHERE cs.course = :courseid
                AND cfo.name = 'parent'
                AND cfo.value = :parentsection
                ORDER BY cs.section ASC";
        
        $childsections = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'parentsection' => $parentsectionnum
        ]);
        
        $sessionsectionmap = [];
        // IMPORTANT: Check longest strings first to avoid 'session' matching inside 'postsession'
        $sessiontypes = ['presession', 'postsession', 'session'];
        
        foreach ($childsections as $section) {
            $name = strtolower(str_replace(['-', '_', ' '], '', trim($section->name)));
            
            foreach ($sessiontypes as $type) {
                $cleantype = str_replace(['-', '_', ' '], '', $type);
                if (strpos($name, $cleantype) !== false) {
                    $sessionsectionmap[$type] = $section->section;
                    break;
                }
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
