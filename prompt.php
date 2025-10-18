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
    global $OUTPUT;
    
    if ($ajax) {
        $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);
        aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, $refresh, ['title' => $title]);
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
$includeaboutassessmentsparam = optional_param('includeaboutassessments', 0, PARAM_BOOL);
$includeaboutlearningparam = optional_param('includeaboutlearning', 0, PARAM_BOOL);
$createsuggestedactivitiesparam = optional_param('createsuggestedactivities', 1, PARAM_BOOL);
$generatedsummaryparam = optional_param('generatedsummary', '', PARAM_RAW);
$curriculumtemplateparam = optional_param('curriculum_template', '', PARAM_TEXT);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'keepweeklabels' => $keepweeklabelsparam,
        'includeaboutassessments' => $includeaboutassessmentsparam,
        'includeaboutlearning' => $includeaboutlearningparam,
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
    $keepweeklabels = $moduletype === 'weekly' && !empty($adata->keepweeklabels);
    $includeaboutassessments = !empty($adata->includeaboutassessments);
    $includeaboutlearning = !empty($adata->includeaboutlearning);

    // Update course format based on module type.
    $courseformat = ($moduletype === 'theme') ? 'topics' : 'weeks';
    $update = new stdClass();
    $update->id = $courseid;
    $update->format = $courseformat;
    update_course($update);
    rebuild_course_cache($courseid, true, true);
    $course = get_course($courseid);

    $results = [];
    $needscacherefresh = false;
    $aboutassessmentsadded = false;
    $aboutlearningadded = false;
    $activitywarnings = [];

    if ($includeaboutassessments) {
        $assessmentname = get_string('aboutassessments', 'aiplacement_modgen');
        $assessmentresult = local_aiplacement_modgen_create_subsection($course, 0, $assessmentname, '', $needscacherefresh);
        if (!empty($assessmentresult) && !empty($assessmentresult['cmid'])) {
            $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $assessmentname);
            $aboutassessmentsadded = true;
        }
    }
    if ($includeaboutlearning) {
        $learningname = get_string('aboutlearningoutcomes', 'aiplacement_modgen');
        $learningresult = local_aiplacement_modgen_create_subsection($course, 0, $learningname, '', $needscacherefresh);
        if (!empty($learningresult) && !empty($learningresult['cmid'])) {
            $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $learningname);
            $aboutlearningadded = true;
        }
    }
    if ($moduletype === 'theme' && !empty($json['themes']) && is_array($json['themes'])) {
        $modinfo = get_fast_modinfo($courseid);
        $existingsections = $modinfo->get_section_info_all();
        $sectionnum = empty($existingsections) ? 1 : max(array_keys($existingsections)) + 1;

        foreach ($json['themes'] as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $title = $theme['title'] ?? get_string('themefallback', 'aiplacement_modgen');
            $summary = $theme['summary'] ?? '';
            $weeks = !empty($theme['weeks']) && is_array($theme['weeks']) ? $theme['weeks'] : [];

            $section = course_create_section($course, $sectionnum);
            $sectionrecord = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);

            $sectionhtml = '';
            if (trim($summary) !== '') {
                $sectionhtml = format_text($summary, FORMAT_HTML, ['context' => $context]);
            }

            $sectionrecord->name = $title;
            $sectionrecord->summary = $sectionhtml;
            $sectionrecord->summaryformat = FORMAT_HTML;
            $sectionrecord->timemodified = time();
            $DB->update_record('course_sections', $sectionrecord);

            if (!empty($theme['activities']) && is_array($theme['activities'])) {
                $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                    $theme['activities'],
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

            if (!empty($weeks)) {
                foreach ($weeks as $week) {
                    if (!is_array($week)) {
                        continue;
                    }
                    $weektitle = $week['title'] ?? get_string('weekfallback', 'aiplacement_modgen');
                    $weeksummary = isset($week['summary']) ? $week['summary'] : '';

                    // Use the generated weekly summary as the subsection description.
                    $subsectionsummary = '';
                    if (trim($weeksummary) !== '') {
                        $subsectionsummary = format_text($weeksummary, FORMAT_HTML, ['context' => $context]);
                    }

                    $subsectionresult = local_aiplacement_modgen_create_subsection($course, $sectionnum, $weektitle, $subsectionsummary, $needscacherefresh);
                    $delegatedsectionnum = null;
                    if (!empty($subsectionresult) && !empty($subsectionresult['cmid'])) {
                        $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $weektitle);
                        
                        // Convert delegated section ID to section number for activity creation
                        if (!empty($subsectionresult['delegatedsectionid'])) {
                            $delegatedsectionrec = $DB->get_record('course_sections', ['id' => $subsectionresult['delegatedsectionid']]);
                            if ($delegatedsectionrec) {
                                $delegatedsectionnum = $delegatedsectionrec->section;
                            }
                        }
                    }

                    // Process activities within this week - place them in the subsection's delegated section
                    if (!empty($week['activities']) && is_array($week['activities'])) {
                        $activitysectionnum = !empty($delegatedsectionnum) ? $delegatedsectionnum : $sectionnum;
                        $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                            $week['activities'],
                            $course,
                            $activitysectionnum
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

            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            $sectionnum++;
        }
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
}

// Prompt form handling.
$promptform = new aiplacement_modgen_generator_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
]);

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
            error_log('Upload form error: ' . $e->getMessage());
            if ($ajax) {
                aiplacement_modgen_send_ajax_response($e->getMessage(), '', false);
            }
        }
    }
}if ($pdata = $promptform->get_data()) {
    // Debug: Log all form data received
    error_log('=== PROMPT FORM SUBMITTED ===');
    error_log('All pdata properties: ' . print_r((array)$pdata, true));
    error_log('curriculum_template in pdata: ' . (isset($pdata->curriculum_template) ? 'YES' : 'NO'));
    if (isset($pdata->curriculum_template)) {
        error_log('curriculum_template value: "' . $pdata->curriculum_template . '"');
        error_log('curriculum_template is empty: ' . (empty($pdata->curriculum_template) ? 'YES' : 'NO'));
    }
    error_log('=== END FORM DEBUG ===');
    
    // Visual debug display (appears on page if ?debug=1 is in URL)
    if (isset($_GET['debug'])) {
        echo '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 20px 0; font-family: monospace; font-size: 12px; border-radius: 4px;">';
        echo '<strong style="color: #ff6b00;">⚠️ DEBUG MODE: Form Submission</strong><br>';
        echo 'curriculum_template isset: <strong>' . (isset($pdata->curriculum_template) ? 'YES' : 'NO') . '</strong><br>';
        echo 'curriculum_template value: <code style="background: white; padding: 2px 4px;">"' . htmlspecialchars((string)($pdata->curriculum_template ?? '')) . '"</code><br>';
        echo 'curriculum_template empty: <strong>' . (empty($pdata->curriculum_template) ? 'YES' : 'NO') . '</strong><br>';
        echo 'All form fields: ';
        $fields = [];
        foreach ((array)$pdata as $key => $val) {
            if (!is_object($val) && !is_array($val)) {
                $fields[] = "$key=" . substr((string)$val, 0, 20);
            }
        }
        echo implode(', ', $fields) . '<br>';
        echo '</div>';
    }
    
    $prompt = $pdata->prompt;
    $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'weekly';
    $keepweeklabels = !empty($pdata->keepweeklabels);
    $includeaboutassessments = !empty($pdata->includeaboutassessments);
    $includeaboutlearning = !empty($pdata->includeaboutlearning);
    $createsuggestedactivities = !empty($pdata->createsuggestedactivities);
    $curriculum_template = !empty($pdata->curriculum_template) ? $pdata->curriculum_template : '';
    $typeinstruction = get_string('moduletypeinstruction_' . $moduletype, 'aiplacement_modgen');
    $compositeprompt = trim($prompt . "\n\n" . $typeinstruction);
    
    // Modify prompt if activities should not be created
    if (!$createsuggestedactivities) {
        $compositeprompt .= "\n\nIMPORTANT: Do NOT include an 'activities' array in your response. " .
            "Create section headings and summaries only. The sections should be structured with titles and descriptions, " .
            "but do not suggest any activities, quizzes, or resources. This allows the user to add their own content.";
    }
    
    // Generate module with or without template
    error_log('DEBUG: $pdata->curriculum_template exists: ' . (isset($pdata->curriculum_template) ? 'YES' : 'NO'));
    error_log('DEBUG: $pdata->curriculum_template value: ' . var_export($pdata->curriculum_template, true));
    error_log('DEBUG: $curriculum_template after assignment: ' . var_export($curriculum_template, true));
    error_log('Checking curriculum_template: empty=' . (empty($curriculum_template) ? '1' : '0') . ', value=' . var_export($curriculum_template, true));
    if (!empty($curriculum_template)) {
        error_log('Template selected: ' . $curriculum_template);
        try {
            $template_reader = new \aiplacement_modgen\local\template_reader();
            $template_data = $template_reader->extract_curriculum_template($curriculum_template);
            error_log('Template data extracted, keys: ' . implode(', ', array_keys($template_data)));
            
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
            error_log('Template data summary: ' . json_encode($data_summary));
            
            // Extract Bootstrap structure from the template
            $bootstrap_structure = $template_reader->extract_bootstrap_structure($curriculum_template);
            error_log('Extracted bootstrap structure: ' . print_r($bootstrap_structure, true));
            $template_data['bootstrap_structure'] = $bootstrap_structure;
            
            // Log template HTML extraction
            if (!empty($template_data['template_html'])) {
                error_log('Template HTML extracted, length: ' . strlen($template_data['template_html']));
                error_log('First 500 chars of template HTML: ' . substr($template_data['template_html'], 0, 500));
            } else {
                error_log('No template HTML extracted');
            }
            
            // Validate structure and activities are not empty
            if (empty($template_data['structure'])) {
                error_log('WARNING: Template has no structure/sections');
            } else {
                error_log('Template structure count: ' . count($template_data['structure']));
            }
            
            if (empty($template_data['activities'])) {
                error_log('WARNING: Template has no activities');
            } else {
                error_log('Template activities count: ' . count($template_data['activities']));
            }
            
            error_log('Calling generate_module_with_template with template data');
            $json = \aiplacement_modgen\ai_service::generate_module_with_template($compositeprompt, $template_data, [], $moduletype);
        } catch (Exception $e) {
            // Fall back to normal generation if template fails
            error_log('Template generation EXCEPTION: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, [], $moduletype);
        }
    } else {
        error_log('No template selected');
        $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, [], $moduletype);
    }
    // Get the final prompt sent to AI for debugging (returned by ai_service).
    $debugprompt = isset($json['debugprompt']) ? $json['debugprompt'] : $prompt;
    $jsonstr = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonstr === false) {
        $jsonstr = print_r($json, true);
    }
    $summarytext = \aiplacement_modgen\ai_service::summarise_module($json, $moduletype);
    if ($summarytext === '') {
        $summarytext = aiplacement_modgen_generate_fallback_summary($json, $moduletype);
    }
    $summaryformatted = $summarytext !== '' ? nl2br(s($summarytext)) : '';

    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $jsonstr,
        'moduletype' => $moduletype,
        'keepweeklabels' => $keepweeklabels ? 1 : 0,
        'includeaboutassessments' => $includeaboutassessments ? 1 : 0,
        'includeaboutlearning' => $includeaboutlearning ? 1 : 0,
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
