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

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- AJAX_SCRIPT must be defined before config.php is loaded so it can adjust output/session handling accordingly.
if (!defined('AJAX_SCRIPT') && !empty($_REQUEST['ajax'])) {
    define('AJAX_SCRIPT', true);
}

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_login();

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

// Get course ID early for authentication.
$courseid = optional_param('id', 0, PARAM_INT);
if (!$courseid) {
    $courseid = optional_param('courseid', 0, PARAM_INT);
}
if (!$courseid) {
    throw new moodle_exception('missingcourseid', 'aiplacement_modgen');
}

// Verify user has access to this course and can generate content.
$context = context_course::instance($courseid);
$hasprompt = has_capability('aiplacement/modgen:generatewithprompt', $context);
$hastemplate = has_capability('aiplacement/modgen:generatefromtemplate', $context);
if (!$hasprompt && !$hastemplate) {
    throw new required_capability_exception(
        $context,
        'aiplacement/modgen:generatefromtemplate',
        'nopermissions',
        'error'
    );
}

// Set page URL early to avoid "page did not call set_url()" errors.
$PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course(get_course($courseid));

// Include form classes.
require_once(__DIR__ . '/classes/form/generator_form.php');
require_once(__DIR__ . '/classes/form/approve_form.php');

// Cache configuration values for efficiency.
$pluginconfig = (object)[
    'timeout' => get_config('aiplacement_modgen', 'timeout') ?: 300,
];

// Increase PHP execution time for AI processing.
core_php_time_limit::raise((int) $pluginconfig->timeout);

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

    header('Content-Type: application/json; charset=utf-8');
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
    return array_map(function ($btn) {
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
function aiplacement_modgen_output_response(
    string $bodyhtml,
    array $footeractions,
    bool $ajax,
    string $title,
    bool $refresh = false
): void {
    global $OUTPUT, $PAGE;

    if ($ajax) {
        $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);
        aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, $refresh, ['title' => $title]);
    }

    // Set up navigation breadcrumb for non-AJAX page requests.
    if (!$ajax && !defined('AJAX_SCRIPT')) {
        $PAGE->navbar->add($title);
    }

    // The footer buttons only exist in the modal; on a full page, offer the way back as a link.
    foreach ($footeractions as $action) {
        if (($action['action'] ?? '') === 'aiplacement-modgen-reenter') {
            $bodyhtml .= html_writer::div(
                html_writer::link($PAGE->url, $action['label'], ['class' => 'btn btn-primary']),
                'mt-3'
            );
        }
    }

    echo $OUTPUT->header();
    echo $bodyhtml;
    echo $OUTPUT->footer();
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
    // For AJAX requests, continue to show the main form instead of stopping here.
    // The policy check below will now pass and show the normal content.
    if (!$ajax) {
        redirect($PAGE->url);
    }
}


// Check AI policy acceptance before allowing access.
$manager = \core\di::get(\core_ai\manager::class);
if (!$manager->get_user_policy_status($USER->id)) {
    // User hasn't accepted AI policy yet.
    // Build the policy acceptance form using Mustache template (SECURITY FIX: replaced inline HTML).
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
        // Render template (safe for AJAX context).
        $policyformhtml = $OUTPUT->render_from_template('aiplacement_modgen/ai_policy_acceptance', $policydata);

        $footer = aiplacement_modgen_render_modal_footer([
            [
                'label' => get_string('accept'),
                'classes' => 'btn btn-primary',
                'isbutton' => true,
                'action' => 'aiplacement-modgen-submit',
                'disabled' => true,
                'id' => 'accept-policy-btn',
            ],
        ]);

        // Load AMD module for policy acceptance (SECURITY FIX: replaced inline JavaScript).
        $PAGE->requires->js_call_amd('aiplacement_modgen/policy_acceptance', 'init');

        aiplacement_modgen_send_ajax_response($policyformhtml, $footer);
    } else {
        // For regular page requests, show the policy acceptance form as a full page
        // Set up page context FIRST (before any output rendering).
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

        // Load AMD module for policy acceptance.
        $PAGE->requires->js_call_amd('aiplacement_modgen/policy_acceptance', 'init');

        // NOW render the template (after page setup is complete).
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

// Attempt approval form first (so refreshes on approval post are handled).
$approveform = null;
$approvedjsonparam = optional_param('approvedjson', null, PARAM_RAW);
$approvedtypeparam = optional_param('moduletype', 'connected_weekly', PARAM_ALPHA);
$generatedsummaryparam = optional_param('generatedsummary', '', PARAM_RAW);
$createsummaryactivitiesparam = optional_param('createsummaryactivities', 0, PARAM_BOOL);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'generatedsummary' => $generatedsummaryparam,
        'createsummaryactivities' => $createsummaryactivitiesparam,
        'embedded' => $embedded ? 1 : 0,
    ]);
}

if ($approveform && ($adata = $approveform->get_data())) {
    // Create sections from approved JSON using the section creation service.
    if (strlen($adata->approvedjson) > \aiplacement_modgen\local\constants::MAX_FILE_CONTENT_LENGTH * 2) {
        throw new \moodle_exception('jsontoolarge', 'aiplacement_modgen');
    }

    $json = json_decode($adata->approvedjson, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new \moodle_exception('invalidjson', 'aiplacement_modgen', '', json_last_error_msg());
    }

    // Sanitise every string in the structure: it came back from the browser, so it is untrusted.
    $json = \aiplacement_modgen\local\json_sanitiser::clean(is_array($json) ? $json : []);

    $moduletype = !empty($adata->moduletype) ? $adata->moduletype : 'connected_weekly';
    $createsummaryactivities = !empty($adata->createsummaryactivities);

    // Count sections for display purposes.
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
    } else if (!empty($json['sections'])) {
        // The weekly layout lists its weeks under 'sections'.
        $sectioncount = count($json['sections']);
    }

    // Refuse now rather than queue a job that is certain to fail in the background.
    try {
        \aiplacement_modgen\local\theme_builder::enforce_section_limit(
            $courseid,
            \aiplacement_modgen\local\theme_builder::count_projected_sections_from_json($json, $moduletype)
        );
    } catch (\moodle_exception $e) {
        if ($ajax) {
            \aiplacement_modgen\local\ajax_response::error($e->getMessage(), 'sectionlimitexceeded');
        }
        // On a full page, go back to the form with the reason rather than showing an error page.
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Always use background task for all creation jobs.
    $jobid = \aiplacement_modgen\local\job_status_helper::queue_creation_job($courseid, (int) $USER->id, 'create_from_json', [
        'json' => $json,
        'moduletype' => $moduletype,
        'createsummaryactivities' => $createsummaryactivities,
    ], [
        // The job row only needs enough to describe the job; the structure itself is in the task data.
        'moduletype' => $moduletype,
        'sectioncount' => $sectioncount,
        'createsummaryactivities' => $createsummaryactivities,
    ]);

    // Return queued response for AJAX.
    if ($ajax) {
        \aiplacement_modgen\local\ajax_response::success([
            'queued' => true,
            'jobid' => $jobid,
            'message' => get_string('jobqueued', 'aiplacement_modgen', $sectioncount),
        ]);
    }

    // For non-AJAX, redirect to job status page.
    $statusurl = new moodle_url('/ai/placement/modgen/job_status.php', ['jobid' => $jobid]);
    redirect($statusurl);
}


// Generator form: Create and display for standalone page access
// Check which form is being submitted.
$ispromptform = optional_param('_qf__aiplacement_modgen_prompt_form', 0, PARAM_BOOL);

if ($ispromptform) {
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

    // Render header template.
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
    $prompt = !empty($pdata->prompt) ? trim($pdata->prompt) : '';
    $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'connected_weekly';

    // Check if user wants the learningactivity "section summary" placeholders.
    $createsummaryactivities = !empty($pdata->createsummaryactivities);

    $typeinstruction = get_string('moduletypeinstruction_' . $moduletype, 'aiplacement_modgen');

    // Build composite prompt - combine user prompt with type instruction.
    if (!empty($prompt)) {
        $compositeprompt = trim($prompt . "\n\n" . $typeinstruction);
    } else {
        $compositeprompt = trim($typeinstruction);
    }

    // Theme sections are containers only: tell the AI to leave theme summaries empty.
    if ($moduletype === 'connected_theme') {
        $compositeprompt .= "\n\nIMPORTANT: Do NOT generate summaries for themes. Leave the 'summary' field EMPTY " .
            "for each theme object (empty string, not null). Only provide theme titles and the weeks array. " .
            "This keeps the theme sections as containers without descriptive text.";
    }

    // Generate module using template processing service.
    $templateprocessor = new \aiplacement_modgen\local\template_processing_service();

    try {
        $json = $templateprocessor->process_and_generate(
            $pdata,
            $courseid,
            $compositeprompt
        );

        // Check if the service returned a detected module type (from CSV auto-detection).
        if (!empty($json['_detected_moduletype'])) {
            $moduletype = $json['_detected_moduletype'];
            unset($json['_detected_moduletype']); // Remove internal flag.
        }

        // Debug: Check what was returned.
        if (empty($json)) {
            throw new \moodle_exception('templateprocessorempty', 'aiplacement_modgen');
        }

        if (!is_array($json)) {
            throw new \moodle_exception('templateprocessorempty', 'aiplacement_modgen');
        }
    } catch (\Throwable $e) {
        $errorhtml = html_writer::div(
            html_writer::tag('h4', get_string('generationfailed', 'aiplacement_modgen'), ['class' => 'text-danger']) .
            $OUTPUT->notification(s(get_string('error') . ': ' . $e->getMessage()), \core\output\notification::NOTIFY_ERROR),
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

    if (!empty($json['error'])) {
        $errorhtml = html_writer::div(
            html_writer::tag('h4', get_string('aierrorheading', 'aiplacement_modgen'), ['class' => 'text-danger']) .
            $OUTPUT->notification(s($json['template']), \core\output\notification::NOTIFY_ERROR),
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
        // AI returned malformed structure - show error and don't allow approval.
        $errorhtml = html_writer::div(
            html_writer::tag('h4', get_string('generationfailed', 'aiplacement_modgen'), ['class' => 'text-danger']) .
            $OUTPUT->notification(s($json['validation_error']), \core\output\notification::NOTIFY_ERROR) .
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
    // For fresh generation (start from scratch), skip re-encoding module data for summary
    // Just use a simple generated fallback summary instead.
    $summarytext = aiplacement_modgen_generate_fallback_summary($json, $moduletype);
    $summaryformatted = $summarytext !== '' ? nl2br(s($summarytext)) : '';

    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $jsonstr,
        'moduletype' => $moduletype,
        'generatedsummary' => $summarytext,
        'createsummaryactivities' => $createsummaryactivities ? 1 : 0,
        'embedded' => $embedded ? 1 : 0,
        'usedaioptions' => !empty($prompt) ? 1 : 0,
    ]);

    $notifications = [];
    if (!empty($json['error'])) {
        $notifications[] = [
            'html' => $OUTPUT->notification(s($json['template']), \core\output\notification::NOTIFY_ERROR),
        ];
    }

    $formhtml = '';
    ob_start();
    $approveform->display();
    $formhtml = ob_get_clean();

    // Add regenerate button functionality if AI is enabled.
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

    // Build module preview from the generated JSON.
    $modulepreview = aiplacement_modgen_build_module_preview($json, $moduletype);
    // Ensure modulepreview is always included (will be truthy if it has themes or weeks).
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

    // Define footer buttons for the preview step - server-driven approach.
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

    // For non-AJAX, use the normal response with form buttons.
    $footeractions = [];
    aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
    exit;
}

// If form validation failed, redisplay the form with errors.
if ($promptform->is_submitted()) {
    $PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
    $PAGE->set_title(get_string('modgenmodalheading', 'aiplacement_modgen'));
    $PAGE->set_heading(get_string('modgenmodalheading', 'aiplacement_modgen'));

    echo $OUTPUT->header();

    // Render header template.
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
// This shouldn't happen in normal flow.
throw new moodle_exception('errorunexpected', 'aiplacement_modgen');
