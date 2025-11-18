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
 * Plugin callbacks and navigation hooks.
 *
 * @package     aiplacement_modgen
 * @category    lib
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extends course navigation to load Module Assistant toolbar.
 *
 * Note: This hook loads the toolbar AMD module when in edit mode.
 * The toolbar is rendered via Fragment API for proper form initialization.
 *
 * @param navigation_node $navigation Navigation node to extend
 * @param stdClass $course Course object
 * @param context_course $context Course context
 * @return void
 */
function aiplacement_modgen_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    global $PAGE;

    if (!has_capability('moodle/course:update', $context)) {
        return;
    }

    // Navigation bar - only show in edit mode
    if ($PAGE->user_is_editing()) {
        // Show generator to anyone who can edit the course
        $showgenerator = true;
        
        // Only show explore if BOTH admin settings are enabled
        $ai_generation_enabled = !empty(get_config('aiplacement_modgen', 'enable_ai'));
        $explore_enabled = !empty(get_config('aiplacement_modgen', 'enable_exploration'));
        $showexplore = $ai_generation_enabled && $explore_enabled;
        
        // Only render nav bar if at least one tool is available
        if ($showgenerator || $showexplore) {
            // Load CSS
            $PAGE->requires->css('/ai/placement/modgen/styles.css');
            
            // Initialize toolbar via AMD module using Fragment API
            $PAGE->requires->js_call_amd('aiplacement_modgen/course_toolbar', 'init', [[
                'courseid' => $course->id,
                'contextid' => $context->id,
                'showgenerator' => $showgenerator,
                'showexplore' => $showexplore,
            ]]);
        }
    }

    // Module exploration - also add to course navigation menu when BOTH admin settings are enabled
    $ai_generation_enabled = !empty(get_config('aiplacement_modgen', 'enable_ai'));
    $explore_enabled = !empty(get_config('aiplacement_modgen', 'enable_exploration'));
    
    if ($ai_generation_enabled && $explore_enabled) {
        $exploreurl = new moodle_url('/ai/placement/modgen/explore.php', ['id' => $course->id]);
        $navigation->add(
            get_string('exploremenuitem', 'aiplacement_modgen'),
            $exploreurl,
            navigation_node::TYPE_SETTING,
            null,
            'aiplacement_modgen_explore'
        );
    }

    // Add a direct generator page link into the course navigation
    $genurl = new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $course->id, 'standalone' => 1]);
    $navigation->add(
        get_string('launchgenerator', 'aiplacement_modgen'),
        $genurl,
        navigation_node::TYPE_SETTING,
        null,
        'aiplacement_modgen_generator'
    );
}

/**
 * Fragment callback to render the course toolbar.
 *
 * Renders the Module Assistant toolbar with generator and explore buttons
 * based on plugin configuration and user permissions.
 *
 * @param array $args Fragment arguments containing:
 *                    - courseid: Course ID (required)
 *                    - showgenerator: Whether to show generator button
 *                    - showexplore: Whether to show explore button
 * @return string Rendered HTML
 */
function aiplacement_modgen_output_fragment_course_toolbar(array $args): string {
    global $PAGE;
    
    // Validate and clean parameters
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $showgenerator = !empty($args['showgenerator']);
    $showexplore = !empty($args['showexplore']);
    
    // Verify course exists and get context
    $course = get_course($courseid);
    $context = context_course::instance($courseid);
    
    // Verify permissions
    require_capability('moodle/course:update', $context);
    
    // Create the toolbar renderable
    $toolbar = new \aiplacement_modgen\output\course_toolbar($courseid, $showgenerator, $showexplore);
    
    // Get the plugin renderer and render the toolbar
    $renderer = $PAGE->get_renderer('aiplacement_modgen');
    return $renderer->render($toolbar);
}

/**
 * Fragment callback to render the generator form in a modal.
 *
 * This properly handles filemanager initialization and other form JavaScript.
 *
 * @param array $args Fragment arguments containing courseid
 * @return string Rendered form HTML
 */
function aiplacement_modgen_output_fragment_generator_form(array $args): string {
    global $PAGE, $USER, $CFG;
    
    // Ensure required libraries are loaded
    require_once($CFG->libdir . '/formslib.php');
    require_once($CFG->libdir . '/filelib.php');
    
    // Validate parameters
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $context = context_course::instance($courseid);
    
    // Verify permission
    require_capability('moodle/course:update', $context);
    
    // Set page context for proper JS/CSS loading
    $PAGE->set_context($context);
    
    // Check AI policy acceptance
    $manager = \core\di::get(\core_ai\manager::class);
    if (!$manager->get_user_policy_status($USER->id)) {
        return $PAGE->get_renderer('aiplacement_modgen')->render_from_template(
            'aiplacement_modgen/ai_policy',
            [
                'courseid' => $courseid,
                'policytext' => get_string('aipolicyinfo', 'aiplacement_modgen'),
            ]
        );
    }
    
    // Create form with embedded flag
    require_once(__DIR__ . '/classes/form/generator_form.php');
    $formdata = [
        'courseid' => $courseid,
        'embedded' => 1,
        'contextid' => $context->id,
        'supportingfiles' => $draftitemid,
    ];
    $form = new \aiplacement_modgen_generator_form(null, $formdata);
    
    // Set the draft area data
    $formdefaults = new stdClass();
    $formdefaults->supportingfiles = $draftitemid;
    $form->set_data($formdefaults);
    
    // Return rendered form
    // Fragment API automatically handles JavaScript initialization
    return $form->render();
}




