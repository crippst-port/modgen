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
        $parts = explode('|', $template_key);
        $courseid = (int)$parts[0];
        $sectionid = isset($parts[1]) ? (int)$parts[1] : null;
        
        if (!$this->validate_template_access($courseid)) {
            throw new \moodle_exception('curriculumnotfound', 'aiplacement_modgen');
        }
        
        $template = [
            'course_info' => $this->get_course_info($courseid),
            'structure' => $this->get_course_structure($courseid, $sectionid),
            'activities' => $this->get_activities_detail($courseid, $sectionid),
            'template_html' => $this->get_course_html_structure($courseid, $sectionid)
        ];
        
        return $template;
    }
    
    /**
     * Validate that user has access to the template course.
     *
     * @param int $courseid Course ID
     * @return bool True if accessible
     */
    private function validate_template_access($courseid) {
        global $DB, $USER;
        
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return false;
        }
        
        $context = \context_course::instance($courseid);
        return has_capability('moodle/course:view', $context);
    }
    
    /**
     * Get basic course information.
     *
     * @param int $courseid Course ID
     * @return array Course info
     */
    private function get_course_info($courseid) {
        global $DB;
        
        $course = $DB->get_record('course', ['id' => $courseid], 'fullname,shortname,summary,format');
        return [
            'name' => $course->fullname,
            'format' => $course->format,
            'summary' => strip_tags($course->summary)
        ];
    }
    
    /**
     * Get course structure (sections).
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return array Sections structure
     */
    private function get_course_structure($courseid, $sectionid = null) {
        $modinfo = get_fast_modinfo($courseid);
        $sections = [];
        
        foreach ($modinfo->get_section_info_all() as $section) {
            if ($sectionid && $section->id != $sectionid) {
                continue;
            }
            
            if ($section->uservisible) {
                $sections[] = [
                    'id' => $section->id,
                    'name' => get_section_name($courseid, $section),
                    'summary' => strip_tags($section->summary),
                    'activity_count' => count($modinfo->sections[$section->section] ?? [])
                ];
            }
        }
        
        return $sections;
    }
    
    /**
     * Get detailed activity information.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return array Activities details
     */
    private function get_activities_detail($courseid, $sectionid = null) {
        $modinfo = get_fast_modinfo($courseid);
        $activities = [];
        
        foreach ($modinfo->get_cms() as $cm) {
            if ($sectionid && $cm->sectionnum != $sectionid) {
                continue;
            }
            
            if ($cm->uservisible) {
                $activity_data = [
                    'type' => $cm->modname,
                    'name' => $cm->name,
                    'intro' => strip_tags($cm->content ?? ''),
                    'section' => get_section_name($courseid, $cm->sectionnum)
                ];
                
                // Add module-specific content
                switch ($cm->modname) {
                    case 'quiz':
                        $activity_data['quiz_details'] = $this->extract_quiz_details($cm->instance);
                        break;
                    case 'page':
                        $activity_data['page_content'] = $this->extract_page_content($cm->instance);
                        break;
                    case 'label':
                        $activity_data['label_content'] = $this->extract_label_content($cm->instance);
                        break;
                }
                
                $activities[] = $activity_data;
            }
        }
        
        return $activities;
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
        
        $sql = "SELECT q.id, q.name, q.questiontext, q.qtype
                FROM {question} q
                JOIN {quiz_slots} qs ON q.id = qs.questionid
                WHERE qs.quizid = ?
                ORDER BY qs.slot";
        
        $questions = $DB->get_records_sql($sql, [$quizid]);
        $formatted_questions = [];
        
        foreach ($questions as $question) {
            $question_data = [
                'name' => $question->name,
                'text' => strip_tags($question->questiontext),
                'type' => $question->qtype
            ];
            
            // Add answers for multiple choice questions
            if ($question->qtype === 'multichoice') {
                $question_data['answers'] = $this->get_multichoice_answers($question->id);
            }
            
            $formatted_questions[] = $question_data;
        }
        
        return $formatted_questions;
    }
    
    /**
     * Get multiple choice answers.
     *
     * @param int $questionid Question ID
     * @return array Answers
     */
    private function get_multichoice_answers($questionid) {
        global $DB;
        
        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id');
        $formatted_answers = [];
        
        foreach ($answers as $answer) {
            $formatted_answers[] = [
                'text' => strip_tags($answer->answer),
                'correct' => $answer->fraction > 0
            ];
        }
        
        return $formatted_answers;
    }
    
    /**
     * Extract page content.
     *
     * @param int $pageid Page ID
     * @return string Page content
     */
    private function extract_page_content($pageid) {
        global $DB;
        
        $page = $DB->get_record('page', ['id' => $pageid], 'content');
        return $page ? strip_tags($page->content) : '';
    }
    
    /**
     * Extract label content.
     *
     * @param int $labelid Label ID
     * @return string Label content
     */
    private function extract_label_content($labelid) {
        global $DB;
        
        $label = $DB->get_record('label', ['id' => $labelid], 'intro');
        return $label ? strip_tags($label->intro) : '';
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
        $sectionid = isset($parts[1]) ? (int)$parts[1] : null;

        if (!$this->validate_template_access($courseid)) {
            return ['components' => [], 'description' => ''];
        }

        global $DB;
        $components = [];
        $description_parts = [];

        if ($sectionid) {
            // Specific section
            $section = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid]);
            if ($section) {
                $components = $this->analyze_html_for_bootstrap($section->summary);
                $description_parts[] = "Section '{$section->name}' uses: " . implode(', ', $components);
            }
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
     * Get the HTML structure of the course for structure preservation
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Specific section ID (optional)
     * @return string Combined HTML from course sections
     */
    private function get_course_html_structure($courseid, $sectionid = null) {
        global $DB;

        $html_parts = [];

        // Get HTML directly from section summaries in the database (preserves raw HTML)
        if ($sectionid) {
            // Specific section only
            $section = $DB->get_record('course_sections', ['course' => $courseid, 'id' => $sectionid], 'id,summary');
            if ($section && !empty($section->summary)) {
                $html_parts[] = $section->summary;
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
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            if ($sectionid) {
                // If specific section requested, check if this module's section matches
                $cm_section = $DB->get_record('course_modules', ['id' => $cm->id], 'section');
                if ($cm_section && $cm_section->section != $sectionid) {
                    continue;
                }
            }

            if ($cm->uservisible) {
                // For pages and labels, try to get the actual HTML content
                if ($cm->modname === 'page') {
                    $page_html = $this->extract_page_html($cm->instance);
                    if (!empty($page_html)) {
                        $html_parts[] = $page_html;
                    }
                } elseif ($cm->modname === 'label') {
                    $label_html = $this->extract_label_html($cm->instance);
                    if (!empty($label_html)) {
                        $html_parts[] = $label_html;
                    }
                }
            }
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
