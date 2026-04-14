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
 * List of unedited AI-generated activities in a course.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Get course ID parameter.
$courseid = required_param('id', PARAM_INT);

// Get course and context.
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Require login and capability.
require_login($course);
$cangenerate = has_capability('aiplacement/modgen:generatewithprompt', $context) ||
               has_capability('aiplacement/modgen:generatefromtemplate', $context);
if (!$cangenerate) {
    throw new required_capability_exception($context, 'aiplacement/modgen:generatewithprompt',
        'nopermissions', 'error');
}

// Set up page.
$PAGE->set_url(new moodle_url('/ai/placement/modgen/aigen_list.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('aigen_list_title', 'aiplacement_modgen'));
$PAGE->set_heading($course->fullname);

// Add CSS.
$PAGE->requires->css('/ai/placement/modgen/styles.css');

// Add JS for visibility toggle.
$PAGE->requires->js_call_amd('aiplacement_modgen/aigen_list', 'init');

// Get all unedited AI-generated activities for this course.
$sql = "SELECT ag.id, ag.cmid, ag.courseid, ag.timecreated,
               cm.module, cm.instance, cm.section,
               m.name AS modname,
               cs.name AS sectionname, cs.section AS sectionnumber
          FROM {aiplacement_modgen_aigen} ag
          JOIN {course_modules} cm ON cm.id = ag.cmid
          JOIN {modules} m ON m.id = cm.module
          JOIN {course_sections} cs ON cs.id = cm.section
         WHERE ag.courseid = :courseid
           AND cm.deletioninprogress = 0
      ORDER BY cs.section ASC, ag.timecreated DESC";

$activities = $DB->get_records_sql($sql, ['courseid' => $courseid]);

// Get activity names.
$activitydata = [];
foreach ($activities as $activity) {
    $modinfo = get_fast_modinfo($course);
    
    if (!isset($modinfo->cms[$activity->cmid])) {
        // Activity no longer exists, clean up the record.
        $DB->delete_records('aiplacement_modgen_aigen', ['id' => $activity->id]);
        continue;
    }
    
    $cm = $modinfo->cms[$activity->cmid];
    
    // Get section info including parent.
    $sectioninfo = $modinfo->get_section_info($activity->sectionnumber);
    $sectionname = $activity->sectionname ?: get_string('section') . ' ' . $activity->sectionnumber;
    
    // Check for parent section.
    $parentsectionname = '';
    $parentsectionurl = '';
    
    // Method 1: Check for flexsections format parent (stored as section format option).
    if (isset($sectioninfo->parent) && $sectioninfo->parent > 0) {
        $parentsection = $modinfo->get_section_info($sectioninfo->parent);
        if ($parentsection) {
            $parentsectionname = $parentsection->name ?: get_string('section') . ' ' . $parentsection->section;
            $parentsectionurl = (new moodle_url('/course/view.php', [
                'id' => $courseid,
                'section' => $parentsection->section,
            ]))->out(false);
        }
    }
    
    // Method 2: Check for delegated sections / subsections (Moodle 4.x).
    if (empty($parentsectionname)) {
        $delegate = $sectioninfo->get_component_instance();
        if ($delegate && method_exists($delegate, 'get_parent_section')) {
            $parentsection = $delegate->get_parent_section();
            if ($parentsection) {
                $parentsectionname = $parentsection->name ?: get_string('section') . ' ' . $parentsection->section;
                $parentsectionurl = (new moodle_url('/course/view.php', [
                    'id' => $courseid,
                    'section' => $parentsection->section,
                ]))->out(false);
            }
        }
    }
    
    // Build section URL.
    $sectionurl = new moodle_url('/course/view.php', [
        'id' => $courseid,
        'section' => $activity->sectionnumber,
    ]);
    
    // Build edit URL.
    $editurl = new moodle_url('/course/modedit.php', [
        'update' => $activity->cmid,
        'return' => 1,
    ]);
    
    // Build view URL.
    $viewurl = new moodle_url('/mod/' . $activity->modname . '/view.php', [
        'id' => $activity->cmid,
    ]);
    
    $activitydata[] = (object)[
        'id' => $activity->id,
        'cmid' => $activity->cmid,
        'name' => $cm->name,
        'modname' => get_string('pluginname', 'mod_' . $activity->modname),
        'modicon' => $activity->modname,
        'sectionname' => $sectionname,
        'sectionurl' => $sectionurl->out(false),
        'sectionnumber' => $activity->sectionnumber,
        'hasparent' => !empty($parentsectionname),
        'parentsectionname' => $parentsectionname,
        'parentsectionurl' => $parentsectionurl,
        'timecreated' => userdate($activity->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'editurl' => $editurl->out(false),
        'viewurl' => $viewurl->out(false),
        'iconurl' => $cm->get_icon_url()->out(false),
        'visible' => $cm->visible,
    ];
}

// Output page.
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('aigen_list_title', 'aiplacement_modgen'));

echo html_writer::tag('p', get_string('aigen_list_description', 'aiplacement_modgen'), ['class' => 'lead']);

if (empty($activitydata)) {
    echo $OUTPUT->notification(get_string('aigen_list_empty', 'aiplacement_modgen'), 'info');
} else {
    // Render the list.
    echo $OUTPUT->render_from_template('aiplacement_modgen/aigen_list', [
        'activities' => $activitydata,
        'count' => count($activitydata),
        'courseid' => $courseid,
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    ]);
}

echo $OUTPUT->footer();
