<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_login();

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

// Resolve course id from id or courseid.
$courseid = optional_param('id', 0, PARAM_INT);
if (!$courseid) {
    $courseid = optional_param('courseid', 0, PARAM_INT);
}
if (!$courseid) {
    print_error('missingcourseid', 'aiplacement_modgen');
}

$context = context_course::instance($courseid);
$PAGE->set_url(new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'aiplacement_modgen'));
$PAGE->set_heading(get_string('pluginname', 'aiplacement_modgen'));

// Define first form: prompt input.
class aiplacement_modgen_prompt_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
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
        $this->add_action_buttons(false, get_string('approveandcreate', 'aiplacement_modgen'));
    }
}

// Business logic.
$orgparams = get_config('aiplacement_modgen', 'orgparams');
require_once(__DIR__ . '/classes/local/ai_service.php');

// Attempt approval form first (so refreshes on approval post are handled).
$approveform = null;
$approvedjsonparam = optional_param('approvedjson', null, PARAM_RAW);
$approvedtypeparam = optional_param('moduletype', 'weekly', PARAM_ALPHA);
$keepweeklabelsparam = optional_param('keepweeklabels', 0, PARAM_BOOL);
$includeaboutassessmentsparam = optional_param('includeaboutassessments', 0, PARAM_BOOL);
$includeaboutlearningparam = optional_param('includeaboutlearning', 0, PARAM_BOOL);
if ($approvedjsonparam !== null) {
    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $approvedjsonparam,
        'moduletype' => $approvedtypeparam,
        'keepweeklabels' => $keepweeklabelsparam,
        'includeaboutassessments' => $includeaboutassessmentsparam,
        'includeaboutlearning' => $includeaboutlearningparam,
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

            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            $sectionnum++;
        }
    }

    if ($needscacherefresh) {
        rebuild_course_cache($courseid, true, true);
    }

    $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    $resultsdata = [
        'notifications' => [],
        'hasresults' => !empty($results),
        'results' => array_map(static function(string $text): array {
            return ['text' => $text];
        }, $results),
        'returnlink' => [
            'url' => $courseurl->out(false),
            'label' => get_string('returntocourse', 'aiplacement_modgen'),
        ],
    ];

    if (empty($results)) {
        $resultsdata['notifications'][] = [
            'message' => get_string('nosectionscreated', 'aiplacement_modgen'),
            'classes' => 'alert alert-warning',
        ];
    }

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('aiplacement_modgen/generation_results', $resultsdata);
    echo $OUTPUT->footer();
    exit;
}

// Prompt form handling.
$promptform = new aiplacement_modgen_prompt_form(null, ['courseid' => $courseid]);
if ($promptform->is_cancelled()) {
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
    $jsonstr = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $approveform = new aiplacement_modgen_approve_form(null, [
        'courseid' => $courseid,
        'approvedjson' => $jsonstr,
        'moduletype' => $moduletype,
        'keepweeklabels' => $keepweeklabels ? 1 : 0,
        'includeaboutassessments' => $includeaboutassessments ? 1 : 0,
        'includeaboutlearning' => $includeaboutlearning ? 1 : 0,
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
        'promptheading' => get_string('promptsentheading', 'aiplacement_modgen'),
        'prompt' => $debugprompt,
        'debugresponse' => !empty($json['debugresponse']) ? [
            'heading' => get_string('aisubsystemresponsedata', 'aiplacement_modgen'),
            'content' => print_r($json['debugresponse'], true),
        ] : null,
        'raw' => !empty($json['raw']) ? [
            'heading' => get_string('rawoutput', 'aiplacement_modgen'),
            'content' => $json['raw'],
        ] : null,
        'jsonheading' => get_string('jsonpreview', 'aiplacement_modgen'),
        'json' => $jsonstr,
        'form' => $formhtml,
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('aiplacement_modgen/prompt_preview', $previewdata);
    echo $OUTPUT->footer();
    exit;
}

// Default display: prompt form.
echo $OUTPUT->header();
$promptform->display();
echo $OUTPUT->footer();
