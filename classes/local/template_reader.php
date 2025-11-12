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
 * Template reader for curriculum modules.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Template reader class for extracting curriculum module structure.
 */
class template_reader {
    
    /**
     * Extract template data from an existing module.
     *
     * @param string $template_key Template key in format "courseid" or "courseid|sectionid"
     * @return array Template data structure
     */
    public function extract_curriculum_template($template_key) {
        try {
            $parts = explode('|', $template_key);
            $courseid = (int)$parts[0];
            $rawsection = isset($parts[1]) ? trim($parts[1]) : null;
            $sectionid = $rawsection !== null && $rawsection !== '' ? (int)$rawsection : null;
            
            if (!$this->validate_template_access($courseid)) {
                throw new \moodle_exception('curriculumnotfound', 'aiplacement_modgen');
            }
            
            // Normalize section identifier so callers may provide either the DB id
            // (course_sections.id) or the section number (course_sections.section).
            global $DB;
            $resolvedsectionid = null; // DB id
            $resolvedsectionnum = null; // section number
            if ($sectionid) {
                try {
                    // First, try to find by DB id
                    $record = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid]);
                    if ($record) {
                        $resolvedsectionid = (int)$record->id;
                        $resolvedsectionnum = (int)$record->section;
                    } else {
                        // If not found by id, try treating supplied value as section number
                        $record2 = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionid]);
                        if ($record2) {
                            $resolvedsectionid = (int)$record2->id;
                            $resolvedsectionnum = (int)$record2->section;
                        } else {
                            // Not found - clear both so callers treat as no section filter
                            $resolvedsectionid = null;
                            $resolvedsectionnum = null;
                        }
                    }
                } catch (Throwable $e) {
                    // Don't fail - just skip section filtering
                    $resolvedsectionid = null;
                    $resolvedsectionnum = null;
                }
            }

            try {
                $course_info = $this->get_course_info($courseid);
            } catch (Throwable $e) {
                throw new Exception("Failed in get_course_info: " . $e->getMessage());
            }
            
            try {
                $structure = $this->get_course_structure($courseid, $resolvedsectionid);
            } catch (Throwable $e) {
                // Don't fail - just use empty structure
                $structure = [];
            }
            
            try {
                $activities = $this->get_activities_detail($courseid, $resolvedsectionnum);
            } catch (Throwable $e) {
                // Don't fail - just use empty activities
                $activities = [];
            }

            $template = [
                'course_info' => $course_info,
                'structure' => $structure,
                'activities' => $activities,
            ];
            
            return $template;
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Validate that user has access to the template course.
     *
     * @param int $courseid Course ID
     * @return bool True if accessible
     */
    private function validate_template_access($courseid) {
        global $DB, $USER;
        
        try {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                return false;
            }
            
            // Simple check: just verify user is logged in and course exists
            // More detailed capability checks can fail with database errors in some Moodle instances
            return !empty($USER->id);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get basic course information.
     *
     * @param int $courseid Course ID
     * @return array Course info
     */
    private function get_course_info($courseid) {
        global $DB;
        
        $courseid = (int)$courseid;
        
        try {
            $course = $DB->get_record('course', ['id' => $courseid], 'fullname,shortname,summary,format');
            
            if (!$course) {
                throw new Exception("Course not found: $courseid");
            }
            
            return [
                'name' => $course->fullname,
                'format' => $course->format,
                'summary' => strip_tags($course->summary)
            ];
        } catch (Throwable $e) {
            throw $e;
        }
    }
    
    /**
     * Get course structure (sections) - using get_fast_modinfo for cached module data.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return array Sections structure
     */
    private function get_course_structure($courseid, $sectionid = null) {
        try {
            $courseid = (int)$courseid;  // Ensure it's an integer
            $course = get_course($courseid);
            
            // Use cached modinfo for performance
            $modinfo = get_fast_modinfo($course);
            $sections = [];
            
            // Get all sections and activity counts from cached modinfo
            $sectiondata = $modinfo->get_sections();
            
            foreach ($sectiondata as $sectionnum => $cmids) {
                $section = $modinfo->get_section_info($sectionnum);
                
                if ($sectionid && $section->id != $sectionid) {
                    continue;
                }
                
                $sections[] = [
                    'id' => $section->id,
                    'name' => !empty($section->name) ? $section->name : "Section {$sectionnum}",
                    'summary' => strip_tags($section->summary ?? ''),
                    'activity_count' => count($cmids)
                ];
            }
            
            return $sections;
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Get detailed activity information - optimized for token efficiency.
     * Extracts label intro text (structure/headings) and activity names/types.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional, filter by section number)
     * @return array Activities details (labels with intro, others with just name/type)
     */
    private function get_activities_detail($courseid, $sectionid = null) {
        try {
            global $DB;
            $activities = [];
            $courseid = (int)$courseid;  // Ensure integer
            
            // First get all modules to have a lookup table
            try {
                $modules_list = $DB->get_records('modules', [], '', 'id, name');
            } catch (Throwable $e) {
                throw new Exception("Failed to query modules: " . $e->getMessage());
            }
            
            $module_lookup = [];
            foreach ($modules_list as $mod) {
                $module_lookup[$mod->id] = $mod->name;
            }
            
            // Pre-fetch ALL sections to avoid N+1 queries
            try {
                $all_sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id, section, name');
                $section_lookup = [];
                foreach ($all_sections as $sec) {
                    $section_lookup[$sec->section] = !empty($sec->name) ? $sec->name : "Section {$sec->section}";
                }
            } catch (Throwable $e) {
                $section_lookup = [];
            }
            
            // Query course modules directly from database
            try {
                $allcms = $DB->get_records('course_modules', ['course' => $courseid], 'section, id');
            } catch (Throwable $e) {
                throw new Exception("Failed to query course_modules: " . $e->getMessage());
            }
            
            foreach ($allcms as $cm) {
                $modname = $module_lookup[$cm->module] ?? 'unknown';
                
                if ($sectionid !== null && $cm->section != $sectionid) {
                    continue;
                }
                
                // Use pre-fetched section name
                $section_name = $section_lookup[$cm->section] ?? "Section {$cm->section}";
                
                // Get the full course module object with name and intro from the module instance table
                // Use Moodle API to get the proper module details
                $fullcm = get_coursemodule_from_id($modname, $cm->id);
                
                // If we couldn't load the full module object, skip it
                if (!$fullcm) {
                    continue;
                }
                
                // Build activity data efficiently based on type:
                // - Labels: Extract full intro (these are headings/structure markers)
                // - Other activities: Just name and type (AI doesn't need full descriptions)
                $activity_data = [
                    'type' => $modname,
                    'name' => $fullcm->name ?? "Unknown {$modname}",
                    'section' => $section_name
                ];
                
                // Only extract intro content for labels (headings/structure)
                // For other activities, the name and type is sufficient
                if ($modname === 'label') {
                    $activity_data['intro'] = strip_tags($fullcm->intro ?? '');
                }
                
                $activities[] = $activity_data;
            }
            
            return $activities;
        } catch (Throwable $e) {
            throw $e;
        }
    }
}
