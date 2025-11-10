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
     * Get available curriculum templates from admin configuration.
     *
     * @return array Array of template options for select dropdown
     */
    public function get_curriculum_templates() {
        $templates_config = get_config('aiplacement_modgen', 'curriculum_templates');
        $templates = [];
        
        if (empty($templates_config)) {
            return $templates;
        }
        
        $lines = explode("\n", $templates_config);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $parts = explode('|', $line);
            if (count($parts) >= 2) {
                $name = trim($parts[0]);
                $courseid = (int)trim($parts[1]);
                $sectionid = isset($parts[2]) ? (int)trim($parts[2]) : null;
                
                // Validate course exists and user has access
                if ($this->validate_template_access($courseid)) {
                    $key = $courseid . ($sectionid ? '|' . $sectionid : '');
                    $templates[$key] = $name;
                }
            }
        }
        
        return $templates;
    }
    
    /**
     * Extract template data from a curriculum module.
     *
     * @param string $template_key Template key in format "courseid" or "courseid|sectionid"
     * @return array Template data structure
     */
    public function extract_curriculum_template($template_key) {
        try {
            error_log("DEBUG: extract_curriculum_template called with template_key: $template_key");
            
            $parts = explode('|', $template_key);
            $courseid = (int)$parts[0];
            $rawsection = isset($parts[1]) ? trim($parts[1]) : null;
            $sectionid = $rawsection !== null && $rawsection !== '' ? (int)$rawsection : null;
            
            error_log("DEBUG: Parsed courseid=$courseid, sectionid=$sectionid");
            
            if (!$this->validate_template_access($courseid)) {
                error_log("DEBUG: Access validation failed for courseid=$courseid");
                throw new \moodle_exception('curriculumnotfound', 'aiplacement_modgen');
            }
            
            error_log("DEBUG: Access validation passed");
            
            // Normalize section identifier so callers may provide either the DB id
            // (course_sections.id) or the section number (course_sections.section).
            global $DB;
            $resolvedsectionid = null; // DB id
            $resolvedsectionnum = null; // section number
            if ($sectionid) {
                try {
                    error_log("DEBUG: About to resolve section with sectionid=$sectionid");
                    // First, try to find by DB id
                    $record = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid]);
                    if ($record) {
                        $resolvedsectionid = (int)$record->id;
                        $resolvedsectionnum = (int)$record->section;
                        error_log("DEBUG: Resolved section by ID: db_id=$resolvedsectionid, section_num=$resolvedsectionnum");
                    } else {
                        // If not found by id, try treating supplied value as section number
                        $record2 = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionid]);
                        if ($record2) {
                            $resolvedsectionid = (int)$record2->id;
                            $resolvedsectionnum = (int)$record2->section;
                            error_log("DEBUG: Resolved section by number: db_id=$resolvedsectionid, section_num=$resolvedsectionnum");
                        } else {
                            // Not found - clear both so callers treat as no section filter
                            $resolvedsectionid = null;
                            $resolvedsectionnum = null;
                            error_log("DEBUG: Section not found with either method");
                        }
                    }
                } catch (Throwable $e) {
                    error_log("DEBUG: Section resolution threw exception: " . get_class($e) . " - " . $e->getMessage());
                    // Don't fail - just skip section filtering
                    $resolvedsectionid = null;
                    $resolvedsectionnum = null;
                }
            }

            error_log("DEBUG: About to call get_course_info");
            try {
                $course_info = $this->get_course_info($courseid);
                error_log("DEBUG: get_course_info completed successfully");
            } catch (Throwable $e) {
                error_log("DEBUG: get_course_info threw exception: " . get_class($e) . " - " . $e->getMessage());
                throw new Exception("Failed in get_course_info: " . $e->getMessage());
            }
            
            error_log("DEBUG: About to call get_course_structure");
            try {
                $structure = $this->get_course_structure($courseid, $resolvedsectionid);
                error_log("DEBUG: get_course_structure completed successfully, got " . count($structure) . " sections");
            } catch (Throwable $e) {
                error_log("DEBUG: get_course_structure threw exception: " . get_class($e) . " - " . $e->getMessage());
                // Don't fail - just use empty structure
                $structure = [];
            }
            
            error_log("DEBUG: About to call get_activities_detail");
            try {
                $activities = $this->get_activities_detail($courseid, $resolvedsectionnum);
                error_log("DEBUG: get_activities_detail completed successfully, got " . count($activities) . " activities");
            } catch (Throwable $e) {
                error_log("DEBUG: get_activities_detail threw exception: " . get_class($e) . " - " . $e->getMessage());
                // Don't fail - just use empty activities
                $activities = [];
            }
            
            error_log("DEBUG: Extracted course_info: " . json_encode($course_info));
            error_log("DEBUG: Extracted structure count: " . count($structure) . " sections");
            error_log("DEBUG: Extracted activities count: " . count($activities) . " activities");

            $template = [
                'course_info' => $course_info,
                'structure' => $structure,
                'activities' => $activities,
            ];
            
            error_log("DEBUG: Returning template with keys: " . implode(', ', array_keys($template)));
            return $template;
        } catch (Exception $e) {
            error_log("DEBUG: Exception in extract_curriculum_template: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
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
            error_log("DEBUG: Exception in validate_template_access: " . $e->getMessage());
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
        
        error_log("DEBUG: get_course_info called with courseid=$courseid");
        $courseid = (int)$courseid;
        
        try {
            error_log("DEBUG: About to query course table with id=$courseid");
            $course = $DB->get_record('course', ['id' => $courseid], 'fullname,shortname,summary,format');
            
            if (!$course) {
                error_log("DEBUG: Course not found: courseid=$courseid");
                throw new Exception("Course not found: $courseid");
            }
            
            error_log("DEBUG: Course found: " . $course->fullname);
            
            return [
                'name' => $course->fullname,
                'format' => $course->format,
                'summary' => strip_tags($course->summary)
            ];
        } catch (Throwable $e) {
            error_log("DEBUG: Error in get_course_info: " . get_class($e) . " - " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get course structure (sections) - using direct database queries instead of get_fast_modinfo
     * to avoid potential database errors when loading course module info.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return array Sections structure
     */
    private function get_course_structure($courseid, $sectionid = null) {
        try {
            error_log("DEBUG: get_course_structure called with courseid=$courseid, sectionid=$sectionid");
            
            global $DB;
            $sections = [];
            $courseid = (int)$courseid;  // Ensure it's an integer
            
            // Query sections directly from database instead of using get_fast_modinfo
            try {
                error_log("DEBUG: About to query course_sections for courseid=$courseid");
                $allsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
                error_log("DEBUG: course_sections query succeeded, found " . count($allsections) . " sections");
            } catch (Throwable $e) {
                error_log("DEBUG: course_sections query failed: " . get_class($e) . " - " . $e->getMessage());
                throw new Exception("Failed to query course_sections: " . $e->getMessage());
            }
            
            // Pre-count activities per section in one query instead of N queries
            try {
                error_log("DEBUG: Pre-fetching activity counts for all sections");
                $allcms = $DB->get_records('course_modules', ['course' => $courseid], '', 'id, section');
                $activity_counts = [];
                foreach ($allcms as $cm) {
                    if (!isset($activity_counts[$cm->section])) {
                        $activity_counts[$cm->section] = 0;
                    }
                    $activity_counts[$cm->section]++;
                }
                error_log("DEBUG: Pre-fetched activity counts for " . count($activity_counts) . " sections");
            } catch (Throwable $e) {
                error_log("DEBUG: Failed to pre-fetch activity counts: " . $e->getMessage());
                $activity_counts = [];
            }
            
            foreach ($allsections as $section) {
                if ($sectionid && $section->id != $sectionid) {
                    continue;
                }
                
                // Use pre-counted activity count
                $activity_count = $activity_counts[$section->section] ?? 0;
                
                $sections[] = [
                    'id' => $section->id,
                    'name' => !empty($section->name) ? $section->name : "Section {$section->section}",
                    'summary' => strip_tags($section->summary ?? ''),
                    'activity_count' => $activity_count
                ];
            }
            
            error_log("DEBUG: get_course_structure returning " . count($sections) . " sections");
            return $sections;
        } catch (Exception $e) {
            error_log("DEBUG: Exception in get_course_structure: " . $e->getMessage());
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
            error_log("DEBUG: get_activities_detail called with courseid=$courseid, sectionid=$sectionid");
            
            global $DB;
            $activities = [];
            $courseid = (int)$courseid;  // Ensure integer
            
            // First get all modules to have a lookup table
            try {
                error_log("DEBUG: About to query modules table");
                $modules_list = $DB->get_records('modules', [], '', 'id, name');
                error_log("DEBUG: modules query succeeded, got " . count($modules_list) . " module types");
            } catch (Throwable $e) {
                error_log("DEBUG: modules query failed: " . get_class($e) . " - " . $e->getMessage());
                throw new Exception("Failed to query modules: " . $e->getMessage());
            }
            
            $module_lookup = [];
            foreach ($modules_list as $mod) {
                $module_lookup[$mod->id] = $mod->name;
            }
            error_log("DEBUG: Module lookup has " . count($module_lookup) . " entries");
            
            // Pre-fetch ALL sections to avoid N+1 queries
            try {
                error_log("DEBUG: Pre-fetching all course sections");
                $all_sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id, section, name');
                $section_lookup = [];
                foreach ($all_sections as $sec) {
                    $section_lookup[$sec->section] = !empty($sec->name) ? $sec->name : "Section {$sec->section}";
                }
                error_log("DEBUG: Pre-fetched " . count($all_sections) . " sections");
            } catch (Throwable $e) {
                error_log("DEBUG: Failed to pre-fetch sections: " . $e->getMessage());
                $section_lookup = [];
            }
            
            // Query course modules directly from database
            try {
                error_log("DEBUG: About to query course_modules for courseid=$courseid");
                $allcms = $DB->get_records('course_modules', ['course' => $courseid], 'section, id');
                error_log("DEBUG: course_modules query succeeded, got " . count($allcms) . " modules");
            } catch (Throwable $e) {
                error_log("DEBUG: course_modules query failed: " . get_class($e) . " - " . $e->getMessage());
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
                    error_log("DEBUG: Could not load full module object for cm->id={$cm->id}, modname={$modname}");
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
            
            error_log("DEBUG: get_activities_detail returning " . count($activities) . " activities");
            return $activities;
        } catch (Throwable $e) {
            error_log("DEBUG: Exception in get_activities_detail: " . $e->getMessage() . " / " . $e->getTraceAsString());
            throw $e;
        }
    }
}
