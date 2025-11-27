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
    print_error('missingcourseid', 'aiplacement_modgen');
}

// Verify user has access to this course
$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

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
 * Convert JSON module data into structured preview data for template rendering.
 *
 * @param array $moduledata Decoded module structure returned by the AI.
 * @param string $structure Module structure type ('theme', 'connected_theme', 'weekly', 'connected_weekly', etc).
 * @return array Structured data with themes/weeks and activities for display.
 */
function aiplacement_modgen_build_module_preview(array $moduledata, string $structure): array {
    $preview = [
        'structure' => $structure,
        'hasthemes' => false,
        'themes' => [],
        'hasweeks' => false,
        'weeks' => [],
    ];

    // Normalize structure type - check for 'theme' variations
    $isthemeformat = strpos($structure, 'theme') !== false;

    if ($isthemeformat && !empty($moduledata['themes']) && is_array($moduledata['themes'])) {
        $preview['hasthemes'] = true;

        foreach ($moduledata['themes'] as $theme) {
            if (!is_array($theme)) {
                continue;
            }

            $themeitem = [
                'title' => !empty($theme['title']) ? s($theme['title']) : get_string('themefallback', 'aiplacement_modgen'),
                'summary' => !empty($theme['summary']) ? s($theme['summary']) : '',
                'weeks' => [],
                'hasweeks' => false,
            ];

            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                foreach ($theme['weeks'] as $week) {
                    if (!is_array($week)) {
                        continue;
                    }

                    $weekitem = [
                        'title' => !empty($week['title']) ? s($week['title']) : get_string('weekfallback', 'aiplacement_modgen'),
                        'summary' => !empty($week['summary']) ? s($week['summary']) : '',
                        'activities' => [],
                        'hasactivities' => false,
                        'sessions' => [],
                        'hassessions' => false,
                    ];

                    // Collect all activities from all session types
                    // Sessions can be either direct arrays (presession, session, postsession keys)
                    // or nested in a sessions object (sessions.presession.activities, etc)
                    $sessionsData = $week['sessions'] ?? [
                        'presession' => $week['presession'] ?? [],
                        'session' => $week['session'] ?? [],
                        'postsession' => $week['postsession'] ?? [],
                    ];

                    $sessionLabels = ['presession' => 'Pre-session', 'session' => 'Session', 'postsession' => 'Post-session'];

                    foreach ($sessionsData as $sessiontype => $sessiondata) {
                        // Handle both formats:
                        // 1. Sessions object with {presession: {activities: [...]}}
                        // 2. Direct activities array [{type, name, ...}]
                        $activities = [];
                        if (is_array($sessiondata)) {
                            if (isset($sessiondata['activities']) && is_array($sessiondata['activities'])) {
                                // Format 1: nested structure
                                $activities = $sessiondata['activities'];
                            } elseif (!isset($sessiondata['activities']) && !isset($sessiondata['description'])) {
                                // Format 2: direct array of activities
                                $activities = $sessiondata;
                            }
                        }

                        // Track that this session type exists (even if empty)
                        if (!empty($activities) || (is_array($sessiondata) && isset($sessiondata['activities']))) {
                            $sessionActivities = [];
                            foreach ($activities as $activity) {
                                if (!is_array($activity)) {
                                    continue;
                                }

                                $sessionActivities[] = [
                                    'name' => !empty($activity['name']) ? s($activity['name']) : '',
                                    'type' => !empty($activity['type']) ? s($activity['type']) : '',
                                    'session' => $sessiontype,
                                ];

                                // Also add to the flat activities list for backward compatibility
                                $weekitem['activities'][] = [
                                    'name' => !empty($activity['name']) ? s($activity['name']) : '',
                                    'type' => !empty($activity['type']) ? s($activity['type']) : '',
                                    'session' => $sessiontype,
                                ];
                            }

                            $weekitem['sessions'][] = [
                                'type' => $sessiontype,
                                'label' => $sessionLabels[$sessiontype] ?? $sessiontype,
                                'activities' => $sessionActivities,
                            ];
                            $weekitem['hassessions'] = true;
                        }
                    }

                    if (!empty($weekitem['activities'])) {
                        $weekitem['hasactivities'] = true;
                    }

                    $themeitem['weeks'][] = $weekitem;
                }
            }

            if (!empty($themeitem['weeks'])) {
                $themeitem['hasweeks'] = true;
            }

            $preview['themes'][] = $themeitem;
        }
    } else if (!empty($moduledata['sections']) && is_array($moduledata['sections'])) {
        // Weekly format
        $preview['hasweeks'] = true;

        foreach ($moduledata['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $weekitem = [
                'title' => !empty($section['title']) ? s($section['title']) : get_string('weekfallback', 'aiplacement_modgen'),
                'summary' => !empty($section['summary']) ? s($section['summary']) : '',
                'activities' => [],
                'hasactivities' => false,
                'sessions' => [],
                'hassessions' => false,
            ];

            // Check for sessions structure first (connected_weekly with sessions)
            if (!empty($section['sessions']) && is_array($section['sessions'])) {
                $sessionsData = $section['sessions'];
                $sessionLabels = ['presession' => 'Pre-session', 'session' => 'Session', 'postsession' => 'Post-session'];
                
                foreach ($sessionsData as $sessiontype => $sessiondata) {
                    // Handle both formats:
                    // 1. Sessions object with {presession: {activities: [...]}}
                    // 2. Direct activities array [{type, name, ...}]
                    $activities = [];
                    if (is_array($sessiondata)) {
                        if (isset($sessiondata['activities']) && is_array($sessiondata['activities'])) {
                            // Format 1: nested structure
                            $activities = $sessiondata['activities'];
                        } elseif (!isset($sessiondata['activities']) && !isset($sessiondata['description'])) {
                            // Format 2: direct array of activities
                            $activities = $sessiondata;
                        }
                    }

                    // Track that this session type exists (even if empty)
                    if (!empty($activities) || (is_array($sessiondata) && isset($sessiondata['activities']))) {
                        $sessionActivities = [];
                        foreach ($activities as $activity) {
                            if (!is_array($activity)) {
                                continue;
                            }

                            $sessionActivities[] = [
                                'name' => !empty($activity['name']) ? s($activity['name']) : '',
                                'type' => !empty($activity['type']) ? s($activity['type']) : '',
                                'session' => $sessiontype,
                            ];

                            // Also add to the flat activities list for backward compatibility
                            $weekitem['activities'][] = [
                                'name' => !empty($activity['name']) ? s($activity['name']) : '',
                                'type' => !empty($activity['type']) ? s($activity['type']) : '',
                                'session' => $sessiontype,
                            ];
                        }

                        $weekitem['sessions'][] = [
                            'type' => $sessiontype,
                            'label' => $sessionLabels[$sessiontype] ?? $sessiontype,
                            'activities' => $sessionActivities,
                        ];
                        $weekitem['hassessions'] = true;
                    }
                }
            } else if (!empty($section['outline']) && is_array($section['outline'])) {
                // Fallback to outline format if sessions not present
                foreach ($section['outline'] as $activity) {
                    if (is_string($activity) && trim($activity) !== '') {
                        $weekitem['activities'][] = [
                            'name' => s($activity),
                            'type' => '',
                            'session' => 'outline',
                        ];
                    }
                }
            }

            if (!empty($weekitem['activities'])) {
                $weekitem['hasactivities'] = true;
            }

            $preview['weeks'][] = $weekitem;
        }
    }

    return $preview;
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
        // Create weekly sections from approved JSON.
        $json = json_decode($adata->approvedjson, true);
        $moduletype = !empty($adata->moduletype) ? $adata->moduletype : 'connected_weekly';
        $hideexistingsections = !empty($adata->hideexistingsections);
        
        // Lock the course to prevent concurrent access during build
        $lockkey = 'aiplacement_modgen_building_' . $courseid;
        $lock = \core\lock\lock_config::get_lock_factory('aiplacement_modgen')->get_lock($lockkey, 600); // 10 minute timeout
        
        try {
            // Track existing section numbers BEFORE creating new content
            $existing_section_ids = [];
            $new_toplevel_section_ids = []; // Track new top-level sections to move to top
            if ($hideexistingsections) {
                $existingsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
                foreach ($existingsections as $section) {
                    // Skip section 0 (general section)
                    if ($section->section == 0) {
                        continue;
                    }
                    $existing_section_ids[] = $section->id;
                }
            }
            
            // CRITICAL: Ensure course format is set to flexsections FIRST - both theme and weekly require it
            // This must happen before ANY section or module creation
            $pluginmanager = core_plugin_manager::instance();
            $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
            
            if (empty($flexsectionsplugin)) {
                throw new Exception(
                    "The Flexible Sections plugin is required for module generation with both theme and weekly structures. " .
                    "Please ensure the flexsections format plugin is installed and enabled in your Moodle instance."
                );
            }
            
            // Get fresh course object to check current format
            $course = get_course($courseid, true);
            
            // Update course format to flexsections if not already set
            if ($course->format !== 'flexsections') {
                $update = new stdClass();
                $update->id = $courseid;
                $update->format = 'flexsections';
                
                update_course($update);
                rebuild_course_cache($courseid, true, true);
                
                // Force a fresh course object to get updated format
                $course = get_course($courseid, true);
                
                // If still not flexsections, try direct database update as fallback
                if ($course->format !== 'flexsections') {
                    $DB->set_field('course', 'format', 'flexsections', ['id' => $courseid]);
                    $course = get_course($courseid, true);
                }
                
                // Verify format was actually updated
                if ($course->format !== 'flexsections') {
                    throw new Exception(
                        "Failed to update course format to 'flexsections'. Current format is '{$course->format}'. " .
                        "Please check that the Flexible Sections plugin is properly installed and enabled."
                    );
                }
            }
            
            // Re-fetch the course format instance to ensure it reflects the updated format
            $courseformat = course_get_format($course);

            $results = [];
            $needscacherefresh = false;
            $activitywarnings = [];
        
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
                // Verify the courseformat instance has the required method
                if (!method_exists($courseformat, 'create_new_section')) {
                    throw new Exception('The flexsections course format is not properly supporting nested sections. Please ensure the Flexible Sections plugin is correctly installed and enabled.');
                }
                $themesectionnum = $courseformat->create_new_section(0, null); // 0 means top level (no parent)
                $themesectionnums[] = $themesectionnum;
                
                // Track this top-level section for potential moving later
                if ($hideexistingsections) {
                    $themesection = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $themesectionnum]);
                    if ($themesection) {
                        $new_toplevel_section_ids[] = $themesection->id;
                    }
                }
            } catch (Exception $e) {
                $activitywarnings[] = "Failed to create theme section: " . $e->getMessage();
                continue;
            }
            
            $themetitle = format_string($title, true, ['context' => $context]);
            $sectionhtml = '';
            
            // Check if AI is enabled
            $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
            
            // Include theme summary if: 
            // - AI is disabled (CSV mode - always use descriptions), OR
            // - AI is enabled AND "Generate theme introductions" is checked
            if ((!$ai_enabled || !empty($adata->generatethemeintroductions)) && trim($summary) !== '') {
                $sectionhtml = format_text($summary, FORMAT_HTML, ['context' => $context]);
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
                        $currentcourseformat = $course->format;
                        if ($currentcourseformat !== 'flexsections') {
                            throw new Exception("Course is using '{$currentcourseformat}' format, not 'flexsections'. Nested sections require the Flexible Sections plugin.");
                        }
                        if (!method_exists($courseformat, 'create_new_section')) {
                            throw new Exception('The flexsections course format is not properly supporting nested sections.');
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
                    
                    // Create the three session subsections using shared helper
                    try {
                        $weekSessionData = $week['sessions'] ?? null;
                        $sessionsectionmap = \aiplacement_modgen\local\session_creator::create_session_subsections(
                            $courseformat, 
                            $weeksectionnum, 
                            $courseid, 
                            $weekSessionData
                        );
                        
                        $sessiontypes = ['presession' => get_string('presession', 'aiplacement_modgen'),
                                        'session' => get_string('session', 'aiplacement_modgen'),
                                        'postsession' => get_string('postsession', 'aiplacement_modgen')];
                        foreach ($sessiontypes as $sessionlabel) {
                            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $sessionlabel);
                        }
                    } catch (Exception $e) {
                        $activitywarnings[] = "Failed to create session subsections: " . $e->getMessage();
                        continue; // Skip to next week
                    }
                    
                    // Create activities in the appropriate session subsections
                    if (!empty($adata->createsuggestedactivities)) {
                        // Check if week has nested sessions structure
                        if (!empty($week['sessions']) && is_array($week['sessions'])) {
                            // Use shared helper to create session activities
                            \aiplacement_modgen\local\session_creator::create_session_activities(
                                $week['sessions'],
                                $sessionsectionmap,
                                $course,
                                $results,
                                $activitywarnings
                            );
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
            
            // Check if this section has a sessions structure (connected_weekly mode)
            $hassessions = !empty($sectiondata['sessions']) && is_array($sectiondata['sessions']);
            
            // For connected_weekly with sessions, use flexsections create_new_section to support nesting
            if ($hassessions) {
                if (!method_exists($courseformat, 'create_new_section')) {
                    throw new Exception('The flexsections course format is required for connected_weekly mode.');
                }
                $actualsectionnum = $courseformat->create_new_section(0, null); // 0 = top level
                $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $actualsectionnum], '*', MUST_EXIST);
                
                // Track this top-level section for potential moving later
                if ($hideexistingsections) {
                    $new_toplevel_section_ids[] = $section->id;
                }
            } else {
                // For plain weekly, use standard section creation
                $section = course_create_section($course, $sectionnum);
                $actualsectionnum = $sectionnum;
                
                // Track this top-level section for potential moving later
                if ($hideexistingsections) {
                    $new_toplevel_section_ids[] = $section->id;
                }
            }
            
            $sectionrecord = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
            $sectionhtml = '';
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

            // Always use section name field for title (modern approach)
            $sectionrecord->name = $title;
            $sectionrecord->summary = $sectionhtml;
            $sectionrecord->summaryformat = FORMAT_HTML;
            $sectionrecord->timemodified = time();
            $DB->update_record('course_sections', $sectionrecord);
            
            // If this section has sessions structure, create subsections and set as link
            if ($hassessions) {
                // Set the main section to appear as a link (collapsed = 1 in flexsections)
                $sectionid = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $actualsectionnum]);
                if ($sectionid && $courseformat) {
                    $courseformat->update_section_format_options(['id' => $sectionid, 'collapsed' => 1]);
                }
                
                // Create subsections using shared helper
                try {
                    $sessionsectionmap = \aiplacement_modgen\local\session_creator::create_session_subsections(
                        $courseformat,
                        $actualsectionnum,
                        $courseid,
                        $sectiondata['sessions']
                    );
                    
                    // Create activities in the appropriate subsections using shared helper
                    \aiplacement_modgen\local\session_creator::create_session_activities(
                        $sectiondata['sessions'],
                        $sessionsectionmap,
                        $course,
                        $results,
                        $activitywarnings
                    );
                    
                    $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title . ' (with subsections)');
                } catch (Exception $e) {
                    $activitywarnings[] = "Failed to create session subsections for '{$title}': " . $e->getMessage();
                    $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
                }
            } else {
                // Simple weekly section - create activities directly in the section
                if (!empty($sectiondata['activities']) && is_array($sectiondata['activities'])) {
                    $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                        $sectiondata['activities'],
                        $course,
                        $actualsectionnum
                    );
                    
                    if (!empty($activityoutcome['created'])) {
                        $results = array_merge($results, $activityoutcome['created']);
                    }
                    if (!empty($activityoutcome['warnings'])) {
                        $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                    }
                }
                
                $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            }

            $sectionnum++;
        }
    }

        // Handle hiding existing sections and moving new ones to top if requested.
        if ($hideexistingsections && !empty($existing_section_ids)) {
            // Hide the old sections so only new content is visible
            foreach ($existing_section_ids as $old_section_id) {
                $DB->set_field('course_sections', 'visible', 0, ['id' => $old_section_id]);
            }

            // Move new top-level sections to the top (after section 0) using the course format API
            // Prefer format-specific move (preserves format metadata like parent/child relationships)
            if (!empty($new_toplevel_section_ids)) {
                /** @var \course_format $courseformat */
                $courseformat = course_get_format($course);
                $modinfo = get_fast_modinfo($course);

                // Find the first existing top-level section that is NOT one of the newly created sections.
                $anchorsectionnum = null;
                foreach ($modinfo->get_section_info_all() as $s) {
                    if ($s->section && empty($s->parent) && !in_array($s->id, $new_toplevel_section_ids, true)) {
                        $anchorsectionnum = $s->section;
                        break;
                    }
                }

                // Move sections in reverse order before the anchor so their relative order is preserved.
                // Compute the anchor once (the first existing top-level section that is not part of the new set).
                $anchor = $anchorsectionnum;
                if ($anchor === null) {
                    // If there's no other top-level section, we'll use position 1 as the numeric target.
                    $anchor = 1;
                }

                foreach (array_reverse($new_toplevel_section_ids) as $new_section_id) {
                    $newsection = $DB->get_record('course_sections', ['id' => $new_section_id]);
                    if (!$newsection) {
                        continue;
                    }

                    $fromnum = $newsection->section;
                    $moved = false;

                    // Prefer format-specific move_section if available (flexsections provides move_section)
                    if (is_object($courseformat) && method_exists($courseformat, 'move_section')) {
                        try {
                            // Insert before the original anchor so the anchor remains the same across iterations.
                            $courseformat->move_section($fromnum, 0, $anchor);
                            $moved = true;
                        } catch (Throwable $e) {
                            debugging('format move_section failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                            $moved = false;
                        }
                    }

                    // Next preference: format-level move_section_after (core formats may implement it).
                    if (!$moved && is_object($courseformat) && method_exists($courseformat, 'move_section_after')) {
                        try {
                            $modinfo = get_fast_modinfo($course);
                            $frominfo = $modinfo->get_section_info_by_id($newsection->id, MUST_EXIST);
                            if ($anchor !== null && $anchor !== 1) {
                                $destinfo = $modinfo->get_section_info($anchor);
                                $courseformat->move_section_after($frominfo, $destinfo);
                            }
                            // If anchor == 1 and no destinfo available we will fall back to numeric move.
                            $moved = true;
                        } catch (Throwable $e) {
                            debugging('format move_section_after failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                            $moved = false;
                        }
                    }

                    // Final fallback: use core helper move_section_to (works for simple formats but may not
                    // preserve format-specific metadata for complex formats like flexsections).
                    if (!$moved) {
                        $targetposition = $anchor;
                        move_section_to($course, $fromnum, $targetposition);
                    }
                }

                // Rebuild cache once after moves.
                rebuild_course_cache($courseid, true, true);
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

        } finally {
            // Always release the lock when done, even if there's an error
            // Also ensure cache is refreshed one more time for safety
            rebuild_course_cache($courseid, true, true);
            if (isset($lock)) {
                $lock->release();
            }
        }        if ($embedded) {
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

// Generator form: Create and display for standalone page access
$promptform = new aiplacement_modgen_generator_form(null, [
    'courseid' => $courseid,
    'embedded' => 0,
    'contextid' => context_course::instance((int)$courseid)->id,
]);

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
    
    if (!empty($existing_module) || !empty($existing_modules)) {
        try {
            $template_reader = new \aiplacement_modgen\local\template_reader();
            $template_data = null;
            
            // Extract and merge templates from all selected modules
            if (!empty($existing_module) || !empty($existing_modules)) {
                $modules_to_extract = [];
                
                // Add primary module (if it was set from multiselect)
                if (!empty($existing_module)) {
                    $modules_to_extract[] = $existing_module;
                }
                
                // Add any additional modules from multiselect
                if (!empty($existing_modules)) {
                    $modules_to_extract = array_merge($modules_to_extract, $existing_modules);
                }
                
                // Remove duplicates
                $modules_to_extract = array_unique($modules_to_extract);
                
                $debuglog[] = 'Extracting templates from ' . count($modules_to_extract) . ' module(s)';
                
                $all_templates = [];
                
                // Extract each selected module
                foreach ($modules_to_extract as $idx => $module_id) {
                    $template_source = (string)$module_id;
                    $debuglog[] = 'Module ' . ($idx + 1) . ': ' . $template_source;
                    
                    try {
                        // Try full extraction first
                        $extracted = $template_reader->extract_curriculum_template($template_source);
                        $debuglog[] = '  → Full extraction succeeded';
                        $all_templates[] = $extracted;
                    } catch (Throwable $e) {
                        // If full extraction fails, try fallback
                        $debuglog[] = '  → Full extraction failed: ' . $e->getMessage();
                        $debuglog[] = '  → Attempting fallback extraction...';
                        
                        try {
                            global $DB;
                            $courseid_int = (int)$template_source;
                            $course = $DB->get_record('course', ['id' => $courseid_int]);
                            if (!$course) {
                                throw new Exception('Course not found');
                            }
                            
                            $fallback = [
                                'course_info' => [
                                    'name' => $course->fullname,
                                    'format' => $course->format,
                                    'summary' => strip_tags($course->summary ?? '')
                                ],
                                'structure' => [],
                                'activities' => [],
                                'template_html' => ''
                            ];
                            $debuglog[] = '  → Fallback extraction succeeded';
                            $all_templates[] = $fallback;
                        } catch (Exception $fe) {
                            $debuglog[] = '  → Fallback failed: ' . $fe->getMessage();
                            throw new Exception('Both full and fallback extraction failed for module ' . $module_id . ': ' . $fe->getMessage());
                        }
                    }
                }
                
                // Merge all extracted templates into one
                if (!empty($all_templates)) {
                    $template_data = $all_templates[0]; // Start with first template
                    
                    if (count($all_templates) > 1) {
                        $debuglog[] = 'Merging ' . count($all_templates) . ' templates...';
                        
                        // Merge structures and activities from additional modules
                        for ($i = 1; $i < count($all_templates); $i++) {
                            $other = $all_templates[$i];
                            
                            // Merge structures
                            if (!empty($other['structure']) && is_array($other['structure'])) {
                                if (!isset($template_data['structure']) || !is_array($template_data['structure'])) {
                                    $template_data['structure'] = [];
                                }
                                $template_data['structure'] = array_merge($template_data['structure'], $other['structure']);
                            }
                            
                            // Merge activities
                            if (!empty($other['activities']) && is_array($other['activities'])) {
                                if (!isset($template_data['activities']) || !is_array($template_data['activities'])) {
                                    $template_data['activities'] = [];
                                }
                                $template_data['activities'] = array_merge($template_data['activities'], $other['activities']);
                            }
                            
                            // Append template HTML with separator
                            if (!empty($other['template_html'])) {
                                if (empty($template_data['template_html'])) {
                                    $template_data['template_html'] = '';
                                }
                                $template_data['template_html'] .= "\n\n--- Module " . ($i + 1) . " ---\n\n" . $other['template_html'];
                            }
                        }
                        $debuglog[] = 'Merged ' . count($all_templates) . ' templates successfully';
                    }
                }
            } else {
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
            
            // Simplified decision logic:
            // 1. CSV only (no prompt, no expand, no examples) = Pure CSV parsing
            // 2. CSV + prompt (no expand) = AI modifies per prompt, no title expansion
            // 3. CSV/prompt + expand = Full AI enhancement including titles
            // 4. CSV + examples (no expand) = Generate content, keep CSV titles
            
            $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
            $expand_on_themes = !empty($pdata->expandonthemes);
            $has_user_prompt = !empty($pdata->prompt) && trim($pdata->prompt) !== '';
            $has_csv_file = !empty($pdata->supportingfiles);
            $generate_examples = !empty($pdata->generateexamplecontent);
            
            // Use pure CSV parsing only if: AI disabled OR (AI enabled + has CSV + no prompt + no expand + no examples)
            if (!$ai_enabled || ($ai_enabled && $has_csv_file && !$has_user_prompt && !$expand_on_themes && !$generate_examples)) {
                // Process uploaded CSV file directly without AI enhancement
                require_once(__DIR__ . '/classes/local/csv_parser.php');
                
                // Get the first uploaded file from draft area (should be CSV)
                $draftitemid = $pdata->supportingfiles;
                $usercontext = context_user::instance($USER->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
                
                if (empty($files)) {
                    throw new Exception('No CSV file uploaded. A CSV file with the module structure is required.');
                }
                
                $csvfile = array_shift($files);
                
                // Auto-detect CSV format if module type is not explicitly set or is default
                if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                    $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
                    $moduletype = $detectedformat;
                }
                
                $json = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
            } else {
                // AI enhancement enabled (has prompt OR expand on themes checked)
                
                // Check if there's a CSV file to use as base structure
                $csv_structure = null;
                if ($has_csv_file) {
                    require_once(__DIR__ . '/classes/local/csv_parser.php');
                    
                    $draftitemid = $pdata->supportingfiles;
                    $usercontext = context_user::instance($USER->id);
                    $fs = get_file_storage();
                    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
                    
                    if (!empty($files)) {
                        $csvfile = array_shift($files);
                        
                        // Auto-detect CSV format if needed
                        if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                            $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
                            $moduletype = $detectedformat;
                        }
                        
                        // Parse CSV to get base structure
                        $csv_structure = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
                    }
                }
                
                // Build the AI prompt based on what's enabled
                $ai_instructions = "";
                
                if ($csv_structure !== null) {
                    // Count themes/weeks for explicit instruction
                    $themecount = 0;
                    $weekcount = 0;
                    if (!empty($csv_structure['themes']) && is_array($csv_structure['themes'])) {
                        $themecount = count($csv_structure['themes']);
                        // Count total weeks across all themes
                        foreach ($csv_structure['themes'] as $theme) {
                            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                                $weekcount += count($theme['weeks']);
                            }
                        }
                    }
                    
                    $ai_instructions .= "\n\n*** BASE STRUCTURE FROM CSV ***\n";
                    $ai_instructions .= json_encode($csv_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $ai_instructions .= "\n\n*** CRITICAL STRUCTURAL REQUIREMENTS ***\n";
                    $ai_instructions .= "You MUST preserve the exact structure from the CSV:\n";
                    $ai_instructions .= "- Create EXACTLY " . $themecount . " themes with " . $weekcount . " weeks total\n";
                    $ai_instructions .= "- Do NOT add extra themes, weeks, or sessions\n";
                    $ai_instructions .= "- Do NOT remove any themes, weeks, or sessions\n";
                    $ai_instructions .= "- Do NOT merge or split sections\n";
                    $ai_instructions .= "- Maintain the EXACT organizational hierarchy\n";
                    $ai_instructions .= "- Keep the SAME session structure within each theme/week\n";
                    $ai_instructions .= "- Your output MUST have EXACTLY " . $themecount . " themes (this is non-negotiable)\n";
                    $ai_instructions .= "- Return ONLY the exact structure shown above - no modifications to theme/week count\n\n";
                    
                    if ($expand_on_themes) {
                        // Expand on themes: enhance titles and descriptions professionally
                        $ai_instructions .= "*** TITLE ENHANCEMENT INSTRUCTIONS ***\n";
                        $ai_instructions .= "Improve the section titles with these requirements:\n";
                        $ai_instructions .= "- Use professional, academic language suitable for UK higher education\n";
                        $ai_instructions .= "- Make titles clear, descriptive, and informative\n";
                        $ai_instructions .= "- Avoid marketing language or overly casual tone\n";
                        $ai_instructions .= "- Focus on clarity and academic rigor\n";
                        $ai_instructions .= "- Enhanced titles should be scholarly but accessible\n";
                        if ($generate_examples) {
                            $ai_instructions .= "\n*** ADDITIONAL CONTENT GENERATION ***\n";
                            $ai_instructions .= "Generate example content ONLY within the existing structure:\n";
                            $ai_instructions .= "- Do NOT create new weeks, themes, or sessions\n";
                            $ai_instructions .= "- Add activities ONLY to the sessions that exist in the CSV structure\n";
                            $ai_instructions .= "- Add session instructions ONLY to existing sessions\n";
                            $ai_instructions .= "- Generate theme/week summaries ONLY where 'summary' field is empty\n";
                            $ai_instructions .= "- Preserve any existing user-provided summaries exactly as given\n";
                            $ai_instructions .= "- The output structure MUST have the EXACT same number of weeks/themes as the CSV\n";
                        }
                    } else {
                        // No expansion: keep titles as-is, but may still generate example content
                        $ai_instructions .= "*** MODIFICATION INSTRUCTIONS ***\n";
                        $ai_instructions .= "Keep all section titles and names EXACTLY as specified in the CSV.\n";
                        $ai_instructions .= "Do NOT modify, enhance, or change any titles or theme names.\n";
                        if ($generate_examples) {
                            $ai_instructions .= "However, you should generate example content (activities, session instructions) while keeping titles unchanged.\n";
                            $ai_instructions .= "IMPORTANT: For theme introductions and week summaries:\n";
                            $ai_instructions .= "- ONLY generate these where the 'summary' field is empty in the CSV structure\n";
                            $ai_instructions .= "- DO NOT replace or modify summaries that the user has already provided\n";
                            $ai_instructions .= "- Preserve user-provided summaries exactly as they appear in the CSV\n";
                        }
                        if ($has_user_prompt) {
                            $ai_instructions .= "Apply the following user-specified modifications:\n";
                        }
                    }
                }
                
                $compositeprompt = $compositeprompt . $ai_instructions;
                
                // Use AI generation with appropriate flags for activities and sessions
                $json = \aiplacement_modgen\ai_service::generate_module_with_template($compositeprompt, $template_data, $supportingfiles, $moduletype, $courseid, $includeactivities, $includesessions);
            }
        } catch (Exception $e) {
            // Fall back to normal generation if template fails
            $debuglog[] = 'Template extraction failed: ' . $e->getMessage();
            
            // Simplified decision logic (same as above)
            $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
            $expand_on_themes = !empty($pdata->expandonthemes);
            $has_user_prompt = !empty($pdata->prompt) && trim($pdata->prompt) !== '';
            $has_csv_file = !empty($pdata->supportingfiles);
            $generate_examples = !empty($pdata->generateexamplecontent);
            
            // Use pure CSV parsing only if: AI disabled OR (AI enabled + has CSV + no prompt + no expand + no examples)
            if (!$ai_enabled || ($ai_enabled && $has_csv_file && !$has_user_prompt && !$expand_on_themes && !$generate_examples)) {
                // Process uploaded CSV file directly without AI enhancement
                require_once(__DIR__ . '/classes/local/csv_parser.php');
                
                // Get the first uploaded file from draft area (should be CSV)
                $draftitemid = $pdata->supportingfiles;
                $usercontext = context_user::instance($USER->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
                
                if (empty($files)) {
                    throw new Exception('No CSV file uploaded. A CSV file with the module structure is required.');
                }
                
                $csvfile = array_shift($files);
                
                // Auto-detect CSV format if module type is not explicitly set or is default
                if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                    $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
                    $moduletype = $detectedformat;
                }
                
                $json = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
            } else {
                // AI enhancement enabled - check for CSV to enhance
                $csv_structure = null;
                if ($has_csv_file) {
                    require_once(__DIR__ . '/classes/local/csv_parser.php');
                    
                    $draftitemid = $pdata->supportingfiles;
                    $usercontext = context_user::instance($USER->id);
                    $fs = get_file_storage();
                    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
                    
                    if (!empty($files)) {
                        $csvfile = array_shift($files);
                        
                        if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                            $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
                            $moduletype = $detectedformat;
                        }
                        
                        $csv_structure = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
                    }
                }
                
                // Build AI instructions based on what's enabled
                $ai_instructions = "";
                
                if ($csv_structure !== null) {
                    // Count themes/weeks for explicit instruction
                    $themecount = 0;
                    $weekcount = 0;
                    if (!empty($csv_structure['themes']) && is_array($csv_structure['themes'])) {
                        $themecount = count($csv_structure['themes']);
                        // Count total weeks across all themes
                        foreach ($csv_structure['themes'] as $theme) {
                            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                                $weekcount += count($theme['weeks']);
                            }
                        }
                    }
                    
                    $ai_instructions .= "\n\n*** BASE STRUCTURE FROM CSV ***\n";
                    $ai_instructions .= json_encode($csv_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $ai_instructions .= "\n\n*** CRITICAL STRUCTURAL REQUIREMENTS ***\n";
                    $ai_instructions .= "You MUST preserve the exact structure from the CSV:\n";
                    $ai_instructions .= "- Create EXACTLY " . $themecount . " themes with " . $weekcount . " weeks total\n";
                    $ai_instructions .= "- Do NOT add extra themes, weeks, or sessions\n";
                    $ai_instructions .= "- Do NOT remove any themes, weeks, or sessions\n";
                    $ai_instructions .= "- Do NOT merge or split sections\n";
                    $ai_instructions .= "- Maintain the EXACT organizational hierarchy\n";
                    $ai_instructions .= "- Keep the SAME session structure within each theme/week\n";
                    $ai_instructions .= "- Your output MUST have EXACTLY " . $themecount . " themes (this is non-negotiable)\n";
                    $ai_instructions .= "- Return ONLY the exact structure shown above - no modifications to theme/week count\n\n";
                    
                    if ($expand_on_themes) {
                        // Expand on themes: enhance titles and descriptions professionally
                        $ai_instructions .= "*** TITLE ENHANCEMENT INSTRUCTIONS ***\n";
                        $ai_instructions .= "Improve the section titles with these requirements:\n";
                        $ai_instructions .= "- Use professional, academic language suitable for UK higher education\n";
                        $ai_instructions .= "- Make titles clear, descriptive, and informative\n";
                        $ai_instructions .= "- Avoid marketing language or overly casual tone\n";
                        $ai_instructions .= "- Focus on clarity and academic rigor\n";
                        $ai_instructions .= "- Enhanced titles should be scholarly but accessible\n";
                        if ($generate_examples) {
                            $ai_instructions .= "\n*** ADDITIONAL CONTENT GENERATION ***\n";
                            $ai_instructions .= "Generate example content ONLY within the existing structure:\n";
                            $ai_instructions .= "- Do NOT create new weeks, themes, or sessions\n";
                            $ai_instructions .= "- Add activities ONLY to the sessions that exist in the CSV structure\n";
                            $ai_instructions .= "- Add session instructions ONLY to existing sessions\n";
                            $ai_instructions .= "- Generate theme/week summaries ONLY where 'summary' field is empty\n";
                            $ai_instructions .= "- Preserve any existing user-provided summaries exactly as given\n";
                            $ai_instructions .= "- The output structure MUST have the EXACT same number of weeks/themes as the CSV\n";
                        }
                    } else {
                        // No expansion: keep titles as-is, but may still generate example content
                        $ai_instructions .= "*** MODIFICATION INSTRUCTIONS ***\n";
                        $ai_instructions .= "Keep all section titles and names EXACTLY as specified in the CSV.\n";
                        $ai_instructions .= "Do NOT modify, enhance, or change any titles or theme names.\n";
                        if ($generate_examples) {
                            $ai_instructions .= "However, you should generate example content (activities, session instructions) while keeping titles unchanged.\n";
                            $ai_instructions .= "IMPORTANT: For theme introductions and week summaries:\n";
                            $ai_instructions .= "- ONLY generate these where the 'summary' field is empty in the CSV structure\n";
                            $ai_instructions .= "- DO NOT replace or modify summaries that the user has already provided\n";
                            $ai_instructions .= "- Preserve user-provided summaries exactly as they appear in the CSV\n";
                        }
                        if ($has_user_prompt) {
                            $ai_instructions .= "Apply the following user-specified modifications:\n";
                        }
                    }
                }
                
                $compositeprompt = $compositeprompt . $ai_instructions;
                
            $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, [], $moduletype, null, $courseid, $includeactivities, $includesessions);
            }
        }
    } else {
    // Simplified decision logic (same as above)
    $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
    $expand_on_themes = !empty($pdata->expandonthemes);
    $has_user_prompt = !empty($pdata->prompt) && trim($pdata->prompt) !== '';
    $has_csv_file = !empty($pdata->supportingfiles);
    $generate_examples = !empty($pdata->generateexamplecontent);
    
    // Use pure CSV parsing only if: AI disabled OR (AI enabled + has CSV + no prompt + no expand + no examples)
    if (!$ai_enabled || ($ai_enabled && $has_csv_file && !$has_user_prompt && !$expand_on_themes && !$generate_examples)) {
        // Process uploaded CSV file directly without AI enhancement
        require_once(__DIR__ . '/classes/local/csv_parser.php');
        
        // Get the first uploaded file from draft area (should be CSV)
        $draftitemid = $pdata->supportingfiles;
        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        
        if (empty($files)) {
            throw new Exception('No CSV file uploaded. A CSV file with the module structure is required.');
        }
        
        $csvfile = array_shift($files);
        
        // Auto-detect CSV format if module type is not explicitly set or is default
        if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
            $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
            $moduletype = $detectedformat;
        }
        
        $json = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
    } else {
        // AI enhancement enabled - check for CSV to enhance
        $csv_structure = null;
        if ($has_csv_file) {
            require_once(__DIR__ . '/classes/local/csv_parser.php');
            
            $draftitemid = $pdata->supportingfiles;
            $usercontext = context_user::instance($USER->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
            
            if (!empty($files)) {
                $csvfile = array_shift($files);
                
                if (empty($pdata->moduletype) || $pdata->moduletype === 'connected_weekly') {
                    $detectedformat = \aiplacement_modgen\local\csv_parser::detect_csv_format($csvfile);
                    $moduletype = $detectedformat;
                }
                
                $csv_structure = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($csvfile, $moduletype);
            }
        }
        
        // Build AI instructions based on what's enabled
        $ai_instructions = "";
        
        if ($csv_structure !== null) {
            // Count themes/weeks for explicit instruction
            $themecount = 0;
            $weekcount = 0;
            if (!empty($csv_structure['themes']) && is_array($csv_structure['themes'])) {
                $themecount = count($csv_structure['themes']);
                // Count total weeks across all themes
                foreach ($csv_structure['themes'] as $theme) {
                    if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                        $weekcount += count($theme['weeks']);
                    }
                }
            }
            
            $ai_instructions .= "\n\n*** BASE STRUCTURE FROM CSV ***\n";
            $ai_instructions .= json_encode($csv_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $ai_instructions .= "\n\n*** CRITICAL STRUCTURAL REQUIREMENTS ***\n";
            $ai_instructions .= "You MUST preserve the exact structure from the CSV:\n";
            $ai_instructions .= "- Create EXACTLY " . $themecount . " themes with " . $weekcount . " weeks total\n";
            $ai_instructions .= "- Do NOT add extra themes, weeks, or sessions\n";
            $ai_instructions .= "- Do NOT remove any themes, weeks, or sessions\n";
            $ai_instructions .= "- Do NOT merge or split sections\n";
            $ai_instructions .= "- Maintain the EXACT organizational hierarchy\n";
            $ai_instructions .= "- Keep the SAME session structure within each theme/week\n";
            $ai_instructions .= "- Your output MUST have EXACTLY " . $themecount . " themes (this is non-negotiable)\n";
            $ai_instructions .= "- Return ONLY the exact structure shown above - no modifications to theme/week count\n\n";
            
            $ai_instructions .= "- The number of sections in your output MUST match the CSV exactly\n\n";
            
            if ($expand_on_themes) {
                // Expand on themes: enhance titles and descriptions professionally
                $ai_instructions .= "*** TITLE ENHANCEMENT INSTRUCTIONS ***\n";
                $ai_instructions .= "Improve the section titles with these requirements:\n";
                $ai_instructions .= "- Use professional, academic language suitable for UK higher education\n";
                $ai_instructions .= "- Make titles clear, descriptive, and informative\n";
                $ai_instructions .= "- Avoid marketing language or overly casual tone\n";
                $ai_instructions .= "- Focus on clarity and academic rigor\n";
                $ai_instructions .= "- Enhanced titles should be scholarly but accessible\n";
                if ($generate_examples) {
                    $ai_instructions .= "\n*** ADDITIONAL CONTENT GENERATION ***\n";
                    $ai_instructions .= "Generate example content ONLY within the existing structure:\n";
                    $ai_instructions .= "- Do NOT create new weeks, themes, or sessions\n";
                    $ai_instructions .= "- Add activities ONLY to the sessions that exist in the CSV structure\n";
                    $ai_instructions .= "- Add session instructions ONLY to existing sessions\n";
                    $ai_instructions .= "- Generate theme/week summaries ONLY where 'summary' field is empty\n";
                    $ai_instructions .= "- Preserve any existing user-provided summaries exactly as given\n";
                    $ai_instructions .= "- The output structure MUST have the EXACT same number of weeks/themes as the CSV\n";
                }
            } else {
                // No expansion: keep titles as-is, but may still generate example content
                $ai_instructions .= "*** MODIFICATION INSTRUCTIONS ***\n";
                $ai_instructions .= "Keep all section titles and names EXACTLY as specified in the CSV.\n";
                $ai_instructions .= "Do NOT modify, enhance, or change any titles or theme names.\n";
                if ($generate_examples) {
                    $ai_instructions .= "However, you should generate example content (activities, session instructions) while keeping titles unchanged.\n";
                    $ai_instructions .= "IMPORTANT: For theme introductions and week summaries:\n";
                    $ai_instructions .= "- ONLY generate these where the 'summary' field is empty in the CSV structure\n";
                    $ai_instructions .= "- DO NOT replace or modify summaries that the user has already provided\n";
                    $ai_instructions .= "- Preserve user-provided summaries exactly as they appear in the CSV\n";
                }
                if ($has_user_prompt) {
                    $ai_instructions .= "Apply the following user-specified modifications:\n";
                }
            }
        }
        
        $compositeprompt = $compositeprompt . $ai_instructions;
        
    $json = \aiplacement_modgen\ai_service::generate_module($compositeprompt, $supportingfiles, $moduletype, null, $courseid, $includeactivities, $includesessions);
    }
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
        'generatethemeintroductions' => $generatethemeintroductions ? 1 : 0,
        'createsuggestedactivities' => $createsuggestedactivities ? 1 : 0,
        'generatedsummary' => $summarytext,
        'hideexistingsections' => $hideexistingsections ? 1 : 0,
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
    $modulepreview['showmodulepreview'] = !empty($modulepreview['themes']) || !empty($modulepreview['weeks']);

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

// If we reach here, something went wrong (form wasn't submitted and wasn't displaying)
// This shouldn't happen in normal flow
throw new moodle_exception('errorunexpected', 'aiplacement_modgen');
