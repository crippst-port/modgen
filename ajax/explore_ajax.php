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
 * AJAX endpoint for fetching module exploration insights.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../classes/local/ai_service.php');

// Get course ID
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('moodle/course:view', $context);

// Check if feature is enabled
if (!get_config('aiplacement_modgen', 'enable_exploration')) {
    http_response_code(403);
    echo json_encode(['error' => get_string('explorationdisabled', 'aiplacement_modgen')]);
    die();
}

header('Content-Type: application/json; charset=utf-8');

try {
    // Generate insights
    $insights = generate_module_insights($course);
    
    if ($insights === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => get_string('exploreerror', 'aiplacement_modgen')]);
        die();
    }
    
    // Generate learning types chart data
    $chartdata = generate_learning_types_chart($insights['moduledata']);
    
    // Prepare template data
    $templatedata = [
        'pedagogical' => $insights['insights']['pedagogical'],
        'learning_types' => $insights['insights']['learning_types'],
        'activities' => $insights['insights']['activities'],
        'improvements' => $insights['insights']['improvements'],
        'chart_data' => $chartdata,
        'debug' => [
            'moduledata' => $insights['moduledata'],
            'debug_json' => json_encode($insights['moduledata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ],
    ];
    
    // Return the data for client-side template rendering
        echo json_encode([
            'success' => true,
            'data' => $templatedata,
        ]);
        die();
} catch (Throwable $e) {
    error_log('AJAX exploration error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error generating insights: ' . $e->getMessage(),
    ]);
    die();
}

/**
 * Generate AI insights about the module.
 *
 * @param stdClass $course The course object
 * @return array|false Array with keys 'pedagogical', 'learning_types', 'activities', or false on error
 */
function generate_module_insights(stdClass $course) {
    try {
        // Get all course sections and activities
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_sections();
        
        // Build module structure data
        $moduledata = [
            'course_name' => $course->fullname,
            'sections' => [],
        ];
        
        // Only iterate section numbers that actually have activities (from get_sections keys)
        // This excludes orphaned/deleted sections from the database
        foreach (array_keys($sections) as $sectionnum) {
            $section = $modinfo->get_section_info($sectionnum);
            if (!$section) {
                continue;
            }
            
            // Skip invisible sections
            if (!$section->visible) {
                continue;
            }
            
            // Skip sections hidden by availability restrictions (conditional access)
            if (!$section->uservisible) {
                continue;
            }
            
            $sectiondata = [
                'title' => ($sectionnum == 0) ? 'General' : ($section->name ?? 'Section ' . $sectionnum),
                'summary' => $section->summary ?? '',
                'activities' => [],
            ];
            
            // Add activities from this section
            if (!empty($sections[$sectionnum])) {
                foreach ($sections[$sectionnum] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    
                    // Skip invisible or non-visible items
                    if (!isset($cm) || !$cm->visible || !$cm->uservisible) {
                        continue;
                    }
                    
                    $sectiondata['activities'][] = [
                        'name' => $cm->name,
                        'modname' => $cm->modname,
                    ];
                }
            }
            
            // Add section
            $moduledata['sections'][] = $sectiondata;
        }
        
        // Generate insights using AI
        $pedagogical = generate_pedagogical_analysis($moduledata);
        $learning_types = generate_learning_types_analysis($moduledata);
        $activities = generate_activities_summary($moduledata);
        $improvements = generate_improvement_suggestions($moduledata, $pedagogical, $learning_types);
        
        return [
            'insights' => [
                'pedagogical' => $pedagogical,
                'learning_types' => $learning_types,
                'activities' => $activities,
                'improvements' => $improvements,
            ],
            'moduledata' => $moduledata,
        ];
    } catch (Exception $e) {
        error_log('Module exploration error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate pedagogical analysis using AI.
 *
 * @param array $moduledata Module structure
 * @return string Analysis text
 */
function generate_pedagogical_analysis(array $moduledata): string {
    $prompt = "Analyze the following Moodle course structure and provide a pedagogical analysis.\n" .
        "Focus on:\n" .
        "1. Overall pedagogical approach and teaching strategy\n" .
        "2. Alignment of activities with learning objectives\n" .
        "3. Balance between different types of learning activities\n" .
        "4. Use of formative and summative assessment\n\n" .
        "Course data:\n" . json_encode($moduledata, JSON_PRETTY_PRINT);
    
    return get_ai_analysis($prompt);
}

/**
 * Generate learning types analysis using Laurillard's framework.
 *
 * @param array $moduledata Module structure
 * @return string Analysis text
 */
function generate_learning_types_analysis(array $moduledata): string {
    $prompt = "Analyze the following Moodle course and identify how it incorporates Laurillard's Learning Types:\n" .
        "1. Narrative/Expository (lectures, explanations)\n" .
        "2. Dialogic (discussions, interactions)\n" .
        "3. Adaptive (feedback, personalization)\n" .
        "4. Interactive (simulations, scenarios)\n" .
        "5. Productive (creation, problem-solving)\n\n" .
        "Provide a breakdown of which learning types are present in the course structure:\n\n" .
        json_encode($moduledata, JSON_PRETTY_PRINT);
    
    return get_ai_analysis($prompt);
}

/**
 * Generate activities summary.
 *
 * @param array $moduledata Module structure
 * @return string Summary text
 */
function generate_activities_summary(array $moduledata): string {
    $prompt = "Summarize the activities in the following course structure.\n" .
        "Provide:\n" .
        "1. Count and types of activities by category\n" .
        "2. Distribution across course sections\n" .
        "3. Balance and variety of activity types\n" .
        "4. Suggestions for improvement (if any)\n\n" .
        json_encode($moduledata, JSON_PRETTY_PRINT);
    
    return get_ai_analysis($prompt);
}

/**
 * Generate improvement suggestions based on pedagogical and learning types analysis.
 *
 * @param array $moduledata Module structure
 * @param string $pedagogical Pedagogical analysis feedback
 * @param string $learning_types Learning types analysis feedback
 * @return string Improvement suggestions
 */
function generate_improvement_suggestions(array $moduledata, string $pedagogical, string $learning_types): string {
    $prompt = "Based on the following course analysis and feedback, provide concrete, actionable suggestions to improve the learning experience:\n\n" .
        "PEDAGOGICAL FEEDBACK:\n" .
        $pedagogical . "\n\n" .
        "LEARNING TYPES ANALYSIS:\n" .
        $learning_types . "\n\n" .
        "COURSE STRUCTURE:\n" .
        json_encode($moduledata, JSON_PRETTY_PRINT) . "\n\n" .
        "Please provide specific, critical, prioritized recommendations such as:\n" .
        "1. Which learning types are underrepresented and should be added\n" .
        "2. Which sections could benefit from additional activities\n" .
        "3. Specific types of activities to introduce (e.g., discussions, quizzes, collaborative tasks)\n" .
        "4. How to better align activities with learning objectives\n" .
        "5. Suggestions for improving the pedagogical balance and effectiveness";
    
    return get_ai_analysis($prompt);
}

/**
 * Generate learning types chart data mapping activities to Laurillard's learning types.
 *
 * @param array $moduledata Module structure with activities
 * @return array Chart data for pie chart
 */
function generate_learning_types_chart(array $moduledata): array {
    // Map activity types to Laurillard's learning types
    $activity_learning_type_map = [
        // Narrative/Expository - lectures, explanations, resources
        'page' => 'Narrative',
        'book' => 'Narrative',
        'resource' => 'Narrative',
        'label' => 'Narrative',
        'url' => 'Narrative',
        
        // Dialogic - discussions, conversations
        'forum' => 'Dialogic',
        'chat' => 'Dialogic',
        
        // Adaptive - feedback, adaptive learning
        'lesson' => 'Adaptive',
        'feedback' => 'Adaptive',
        
        // Interactive - simulations, scenarios, interactions
        'choice' => 'Interactive',
        'survey' => 'Interactive',
        'workshop' => 'Interactive',
        'hsuforum' => 'Interactive',
        
        // Productive - creation, problem-solving, assignments
        'assign' => 'Productive',
        'quiz' => 'Productive',
        'scorm' => 'Productive',
        'bigbluebuttonbn' => 'Productive',
    ];
    
    $learning_type_counts = [
        'Narrative' => 0,
        'Dialogic' => 0,
        'Adaptive' => 0,
        'Interactive' => 0,
        'Productive' => 0,
    ];
    
    // Count activities by learning type
    if (!empty($moduledata['sections'])) {
        foreach ($moduledata['sections'] as $section) {
            if (!empty($section['activities'])) {
                foreach ($section['activities'] as $activity) {
                    $modname = strtolower($activity['modname']);
                    $learning_type = $activity_learning_type_map[$modname] ?? 'Productive'; // Default to Productive
                    $learning_type_counts[$learning_type]++;
                }
            }
        }
    }
    
    // Convert to chart.js format
    $colors = [
        'Narrative' => 'rgba(66, 139, 202, 0.8)',      // Blue
        'Dialogic' => 'rgba(40, 167, 69, 0.8)',        // Green
        'Adaptive' => 'rgba(255, 193, 7, 0.8)',        // Yellow
        'Interactive' => 'rgba(255, 152, 0, 0.8)',     // Orange
        'Productive' => 'rgba(220, 53, 69, 0.8)',      // Red
    ];
    
    return [
        'labels' => array_keys($learning_type_counts),
        'data' => array_values($learning_type_counts),
        'colors' => array_map(function($type) use ($colors) {
            return $colors[$type];
        }, array_keys($learning_type_counts)),
        'hasActivities' => array_sum($learning_type_counts) > 0,
    ];
}

/**
 * Get AI analysis for a given prompt.
 *
 * @param string $prompt The analysis prompt
 * @return string Plain text response
 */
function get_ai_analysis(string $prompt): string {
    try {
        if (!class_exists('aiplacement_modgen\\ai_service')) {
            error_log('ai_service class not found');
            return 'AI service not available.';
        }
        
        $analysis = \aiplacement_modgen\ai_service::analyze_module($prompt);
        
        if (empty($analysis)) {
            return 'Analysis unavailable at this time.';
        }
        
        return $analysis;
    } catch (Throwable $e) {
        error_log('AI analysis error: ' . $e->getMessage());
        return 'Unable to generate analysis: ' . $e->getMessage();
    }
}
