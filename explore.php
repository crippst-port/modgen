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
 * Module exploration page - displays pedagogical insights and learning analysis.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/classes/local/ai_service.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('moodle/course:view', $context);

// Load CSS early before any page setup
global $PAGE;
$PAGE->requires->css('/ai/placement/modgen/styles.css');

// Check if feature is enabled
if (!get_config('aiplacement_modgen', 'enable_exploration')) {
    throw new moodle_exception('explorationdisabled', 'aiplacement_modgen');
}

// Set up page
$PAGE->set_course($course);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/ai/placement/modgen/explore.php', ['id' => $courseid]));
$PAGE->set_title(get_string('exploreheading', 'aiplacement_modgen'));
$PAGE->set_heading($course->fullname . ' - ' . get_string('exploreheading', 'aiplacement_modgen'));

echo $OUTPUT->header();

// Generate module data (single source of truth)
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_sections();
$moduledata = build_moduledata($course, $modinfo, $sections);

// Calculate activity counts from moduledata
$activity_counts = [];
$total_activities = 0;
foreach ($moduledata['sections'] as $section) {
    if (!empty($section['activities'])) {
        foreach ($section['activities'] as $activity) {
            $modname = $activity['modname'];
            if (!isset($activity_counts[$modname])) {
                $activity_counts[$modname] = 0;
            }
            $activity_counts[$modname]++;
            $total_activities++;
        }
    }
}

// Sort by count descending
arsort($activity_counts);

// Format for template - include learning type for each activity type
$activity_summary = [];
foreach ($activity_counts as $modname => $count) {
    $learning_type = get_activity_learning_type($modname);
    $activity_summary[] = [
        'name' => ucfirst($modname),
        'count' => $count,
        'learning_type' => $learning_type,
    ];
}

// Generate learning types chart data
$chartdata = generate_learning_types_chart_data($moduledata);

// Prepare template data for loading state
$templatedata = [
    'loadingmessage' => get_string('exploreloading', 'aiplacement_modgen'),
    'activity_summary' => $activity_summary,
    'total_activities' => $total_activities,
    'chart_data' => $chartdata,
];

// Render template with loading state
echo $OUTPUT->render_from_template('aiplacement_modgen/explore', $templatedata);

// Load the AJAX module to fetch insights
$PAGE->requires->js_call_amd('aiplacement_modgen/explore', 'init', [$courseid, $chartdata, $activity_summary]);

echo $OUTPUT->footer();

/**
 * Get the learning type for an activity module type.
 * Single source of truth for activity type to learning type mapping.
 *
 * @param string $modname The module name (e.g., 'assign', 'quiz')
 * @return string The learning type (Narrative, Dialogic, Adaptive, Interactive, or Productive)
 */
function get_activity_learning_type(string $modname): string {
    $modname = strtolower($modname);
    
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
    
    return $activity_learning_type_map[$modname] ?? 'Productive';
}

/**
 * Build module data structure from course sections and activities.
 *
 * @param stdClass $course The course object
 * @param course_modinfo $modinfo Fast modinfo
 * @param array $sections Sections array from get_sections()
 * @return array Module data structure
 */
function build_moduledata(stdClass $course, course_modinfo $modinfo, array $sections): array {
    $moduledata = [
        'course_name' => $course->fullname,
        'sections' => [],
    ];
    
    foreach (array_keys($sections) as $sectionnum) {
        $section = $modinfo->get_section_info($sectionnum);
        if (!$section || !$section->visible || !$section->uservisible) {
            continue;
        }
        
        $sectiondata = [
            'title' => ($sectionnum == 0) ? 'General' : ($section->name ?? 'Section ' . $sectionnum),
            'summary' => $section->summary ?? '',
            'activities' => [],
        ];
        
        if (!empty($sections[$sectionnum])) {
            foreach ($sections[$sectionnum] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                
                if (!isset($cm) || !$cm->visible || !$cm->uservisible) {
                    continue;
                }
                
                $sectiondata['activities'][] = [
                    'name' => $cm->name,
                    'modname' => $cm->modname,
                ];
            }
        }
        
        $moduledata['sections'][] = $sectiondata;
    }
    
    return $moduledata;
}

/**
 * Generate learning types chart data.
 *
 * @param array $moduledata Module structure with activities
 * @return array Chart data for pie chart
 */
function generate_learning_types_chart_data(array $moduledata): array {
    $learning_type_counts = [
        'Narrative' => 0,
        'Dialogic' => 0,
        'Adaptive' => 0,
        'Interactive' => 0,
        'Productive' => 0,
    ];
    
    // Count activities by learning type using single source of truth
    if (!empty($moduledata['sections'])) {
        foreach ($moduledata['sections'] as $section) {
            if (!empty($section['activities'])) {
                foreach ($section['activities'] as $activity) {
                    $learning_type = get_activity_learning_type($activity['modname']);
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

