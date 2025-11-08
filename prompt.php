<?php
if (!defined('AJAX_SCRIPT') && !empty($_REQUEST['ajax'])) {
    define('AJAX_SCRIPT', true);
}
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

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_login();

// Include form classes
require_once(__DIR__ . '/classes/form/generator_form.php');
require_once(__DIR__ . '/classes/form/approve_form.php');
require_once(__DIR__ . '/classes/form/upload_form.php');

// Cache configuration values for efficiency
$pluginconfig = (object)[
    'timeout' => get_config('aiplacement_modgen', 'timeout') ?: 300,
    'enable_templates' => get_config('aiplacement_modgen', 'enable_templates'),
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
    if (!defined('AJAX_SCRIPT') || !AJAX_SCRIPT) {
        return;
    }

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

// Resolve course id from id or courseid.
$courseid = optional_param('id', 0, PARAM_INT);
if (!$courseid) {
    $courseid = optional_param('courseid', 0, PARAM_INT);
}
if (!$courseid) {
    print_error('missingcourseid', 'aiplacement_modgen');
}

$context = context_course::instance($courseid);

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
    if ($ajax) {
        // For AJAX requests, return policy acceptance form.
        $body = '
            <div class="ai-policy-acceptance">
                <h4>' . get_string('aipolicyacceptance', 'aiplacement_modgen') . '</h4>
                <div class="alert alert-info">
                    <p>' . get_string('aipolicyinfo', 'aiplacement_modgen') . '</p>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="acceptpolicy">
                    <label class="form-check-label" for="acceptpolicy">
                        ' . get_string('acceptaipolicy', 'aiplacement_modgen') . '
                    </label>
                </div>
                <form id="ai-policy-form" method="post">
                    <input type="hidden" name="courseid" value="' . $courseid . '">
                    <input type="hidden" name="acceptpolicy" value="1">
                    <input type="hidden" name="embedded" value="' . ($embedded ? 1 : 0) . '">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="sesskey" value="' . sesskey() . '">
                    <button type="submit" id="hidden-submit-btn" style="display: none;">Submit</button>
                </form>
            </div>
        ';
        
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
        
        // Add JavaScript to handle policy acceptance
        $js = '
        <script>
            require(["jquery"], function($) {
                $("#acceptpolicy").on("change", function() {
                    $("[data-action=\"aiplacement-modgen-submit\"]").prop("disabled", !this.checked);
                });
                
                // Handle form submission for policy acceptance
                $("#ai-policy-form").on("submit", function(e) {
                    if (!$("#acceptpolicy").is(":checked")) {
                        e.preventDefault();
                        return false;
                    }
                    // Allow normal form submission to server
                    // After the server processes it, the response should show the main form
                });
            });
        </script>';
        
        aiplacement_modgen_send_ajax_response($body . $js, $footer);
    } else {
        // For regular requests, show error.
        print_error('aipolicynotaccepted', 'aiplacement_modgen');
    }
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

// Handle AJAX request for upload form only (to avoid filepicker initialization in hidden elements).
if ($ajax && optional_param('action', '', PARAM_ALPHA) === 'getuploadform') {
    require_sesskey();
    $uploadform = new aiplacement_modgen_upload_form(null, [
        'courseid' => $courseid,
        'embedded' => $embedded ? 1 : 0,
    ]);
    
    ob_start();
    $uploadform->display();
    $uploadformhtml = ob_get_clean();
    
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'form' => $uploadformhtml,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Business logic - use cached config values.
require_once(__DIR__ . '/classes/local/ai_service.php');
require_once(__DIR__ . '/classes/activitytype/registry.php');
require_once(__DIR__ . '/classes/local/template_reader.php');
require_once(__DIR__ . '/classes/local/filehandler/file_processor.php');

// Load course libraries once (used by approval form processing)
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/subsection/classes/manager.php');

// Attempt approval form first (so refreshes on approval post are handled).
$approveform = null;
$approvedjsonparam = optional_param('approvedjson', null, PARAM_RAW);
$approvedtypeparam = optional_param('moduletype', 'weekly', PARAM_ALPHA);
$keepweeklabelsparam = optional_param('keepweeklabels', 0, PARAM_BOOL);
$generatethemeintroductionsparam = optional_param('generatethemeintroductions', 0, PARAM_BOOL);
$createsuggestedactivitiesparam = optional_param('createsuggestedactivities', 0, PARAM_BOOL);
$generatedsummaryparam = optional_param('generatedsummary', '', PARAM_RAW);
$curriculumtemplateparam = optional_param('curriculum_template', '', PARAM_TEXT);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'keepweeklabels' => $keepweeklabelsparam,
        'generatethemeintroductions' => $generatethemeintroductionsparam,
        'createsuggestedactivities' => $createsuggestedactivitiesparam,
        'generatedsummary' => $generatedsummaryparam,
        'curriculum_template' => $curriculumtemplateparam,
        'embedded' => $embedded ? 1 : 0,
    ]);
}

    if ($approveform && ($adata = $approveform->get_data())) {
        // Create weekly sections from approved JSON.
        $json = json_decode($adata->approvedjson, true);
        $moduletype = !empty($adata->moduletype) ? $adata->moduletype : 'weekly';
        $keepweeklabels = ($moduletype === 'weekly' || $moduletype === 'connected_weekly') && !empty($adata->keepweeklabels);
        
        // Update course format based on module type.
        // Connected formats require flexsections plugin to be installed
        if ($moduletype === 'connected_weekly' || $moduletype === 'connected_theme') {
            // Check if flexsections is actually installed
            $pluginmanager = core_plugin_manager::instance();
            $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
            
            if (!empty($flexsectionsplugin)) {
                // For Connected formats, use flexsections course format
                $courseformat = 'flexsections';
            } else {
                // Fallback to weeks if flexsections not available
                error_log('flexsections format not installed - falling back to weeks format');
                $courseformat = 'weeks';
                $moduletype = 'weekly'; // Downgrade to standard weekly
            }
        } else {
            // For standard formats, use weeks (weekly)
            $courseformat = 'weeks';
        }
        
        $update = new stdClass();
        $update->id = $courseid;
        $update->format = $courseformat;
        
        update_course($update);
        rebuild_course_cache($courseid, true, true);
        $course = get_course($courseid);

        $results = [];
        $needscacherefresh = false;
        $activitywarnings = [];
        
        // Get the course format instance
        $courseformat = course_get_format($course);
        
        if ($moduletype === 'connected_theme' && !empty($json['themes']) && is_array($json['themes'])) {
        // Use flexsections create_new_section for nested section support
        // (Only called if flexsections is confirmed available above)
        $themesectionnums = [];
        
        foreach ($json['themes'] as $themeindex => $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $title = $theme['title'] ?? get_string('themefallback', 'aiplacement_modgen');
            $summary = $theme['summary'] ?? '';
            $weeks = !empty($theme['weeks']) && is_array($theme['weeks']) ? $theme['weeks'] : [];

            // Create the parent theme section using flexsections
            try {
                // Check if method exists before calling it (safety check for non-flexsections formats)
                if (!method_exists($courseformat, 'create_new_section')) {
                    throw new Exception('Course format does not support nested sections. Flexsections plugin may not be installed.');
                }
                $themesectionnum = $courseformat->create_new_section(0, null); // 0 means top level (no parent)
                $themesectionnums[] = $themesectionnum;
            } catch (Exception $e) {
                $activitywarnings[] = "Failed to create theme section: " . $e->getMessage();
                continue;
            }
            
            $themetitle = format_string($title, true, ['context' => $context]);
            $sectionhtml = '';
            
            // Only include theme summary if "Generate theme introductions" is checked
            if (!empty($adata->generatethemeintroductions) && trim($summary) !== '') {
                $sectionhtml = format_text($summary, FORMAT_HTML, ['context' => $context]);
                // If a curriculum template was used, ensure IDs inside the template HTML are unique
                if (!empty($adata->curriculum_template)) {
                    require_once(__DIR__ . '/classes/local/template_structure_parser.php');
                    $sectionhtml = \aiplacement_modgen\template_structure_parser::ensure_unique_ids($sectionhtml, 'sec' . $themesectionnum);
                }
            }
            
            // Update the theme section name and summary
            $DB->update_record('course_sections', [
                'id' => $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $themesectionnum]),
                'name' => $themetitle,
                'summary' => $sectionhtml,
                'summaryformat' => FORMAT_HTML,
            ]);
            
            // Set theme section to appear as a link (collapsed = 1 in flexsections)
            $themesectionid = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $themesectionnum]);
            if (method_exists($courseformat, 'update_section_format_options')) {
                $courseformat->update_section_format_options(['id' => $themesectionid, 'collapsed' => 1]);
            }
            
            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $themetitle);
            
            // Now create nested week subsections under this theme
            if (!empty($weeks)) {
                foreach ($weeks as $weekindex => $week) {
                    if (!is_array($week)) {
                        continue;
                    }
                    $weektitle = $week['title'] ?? get_string('weekfallback', 'aiplacement_modgen') . ' ' . ($weekindex + 1);
                    $weeksummary = $week['summary'] ?? '';
                    $activities = !empty($week['activities']) && is_array($week['activities']) ? $week['activities'] : [];
                    
                    // Create nested week section under the theme
                    try {
                        if (!method_exists($courseformat, 'create_new_section')) {
                            throw new Exception('Course format does not support nested sections. Flexsections plugin may not be installed.');
                        }
                        $weeksectionnum = $courseformat->create_new_section($themesectionnum, null);
                    } catch (Exception $e) {
                        $activitywarnings[] = "Failed to create week section: " . $e->getMessage();
                        continue;
                    }
                    
                    $weektitle = format_string($weektitle, true, ['context' => $context]);
                    $weeksectionhtml = '';
                    if (trim($weeksummary) !== '') {
                        $weeksectionhtml = format_text($weeksummary, FORMAT_HTML, ['context' => $context]);
                        if (!empty($adata->curriculum_template)) {
                            require_once(__DIR__ . '/classes/local/template_structure_parser.php');
                            $weeksectionhtml = \aiplacement_modgen\template_structure_parser::ensure_unique_ids($weeksectionhtml, 'sec' . $weeksectionnum);
                        }
                    }
                    
                    // Update the week section
                    $DB->update_record('course_sections', [
                        'id' => $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $weeksectionnum]),
                        'name' => $weektitle,
                        'summary' => $weeksectionhtml,
                        'summaryformat' => FORMAT_HTML,
                    ]);
                    
                    // Set week section to appear as a link (collapsed = 1 in flexsections)
                    $weeksectionid = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $weeksectionnum]);
                    if (method_exists($courseformat, 'update_section_format_options')) {
                        $courseformat->update_section_format_options(['id' => $weeksectionid, 'collapsed' => 1]);
                    }
                    
                    $results[] = get_string('sectioncreated', 'aiplacement_modgen', $weektitle);
                    
                    // Create the three session subsections under the week and store their section numbers
                    $sessiontypes = [
                        'presession' => get_string('presession', 'aiplacement_modgen'),
                        'session' => get_string('session', 'aiplacement_modgen'),
                        'postsession' => get_string('postsession', 'aiplacement_modgen'),
                    ];
                    
                    $sessionsectionmap = []; // Map session type to section number
                    
                    foreach ($sessiontypes as $sessionkey => $sessionlabel) {
                        try {
                            // Create nested subsection under the week (parent = weeksectionnum)
                            if (!method_exists($courseformat, 'create_new_section')) {
                                throw new Exception('Course format does not support nested sections. Flexsections plugin may not be installed.');
                            }
                            $sessionsectionnum = $courseformat->create_new_section($weeksectionnum, null);
                            $sessionsectionmap[$sessionkey] = $sessionsectionnum;
                            
                            // Update session section name
                            $sessionsectionid = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $sessionsectionnum]);
                            $DB->update_record('course_sections', [
                                'id' => $sessionsectionid,
                                'name' => $sessionlabel,
                            ]);
                            
                            // Set session section to NOT appear as a link (collapsed = 0 in flexsections)
                            if (method_exists($courseformat, 'update_section_format_options')) {
                                $courseformat->update_section_format_options(['id' => $sessionsectionid, 'collapsed' => 0]);
                            }
                            
                            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $sessionlabel);
                        } catch (Exception $e) {
                            $activitywarnings[] = "Failed to create $sessionkey section: " . $e->getMessage();
                        }
                    }
                    
                    // Create activities in the appropriate session subsections
                    if (!empty($adata->createsuggestedactivities)) {
                        // Check if week has nested sessions structure
                        if (!empty($week['sessions']) && is_array($week['sessions'])) {
                            // New nested structure: activities are in week['sessions']['presession|session|postsession']['activities']
                            foreach ($sessiontypes as $sessionkey => $sessionlabel) {
                                if (!empty($week['sessions'][$sessionkey]) && is_array($week['sessions'][$sessionkey])) {
                                    $sessiondata = $week['sessions'][$sessionkey];
                                    $sessionactivities = $sessiondata['activities'] ?? [];
                                    
                                    if (!empty($sessionactivities) && is_array($sessionactivities)) {
                                        $sessionsectionnum = $sessionsectionmap[$sessionkey] ?? null;
                                        if ($sessionsectionnum !== null) {
                                            $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                                                $sessionactivities,
                                                $course,
                                                $sessionsectionnum
                                            );
                                            
                                            if (!empty($activityoutcome['created'])) {
                                                $results = array_merge($results, $activityoutcome['created']);
                                            }
                                            if (!empty($activityoutcome['warnings'])) {
                                                $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                                            }
                                        }
                                    }
                                }
                            }
                        } else if (!empty($activities) && is_array($activities)) {
                            // Fallback: Old flat structure where activities are directly in week
                            $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                                $activities,
                                $course,
                                $weeksectionnum
                            );
                            
                            if (!empty($activityoutcome['created'])) {
                                $results = array_merge($results, $activityoutcome['created']);
                            }
                            if (!empty($activityoutcome['warnings'])) {
                                $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                            }
                        }
                    }
                }
            }
        }
        $needscacherefresh = true;
    } else if (!empty($json['sections']) && is_array($json['sections'])) {
        $modinfo = get_fast_modinfo($courseid);
        $existingsections = $modinfo->get_section_info_all();
        $sectionnum = empty($existingsections) ? 1 : max(array_keys($existingsections)) + 1;

        foreach ($json['sections'] as $sectiondata) {
            if (!is_array($sectiondata)) {
                continue;
            }
            $title = $sectiondata['title'] ?? get_string('aigensummary', 'aiplacement_modgen');
            $summary = $sectiondata['summary'] ?? '';
            $outline = !empty($sectiondata['outline']) && is_array($sectiondata['outline']) ? $sectiondata['outline'] : [];
            $section = course_create_section($course, $sectionnum);
            $sectionrecord = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
            $sectionhtml = '';
            if ($keepweeklabels) {
                $sectionhtml .= html_writer::tag('h3', s($title));
            }
            $summaryhtml = trim(format_text($summary, FORMAT_HTML, ['context' => $context]));
            if ($summaryhtml !== '') {
                $sectionhtml .= $summaryhtml;
                // If using a curriculum template, ensure any ids are uniquified
                if (!empty($adata->curriculum_template)) {
                    require_once(__DIR__ . '/classes/local/template_structure_parser.php');
                    $sectionhtml = \aiplacement_modgen\template_structure_parser::ensure_unique_ids($sectionhtml, 'sec' . $sectionnum);
                }
            }

            if (!empty($outline)) {
                $items = '';
                foreach ($outline as $entry) {
                    if (!is_string($entry) || trim($entry) === '') {
                        continue;
                    }
                    $items .= html_writer::tag('li', s($entry));
                }
                if ($items !== '') {
                    $sectionhtml .= html_writer::tag('h4', get_string('weeklyoutline', 'aiplacement_modgen'));
                    $sectionhtml .= html_writer::tag('ul', $items);
                }
            }

            if (!$keepweeklabels) {
                $sectionrecord->name = $title;
            }
            $sectionrecord->summary = $sectionhtml;
            $sectionrecord->summaryformat = FORMAT_HTML;
            $sectionrecord->timemodified = time();
            $DB->update_record('course_sections', $sectionrecord);

            if (!empty($sectiondata['activities']) && is_array($sectiondata['activities'])) {
                $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                    $sectiondata['activities'],
                    $course,
                    $sectionnum
                );
                
                if (!empty($activityoutcome['created'])) {
                    $results = array_merge($results, $activityoutcome['created']);
                }
                if (!empty($activityoutcome['warnings'])) {
                    $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                }
            }

            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            $sectionnum++;
        }
    }

        if ($needscacherefresh) {
            rebuild_course_cache($courseid, true, true);
        }

        $resultsdata = [
            'notifications' => [],
            'hasresults' => !empty($results),
            'results' => array_map(static function(string $text): array {
                return ['text' => $text];
            }, $results),
            'showreturnlinkinbody' => !$ajax,
        ];

        if (!empty($activitywarnings)) {
            foreach ($activitywarnings as $warning) {
                $resultsdata['notifications'][] = [
                    'message' => $warning,
                    'classes' => 'alert alert-warning',
                ];
            }
        }

        if ($embedded) {
            $resultsdata['returnlink'] = [
                'url' => '#',
                'label' => get_string('closemodgenmodal', 'aiplacement_modgen'),
                'dataaction' => 'aiplacement-modgen-close',
            ];
            if (!$ajax) {
                $PAGE->requires->js_call_amd('aiplacement_modgen/embedded_results', 'init');
            }
        } else {
            $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
            $resultsdata['returnlink'] = [
                'url' => $courseurl->out(false),
                'label' => get_string('returntocourse', 'aiplacement_modgen'),
            ];
        }

        if (empty($results)) {
            $resultsdata['notifications'][] = [
                'message' => get_string('nosectionscreated', 'aiplacement_modgen'),
                'classes' => 'alert alert-warning',
            ];
        }

        $bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/generation_results', $resultsdata);
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

// Prompt form handling.
$promptform = new aiplacement_modgen_generator_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
    'contextid' => context_course::instance((int)$courseid)->id,
]);

// If requested, render the generator form as a standalone page (not inside the modal).
$standalone = optional_param('standalone', 0, PARAM_BOOL);
if (!$ajax && $standalone) {
    $PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['courseid' => $courseid, 'standalone' => 1]));
    $PAGE->set_title(get_string('modgenmodalheading', 'aiplacement_modgen'));
    $PAGE->set_heading(get_string('modgenmodalheading', 'aiplacement_modgen'));

    echo $OUTPUT->header();
    echo html_writer::div('<h2>' . get_string('launchgenerator', 'aiplacement_modgen') . '</h2>', 'aiplacement-modgen__page-heading');
    
    // Display introduction and warning
    echo html_writer::div(get_string('generatorintroduction', 'aiplacement_modgen'), 'aiplacement-modgen__introduction');
    echo html_writer::div(
        '<div class="alert alert-info"><i class="fa fa-info-circle"></i> ' . 
        get_string('longquery', 'aiplacement_modgen') . '</div>',
        'aiplacement-modgen__warning'
    );
    
    $promptform->display();
    echo $OUTPUT->footer();
    exit;
}

// Upload form handling.
$uploadform = new aiplacement_modgen_upload_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
]);

if ($promptform->is_cancelled() || $uploadform->is_cancelled()) {
    if ($ajax) {
        aiplacement_modgen_send_ajax_response('', '', false, ['close' => true]);
    }
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// Handle upload form submission.
if (!empty($_FILES['contentfile']) || !empty($_POST['contentfile_itemid'])) {
    $uploaddata = $uploadform->get_data();
    if ($uploaddata) {
        try {
            $file_processor = new \aiplacement_modgen\local\filehandler\file_processor();
            $courseid_int = (int) $courseid;
            $course = get_course($courseid_int);
            
            // Get the uploaded file from filepicker draft area
            $usercontextid = context_user::instance($USER->id)->id;
            $file_storage = get_file_storage();
            $files = $file_storage->get_area_files($usercontextid, 'user', 'draft', $uploaddata->contentfile);
            
            $file = null;
            foreach ($files as $f) {
                if (!$f->is_directory()) {
                    $file = $f;
                    break;
                }
            }
            
            if (!$file) {
                throw new Exception(get_string('nofileuploadederror', 'aiplacement_modgen'));
            }
            
            // Extract content from the file.
            $chapters = $file_processor->extract_content_from_file($file, 'html');
            
            if (empty($chapters)) {
                throw new Exception(get_string('nochaptersextractederror', 'aiplacement_modgen'));
            }
            
            // Create the book activity using the registry
            $activity_data = new stdClass();
            $activity_data->name = $uploaddata->activityname;
            $activity_data->intro = $uploaddata->activityintro ?? '';
            $activity_data->chapters = $chapters;
            
            $bookhandler = \aiplacement_modgen\activitytype\registry::get_handler('book');
            if (!$bookhandler) {
                throw new Exception('Book activity handler not available');
            }
            
            $book_module = $bookhandler->create(
                $activity_data,
                $course,
                (int) $uploaddata->sectionnumber
            );
            
            $success_message = get_string('bookactivitycreated', 'aiplacement_modgen', $uploaddata->activityname);
            
            if ($ajax) {
                aiplacement_modgen_send_ajax_response('', '', false, ['close' => true, 'success' => $success_message]);
            } else {
                redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
            }
        } catch (Exception $e) {
            if ($ajax) {
                aiplacement_modgen_send_ajax_response($e->getMessage(), '', false);
            }
        }
    }
}if ($pdata = $promptform->get_data()) {
    // Check if debug button was clicked
    if (!empty($pdata->debugbutton)) {
        $prompt = !empty($pdata->prompt) ? trim($pdata->prompt) : '';
        $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'weekly';
        $curriculum_template = !empty($pdata->curriculum_template) ? $pdata->curriculum_template : '';
        $existing_module = !empty($pdata->existing_module) ? $pdata->existing_module : 0;
        
        // Try to extract template data
        $template_data = null;
        $template_data_debug = [];
        
        if (!empty($curriculum_template) || !empty($existing_module)) {
            try {
                $template_reader = new \aiplacement_modgen\local\template_reader();
                $template_source = !empty($existing_module) ? (string)$existing_module : $curriculum_template;
                $template_data_debug[] = 'Template source: ' . $template_source;
                
                // First, check if the course exists
                global $DB;
                $course_check = $DB->get_record('course', ['id' => (int)$template_source]);
                if (!$course_check) {
                    $template_data_debug[] = 'ERROR: Course ID ' . $template_source . ' not found in database';
                } else {
                    $template_data_debug[] = 'Course exists: ' . $course_check->fullname;
                    
                    // Check user access
                    $course_context = \context_course::instance((int)$template_source);
                    $has_access = has_capability('moodle/course:view', $course_context);
                    $template_data_debug[] = 'User has access: ' . ($has_access ? 'YES' : 'NO');
                    
                    if (!$has_access) {
                        throw new Exception('You do not have access to this course');
                    }
                    
                    try {
                        $template_data = $template_reader->extract_curriculum_template($template_source);
                        $template_data_debug[] = 'Success! Template data extracted.';
                        $template_data_debug[] = 'Keys: ' . implode(', ', array_keys($template_data ?? []));
                        
                        if (!empty($template_data['course_info'])) {
                            $template_data_debug[] = 'Course: ' . $template_data['course_info']['name'];
                        }
                        if (!empty($template_data['structure'])) {
                            $template_data_debug[] = 'Sections: ' . count($template_data['structure']);
                        }
                        if (!empty($template_data['activities'])) {
                            $template_data_debug[] = 'Activities: ' . count($template_data['activities']);
                        }
                    } catch (Throwable $extract_error) {
                        $template_data_debug[] = 'EXTRACTION ERROR: ' . $extract_error->getMessage();
                        $template_data_debug[] = 'Error Type: ' . get_class($extract_error);
                        
                        // Log the full trace for debugging
                        error_log("TEMPLATE_DEBUG: Full exception trace: \n" . $extract_error->getTraceAsString());
                        
                        // Try to extract via simpler method if JOIN failed
                        $template_data_debug[] = '';
                        $template_data_debug[] = 'Attempting fallback extraction method...';
                        
                        try {
                            global $DB;
                            $courseid_int = (int)$template_source;
                            $course = $DB->get_record('course', ['id' => $courseid_int]);
                            if (!$course) {
                                throw new Exception('Course not found');
                            }
                            
                            // Build minimal template data without complex queries
                            $template_data = [
                                'course_info' => [
                                    'name' => $course->fullname,
                                    'format' => $course->format,
                                    'summary' => strip_tags($course->summary ?? '')
                                ],
                                'structure' => [],
                                'activities' => [],
                                'template_html' => ''
                            ];
                            
                            $template_data_debug[] = 'Fallback SUCCESS - got course info only';
                            $template_data_debug[] = 'Course: ' . $course->fullname;
                        } catch (Throwable $fallback_error) {
                            $template_data_debug[] = 'Fallback FAILED: ' . $fallback_error->getMessage();
                            $template_data = null;
                        }
                    }
                    
                }
                
            } catch (Exception $e) {
                $template_data_debug[] = 'ERROR: ' . $e->getMessage();
                error_log("DEBUG BUTTON ERROR: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());
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
    $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'weekly';
    $keepweeklabels = !empty($pdata->keepweeklabels);
    $generatethemeintroductions = !empty($pdata->generatethemeintroductions);
    $createsuggestedactivities = !empty($pdata->createsuggestedactivities);
    $curriculum_template = !empty($pdata->curriculum_template) ? $pdata->curriculum_template : '';
    $existing_module = !empty($pdata->existing_module) ? $pdata->existing_module : 0;
    
    $typeinstruction = get_string('moduletypeinstruction_' . $moduletype, 'aiplacement_modgen');
    
    // Build composite prompt - combine user prompt with type instruction
    // If an existing module is selected, tell the AI to use it as a guide
    if (!empty($prompt)) {
        if (!empty($existing_module)) {
            // User provided both a prompt AND selected a module - use both
            $compositeprompt = trim($prompt . "\n\n" . 
                "You will receive the structure and activities from an existing course as a reference guide. Use this reference structure as a template, but adapt the content and structure based on the user's prompt above.\n\n" .
                $typeinstruction);
        } else {
            // User provided a prompt but no module selection
            $compositeprompt = trim($prompt . "\n\n" . $typeinstruction);
        }
    } else {
        // No user prompt provided
        if (!empty($existing_module)) {
            // If existing module selected but no prompt, ask AI to translate/adapt it
            $compositeprompt = "Translate and adapt the existing module structure to the following format:\n\n" . $typeinstruction;
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

    // Gather supporting files (if any) from the filemanager draft area and try to extract readable text
    $supportingfiles = [];
    // First, check for direct file uploads from a simple <input type="file" multiple> fallback
    if (!empty($_FILES['supportingfiles_files']) && !empty($_FILES['supportingfiles_files']['tmp_name'])) {
        $ff = $_FILES['supportingfiles_files'];
        for ($i = 0; $i < count($ff['tmp_name']); $i++) {
            if (empty($ff['tmp_name'][$i]) || !is_uploaded_file($ff['tmp_name'][$i])) {
                continue;
            }
            $filename = $ff['name'][$i] ?? ('file' . $i);
            $mimetype = $ff['type'][$i] ?? '';
            $content = file_get_contents($ff['tmp_name'][$i]);

            // reuse extraction logic below by creating a temporary file-like array
            $extracted = '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, ['txt', 'md', 'html', 'htm'])) {
                $extracted = is_string($content) ? $content : '';
            } elseif ($ext === 'rtf' || $mimetype === 'application/rtf' || $mimetype === 'text/rtf') {
                // Extract text from RTF by stripping RTF formatting codes
                $extracted = is_string($content) ? $content : '';
                // Remove RTF header and formatting commands
                $extracted = preg_replace('/\{\\\?[^}]*\}/', '', $extracted);  // Remove \*\ blocks
                $extracted = preg_replace('/\\\\[a-z]+\d*\s?/', '', $extracted);  // Remove control words like \f0, \fs30
                $extracted = preg_replace('/\\\\["\047][0-9a-f]{2}/', '', $extracted);  // Remove hex chars
                $extracted = preg_replace('/[{}]/', '', $extracted);  // Remove braces
                $extracted = preg_replace('/\s+/', ' ', $extracted);  // Collapse whitespace
                $extracted = trim($extracted);
                // Clean up any remaining escaped characters (RTF em-dash and quote)
                $extracted = str_replace(['\\\'97', '\\\'92'], ['-', '\''], $extracted);
            } elseif ($ext === 'docx') {
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_docx_');
                file_put_contents($tmp, $content);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $index = $zip->locateName('word/document.xml');
                    if ($index !== false) {
                        $xml = $zip->getFromIndex($index);
                        $xml = preg_replace('/<w:p[^>]*>/', "\n", $xml);
                        $xml = preg_replace('/<br \/>/', "\n", $xml);
                        $extracted = strip_tags($xml);
                    }
                    $zip->close();
                }
                @unlink($tmp);
            } elseif ($ext === 'odt') {
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_odt_');
                file_put_contents($tmp, $content);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $index = $zip->locateName('content.xml');
                    if ($index !== false) {
                        $xml = $zip->getFromIndex($index);
                        $xml = preg_replace('/<text:p[^>]*>/', "\n", $xml);
                        $extracted = strip_tags($xml);
                    }
                    $zip->close();
                }
                @unlink($tmp);
            } elseif (strpos($mimetype, 'text/') === 0 || strpos($mimetype, 'application/xml') === 0 || strpos($mimetype, 'application/json') === 0) {
                $extracted = is_string($content) ? $content : '';
            } elseif ($ext === 'pdf' || $mimetype === 'application/pdf') {
                // Try to extract text from PDF using pdftotext if available on the server.
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_pdf_');
                file_put_contents($tmp, $content);
                $extracted = '';
                if (function_exists('shell_exec')) {
                    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null'));
                    if (!empty($pdftotext)) {
                        // Use -layout to preserve basic structure and output to stdout (-)
                        $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null';
                        $out = shell_exec($cmd);
                        if (is_string($out) && trim($out) !== '') {
                            $extracted = $out;
                        }
                    }
                }
                @unlink($tmp);
                if ($extracted === '') {
                    // Fallback placeholder so AI knows the PDF was provided.
                    $extracted = '[PDF FILE: ' . $filename . ' (' . $mimetype . '); base64_preview=' . substr(base64_encode($content), 0, 1024) . ']';
                }
            } else {
                $extracted = '[BINARY FILE: ' . $filename . ' (' . $mimetype . '); base64_preview=' . substr(base64_encode($content), 0, 1024) . ']';
            }

            if (is_string($extracted) && strlen($extracted) > 100000) {
                $extracted = substr($extracted, 0, 100000) . "\n...[truncated]";
            }

            $supportingfiles[] = [
                'filename' => $filename,
                'mimetype' => $mimetype,
                'content' => $extracted,
            ];
        }
    }

    if (!empty($pdata->supportingfiles)) {
        $draftitemid = $pdata->supportingfiles;
        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            $filename = $f->get_filename();
            $mimetype = $f->get_mimetype();
            $content = $f->get_content();

            $extracted = '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Try simple text extraction for common document types
            if (in_array($ext, ['txt', 'md', 'html', 'htm'])) {
                $extracted = is_string($content) ? $content : '';
            } elseif ($ext === 'rtf' || $mimetype === 'application/rtf' || $mimetype === 'text/rtf') {
                // Extract text from RTF by stripping RTF formatting codes
                $extracted = is_string($content) ? $content : '';
                // Remove RTF header and formatting commands
                $extracted = preg_replace('/\{\\\?[^}]*\}/', '', $extracted);  // Remove \*\ blocks
                $extracted = preg_replace('/\\\\[a-z]+\d*\s?/', '', $extracted);  // Remove control words like \f0, \fs30
                $extracted = preg_replace('/\\\\["\047][0-9a-f]{2}/', '', $extracted);  // Remove hex chars
                $extracted = preg_replace('/[{}]/', '', $extracted);  // Remove braces
                $extracted = preg_replace('/\s+/', ' ', $extracted);  // Collapse whitespace
                $extracted = trim($extracted);
                // Clean up any remaining escaped characters (RTF em-dash and quote)
                $extracted = str_replace(['\\\'97', '\\\'92'], ['-', '\''], $extracted);
            } elseif ($ext === 'docx') {
                // attempt to extract from docx
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_docx_');
                file_put_contents($tmp, $content);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $index = $zip->locateName('word/document.xml');
                    if ($index !== false) {
                        $xml = $zip->getFromIndex($index);
                        // strip tags and convert common tags to newlines for readability
                        $xml = preg_replace('/<w:p[^>]*>/', "\n", $xml);
                        $xml = preg_replace('/<br \/>/', "\n", $xml);
                        $extracted = strip_tags($xml);
                    }
                    $zip->close();
                }
                @unlink($tmp);
            } elseif ($ext === 'odt') {
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_odt_');
                file_put_contents($tmp, $content);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $index = $zip->locateName('content.xml');
                    if ($index !== false) {
                        $xml = $zip->getFromIndex($index);
                        $xml = preg_replace('/<text:p[^>]*>/', "\n", $xml);
                        $extracted = strip_tags($xml);
                    }
                    $zip->close();
                }
                @unlink($tmp);
            } elseif (strpos($mimetype, 'text/') === 0 || strpos($mimetype, 'application/xml') === 0 || strpos($mimetype, 'application/json') === 0) {
                $extracted = is_string($content) ? $content : '';
            } elseif ($ext === 'pdf' || $mimetype === 'application/pdf') {
                // Try to extract text from PDF using pdftotext if available on the server.
                $tmp = tempnam(sys_get_temp_dir(), 'modgen_pdf_');
                file_put_contents($tmp, $content);
                $extracted = '';
                if (function_exists('shell_exec')) {
                    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null'));
                    if (!empty($pdftotext)) {
                        $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null';
                        $out = shell_exec($cmd);
                        if (is_string($out) && trim($out) !== '') {
                            $extracted = $out;
                        }
                    }
                }
                @unlink($tmp);
                if ($extracted === '') {
                    $extracted = '[PDF FILE: ' . $filename . ' (' . $mimetype . '); base64_preview=' . substr(base64_encode($content), 0, 1024) . ']';
                }
            } else {
                // Fallback: include a small base64 summary so AI knows the file exists.
                $extracted = '[BINARY FILE: ' . $filename . ' (' . $mimetype . '); base64_preview=' . substr(base64_encode($content), 0, 1024) . ']';
            }

            // Truncate large extracted content to a reasonable size (e.g., 100k chars)
            if (is_string($extracted) && strlen($extracted) > 100000) {
                $extracted = substr($extracted, 0, 100000) . "\n...[truncated]";
            }

            $supportingfiles[] = [
                'filename' => $filename,
                'mimetype' => $mimetype,
                'content' => $extracted,
            ];
        }
    }
    
    // If files were actually uploaded but no user prompt, add auto-instruction to use the file
    if (!empty($supportingfiles) && empty($prompt)) {
        $compositeprompt .= "\n\nUser has uploaded file(s) without providing a text prompt. Please use the uploaded file content to create the module structure and content.";
    }
    
    // Generate module with or without template
    // Debug tracking
    $debuglog = [];
    
    if (!empty($curriculum_template) || !empty($existing_module)) {
        try {
            $template_reader = new \aiplacement_modgen\local\template_reader();
            
            // Use existing_module if provided, otherwise use curriculum_template
            $template_source = !empty($existing_module) ? (string)$existing_module : $curriculum_template;
            $debuglog[] = 'Template source: ' . $template_source;
            
            try {
                // Try full extraction first
                $template_data = $template_reader->extract_curriculum_template($template_source);
                $debuglog[] = 'Full extraction succeeded';
            } catch (Throwable $e) {
                // If full extraction fails, try fallback
                $debuglog[] = 'Full extraction failed: ' . $e->getMessage();
                $debuglog[] = 'Attempting fallback extraction...';
                
                try {
                    global $DB;
                    $courseid_int = (int)$template_source;
                    $course = $DB->get_record('course', ['id' => $courseid_int]);
                    if (!$course) {
                        throw new Exception('Course not found');
                    }
                    
                    $template_data = [
                        'course_info' => [
                            'name' => $course->fullname,
                            'format' => $course->format,
                            'summary' => strip_tags($course->summary ?? '')
                        ],
                        'structure' => [],
                        'activities' => [],
                        'template_html' => ''
                    ];
                    $debuglog[] = 'Fallback extraction succeeded';
                } catch (Exception $fe) {
                    throw new Exception('Both full and fallback extraction failed: ' . $fe->getMessage());
                }
            }
            
            // Log what we got
            $debuglog[] = 'Template data keys: ' . implode(', ', array_keys($template_data ?? []));
            
            // Ensure template_data is an array and not empty
            if (!is_array($template_data) || empty($template_data)) {
                throw new Exception('Template data extraction returned empty result');
            }
            
            // Validate template data has content
            $data_summary = [];
            foreach ($template_data as $key => $value) {
                if (is_array($value)) {
                    $data_summary[$key] = 'array(' . count($value) . ')';
                } elseif (is_string($value)) {
                    $data_summary[$key] = 'string(' . strlen($value) . ')';
                } else {
                    $data_summary[$key] = gettype($value);
                }
            }
            $debuglog[] = 'Template data summary: ' . implode(', ', $data_summary);
            
            // Don't extract Bootstrap structure - just use the template data as-is
            // The template_data already contains course_info, structure, activities, and template_html
            
            $json = \aiplacement_modgen\ai_service::generate_module_with_template($compositeprompt, $template_data, $supportingfiles, $moduletype, $courseid);
        } catch (Exception $e) {
            // Fall back to normal generation if template fails
            $debuglog[] = 'Template extraction failed: ' . $e->getMessage();
            $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, [], $moduletype, null, $courseid);
        }
    } else {
    $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, $supportingfiles, $moduletype, null, $courseid);
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
        $jsonstr = print_r($json, true);
    }
    // For fresh generation (start from scratch), skip re-encoding module data for summary
    // Just use a simple generated fallback summary instead
    $summarytext = aiplacement_modgen_generate_fallback_summary($json, $moduletype);
    $summaryformatted = $summarytext !== '' ? nl2br(s($summarytext)) : '';

    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $jsonstr,
        'moduletype' => $moduletype,
        'keepweeklabels' => $keepweeklabels ? 1 : 0,
        'generatethemeintroductions' => $generatethemeintroductions ? 1 : 0,
        'createsuggestedactivities' => $createsuggestedactivities ? 1 : 0,
        'generatedsummary' => $summarytext,
        'curriculum_template' => $curriculum_template,
        'embedded' => $embedded ? 1 : 0,
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

    $previewdata = [
        'notifications' => $notifications,
        'hassummary' => $summarytext !== '',
        'summaryheading' => get_string('generationresultssummaryheading', 'aiplacement_modgen'),
        'summary' => $summaryformatted,
        'hasjson' => !empty($jsonstr),
        'jsonheading' => get_string('generationresultsjsonheading', 'aiplacement_modgen'),
        'jsondescription' => get_string('generationresultsjsondescription', 'aiplacement_modgen'),
        'json' => s($jsonstr),
        'jsonnote' => get_string('generationresultsjsonnote', 'aiplacement_modgen'),
        'form' => $formhtml,
        'promptheading' => get_string('generationresultspromptheading', 'aiplacement_modgen'),
        'prompttoggle' => get_string('generationresultsprompttoggle', 'aiplacement_modgen'),
        'prompttext' => format_text($prompt, FORMAT_PLAIN),
        'hasprompt' => trim($prompt) !== '',
    ];

    $bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/prompt_preview', $previewdata);
    $bodyhtml = html_writer::div($bodyhtml, 'aiplacement-modgen__content');

    $footeractions = [[
        'label' => get_string('reenterprompt', 'aiplacement_modgen'),
        'classes' => 'btn btn-secondary',
        'isbutton' => true,
        'action' => 'aiplacement-modgen-reenter',
    ], [
        'label' => get_string('approveandcreate', 'aiplacement_modgen'),
        'classes' => 'btn btn-primary',
        'isbutton' => true,
        'action' => 'aiplacement-modgen-submit',
        'index' => 0,
        'hasindex' => true,
    ]];

    aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
    exit;
}

// Default display: tabbed modal with generate and upload forms.
ob_start();
$promptform->display();
$generateformhtml = ob_get_clean();

// Don't render upload form in hidden tab - load it via AJAX instead to avoid filepicker initialization issues
$uploadformhtml = '';
$enablefileupload = get_config('aiplacement_modgen', 'enable_fileupload');

// Render tabbed modal
$tabdata = [
    'generatecontent' => $generateformhtml,
    'uploadcontent' => $uploadformhtml,
    'generatetablabel' => get_string('generatetablabel', 'aiplacement_modgen'),
    'uploadtablabel' => get_string('uploadtablabel', 'aiplacement_modgen'),
    'submitbuttontext' => get_string('submit', 'aiplacement_modgen'),
    'uploadbuttontext' => get_string('uploadandcreate', 'aiplacement_modgen'),
    'showuploadtab' => $enablefileupload,
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
];
$bodyhtml = $OUTPUT->render_from_template('aiplacement_modgen/modal_tabbed', $tabdata);
$bodyhtml = html_writer::div($bodyhtml, 'aiplacement-modgen__content');

$footeractions = [[
    'label' => get_string('submit', 'aiplacement_modgen'),
    'classes' => 'btn btn-primary',
    'isbutton' => true,
    'action' => 'aiplacement-modgen-submit',
    'index' => 0,
    'hasindex' => true,
]];

aiplacement_modgen_output_response($bodyhtml, $footeractions, $ajax, get_string('pluginname', 'aiplacement_modgen'));
