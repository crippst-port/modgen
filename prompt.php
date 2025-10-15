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
 * Helper to create a subsection module and optionally populate its delegated section summary.
 *
 * @param stdClass $course Course record.
 * @param int $sectionnum Section number where the subsection should be placed.
 * @param string $name Subsection name.
 * @param string $summaryhtml Pre-formatted HTML summary to store in the delegated section.
 * @param bool $needscacherefresh Reference flag toggled when the course cache needs rebuilding.
 * @return int|null Newly created course module id or null on failure.
 */
function local_aiplacement_modgen_create_subsection(stdClass $course, int $sectionnum, string $name, string $summaryhtml, bool &$needscacherefresh): ?int {
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

    if (!empty($cmid) && $summaryhtml !== '') {
        $cmrecord = get_coursemodule_from_id('subsection', $cmid, $course->id, false, IGNORE_MISSING);
        if ($cmrecord) {
            $manager = \mod_subsection\manager::create_from_coursemodule($cmrecord);
            $delegatedsection = $manager->get_delegated_section_info();
            if ($delegatedsection) {
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

    return $cmid ?: null;
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

// Define first form: prompt input.
class aiplacement_modgen_prompt_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }
        $creditsoptions = [
            30 => get_string('connectedcurriculum30', 'aiplacement_modgen'),
            60 => get_string('connectedcurriculum60', 'aiplacement_modgen'),
            120 => get_string('connectedcurriculum120', 'aiplacement_modgen'),
        ];
        $mform->addElement('select', 'credits', get_string('connectedcurriculumcredits', 'aiplacement_modgen'), $creditsoptions);
        $mform->setType('credits', PARAM_INT);
        $mform->setDefault('credits', 30);
        $moduletypeoptions = [
            'weekly' => get_string('moduletype_weekly', 'aiplacement_modgen'),
            'theme' => get_string('moduletype_theme', 'aiplacement_modgen'),
        ];
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->setDefault('moduletype', 'weekly');
        $mform->addElement('advcheckbox', 'keepweeklabels', get_string('keepweeklabels', 'aiplacement_modgen'));
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->setDefault('keepweeklabels', 1);
        $mform->hideIf('keepweeklabels', 'moduletype', 'neq', 'weekly');
        $mform->addElement('advcheckbox', 'includeaboutassessments', get_string('includeaboutassessments', 'aiplacement_modgen'));
        $mform->setType('includeaboutassessments', PARAM_BOOL);
        $mform->setDefault('includeaboutassessments', 0);
        $mform->addElement('advcheckbox', 'includeaboutlearning', get_string('includeaboutlearning', 'aiplacement_modgen'));
        $mform->setType('includeaboutlearning', PARAM_BOOL);
        $mform->setDefault('includeaboutlearning', 0);
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="4" cols="60"');
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $this->add_action_buttons(false, get_string('submit', 'aiplacement_modgen'));
    }
}

// Define second form: approval.
class aiplacement_modgen_approve_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }
        $mform->addElement('hidden', 'approvedjson', $this->_customdata['approvedjson']);
        $mform->setType('approvedjson', PARAM_RAW);
        $mform->addElement('hidden', 'moduletype', $this->_customdata['moduletype']);
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->addElement('hidden', 'keepweeklabels', $this->_customdata['keepweeklabels']);
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->addElement('hidden', 'includeaboutassessments', $this->_customdata['includeaboutassessments']);
        $mform->setType('includeaboutassessments', PARAM_BOOL);
        $mform->addElement('hidden', 'includeaboutlearning', $this->_customdata['includeaboutlearning']);
        $mform->setType('includeaboutlearning', PARAM_BOOL);
        $mform->addElement('hidden', 'generatedsummary', $this->_customdata['generatedsummary']);
        $mform->setType('generatedsummary', PARAM_RAW);
        $this->add_action_buttons(false, get_string('approveandcreate', 'aiplacement_modgen'));
    }
}

// Business logic.
$orgparams = get_config('aiplacement_modgen', 'orgparams');
require_once(__DIR__ . '/classes/local/ai_service.php');
require_once(__DIR__ . '/classes/activitytype/registry.php');

// Attempt approval form first (so refreshes on approval post are handled).
$approveform = null;
$approvedjsonparam = optional_param('approvedjson', null, PARAM_RAW);
$approvedtypeparam = optional_param('moduletype', 'weekly', PARAM_ALPHA);
$keepweeklabelsparam = optional_param('keepweeklabels', 0, PARAM_BOOL);
$includeaboutassessmentsparam = optional_param('includeaboutassessments', 0, PARAM_BOOL);
$includeaboutlearningparam = optional_param('includeaboutlearning', 0, PARAM_BOOL);
$generatedsummaryparam = optional_param('generatedsummary', '', PARAM_RAW);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'keepweeklabels' => $keepweeklabelsparam,
        'includeaboutassessments' => $includeaboutassessmentsparam,
        'includeaboutlearning' => $includeaboutlearningparam,
        'generatedsummary' => $generatedsummaryparam,
        'embedded' => $embedded ? 1 : 0,
    ]);
}

if ($approveform && ($adata = $approveform->get_data())) {
    // Create weekly sections from approved JSON.
    $json = json_decode($adata->approvedjson, true);
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/course/format/lib.php');
    require_once($CFG->dirroot . '/course/modlib.php');
    require_once($CFG->dirroot . '/mod/subsection/classes/manager.php');
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
        $assessmentcmid = local_aiplacement_modgen_create_subsection($course, 0, $assessmentname, '', $needscacherefresh);
        if (!empty($assessmentcmid)) {
            $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $assessmentname);
            $aboutassessmentsadded = true;
        }
    }
    if ($includeaboutlearning) {
        $learningname = get_string('aboutlearningoutcomes', 'aiplacement_modgen');
        $learningcmid = local_aiplacement_modgen_create_subsection($course, 0, $learningname, '', $needscacherefresh);
        if (!empty($learningcmid)) {
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
                $activityoutcome = \local_aiplacement_modgen\activitytype\registry::create_for_section(
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

                    $cmid = local_aiplacement_modgen_create_subsection($course, $sectionnum, $weektitle, $subsectionsummary, $needscacherefresh);
                    if (!empty($cmid)) {
                        $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $weektitle);
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
                $activityoutcome = \local_aiplacement_modgen\activitytype\registry::create_for_section(
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
$promptform = new aiplacement_modgen_prompt_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
]);
if ($promptform->is_cancelled()) {
    if ($ajax) {
        aiplacement_modgen_send_ajax_response('', '', false, ['close' => true]);
    }
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}
if ($pdata = $promptform->get_data()) {
    $prompt = $pdata->prompt;
    $credits = isset($pdata->credits) ? (int)$pdata->credits : 30;
    $moduletype = !empty($pdata->moduletype) ? $pdata->moduletype : 'weekly';
    $keepweeklabels = ($moduletype === 'weekly') && !empty($pdata->keepweeklabels);
    $includeaboutassessments = !empty($pdata->includeaboutassessments);
    $includeaboutlearning = !empty($pdata->includeaboutlearning);
    $creditinfo = get_string('connectedcurriculuminstruction', 'aiplacement_modgen', $credits);
    $typeinstructionkey = $moduletype === 'theme' ? 'moduletypeinstruction_theme' : 'moduletypeinstruction_weekly';
    $typeinstruction = get_string($typeinstructionkey, 'aiplacement_modgen');
    $compositeprompt = trim($prompt . "\n\n" . $creditinfo . "\n" . $typeinstruction);
    $json = \local_aiplacement_modgen\ai_service::generate_module($compositeprompt, $orgparams, [], $moduletype);
    // Get the final prompt sent to AI for debugging (returned by ai_service).
    $debugprompt = isset($json['debugprompt']) ? $json['debugprompt'] : $prompt;
    $jsonstr = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonstr === false) {
        $jsonstr = print_r($json, true);
    }
    $summarytext = \local_aiplacement_modgen\ai_service::summarise_module($json, $moduletype);
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
        'generatedsummary' => $summarytext,
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

    if ($ajax) {
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
        $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);

        aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, false, [
            'title' => get_string('pluginname', 'aiplacement_modgen'),
        ]);
    }

    echo $OUTPUT->header();
    echo $bodyhtml;
    echo $OUTPUT->footer();
    exit;
}

// Default display: prompt form.
ob_start();
$promptform->display();
$formhtml = ob_get_clean();
$bodyhtml = html_writer::div($formhtml, 'aiplacement-modgen__content');

if ($ajax) {
    $footeractions = [[
        'label' => get_string('submit', 'aiplacement_modgen'),
        'classes' => 'btn btn-primary',
        'isbutton' => true,
        'action' => 'aiplacement-modgen-submit',
        'index' => 0,
        'hasindex' => true,
    ]];
    $footerhtml = aiplacement_modgen_render_modal_footer($footeractions);

    aiplacement_modgen_send_ajax_response($bodyhtml, $footerhtml, false, [
        'title' => get_string('pluginname', 'aiplacement_modgen'),
    ]);
}

echo $OUTPUT->header();
echo $bodyhtml;
echo $OUTPUT->footer();
