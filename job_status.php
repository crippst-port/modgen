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

// Get job and verify access
$job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_capability('aiplacement/modgen:managestructure', $context);

// Verify user owns this job
if ($job->userid != $USER->id) {
    throw new moodle_exception('nopermissions', 'error', '', 'view this job');
}

$PAGE->set_url('/ai/placement/modgen/job_status.php', ['jobid' => $jobid]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('jobstatuspage_title', 'aiplacement_modgen'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('jobstatuspage_title', 'aiplacement_modgen'));

// Get action type for display
$actiondisplay = '';
$params = json_decode($job->parameters ?? '{}');
switch ($job->action) {
    case 'create_themes':
        $themecount = $params->themecount ?? 1;
        $weeksperTheme = $params->weeksperTheme ?? 1;
        $actiondisplay = get_string('jobaction_create_themes', 'aiplacement_modgen', [
            'themes' => $themecount,
            'weeks' => $weeksperTheme,
        ]);
        break;
    case 'create_weeks':
        $weekcount = $params->weekcount ?? 1;
        $actiondisplay = get_string('jobaction_create_weeks', 'aiplacement_modgen', $weekcount);
        break;
    case 'create_from_json':
        // Try to get JSON data - it might be in $params->json or directly in $params
        $jsondata = $params->json ?? $params;
        $sectioncount = 0;
        if ($jsondata && is_object($jsondata)) {
            // Check all possible structure keys
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

// Prepare template data
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
$templatedata = [
    'jobid' => $jobid,
    'courseid' => $course->id,
    'coursename' => $course->fullname,
    'courseurl' => $courseurl->out(false),
    'status' => $job->status,
    'actiondisplay' => $actiondisplay,
    'timecreated' => userdate($job->timecreated, get_string('strftimedatetimeshort')),
    'isqueued' => ($job->status === 'queued'),
    'isrunning' => ($job->status === 'running'),
    'iscompleted' => ($job->status === 'completed'),
    'isfailed' => ($job->status === 'failed'),
];

// If completed, add result
if ($job->status === 'completed' && $job->result) {
    $result = json_decode($job->result, true);
    $templatedata['result'] = $result;
    if (!empty($result['messages'])) {
        $templatedata['messages'] = array_map(function ($msg) {
            return ['text' => $msg];
        }, $result['messages']);
    }
}

// If failed, add error
if ($job->status === 'failed' && $job->result) {
    $result = json_decode($job->result, true);
    $templatedata['error'] = $result['error'] ?? get_string('unknownerror', 'aiplacement_modgen');
    $templatedata['canretry'] = !empty($result['will_retry']);
}

// Initialize JavaScript
$PAGE->requires->js_call_amd('aiplacement_modgen/job_status_page', 'init', [
    [
        'jobid' => $jobid,
        'courseid' => $course->id,
        'courseurl' => $courseurl->out(false),
        'initialstatus' => $job->status,
    ],
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('aiplacement_modgen/job_status_page', $templatedata);
echo $OUTPUT->footer();
