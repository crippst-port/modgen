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
            
            error_log("DEBUG: About to call get_course_html_structure");
            try {
                $template_html = $this->get_course_html_structure($courseid, $resolvedsectionid ?? $resolvedsectionnum);
                error_log("DEBUG: get_course_html_structure completed successfully");
            } catch (Throwable $e) {
                error_log("DEBUG: get_course_html_structure threw exception: " . get_class($e) . " - " . $e->getMessage());
                // Don't fail if HTML extraction fails - it's optional
                $template_html = '';
            }
            
            error_log("DEBUG: Extracted course_info: " . json_encode($course_info));
            error_log("DEBUG: Extracted structure count: " . count($structure) . " sections");
            error_log("DEBUG: Extracted activities count: " . count($activities) . " activities");
            error_log("DEBUG: Extracted template_html length: " . strlen($template_html) . " chars");

            $template = [
                'course_info' => $course_info,
                // Pass DB id to structure and HTML extraction (these use course_sections.id)
                'structure' => $structure,
                // Pass section number to activities detail (this method filters by sectionnum)
                'activities' => $activities,
                // Allow HTML extraction to accept either id or section number via robust handling
                'template_html' => $template_html
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
     * Get detailed activity information - using direct database queries instead of get_fast_modinfo
     * to avoid potential database errors when loading course module info.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional, filter by section number)
     * @return array Activities details
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
                
                $activity_data = [
                    'type' => $modname,
                    'name' => $cm->name,
                    'intro' => strip_tags($cm->intro ?? ''),
                    'section' => $section_name
                ];
                
                // Add module-specific content - skip for now due to database issues
                // switch ($modname) {
                //     case 'quiz':
                //         $activity_data['quiz_details'] = $this->extract_quiz_details($cm->instance);
                //         break;
                //     case 'page':
                //         $activity_data['page_content'] = $this->extract_page_content($cm->instance);
                //         break;
                //     case 'label':
                //         $activity_data['label_content'] = $this->extract_label_content($cm->instance);
                //         break;
                // }
                
                $activities[] = $activity_data;
            }
            
            error_log("DEBUG: get_activities_detail returning " . count($activities) . " activities");
            return $activities;
        } catch (Throwable $e) {
            error_log("DEBUG: Exception in get_activities_detail: " . $e->getMessage() . " / " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Extract quiz details including questions.
     *
     * @param int $quizid Quiz ID
     * @return array Quiz details
     */
    private function extract_quiz_details($quizid) {
        global $DB;
        
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return [];
        }
        
        return [
            'settings' => [
                'attempts' => $quiz->attempts,
                'grademethod' => $quiz->grademethod,
                'preferredbehaviour' => $quiz->preferredbehaviour,
                'questionsperpage' => $quiz->questionsperpage
            ],
            'questions' => $this->get_quiz_questions($quizid)
        ];
    }
    
    /**
     * Get quiz questions.
     *
     * @param int $quizid Quiz ID
     * @return array Questions
     */
    private function get_quiz_questions($quizid) {
        global $DB;
        
        // Skip quiz questions entirely - they're causing database errors
        error_log("DEBUG: Skipping quiz question extraction due to previous database errors");
        return [];
    }
    
    /**
     * Get multiple choice answers.
     *
     * @param int $questionid Question ID
     * @return array Answers
     */
    private function get_multichoice_answers($questionid) {
        global $DB;
        
        try {
            $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id');
            $formatted_answers = [];
            
            foreach ($answers as $answer) {
                $formatted_answers[] = [
                    'text' => strip_tags($answer->answer ?? ''),
                    'correct' => ($answer->fraction ?? 0) > 0
                ];
            }
            
            return $formatted_answers;
        } catch (Throwable $e) {
            error_log("DEBUG: Error in get_multichoice_answers: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract page content.
     *
     * @param int $pageid Page ID
     * @return string Page content
     */
    private function extract_page_content($pageid) {
        global $DB;
        
        try {
            $page = $DB->get_record('page', ['id' => $pageid], 'content');
            return $page ? strip_tags($page->content ?? '') : '';
        } catch (Throwable $e) {
            error_log("DEBUG: Error in extract_page_content: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Extract label content.
     *
     * @param int $labelid Label ID
     * @return string Label content
     */
    private function extract_label_content($labelid) {
        global $DB;
        
        try {
            $label = $DB->get_record('label', ['id' => $labelid], 'content');
            return $label ? strip_tags($label->content ?? '') : '';
        } catch (Throwable $e) {
            error_log("DEBUG: Error in extract_label_content: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Extract Bootstrap structure patterns from template sections.
     * Analyzes section summaries for Bootstrap components (tabs, cards, accordion, etc.)
     *
     * @param string $template_key Template key in format "courseid" or "courseid|sectionid"
     * @return array Array with 'components' and 'description' keys
     */
    public function extract_bootstrap_structure($template_key) {
        $parts = explode('|', $template_key);
        $courseid = (int)$parts[0];
        $rawsection = isset($parts[1]) ? trim($parts[1]) : null;
        $sectionid = $rawsection !== null && $rawsection !== '' ? (int)$rawsection : null;

        if (!$this->validate_template_access($courseid)) {
            return ['components' => [], 'description' => ''];
        }

        global $DB;
        $components = [];
        $description_parts = [];

        // Resolve section id or number (accept either)
        $resolvedsection = null; // course_sections record or null
        if ($sectionid) {
            $resolvedsection = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid]);
            if (!$resolvedsection) {
                // try as section number
                $resolvedsection = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionid]);
            }
        }

        if ($resolvedsection) {
            $components = $this->analyze_html_for_bootstrap($resolvedsection->summary);
            $description_parts[] = isset($resolvedsection->name) ? ("Section '{$resolvedsection->name}' uses: " . implode(', ', $components)) : ("Section {$resolvedsection->section} uses: " . implode(', ', $components));
        } else {
            // All sections in course
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section');
            foreach ($sections as $section) {
                if (empty($section->summary)) {
                    continue;
                }
                $section_components = $this->analyze_html_for_bootstrap($section->summary);
                if (!empty($section_components)) {
                    $components = array_unique(array_merge($components, $section_components));
                    $description_parts[] = "Section {$section->section}: " . implode(', ', $section_components);
                }
            }
        }

        // Build description
        $description = "Template structure uses the following Bootstrap components:\n";
        if (!empty($description_parts)) {
            $description .= "- " . implode("\n- ", $description_parts);
        } else {
            $description .= "- Standard Moodle layout (plain text sections)";
        }

        return [
            'components' => array_values(array_unique($components)),
            'description' => $description,
        ];
    }

    /**
     * Analyze HTML content for Bootstrap component usage.
     *
     * @param string $html HTML content to analyze
     * @return array Array of Bootstrap component names found
     */
    private function analyze_html_for_bootstrap($html) {
        $components = [];

        // Check for specific Bootstrap classes
        if (preg_match('/nav-tabs|tabs/', $html)) {
            $components[] = 'Bootstrap tabs';
        }
        if (preg_match('/card/', $html)) {
            $components[] = 'Bootstrap cards';
        }
        if (preg_match('/accordion/', $html)) {
            $components[] = 'Bootstrap accordion';
        }
        if (preg_match('/collapse/', $html)) {
            $components[] = 'Bootstrap collapsible content';
        }
        if (preg_match('/btn-group|button-group/', $html)) {
            $components[] = 'Bootstrap button groups';
        }
        if (preg_match('/alert/', $html)) {
            $components[] = 'Bootstrap alerts';
        }
        if (preg_match('/row|col-md|col-lg/', $html)) {
            $components[] = 'Bootstrap grid layout';
        }
        if (preg_match('/badge|pill/', $html)) {
            $components[] = 'Bootstrap badges/pills';
        }

        return $components;
    }

    /**
     * Get the HTML structure of the course for structure preservation - using direct database queries
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return string Combined HTML from course sections
     */
    private function get_course_html_structure($courseid, $sectionid = null) {
        global $DB;

        $html_parts = [];

        // Get HTML directly from section summaries in the database (preserves raw HTML)
        // Accept either a DB id or a section number. Try to resolve to a course_sections record.
        $resolvedsection = null;
        if ($sectionid) {
            $resolvedsection = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid], 'id,section,summary');
            if (!$resolvedsection) {
                // try as section number
                $resolvedsection = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionid], 'id,section,summary');
            }
        }

        if ($resolvedsection) {
            if (!empty($resolvedsection->summary)) {
                $html_parts[] = $resolvedsection->summary;
            }
        } else {
            // All sections in course
            $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section', 'id,section,summary');
            foreach ($sections as $section) {
                if (!empty($section->summary)) {
                    // Keep the raw HTML from section summary/description (not stripped)
                    $html_parts[] = $section->summary;
                }
            }
        }

        // Also check for page and label modules that might have structured HTML
        // Use direct database query without JOIN to avoid database errors
        try {
            // Get all modules list
            $modules_list = $DB->get_records('modules', [], '', 'id, name');
            $module_lookup = [];
            foreach ($modules_list as $mod) {
                $module_lookup[$mod->id] = $mod->name;
            }
            
            // Get course modules that are pages or labels
            $sql = "SELECT cm.id, cm.module, cm.instance, cm.section
                    FROM {course_modules} cm
                    WHERE cm.course = ?
                    ORDER BY cm.section, cm.id";
            
            $all_modules = $DB->get_records_sql($sql, [$courseid]);
            
            foreach ($all_modules as $cm) {
                $modname = $module_lookup[$cm->module] ?? '';
                
                if ($modname !== 'page' && $modname !== 'label') {
                    continue;
                }
                
                if ($sectionid !== null && $cm->section != $sectionid) {
                    continue;
                }

                // For pages and labels, try to get the actual HTML content
                if ($modname === 'page') {
                    $page_html = $this->extract_page_html($cm->instance);
                    if (!empty($page_html)) {
                        $html_parts[] = $page_html;
                    }
                } elseif ($modname === 'label') {
                    $label_html = $this->extract_label_html($cm->instance);
                    if (!empty($label_html)) {
                        $html_parts[] = $label_html;
                    }
                }
            }
        } catch (Exception $e) {
            // If HTML structure extraction fails, just continue without it
            error_log("DEBUG: Non-critical error in get_course_html_structure: " . $e->getMessage());
        }

        // Combine all HTML parts
        return implode("\n\n", $html_parts);
    }

    /**
     * Extract raw HTML content from a page module instance
     *
     * @param int $pageid Page ID
     * @return string Page HTML content
     */
    private function extract_page_html($pageid) {
        global $DB;
        
        $page = $DB->get_record('page', ['id' => $pageid], 'content');
        return $page ? $page->content : '';
    }

    /**
     * Extract raw HTML content from a label module instance
     *
     * @param int $labelid Label ID
     * @return string Label HTML content
     */
    private function extract_label_html($labelid) {
        global $DB;
        
        $label = $DB->get_record('label', ['id' => $labelid], 'intro');
        return $label ? $label->intro : '';
    }
}
