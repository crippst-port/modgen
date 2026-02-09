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
 * Front-end script for the Module Generator workflow.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('AJAX_SCRIPT') && !empty($_REQUEST['ajax'])) {
    define('AJAX_SCRIPT', true);
}

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_login();

// Get course ID early for authentication
$courseid = optional_param('id', 0, PARAM_INT);
if (!$courseid) {
    $courseid = optional_param('courseid', 0, PARAM_INT);
}
if (!$courseid) {
    throw new moodle_exception('missingcourseid', 'aiplacement_modgen');
}

// Verify user has access to this course and can generate content
$context = context_course::instance($courseid);
$hasprompt = has_capability('aiplacement/modgen:generatewithprompt', $context);
$hastemplate = has_capability('aiplacement/modgen:generatefromtemplate', $context);
if (!$hasprompt && !$hastemplate) {
    throw new required_capability_exception($context, 'aiplacement/modgen:generatefromtemplate', 
        'nopermissions', 'error');
}

// Set page URL early to avoid "page did not call set_url()" errors
$PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course(get_course($courseid));

// Include form classes
require_once(__DIR__ . '/classes/form/generator_form.php');
require_once(__DIR__ . '/classes/form/approve_form.php');

// Cache configuration values for efficiency
$pluginconfig = (object)[
    'timeout' => get_config('aiplacement_modgen', 'timeout') ?: 300,
];

// Increase PHP execution time for AI processing
set_time_limit($pluginconfig->timeout);
ini_set('max_execution_time', $pluginconfig->timeout);

$embedded = optional_param('embedded', 0, PARAM_BOOL);
$ajax = optional_param('ajax', 0, PARAM_BOOL);

if ($ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
}

if ($embedded && !$ajax) {
    $PAGE->requires->css('/ai/placement/modgen/styles.css');
    $PAGE->add_body_class('aiplacement-modgen-embedded');
    $PAGE->requires->js_call_amd('aiplacement_modgen/embedded_prompt', 'init');
}

/**
 * Emit an AJAX response payload and terminate execution.
 *
 * @param string $body Body HTML for the modal content.
 * @param string $footer Footer HTML for modal actions.
 * @param bool $refresh Whether the parent page should refresh after close.
 * @param array $extra Additional response data.
 */
function aiplacement_modgen_send_ajax_response(string $body, string $footer = '', bool $refresh = false, array $extra = []): void {
    $response = array_merge([
        'body' => $body,
        'footer' => $footer,
        'refresh' => $refresh,
    ], $extra);

    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Build button definitions for modal footer.
 * 
 * @param array $buttons Array of button definitions with keys: action, label, class, formaction (optional)
 * @return array Button definitions for JSON response
 */
function aiplacement_modgen_build_footer_buttons(array $buttons): array {
    return array_map(function($btn) {
        return [
            'action' => $btn['action'] ?? 'submit',
            'label' => $btn['label'] ?? 'Submit',
            'class' => $btn['class'] ?? 'btn-secondary',
            'formaction' => $btn['formaction'] ?? null,
        ];
    }, $buttons);
}

/**
 * Render the standard modal footer actions template.
 *
 * @param array $actions Action definitions for the footer.
 * @param bool $includeclose Whether to append the default close button.
 * @return string HTML fragment for the modal footer.
 */
function aiplacement_modgen_render_modal_footer(array $actions, bool $includeclose = true): string {
    global $OUTPUT;

    if ($includeclose) {
        $actions[] = [
            'label' => get_string('closemodgenmodal', 'aiplacement_modgen'),
            'classes' => 'btn btn-secondary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-close',
        ];
    }

    if (empty($actions)) {
        return '';
    }

    return $OUTPUT->render_from_template('aiplacement_modgen/modal_footer', [
        'actions' => $actions,
    ]);
}

/**
 * Helper function to output response in AJAX or regular mode.
 *
 * @param string $bodyhtml Body HTML content.
 * @param array $footeractions Footer action definitions.
 * @param bool $ajax Whether this is an AJAX request.
 * @param string $title Modal title for AJAX mode.
 * @param bool $refresh Whether to refresh on close (AJAX only).
 */
function aiplacement_modgen_output_response(string $bodyhtml, array $footeractions, bool $ajax, string $title, bool $refresh = false): void {
    global $OUTPUT, $PAGE;
    
    if ($ajax) {
        $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);
        aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, $refresh, ['title' => $title]);
    }
    
    // Set up navigation breadcrumb for non-AJAX page requests
    if (!$ajax && !defined('AJAX_SCRIPT')) {
        $PAGE->navbar->add($title);
    }
    
    echo $OUTPUT->header();
    echo $bodyhtml;
    echo $OUTPUT->footer();
}

/**
 * Helper to create a subsection module and optionally populate its delegated section summary.
 *
 * @param stdClass $course Course record.
 * @param int $sectionnum Section number where the subsection should be placed.
 * @param string $name Subsection name.
 * @param string $summaryhtml Pre-formatted HTML summary to store in the delegated section.
 * @param bool $needscacherefresh Reference flag toggled when the course cache needs rebuilding.
 * @return array|null Array with 'cmid' and 'delegatedsectionid' keys, or null on failure.
 */
function local_aiplacement_modgen_create_subsection(stdClass $course, int $sectionnum, string $name, string $summaryhtml, bool &$needscacherefresh): ?array {
    global $DB;

    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'subsection';
    $moduleinfo->course = $course->id;
    $moduleinfo->section = $sectionnum;
    $moduleinfo->visible = 1;
    $moduleinfo->completion = 0;
    $moduleinfo->name = $name;
    $moduleinfo->intro = '';
    $moduleinfo->introformat = FORMAT_HTML;

    $cm = create_module($moduleinfo);
    $cmid = null;
    if (is_object($cm)) {
        $cmid = $cm->coursemodule ?? ($cm->id ?? null);
    } else if (is_numeric($cm)) {
        $cmid = (int)$cm;
    }

    if (empty($cmid)) {
        return null;
    }

    $delegatedsectionid = null;
    $cmrecord = get_coursemodule_from_id('subsection', $cmid, $course->id, false, IGNORE_MISSING);
    if ($cmrecord) {
        $manager = \mod_subsection\manager::create_from_coursemodule($cmrecord);
        $delegatedsection = $manager->get_delegated_section_info();
        if ($delegatedsection) {
            $delegatedsectionid = $delegatedsection->id;
            if ($summaryhtml !== '') {
                $sectionrecord = $DB->get_record('course_sections', ['id' => $delegatedsection->id]);
                if ($sectionrecord) {
                    $sectionrecord->summary = $summaryhtml;
                    $sectionrecord->summaryformat = FORMAT_HTML;
                    $sectionrecord->timemodified = time();
                    $DB->update_record('course_sections', $sectionrecord);
                    $needscacherefresh = true;
                }
            }
        }
    }

    return [
        'cmid' => $cmid,
        'delegatedsectionid' => $delegatedsectionid,
    ];
}

/**
 * Provide a readable fallback summary when the AI description is unavailable.
 *
 * @param array $moduledata Decoded module structure returned by the AI.
 * @param string $structure Requested structure ('weekly' or 'theme').
 * @return string Fallback summary text or empty string when details are missing.
 */
function aiplacement_modgen_generate_fallback_summary(array $moduledata, string $structure): string {
    $structure = ($structure === 'theme') ? 'theme' : 'weekly';

    if ($structure === 'theme' && !empty($moduledata['themes']) && is_array($moduledata['themes'])) {
        $themes = array_filter($moduledata['themes'], 'is_array');
        $themecount = count($themes);
        $weekcount = 0;
        foreach ($themes as $theme) {
            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                $weekcount += count(array_filter($theme['weeks'], 'is_array'));
            }
        }

        if ($themecount > 0) {
            return get_string('generationresultsfallbacksummary_theme', 'aiplacement_modgen', [
                'themes' => $themecount,
                'weeks' => $weekcount,
            ]);
        }
    }

    if (!empty($moduledata['sections']) && is_array($moduledata['sections'])) {
        $sections = array_filter($moduledata['sections'], 'is_array');
        $sectioncount = count($sections);
        $outlineitems = 0;
        foreach ($sections as $section) {
            if (!empty($section['outline']) && is_array($section['outline'])) {
                foreach ($section['outline'] as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $outlineitems++;
                    }
                }
            }
        }

        if ($sectioncount > 0) {
            return get_string('generationresultsfallbacksummary_weekly', 'aiplacement_modgen', [
                'sections' => $sectioncount,
                'outlineitems' => $outlineitems,
            ]);
        }
    }

    return '';
}

/**
 * Build a module preview from AI-generated JSON using the unified preview generator.
 *
 * @param array $moduledata The decoded module structure returned by the AI.
 * @param string $structure Module structure type ('theme', 'connected_theme', 'weekly', 'connected_weekly', etc).
 * @return array Structured data with themes/weeks and activities for display.
 */
function aiplacement_modgen_build_module_preview(array $moduledata, string $structure): array {
    return \aiplacement_modgen\local\preview_generator::generate($moduledata, $structure);
}

// Handle policy acceptance first (before checking status).
$acceptpolicy = optional_param('acceptpolicy', 0, PARAM_BOOL);
if ($acceptpolicy && confirm_sesskey()) {
    $manager = \core\di::get(\core_ai\manager::class);
    $manager->user_policy_accepted($USER->id, $context->id);
    if ($ajax) {
        // For AJAX requests, continue to show the main form instead of stopping here.
        // The policy check below will now pass and show the normal content.
    } else {
        redirect($PAGE->url);
    }
}


// Check AI policy acceptance before allowing access.
$manager = \core\di::get(\core_ai\manager::class);
if (!$manager->get_user_policy_status($USER->id)) {
    // User hasn't accepted AI policy yet.
    // Build the policy acceptance form using Mustache template (SECURITY FIX: replaced inline HTML)
    $policydata = [
        'aipolicyacceptance' => get_string('aipolicyacceptance', 'aiplacement_modgen'),
        'aipolicyinfo' => get_string('aipolicyinfo', 'aiplacement_modgen'),
        'acceptaipolicy' => get_string('acceptaipolicy', 'aiplacement_modgen'),
        'accept' => get_string('accept'),
        'courseid' => $courseid,
        'embedded' => $embedded ? 1 : 0,
        'ajax' => $ajax ? 1 : 0,
        'sesskey' => sesskey(),
        'formaction' => $PAGE->url->out(false),
    ];

    if ($ajax) {
        // For AJAX requests, return policy acceptance form with modal footer.
        // Render template (safe for AJAX context)
        $policyformhtml = $OUTPUT->render_from_template('aiplacement_modgen/ai_policy_acceptance', $policydata);

        $footer = aiplacement_modgen_render_modal_footer([
            [
                'label' => get_string('accept'),
                'classes' => 'btn btn-primary',
                'isbutton' => true,
                'action' => 'aiplacement-modgen-submit',
                'disabled' => true,
                'id' => 'accept-policy-btn',
            ]
        ]);

        // Load AMD module for policy acceptance (SECURITY FIX: replaced inline JavaScript)
        $PAGE->requires->js_call_amd('aiplacement_modgen/policy_acceptance', 'init');

        aiplacement_modgen_send_ajax_response($policyformhtml, $footer);
    } else {
        // For regular page requests, show the policy acceptance form as a full page
        // Set up page context FIRST (before any output rendering)
        $pageparams = ['id' => $courseid];
        if ($embedded) {
            $pageparams['embedded'] = 1;
        }
        $PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', $pageparams));
        $PAGE->set_context($context);
        $PAGE->set_course(get_course($courseid));
        $PAGE->set_title(get_string('pluginname', 'aiplacement_modgen'));
        $PAGE->set_heading(get_string('pluginname', 'aiplacement_modgen'));
        if ($embedded) {
            $PAGE->set_pagelayout('embedded');
        }

        // Load AMD module for policy acceptance
        $PAGE->requires->js_call_amd('aiplacement_modgen/policy_acceptance', 'init');

        // NOW render the template (after page setup is complete)
        $policyformhtml = $OUTPUT->render_from_template('aiplacement_modgen/ai_policy_acceptance', $policydata);

        echo $OUTPUT->header();
        echo html_writer::div($policyformhtml, 'aiplacement-modgen__content');
        echo $OUTPUT->footer();
    }
    exit;
}


$pageparams = ['id' => $courseid];
if ($embedded) {
    $pageparams['embedded'] = 1;
}
$PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', $pageparams));
$PAGE->set_context($context);
$PAGE->set_course(get_course($courseid));
$PAGE->set_title(get_string('pluginname', 'aiplacement_modgen'));
$PAGE->set_heading(get_string('pluginname', 'aiplacement_modgen'));
if ($embedded || $ajax) {
    $PAGE->set_pagelayout('embedded');
}

// Business logic - use cached config values.
require_once(__DIR__ . '/classes/local/ai_service.php');
require_once(__DIR__ . '/classes/activitytype/registry.php');
require_once(__DIR__ . '/classes/local/template_reader.php');
require_once(__DIR__ . '/classes/local/filehandler/file_processor.php');
require_once(__DIR__ . '/classes/local/constants.php');
require_once(__DIR__ . '/classes/local/file_processor_service.php');
require_once(__DIR__ . '/classes/local/csv_processing_service.php');
require_once(__DIR__ . '/classes/local/template_processing_service.php');
require_once(__DIR__ . '/classes/local/section_creation_service.php');

// Load course libraries once (used by approval form processing)
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/subsection/classes/manager.php');

// Attempt approval form first (so refreshes on approval post are handled).
$approveform = null;
$approvedjsonparam = optional_param('approvedjson', null, PARAM_RAW);
$approvedtypeparam = optional_param('moduletype', 'connected_weekly', PARAM_ALPHA);
$generatethemeintroductionsparam = optional_param('generatethemeintroductions', 0, PARAM_BOOL);
$createsuggestedactivitiesparam = optional_param('createsuggestedactivities', 0, PARAM_BOOL);
$generatedsummaryparam = optional_param('generatedsummary', '', PARAM_RAW);
$hideexistingsectionsparam = optional_param('hideexistingsections', 0, PARAM_BOOL);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'generatethemeintroductions' => $generatethemeintroductionsparam,
        'createsuggestedactivities' => $createsuggestedactivitiesparam,
        'generatedsummary' => $generatedsummaryparam,
        'hideexistingsections' => $hideexistingsectionsparam,
        'embedded' => $embedded ? 1 : 0,
    ]);
}

    if ($approveform && ($adata = $approveform->get_data())) {
        // Create sections from approved JSON using the section creation service
        if (strlen($adata->approvedjson) > \aiplacement_modgen\local\constants::MAX_FILE_CONTENT_LENGTH * 2) {
            throw new \moodle_exception('jsontoolarge', 'aiplacement_modgen');
        }
        
        $json = json_decode($adata->approvedjson, true);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'aiplacement_modgen', '', json_last_error_msg());
        }
        
        $moduletype = !empty($adata->moduletype) ? $adata->moduletype : 'connected_weekly';
        $generatethemeintroductions = !empty($adata->generatethemeintroductions);
        $createsuggestedactivities = !empty($adata->createsuggestedactivities);
        $hideexistingsections = !empty($adata->hideexistingsections);
        
        // Count sections to determine if background processing needed
        $sectioncount = 0;
        if (!empty($json['themes'])) {
            $sectioncount += count($json['themes']);
            foreach ($json['themes'] as $theme) {
                if (!empty($theme['weeks'])) {
                    $sectioncount += count($theme['weeks']);
                }
            }
        } else if (!empty($json['weeks'])) {
            $sectioncount = count($json['weeks']);
        }
        
        // Use background job for large operations (10+ sections)
        if ($sectioncount >= 10) {
            // Create job record
            $job = new \stdClass();
            $job->courseid = $courseid;
            $job->userid = $USER->id;
            $job->action = 'create_from_json';
            $job->status = 'queued';
            $job->parameters = json_encode([
                'json' => $json,
                'moduletype' => $moduletype,
                'generatethemeintroductions' => $generatethemeintroductions,
                'createsuggestedactivities' => $createsuggestedactivities,
                'hideexistingsections' => $hideexistingsections
            ]);
            $job->timecreated = time();
            $jobid = $DB->insert_record('aiplacement_modgen_jobs', $job);
            
            // Queue ad-hoc task
            $task = new \aiplacement_modgen\task\create_sections_task();
            $task->set_custom_data(['jobid' => $jobid]);
            \core\task\manager::queue_adhoc_task($task);
            
            // Return queued response for AJAX
            if ($ajax) {
                \aiplacement_modgen\local\ajax_response::success([
                    'queued' => true,
                    'jobid' => $jobid,
                    'message' => get_string('jobqueued', 'aiplacement_modgen', $sectioncount)
                ]);
            }
            // For non-AJAX, results will be empty and handled below
            $results = [];
            $activitywarnings = [];
        } else {
            // Small operation - run synchronously
            $section_service = new \aiplacement_modgen\local\section_creation_service();
            $creation_result = $section_service->create_sections_from_json(
                $json,
                $courseid,
                $moduletype,
                $generatethemeintroductions,
                $createsuggestedactivities,
                $hideexistingsections
            );
            
            $results = $creation_result['results'];
            $activitywarnings = $creation_result['warnings'];
        }

        // Prepare data for success template
        $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
        $successdata = [
            'message' => get_string('sectionscreatedsuccess', 'aiplacement_modgen', count($results)),
            'hasdetails' => !empty($results),
            'details' => $results,
            'showcoursereturn' => !$ajax, // Show button in body for standalone pages, use footer for AJAX/modal
            'courseurl' => $courseurl->out(false),
        ];

        $bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/success_message', $successdata);
        
        // Add any warnings below the success message
        if (!empty($activitywarnings)) {
            $warningshtml = '';
            foreach ($activitywarnings as $warning) {
                $warningshtml .= html_writer::div($warning, 'alert alert-warning', ['role' => 'alert']);
            }
            $bodyhtml .= $warningshtml;
        }
        
        $bodyhtml = html_writer::div($bodyhtml, 'aiplacement-modgen__content');

        if ($ajax) {
            $footeractions = [];
            if ($embedded) {
                $footeractions[] = [
                    'label' => get_string('closemodgenmodal', 'aiplacement_modgen'),
                    'classes' => 'btn btn-secondary',
                    'isbutton' => true,
                    'action' => 'aiplacement-modgen-close',
                ];
                $footerhtml = aiplacement_modgen_render_modal_footer($footeractions, false);
            } else {
                $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
                $footeractions[] = [
                    'label' => get_string('returntocourse', 'aiplacement_modgen'),
                    'classes' => 'btn btn-primary',
                    'islink' => true,
                    'url' => $courseurl->out(false),
                ];
                $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);
            }

            aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, true, [
                'close' => false,
                'title' => get_string('pluginname', 'aiplacement_modgen'),
            ]);
        }

        echo $OUTPUT->header();
        echo $bodyhtml;
        echo $OUTPUT->footer();
        exit;
    } // Close the if ($approveform && ($adata = $approveform->get_data())) block

// Generator form: Create and display for standalone page access
// Check which form is being submitted
$is_prompt_form = optional_param('_qf__aiplacement_modgen_prompt_form', 0, PARAM_BOOL);

if ($is_prompt_form) {
    require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/prompt_form.php');
    $promptform = new \aiplacement_modgen_prompt_form(null, ['courseid' => $courseid]);
} else {
    $promptform = new aiplacement_modgen_generator_form(null, [
        'courseid' => $courseid,
        'embedded' => 0,
        'contextid' => context_course::instance((int)$courseid)->id,
    ]);
}

// Render the generator form as a standalone page (only if form is not being submitted).
if (!$promptform->is_submitted()) {
    $PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
    $PAGE->set_title(get_string('modgenmodalheading', 'aiplacement_modgen'));
    $PAGE->set_heading(get_string('modgenmodalheading', 'aiplacement_modgen'));

    echo $OUTPUT->header();
    
    // Render header template
    $headerdata = [
        'heading' => get_string('launchgenerator', 'aiplacement_modgen'),
        'introduction' => get_string('generatorintroduction', 'aiplacement_modgen'),
        'warning' => get_string('longquery', 'aiplacement_modgen'),
    ];
    echo $OUTPUT->render_from_template('aiplacement_modgen/generator_header', $headerdata);
    
    $promptform->display();
    echo $OUTPUT->footer();
    exit;
}

if ($promptform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($pdata = $promptform->get_data()) {
    // Check if debug button was clicked
    if (!empty($pdata->debugbutton)) {
        $prompt = !empty($pdata->prompt) ? trim($pdata->prompt) : '';
        $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'weekly';
        
        // Collect selected modules from multiselect
        $existing_modules = [];
        if (!empty($pdata->existing_modules)) {
            if (is_array($pdata->existing_modules)) {
                $existing_modules = array_map('intval', array_filter($pdata->existing_modules));
            } else {
                $existing_modules = [(int)$pdata->existing_modules];
            }
        }
        $existing_modules = array_unique(array_filter($existing_modules));
        $existing_module = !empty($existing_modules) ? array_shift($existing_modules) : 0; // Primary module
        
        // Try to extract template data
        $template_data = null;
        $template_data_debug = [];
        
        if (!empty($existing_module) || !empty($existing_modules)) {
            try {
                $template_reader = new \aiplacement_modgen\local\template_reader();
                $all_templates = [];
                
                // Collect modules to extract
                $modules_to_extract = [];
                if (!empty($existing_module)) {
                    $modules_to_extract[] = $existing_module;
                }
                if (!empty($existing_modules)) {
                    $modules_to_extract = array_merge($modules_to_extract, $existing_modules);
                }
                $modules_to_extract = array_unique(array_filter($modules_to_extract));
                
                if (!empty($modules_to_extract)) {
                    $template_data_debug[] = 'Extracting from ' . count($modules_to_extract) . ' module(s)...';
                    
                    global $DB;
                    
                    foreach ($modules_to_extract as $idx => $mod_id) {
                        $template_data_debug[] = '';
                        $template_data_debug[] = '=== Module ' . ($idx + 1) . ' (ID: ' . $mod_id . ') ===';
                        
                        // Check if course exists
                        $course_check = $DB->get_record('course', ['id' => (int)$mod_id]);
                        if (!$course_check) {
                            $template_data_debug[] = 'ERROR: Course ID ' . $mod_id . ' not found';
                            continue;
                        }
                        
                        $template_data_debug[] = 'Course: ' . $course_check->fullname;
                        
                        // Check access
                        $course_context = \context_course::instance((int)$mod_id);
                        $has_access = has_capability('moodle/course:view', $course_context);
                        $template_data_debug[] = 'Access: ' . ($has_access ? 'YES' : 'NO');
                        
                        if (!$has_access) {
                            $template_data_debug[] = 'Skipped - no access';
                            continue;
                        }
                        
                        try {
                            $extracted = $template_reader->extract_curriculum_template((string)$mod_id);
                            $template_data_debug[] = 'Extraction: SUCCESS';
                            $template_data_debug[] = 'Sections: ' . count($extracted['structure'] ?? []);
                            $template_data_debug[] = 'Activities: ' . count($extracted['activities'] ?? []);
                            $all_templates[] = $extracted;
                        } catch (Throwable $extract_error) {
                            $template_data_debug[] = 'Extraction FAILED: ' . $extract_error->getMessage();
                            
                            // Try fallback
                            try {
                                $fallback = [
                                    'course_info' => [
                                        'name' => $course_check->fullname,
                                        'format' => $course_check->format,
                                        'summary' => strip_tags($course_check->summary ?? '')
                                    ],
                                    'structure' => [],
                                    'activities' => [],
                                    'template_html' => ''
                                ];
                                $template_data_debug[] = 'Fallback: SUCCESS (course info only)';
                                $all_templates[] = $fallback;
                            } catch (Throwable $fallback_error) {
                                $template_data_debug[] = 'Fallback FAILED: ' . $fallback_error->getMessage();
                            }
                        }
                    }
                    
                    // Merge all templates
                    if (!empty($all_templates)) {
                        $template_data_debug[] = '';
                        $template_data_debug[] = 'Merging ' . count($all_templates) . ' template(s)...';
                        $template_data = $all_templates[0];
                        
                        // Track how many modules are being merged for AI prompt
                        $template_data['module_count'] = count($all_templates);
                        
                        if (count($all_templates) > 1) {
                            for ($i = 1; $i < count($all_templates); $i++) {
                                $other = $all_templates[$i];
                                if (!empty($other['structure'])) {
                                    $template_data['structure'] = array_merge($template_data['structure'] ?? [], $other['structure']);
                                }
                                if (!empty($other['activities'])) {
                                    $template_data['activities'] = array_merge($template_data['activities'] ?? [], $other['activities']);
                                }
                                if (!empty($other['template_html'])) {
                                    $template_data['template_html'] .= "\n\n--- Module " . ($i + 1) . " ---\n\n" . $other['template_html'];
                                }
                            }
                        }
                        $template_data_debug[] = 'Final: ' . count($template_data['structure'] ?? []) . ' sections, ' . count($template_data['activities'] ?? []) . ' activities';
                    }
                    
                }
                
            } catch (Exception $e) {
                $template_data_debug[] = 'ERROR: ' . $e->getMessage();
                $template_data = null;
            }
        } else {
            $template_data_debug[] = 'No template source selected';
        }
        
        // Display the debug output
        $html = html_writer::tag('h3', 'DEBUG: Template Data Extraction', ['class' => 'mt-3']);
        $html .= html_writer::tag('pre', implode("\n", $template_data_debug), [
            'style' => 'background: #f5f5f5; padding: 15px; border-radius: 3px; font-size: 0.85em; overflow-x: auto; border: 1px solid #ddd;'
        ]);
        
        if ($template_data) {
            $html .= html_writer::tag('h4', 'Full Template Data (JSON)', ['class' => 'mt-3']);
            $html .= html_writer::tag('pre', json_encode($template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), [
                'style' => 'background: #f5f5f5; padding: 15px; border-radius: 3px; font-size: 0.75em; overflow-x: auto; border: 1px solid #ddd; max-height: 600px; overflow-y: auto;'
            ]);
            
            // Show the compact structure that gets sent to the AI
            $html .= html_writer::tag('h4', 'Compact Structure for AI (What the AI Actually Receives)', ['class' => 'mt-3']);
            $html .= html_writer::tag('p', 'This is the optimized structure that gets included in the AI prompt:', ['class' => 'text-muted']);
            
            // Create the compact structure inline to avoid namespace issues
            $compact_structure = [
                'source' => !empty($template_data['module_count']) && $template_data['module_count'] > 1 
                    ? 'multiple_modules' 
                    : 'single_module',
                'organizational_pattern' => [
                    'label_sequence' => [],
                    'activity_types_used' => [],
                    'typical_activities_per_section' => 0
                ],
                'sections' => []
            ];
            
            // Extract organizational pattern
            if (!empty($template_data['activities']) && is_array($template_data['activities'])) {
                $label_sequence = [];
                $activity_types = [];
                $section_counts = [];
                
                foreach ($template_data['activities'] as $activity) {
                    $type = $activity['type'] ?? 'unknown';
                    $section = $activity['section'] ?? 'unknown';
                    
                    if (!isset($section_counts[$section])) {
                        $section_counts[$section] = 0;
                    }
                    $section_counts[$section]++;
                    
                    if ($type === 'label' && !empty($activity['intro'])) {
                        if (!in_array($activity['intro'], $label_sequence)) {
                            $label_sequence[] = $activity['intro'];
                        }
                    }
                    
                    if (!in_array($type, $activity_types)) {
                        $activity_types[] = $type;
                    }
                }
                
                $compact_structure['organizational_pattern']['label_sequence'] = $label_sequence;
                $compact_structure['organizational_pattern']['activity_types_used'] = $activity_types;
                
                if (!empty($section_counts)) {
                    $compact_structure['organizational_pattern']['typical_activities_per_section'] = 
                        (int) round(array_sum($section_counts) / count($section_counts));
                }
            }
            
            // Process sections
            if (!empty($template_data['structure']) && is_array($template_data['structure'])) {
                foreach ($template_data['structure'] as $section) {
                    $section_data = [
                        'number' => $section['id'] ?? 0,
                        'title' => $section['name'] ?? 'Untitled',
                        'content' => []
                    ];
                    
                    if (!empty($section['summary'])) {
                        $section_data['summary'] = substr($section['summary'], 0, 200);
                    }
                    
                    // Add activities for this section
                    if (!empty($template_data['activities']) && is_array($template_data['activities'])) {
                        foreach ($template_data['activities'] as $activity) {
                            if (isset($activity['section']) && $activity['section'] === $section_data['title']) {
                                $activity_item = ['type' => $activity['type'] ?? 'unknown'];
                                
                                if ($activity['type'] === 'label' && !empty($activity['intro'])) {
                                    $activity_item['text'] = $activity['intro'];
                                } else {
                                    $activity_item['name'] = $activity['name'] ?? 'Untitled';
                                }
                                
                                $section_data['content'][] = $activity_item;
                            }
                        }
                    }
                    
                    $compact_structure['sections'][] = $section_data;
                }
            }
            
            $html .= html_writer::tag('pre', json_encode($compact_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), [
                'style' => 'background: #e8f5e9; padding: 15px; border-radius: 3px; font-size: 0.75em; overflow-x: auto; border: 2px solid #4caf50; max-height: 600px; overflow-y: auto;'
            ]);
            
            // Token estimate
            $compact_json = json_encode($compact_structure);
            $estimated_tokens = (int)(strlen($compact_json) / 4);
            $html .= html_writer::tag('p', "Estimated tokens: ~{$estimated_tokens} (compact) vs ~" . (int)(strlen(json_encode($template_data))/4) . " (full)", 
                ['class' => 'text-muted mt-2']);
        }
        
        $bodyhtml = html_writer::div($html, 'aiplacement-modgen__content p-3');
        
        $footeractions = [[
            'label' => 'Back to form',
            'classes' => 'btn btn-secondary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-reenter',
        ]];
        
        aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, 'DEBUG: Template Data');
        exit;
    }
    
    $prompt = !empty($pdata->prompt) ? trim($pdata->prompt) : '';
    $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'connected_weekly';
    
    // Check for any AI-based content options
    $expandonthemes = !empty($pdata->expandonthemes);
    
    // New simplified checkbox - if checked, generate all example content
    $generateexamplecontent = !empty($pdata->generateexamplecontent);
    $generatethemeintroductions = $generateexamplecontent;
    $createsuggestedactivities = $generateexamplecontent;
    $generatesessioninstructions = $generateexamplecontent;
    
    // Check if user wants to hide existing sections and place new content at top
    $hideexistingsections = !empty($pdata->hideexistingsections);
    
    // For connected layouts, ALWAYS generate the sessions structure, but respect activity creation preference
    $includesessions = $generatesessioninstructions || ($moduletype === 'connected_weekly' || $moduletype === 'connected_theme');
    $includeactivities = $createsuggestedactivities;
    
    // Collect all selected existing modules from multiselect
    $existing_modules = [];
    if (!empty($pdata->existing_modules)) {
        if (is_array($pdata->existing_modules)) {
            $existing_modules = array_map('intval', array_filter($pdata->existing_modules));
        } else {
            $existing_modules = [(int)$pdata->existing_modules];
        }
    }
    $existing_modules = array_unique(array_filter($existing_modules));
    $existing_module = !empty($existing_modules) ? array_shift($existing_modules) : 0; // Primary module
    
    $typeinstruction = get_string('moduletypeinstruction_' . $moduletype, 'aiplacement_modgen');
    
    // Build composite prompt - combine user prompt with type instruction
    // If existing module(s) are selected, tell the AI to use them as a guide
    if (!empty($prompt)) {
        if (!empty($existing_module)) {
            // User provided both a prompt AND selected module(s) - use both
            $modulecount = count($existing_modules) + 1; // +1 for the primary module
            if ($modulecount > 1) {
                // Multiple modules: ALL content must be included
                $multipleinstruction = "You will receive content from $modulecount existing courses. " .
                    "Include ALL subject matter from every course - adapt and combine to fit the prompt, but use all content.";
            } else {
                // Single module: use as template and adapt
                $multipleinstruction = "You will receive content from an existing course as a reference. " .
                    "Use it as a template, adapting based on the prompt above.";
            }
            
            $compositeprompt = trim($prompt . "\n\n" . $multipleinstruction . "\n\n" . $typeinstruction);
        } else {
            // User provided a prompt but no module selection
            $compositeprompt = trim($prompt . "\n\n" . $typeinstruction);
        }
    } else {
        // No user prompt provided
        if (!empty($existing_module)) {
            // If existing module(s) selected but no prompt, ask AI to translate/adapt them
            $modulecount = count($existing_modules) + 1;
            if ($modulecount > 1) {
                // Multiple modules: merge all into single cohesive structure
                $multipleinstruction = "Merge content from $modulecount existing courses into a single module. " .
                    "Include ALL subject matter from every course.";
            } else {
                // Single module: translate/adapt
                $multipleinstruction = "Translate the existing module";
            }
            
            $compositeprompt = trim($multipleinstruction . " following this format:\n\n" . $typeinstruction);
        } else {
            // No prompt and no existing module - just use type instruction
            $compositeprompt = trim($typeinstruction);
        }
    }
    
    // Add theme introductions instruction if enabled and using connected_theme
    if ($generatethemeintroductions && $moduletype === 'connected_theme') {
        $compositeprompt .= "\n\nIMPORTANT: For each theme in the themes array, generate a 2-3 sentence introductory paragraph for students. This paragraph should be placed in the 'summary' field of each theme object. The summary should introduce the theme content to students, explaining what they will learn or explore in that themed section.";
    } elseif ($moduletype === 'connected_theme') {
        // If connected_theme format but NOT generating introductions, tell AI to leave theme summaries empty
        $compositeprompt .= "\n\nIMPORTANT: Do NOT generate summaries for themes. Leave the 'summary' field EMPTY for each theme object (empty string, not null). Only provide theme titles and the weeks array. This keeps the theme sections as containers without descriptive text.";
    }
    
    // Add activity guidance instruction if activities are being created
    if ($createsuggestedactivities) {
        $activityguidance = get_string('activityguidanceinstructions', 'aiplacement_modgen');
        $compositeprompt .= "\n\n" . $activityguidance;
    }
    
    // Add session instructions directive if enabled
    if ($generatesessioninstructions) {
        $compositeprompt .= "\n\nSESSION INSTRUCTIONS - DETAILED STUDENT GUIDANCE:\n" .
            "For each session subsection (pre-session, session, post-session), create a 'description' field (5-8 sentences minimum, 150-250 words) with student-facing guidance.\n\n" .
            "STRUCTURE:\n" .
            "A. LEARNING CONTEXT (1-2 sentences): What is the phase goal and learning level?\n" .
            "B. ACTIVITY GUIDANCE (3-4 sentences per activity):\n" .
            "   - Activity name and purpose\n" .
            "   - WHY it matters (pedagogical rationale)\n" .
            "   - HOW to approach (step-by-step)\n" .
            "   - Key concepts/skills to develop\n" .
            "   - Time estimate and progression to next activity\n" .
            "C. LEARNING OUTCOMES (1-2 sentences): What will students achieve?\n" .
            "D. SUPPORT (optional): Tips for challenging content\n\n" .
            "LANGUAGE:\n" .
            "- Write for UK university students (academic tone)\n" .
            "- Explain WHY activities matter, not just WHAT\n" .
            "- Reference activities naturally by name\n" .
            "- Use active voice ('You will develop X by doing Y')\n" .
            "- Sequence activities logically, building toward learning goals\n\n" .
            "CRITICAL:\n" .
            "- Every activity must be mentioned by name and purpose\n" .
            "- Descriptions must be 5-8 sentences minimum\n" .
            "- PRE-PHASE: Preparatory/foundational work\n" .
            "- SESSION PHASE: Core engagement and interaction\n" .
            "- POST-PHASE: Reflection, consolidation, application";
    }

    // Extract and include file contents in the prompt if files are provided
    $filecontent = '';
    if (!empty($uploadform) && ($filedata = $uploadform->get_data())) {
        // Get file manager data
        $usercontext = context_user::instance($USER->id);
        $files = $filedata->supportingfiles_filemanager ?? $filedata->supportingfiles ?? 0;
        
        if (!empty($files)) {
            $fs = get_file_storage();
            $contextid = !empty($filedata->contextid) ? $filedata->contextid : $usercontext->id;
            $storedfiles = $fs->get_area_files($contextid, 'aiplacement_modgen', 'supportingfiles', $files, 'sortorder', false);
            
            if (!empty($storedfiles)) {
                $filecontent = "UPLOADED FILE STRUCTURE:\n\n";
                foreach ($storedfiles as $file) {
                    if ($file->is_valid_image()) {
                        continue; // Skip images
                    }
                    $content = $file->get_content();
                    $filecontent .= "File: {$file->get_filename()}\n";
                    $filecontent .= "---\n";
                    $filecontent .= $content;
                    $filecontent .= "\n---\n\n";
                }
            }
        }
    }

    if (!empty($filecontent)) {
        $compositeprompt .= "\n\n" . $filecontent;
    }

    // Gather supporting files using the file processor service
    $supportingfiles = [];
    $fileprocessor = new \aiplacement_modgen\local\file_processor_service();
    
    if (!empty($pdata->supportingfiles)) {
        $draftitemid = $pdata->supportingfiles;
        $usercontext = context_user::instance($USER->id);
        $supportingfiles = $fileprocessor->process_draft_files($draftitemid, $usercontext->id);
    }
    
    // If files were actually uploaded but no user prompt, add auto-instruction to use the file
    if (!empty($supportingfiles) && empty($prompt)) {
        $compositeprompt .= "\n\nUser has uploaded file(s) without providing a text prompt. Please use the uploaded file content to create the module structure and content.";
    }
    
    // Generate module using template processing service
    $template_processor = new \aiplacement_modgen\local\template_processing_service();
    
    try {
        $json = $template_processor->process_and_generate(
            $pdata,
            $courseid,
            $compositeprompt,
            $supportingfiles,
            $includeactivities,
            $includesessions
        );
        
        // Check if the service returned a detected module type (from CSV auto-detection)
        if (!empty($json['_detected_moduletype'])) {
            $moduletype = $json['_detected_moduletype'];
            unset($json['_detected_moduletype']); // Remove internal flag
        }
        
        // Debug: Check what was returned
        if (empty($json)) {
            throw new Exception('Template processor returned empty result');
        }
        
        if (!is_array($json)) {
            throw new Exception('Template processor did not return an array');
        }
        
    } catch (Exception $e) {
        $errorhtml = html_writer::div(
            html_writer::tag('h4', get_string('generationfailed', 'aiplacement_modgen'), ['class' => 'text-danger']) .
            html_writer::div('Error: ' . $e->getMessage(), 'alert alert-danger'),
            'aiplacement-modgen__validation-error'
        );

        $bodyhtml = html_writer::div($errorhtml, 'aiplacement-modgen__content');

        $footeractions = [[
            'label' => get_string('tryagain', 'aiplacement_modgen'),
            'classes' => 'btn btn-primary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-reenter',
        ]];

        aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
        exit;
    }
    
    // Check if the AI response contains validation errors
    if (empty($json)) {
        $debuginfo = '';
        if (!empty($debuglog)) {
            $debuginfo = html_writer::div(
                html_writer::tag('h5', 'Debug Information') .
                html_writer::tag('pre', implode("\n", $debuglog), ['style' => 'background:#f5f5f5; padding: 10px; border-radius: 3px; font-size: 0.85em; overflow-x: auto;']),
                'alert alert-info mt-3'
            );
        }
        
        $errorhtml = html_writer::div(
            html_writer::tag('h4', 'AI Error', ['class' => 'text-danger']) .
            html_writer::div('The AI service returned no response. The API may be unavailable or returned an error. Please check the system logs and try again.', 'alert alert-danger') .
            (isset($json['template']) ? html_writer::div('Details: ' . $json['template'], 'alert alert-warning') : '') .
            $debuginfo,
            'aiplacement-modgen__validation-error'
        );

        $bodyhtml = html_writer::div($errorhtml, 'aiplacement-modgen__content');

        $footeractions = [[
            'label' => get_string('tryagain', 'aiplacement_modgen'),
            'classes' => 'btn btn-primary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-reenter',
        ]];

        aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
        exit;
    }
    
    if (!empty($json['template']) && strpos($json['template'], 'AI error') === 0) {
        $debuginfo = '';
        if (!empty($debuglog)) {
            $debuginfo = html_writer::div(
                html_writer::tag('h5', 'Debug Information') .
                html_writer::tag('pre', implode("\n", $debuglog), ['style' => 'background:#f5f5f5; padding: 10px; border-radius: 3px; font-size: 0.85em; overflow-x: auto;']),
                'alert alert-info mt-3'
            );
        }
        
        $errorhtml = html_writer::div(
            html_writer::tag('h4', 'AI Error', ['class' => 'text-danger']) .
            html_writer::div($json['template'], 'alert alert-danger') .
            $debuginfo,
            'aiplacement-modgen__validation-error'
        );

        $bodyhtml = html_writer::div($errorhtml, 'aiplacement-modgen__content');

        $footeractions = [[
            'label' => get_string('tryagain', 'aiplacement_modgen'),
            'classes' => 'btn btn-primary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-reenter',
        ]];

        aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
        exit;
    }
    
    if (!empty($json['validation_error'])) {
        // AI returned malformed structure - show error and don't allow approval
        $errorhtml = html_writer::div(
            html_writer::tag('h4', get_string('generationfailed', 'aiplacement_modgen'), ['class' => 'text-danger']) .
            html_writer::div($json['validation_error'], 'alert alert-danger') .
            html_writer::tag('p', get_string('validationerrorhelp', 'aiplacement_modgen')),
            'aiplacement-modgen__validation-error'
        );

        $bodyhtml = html_writer::div($errorhtml, 'aiplacement-modgen__content');

        $footeractions = [[
            'label' => get_string('tryagain', 'aiplacement_modgen'),
            'classes' => 'btn btn-primary',
            'isbutton' => true,
            'action' => 'aiplacement-modgen-reenter',
        ]];

        aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
        exit;
    }

    // Get the final prompt sent to AI for debugging (returned by ai_service).
    $debugprompt = isset($json['debugprompt']) ? $json['debugprompt'] : $prompt;
    $jsonstr = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonstr === false) {

    }
    // For fresh generation (start from scratch), skip re-encoding module data for summary
    // Just use a simple generated fallback summary instead
    $summarytext = aiplacement_modgen_generate_fallback_summary($json, $moduletype);
    $summaryformatted = $summarytext !== '' ? nl2br(s($summarytext)) : '';

    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $jsonstr,
        'moduletype' => $moduletype,
        'generatethemeintroductions' => $generatethemeintroductions ? 1 : 0,
        'createsuggestedactivities' => $createsuggestedactivities ? 1 : 0,
        'generatedsummary' => $summarytext,
        'hideexistingsections' => $hideexistingsections ? 1 : 0,
        'embedded' => $embedded ? 1 : 0,
        'usedaioptions' => (!empty($prompt) || $expandonthemes || $generateexamplecontent) ? 1 : 0,
    ]);

    $notifications = [];
    if (!empty($json['template']) && strpos($json['template'], 'AI error:') === 0) {
        $notifications[] = [
            'message' => $json['template'],
            'classes' => 'alert alert-danger',
        ];
    }

    $formhtml = '';
    ob_start();
    $approveform->display();
    $formhtml = ob_get_clean();
    
    // Add regenerate button functionality if AI is enabled
    if (get_config('aiplacement_modgen', 'enable_ai')) {
        $formhtml .= html_writer::script("
            document.addEventListener('DOMContentLoaded', function() {
                var regenerateBtn = document.querySelector('[name=\"regeneratebutton\"]');
                if (regenerateBtn) {
                    regenerateBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        location.reload();
                    });
                }
            });
        ");
    }

    // Build module preview from the generated JSON
    $modulepreview = aiplacement_modgen_build_module_preview($json, $moduletype);
    // Ensure modulepreview is always included (will be truthy if it has themes or weeks)
    $modulepreview['showmodulepreview'] = !empty($modulepreview['hasthemes']) || !empty($modulepreview['hasweeks']);

    $previewdata = [
        'notifications' => $notifications,
        'hassummary' => $summarytext !== '',
        'summaryheading' => get_string('generationresultssummaryheading', 'aiplacement_modgen'),
        'summary' => $summaryformatted,
        'modulepreview' => $modulepreview['showmodulepreview'] ? $modulepreview : false,
        'modulestructureinfo' => get_string('modulestructureinfo', 'aiplacement_modgen'),
        'hasjson' => !empty($jsonstr),
        'jsonheading' => get_string('generationresultsjsonheading', 'aiplacement_modgen'),
        'jsondescription' => get_string('generationresultsjsondescription', 'aiplacement_modgen'),
        'json' => s($jsonstr),
        'jsonnote' => get_string('generationresultsjsonnote', 'aiplacement_modgen'),
        'downloadjsontext' => get_string('downloadjson', 'aiplacement_modgen'),
        'form' => $formhtml,
        'promptheading' => get_string('generationresultspromptheading', 'aiplacement_modgen'),
        'prompttoggle' => get_string('generationresultsprompttoggle', 'aiplacement_modgen'),
        'prompttext' => format_text($prompt, FORMAT_PLAIN),
        'hasprompt' => trim($prompt) !== '',
    ];

    $bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/prompt_preview', $previewdata);
    $bodyhtml = html_writer::div($bodyhtml, 'aiplacement-modgen__content');

    // Define footer buttons for the preview step - server-driven approach
    if ($ajax) {
        $buttons = [];
        if (get_config('aiplacement_modgen', 'enable_ai')) {
            $buttons[] = [
                'action' => 'regenerate',
                'label' => get_string('regenerate', 'aiplacement_modgen'),
                'class' => 'btn-secondary',
            ];
        }
        $buttons[] = [
            'action' => 'submit',
            'label' => get_string('approveandcreate', 'aiplacement_modgen'),
            'class' => 'btn-primary',
        ];
        
        aiplacement_modgen_send_ajax_response($bodyhtml, '', false, [
            'title' => get_string('pluginname', 'aiplacement_modgen'),
            'buttons' => aiplacement_modgen_build_footer_buttons($buttons),
            'step' => 'preview',
        ]);
        exit;
    }
    
    // For non-AJAX, use the normal response with form buttons
    $footeractions = [];
    aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
    exit;
}

// If form validation failed, redisplay the form with errors
if ($promptform->is_submitted()) {
    $PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
    $PAGE->set_title(get_string('modgenmodalheading', 'aiplacement_modgen'));
    $PAGE->set_heading(get_string('modgenmodalheading', 'aiplacement_modgen'));

    echo $OUTPUT->header();
    
    // Render header template
    $headerdata = [
        'heading' => get_string('launchgenerator', 'aiplacement_modgen'),
        'introduction' => get_string('generatorintroduction', 'aiplacement_modgen'),
        'warning' => get_string('longquery', 'aiplacement_modgen'),
    ];
    echo $OUTPUT->render_from_template('aiplacement_modgen/generator_header', $headerdata);
    
    $promptform->display();
    echo $OUTPUT->footer();
    exit;
}

// If we reach here, something went wrong (form wasn't submitted and wasn't displaying)
// This shouldn't happen in normal flow
throw new moodle_exception('errorunexpected', 'aiplacement_modgen');
