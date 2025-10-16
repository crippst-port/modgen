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
                $courseid = trim($parts[1]);
                $sectionid = isset($parts[2]) ? trim($parts[2]) : null;
                
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
            'activities' => $this->get_activities_detail($courseid, $sectionid)
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
                    'intro' => strip_tags($cm->get_content()),
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
}