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
 * Job status page - shows real-time progress of background section creation.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

$jobid = required_param('jobid', PARAM_INT);

require_login();

// Master switch: Site admin > AI > AI placements.
\aiplacement_modgen\local\settings_helper::require_placement_enabled();

// Get the job, checking it belongs to this user and that they may still use the plugin in its course.
$job = \aiplacement_modgen\local\job_status_helper::get_job_for_user($jobid, (int) $USER->id);
$course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);

$PAGE->set_url('/ai/placement/modgen/job_status.php', ['jobid' => $jobid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('jobstatuspage_title', 'aiplacement_modgen'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->navbar->add(get_string('jobstatuspage_title', 'aiplacement_modgen'));

// Get action type for display.
$actiondisplay = '';
$params = json_decode($job->parameters ?? '{}');
switch ($job->action) {
    case 'create_themes':
        $themecount = $params->themecount ?? 1;
        $weeksperthemecount = $params->weeksperTheme ?? 1;
        $actiondisplay = get_string('jobaction_create_themes', 'aiplacement_modgen', [
            'themes' => $themecount,
            'weeks' => $weeksperthemecount,
        ]);
        break;
    case 'create_weeks':
        $weekcount = $params->weekcount ?? 1;
        $actiondisplay = get_string('jobaction_create_weeks', 'aiplacement_modgen', $weekcount);
        break;
    case 'create_from_json':
        // New jobs store a section count; jobs queued before that kept the whole structure in the parameters.
        $sectioncount = (int) ($params->sectioncount ?? 0);
        $jsondata = $params->json ?? $params;
        if (!$sectioncount && $jsondata && is_object($jsondata)) {
            // Check all possible structure keys.
            if (!empty($jsondata->themes)) {
                $sectioncount = count($jsondata->themes);
            } else if (!empty($jsondata->weeks)) {
                $sectioncount = count($jsondata->weeks);
            } else if (!empty($jsondata->sections)) {
                $sectioncount = count($jsondata->sections);
            }
        }
        $actiondisplay = get_string('jobaction_create_from_json', 'aiplacement_modgen', $sectioncount);
        break;
    default:
        $actiondisplay = get_string('jobaction_generic', 'aiplacement_modgen');
}

// Prepare template data.
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$templatedata = [
    'jobid' => $jobid,
    'courseid' => $course->id,
    'coursename' => format_string($course->fullname, true, ['context' => $context]),
    'courseurl' => $courseurl->out(false),
    'status' => get_string('jobstate_' . $job->status, 'aiplacement_modgen'),
    'actiondisplay' => $actiondisplay,
    'timecreated' => userdate($job->timecreated, get_string('strftimedatetimeshort')),
    'isqueued' => ($job->status === 'queued'),
    'isrunning' => ($job->status === 'running'),
    'iscompleted' => ($job->status === 'completed'),
    'isfailed' => ($job->status === 'failed'),
];

// If completed, add result.
if ($job->status === 'completed' && $job->result) {
    $result = json_decode($job->result, true);
    $templatedata['result'] = $result;
    if (!empty($result['messages'])) {
        $templatedata['messages'] = array_map(function ($msg) {
            return ['text' => $msg];
        }, $result['messages']);
    }
    if (!empty($result['warnings'])) {
        $templatedata['haswarnings'] = true;
        $templatedata['warnings'] = array_map(function ($warning) {
            return ['text' => $warning];
        }, $result['warnings']);
    }
}

// If failed, add error.
if ($job->status === 'failed' && $job->result) {
    $result = json_decode($job->result, true);
    $templatedata['error'] = $result['error'] ?? get_string('unknownerror', 'aiplacement_modgen');
    $templatedata['canretry'] = !empty($result['will_retry']);
}

// Initialize JavaScript.
$PAGE->requires->js_call_amd('aiplacement_modgen/job_status_page', 'init', [
    [
        'jobid' => $jobid,
        'courseid' => $course->id,
        'courseurl' => $courseurl->out(false),
        'initialstatus' => $job->status,
        'haswarnings' => !empty($templatedata['haswarnings']),
        'willretry' => !empty($templatedata['canretry']),
        'strings' => [
            'completed' => get_string('jobstatus_completed', 'aiplacement_modgen'),
            'completedwarnings' => get_string('jobcompletedwarnings', 'aiplacement_modgen'),
            'queued' => get_string('jobstatus_queued', 'aiplacement_modgen'),
            'queueddesc' => get_string('jobstatus_queued_desc', 'aiplacement_modgen'),
            'running' => get_string('jobstatus_running', 'aiplacement_modgen'),
            'runningdesc' => get_string('jobstatus_running_desc', 'aiplacement_modgen'),
            'redirectnote' => get_string('jobstatus_redirect_note', 'aiplacement_modgen'),
            'failed' => get_string('jobstatus_failed', 'aiplacement_modgen'),
            'willretry' => get_string('jobstatus_will_retry', 'aiplacement_modgen'),
            'returncourse' => get_string('returntocourse', 'aiplacement_modgen'),
            'unknownerror' => get_string('unknownerror', 'aiplacement_modgen'),
            'error' => get_string('error'),
            'loading' => get_string('loading'),
            'states' => [
                'queued' => get_string('jobstate_queued', 'aiplacement_modgen'),
                'running' => get_string('jobstate_running', 'aiplacement_modgen'),
                'completed' => get_string('jobstate_completed', 'aiplacement_modgen'),
                'failed' => get_string('jobstate_failed', 'aiplacement_modgen'),
            ],
        ],
    ],
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('aiplacement_modgen/job_status_page', $templatedata);
echo $OUTPUT->footer();
