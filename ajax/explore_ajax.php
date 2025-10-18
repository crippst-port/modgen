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
    
    // Generate section activity chart data
    $sectionchartdata = generate_section_activity_chart($insights['moduledata']);
    
    // Debug logging
    error_log('Section chart data: ' . json_encode($sectionchartdata));
    
    // Generate workload analysis
    $workload_analysis = generate_workload_analysis($insights['moduledata']);
    
    // Generate quick summary for card display
    $summary = generate_quick_summary($insights);
    
    // Prepare template data
    $templatedata = [
        'pedagogical' => $insights['insights']['pedagogical'],
        'learning_types' => $insights['insights']['learning_types'],
        'activities' => $insights['insights']['activities'],
        'improvements' => $insights['insights']['improvements'],
        'summary' => $summary,
        'chart_data' => $chartdata,
        'section_chart_data' => $sectionchartdata,
        'workload_analysis' => $workload_analysis,
        'debug' => [
            'moduledata' => $insights['moduledata'],
            'debug_json' => json_encode($insights['moduledata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ],
    ];
    
    // Debug logging
    error_log('Template data keys: ' . implode(', ', array_keys($templatedata)));
    error_log('Complete response: ' . json_encode([
        'success' => true,
        'data' => $templatedata,
    ]) );
    
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
        $improvements = generate_improvement_suggestions($moduledata, $pedagogical, $learning_types);
        
        return [
            'insights' => [
                'pedagogical' => $pedagogical,
                'learning_types' => $learning_types,
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
 * @return array Analysis with heading and paragraphs
 */
function generate_pedagogical_analysis(array $moduledata): array {
    $prompt = "Analyze the following Moodle course structure and provide a pedagogical analysis.\n" .
        "Focus on:\n" .
        "1. Overall pedagogical approach and teaching strategy\n" .
        "2. Alignment of activities with learning objectives\n" .
        "3. Balance between different types of learning activities\n" .
        "4. Use of formative and summative assessment\n\n" .
        "Return ONLY a valid JSON object with this exact structure:\n" .
        "{\"heading\": \"Brief title\", \"paragraphs\": [\"paragraph 1\", \"paragraph 2\"]}\n\n" .
        "Course data:\n" . json_encode($moduledata, JSON_PRETTY_PRINT);
    
    return parse_analysis_json(get_ai_analysis($prompt));
}

/**
 * Parse AI response as JSON and ensure it has the right structure.
 */
function parse_analysis_json(string $response): array {
    try {
        $data = json_decode($response, true);
        if (is_array($data)) {
            return $data;
        }
    } catch (Exception $e) {
        error_log('Failed to parse JSON: ' . $e->getMessage());
    }
    
    // Fallback if JSON parsing fails
    return [
        'heading' => 'Analysis',
        'paragraphs' => [$response]
    ];
}

/**
 * Generate learning types analysis using Laurillard's framework.
 *
 * @param array $moduledata Module structure
 * @return array Analysis with heading and paragraphs
 */
function generate_learning_types_analysis(array $moduledata): array {
    $prompt = "Analyze the following Moodle course and identify how it incorporates Laurillard's Learning Types:\n" .
        "1. Narrative/Expository (lectures, explanations)\n" .
        "2. Dialogic (discussions, interactions)\n" .
        "3. Adaptive (feedback, personalization)\n" .
        "4. Interactive (simulations, scenarios)\n" .
        "5. Productive (creation, problem-solving)\n\n" .
        "Return ONLY a valid JSON object with this exact structure:\n" .
        "{\"heading\": \"Learning types analysis\", \"paragraphs\": [\"paragraph 1\", \"paragraph 2\"]}\n\n" .
        json_encode($moduledata, JSON_PRETTY_PRINT);
    
    return parse_analysis_json(get_ai_analysis($prompt));
}

/**
 * Generate activities summary.
 *
 * @param array $moduledata Module structure
 * @return array Summary with activities list and paragraphs
 */

/**
 * Generate improvement suggestions based on pedagogical and learning types analysis.
 *
 * @param array $moduledata Module structure
 * @param array $pedagogical Pedagogical analysis feedback
 * @param array $learning_types Learning types analysis feedback
 * @return array Improvement suggestions with summary and numbered list
 */
function generate_improvement_suggestions(array $moduledata, array $pedagogical, array $learning_types): array {
    $ped_text = implode(' ', $pedagogical['paragraphs'] ?? []);
    $lt_text = implode(' ', $learning_types['paragraphs'] ?? []);
    
    $prompt = "Based on the following course analysis and feedback, provide concrete, actionable suggestions to improve the learning experience.\n\n" .
        "PEDAGOGICAL FEEDBACK:\n" . $ped_text . "\n\n" .
        "LEARNING TYPES ANALYSIS:\n" . $lt_text . "\n\n" .
        "COURSE STRUCTURE:\n" . json_encode($moduledata, JSON_PRETTY_PRINT) . "\n\n" .
        "Return ONLY a valid JSON object with this exact structure:\n" .
        "{" .
        "\"summary\": \"Brief lead paragraph\", " .
        "\"suggestions\": [\"First suggestion\", \"Second suggestion\", ...]" .
        "}\n\n";
    
    return parse_improvement_json(get_ai_analysis($prompt));
}

/**
 * Parse improvement suggestions JSON.
 */
function parse_improvement_json(string $response): array {
    try {
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['summary']) && isset($data['suggestions'])) {
            return $data;
        }
    } catch (Exception $e) {
        error_log('Failed to parse improvements JSON: ' . $e->getMessage());
    }
    
    // Fallback
    return [
        'summary' => 'Recommendations for improvement:',
        'suggestions' => [$response]
    ];
}

/**
 * Generate a quick summary with headline positives and key improvement points.
 * Perfect for card-style display.
 *
 * @param array $insights The insights data containing pedagogical, learning_types, and improvements
 * @return array Summary with 'positives' and 'improvements' arrays
 */
function generate_quick_summary(array $insights): array {
    $ped_text = implode(' ', $insights['insights']['pedagogical']['paragraphs'] ?? []);
    $lt_text = implode(' ', $insights['insights']['learning_types']['paragraphs'] ?? []);
    $imp_text = implode(' ', $insights['insights']['improvements']['suggestions'] ?? []);
    
    $prompt = "Create a concise executive summary of a Moodle course based on these analyses. Return ONLY valid JSON.\n\n" .
        "PEDAGOGICAL ANALYSIS:\n" . $ped_text . "\n\n" .
        "LEARNING TYPES ANALYSIS:\n" . $lt_text . "\n\n" .
        "IMPROVEMENT SUGGESTIONS:\n" . $imp_text . "\n\n" .
        "Return ONLY a valid JSON object with this exact structure:\n" .
        "{" .
        "\"positives\": [\"Positive strength 1\", \"Positive strength 2\", \"Positive strength 3\"], " .
        "\"improvements\": [\"Key improvement area 1\", \"Key improvement area 2\"]" .
        "}\n\n";
    
    return parse_summary_json(get_ai_analysis($prompt));
}

/**
 * Parse quick summary JSON.
 */
function parse_summary_json(string $response): array {
    try {
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['positives']) && isset($data['improvements'])) {
            return $data;
        }
    } catch (Exception $e) {
        error_log('Failed to parse summary JSON: ' . $e->getMessage());
    }
    
    // Fallback
    return [
        'positives' => ['Module is structured with multiple activity types'],
        'improvements' => ['Review and enhance course content delivery']
    ];
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
 * Generate section/topic activity count chart data.
 *
 * @param array $moduledata The module structure data
 * @return array Chart data in chart.js format
 */
function generate_section_activity_chart(array $moduledata): array {
    $section_counts = [];
    
    // Count activities per section
    if (!empty($moduledata['sections'])) {
        foreach ($moduledata['sections'] as $section) {
            $section_name = $section['title'] ?? 'Untitled Section';
            $activity_count = count($section['activities'] ?? []);
            $section_counts[$section_name] = $activity_count;
        }
    }
    
    // Color gradient for bars
    $colors = 'rgba(52, 152, 219, 0.8)'; // Professional blue
    $borderColor = 'rgba(52, 152, 219, 1)';
    
    return [
        'labels' => array_keys($section_counts),
        'data' => array_values($section_counts),
        'backgroundColor' => $colors,
        'borderColor' => $borderColor,
        'hasActivities' => count($section_counts) > 0,
    ];
}

/**
 * Generate AI analysis of student workload based on activity distribution per section.
 *
 * @param array $moduledata The module structure data
 * @return array Analysis with heading and paragraphs
 */
function generate_workload_analysis(array $moduledata): array {
    // Build activity distribution summary
    $section_activities = [];
    $activity_type_counts = [];
    
    if (!empty($moduledata['sections'])) {
        foreach ($moduledata['sections'] as $section) {
            $section_name = $section['title'] ?? 'Untitled Section';
            $activities = $section['activities'] ?? [];
            
            if (!empty($activities)) {
                $activity_names = [];
                foreach ($activities as $activity) {
                    $activity_names[] = $activity['name'];
                    $modname = strtolower($activity['modname']);
                    $activity_type_counts[$modname] = ($activity_type_counts[$modname] ?? 0) + 1;
                }
                $section_activities[$section_name] = [
                    'count' => count($activities),
                    'names' => $activity_names,
                ];
            }
        }
    }
    
    // Build activity type summary
    $activity_descriptions = [
        'assign' => 'Assignment',
        'quiz' => 'Quiz',
        'forum' => 'Forum discussion',
        'lesson' => 'Lesson/interactive content',
        'book' => 'Book/reading material',
        'page' => 'Page/content page',
        'resource' => 'Resource file',
        'chat' => 'Chat session',
        'choice' => 'Choice/poll',
        'feedback' => 'Feedback/survey',
        'workshop' => 'Workshop',
        'label' => 'Label/text',
        'url' => 'External URL',
        'scorm' => 'SCORM package',
        'bigbluebuttonbn' => 'BigBlueButton session',
    ];
    
    $type_summary = [];
    foreach ($activity_type_counts as $type => $count) {
        $description = $activity_descriptions[$type] ?? ucfirst($type);
        $type_summary[] = "$count x $description";
    }
    
    // Create prompt for AI analysis
    $prompt = "Analyze the student workload distribution for an online module. " .
        "Acknowledge that actual workload depends on many unknown factors like assignment complexity, " .
        "quiz difficulty, discussion expectations, and video length. However, provide pedagogical insights " .
        "based on the activity distribution pattern below.\n\n" .
        "ACTIVITY DISTRIBUTION BY WEEK/TOPIC:\n";
    
    foreach ($section_activities as $section => $data) {
        $prompt .= "\n$section: " . $data['count'] . " activities\n";
        $prompt .= "  - " . implode("\n  - ", array_slice($data['names'], 0, 10));
        if (count($data['names']) > 10) {
            $prompt .= "\n  - ... and " . (count($data['names']) - 10) . " more";
        }
    }
    
    $prompt .= "\n\nACTIVITY TYPES USED:\n" . implode("\n", $type_summary) . "\n\n" .
        "Based on this distribution, provide a brief pedagogical analysis of student workload. Include:\n" .
        "1. Observations about workload distribution consistency across weeks/topics\n" .
        "2. Balance or imbalance in activity types (e.g., heavy on quizzes vs assignments)\n" .
        "3. Pedagogical implications for learner engagement and retention\n" .
        "4. Any notable patterns that might affect student experience\n\n" .
        "Keep the analysis concise (2-3 paragraphs) and practical.";
    
    $analysis_text = get_ai_analysis($prompt);
    
    // Parse response into structured format
    $paragraphs = array_filter(array_map('trim', explode("\n\n", $analysis_text)));
    
    return [
        'heading' => 'Student Workload Analysis',
        'paragraphs' => $paragraphs,
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
            return json_encode(['error' => 'AI service not available.']);
        }
        
        error_log('Calling AI service with prompt length: ' . strlen($prompt));
        
        $analysis = \aiplacement_modgen\ai_service::analyze_module($prompt);
        
        error_log('AI service returned: ' . (empty($analysis) ? 'EMPTY' : 'Response length: ' . strlen($analysis)));
        
        if (empty($analysis)) {
            error_log('Analysis is empty - returning error JSON');
            return json_encode(['error' => 'Analysis unavailable at this time.']);
        }
        
        return $analysis;
    } catch (Throwable $e) {
        error_log('AI analysis error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return json_encode(['error' => 'Unable to generate analysis: ' . $e->getMessage()]);
    }
}
