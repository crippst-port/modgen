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
 * Admin page for managing CSV templates.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/tablelib.php');

use aiplacement_modgen\local\template_manager;

require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$templateid = optional_param('id', 0, PARAM_INT);

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/ai/placement/modgen/manage_templates.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managetemplates', 'aiplacement_modgen'));
$PAGE->set_heading(get_string('managetemplates', 'aiplacement_modgen'));

// Handle actions
if ($action === 'delete' && $templateid && confirm_sesskey()) {
    template_manager::delete($templateid);
    redirect($PAGE->url, get_string('templatedeleted', 'aiplacement_modgen'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'moveup' && $templateid && confirm_sesskey()) {
    template_manager::reorder($templateid, 'up');
    redirect($PAGE->url);
}

if ($action === 'movedown' && $templateid && confirm_sesskey()) {
    template_manager::reorder($templateid, 'down');
    redirect($PAGE->url);
}

// Form handling
require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/template_form.php');

$mform = new aiplacement_modgen_template_form();

if ($mform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $mform->get_data()) {
    global $USER;
    
    // Get uploaded file
    $draftitemid = $data->templatefile;
    $fs = get_file_storage();
    $context = context_user::instance($USER->id);
    $draftfiles = $fs->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);
    
    if (!empty($draftfiles)) {
        $file = reset($draftfiles);
        
        if ($data->id) {
            // Update existing template
            template_manager::update($data->id, $data->name, $data->description, $file);
            $message = get_string('templateupdated', 'aiplacement_modgen');
        } else {
            // Create new template
            template_manager::create($data->name, $data->description, $file);
            $message = get_string('templatecreated', 'aiplacement_modgen');
        }
        
        redirect($PAGE->url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managetemplates', 'aiplacement_modgen'));

// Display existing templates
$templates = template_manager::get_all();

if (!empty($templates)) {
    $table = new html_table();
    $table->head = [
        get_string('templatename', 'aiplacement_modgen'),
        get_string('templatedescription', 'aiplacement_modgen'),
        get_string('timecreated', 'aiplacement_modgen'),
        get_string('actions')
    ];
    $table->attributes['class'] = 'admintable generaltable';
    
    $templatecount = count($templates);
    $index = 0;
    
    foreach ($templates as $template) {
        $index++;
        
        $actions = [];
        
        // Move up/down
        if ($index > 1) {
            $moveup = new moodle_url($PAGE->url, ['action' => 'moveup', 'id' => $template->id, 'sesskey' => sesskey()]);
            $actions[] = html_writer::link($moveup, $OUTPUT->pix_icon('t/up', get_string('moveup')));
        }
        if ($index < $templatecount) {
            $movedown = new moodle_url($PAGE->url, ['action' => 'movedown', 'id' => $template->id, 'sesskey' => sesskey()]);
            $actions[] = html_writer::link($movedown, $OUTPUT->pix_icon('t/down', get_string('movedown')));
        }
        
        // Delete
        $deleteurl = new moodle_url($PAGE->url, ['action' => 'delete', 'id' => $template->id, 'sesskey' => sesskey()]);
        $actions[] = html_writer::link($deleteurl, $OUTPUT->pix_icon('t/delete', get_string('delete')),
            ['onclick' => 'return confirm("' . get_string('confirmdeletetemplate', 'aiplacement_modgen') . '");']);
        
        $table->data[] = [
            format_string($template->name),
            format_text($template->description, FORMAT_PLAIN),
            userdate($template->timecreated),
            implode(' ', $actions)
        ];
    }
    
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('notemplates', 'aiplacement_modgen'), 'info');
}

// Display form for adding new template
echo $OUTPUT->heading(get_string('addnewtemplate', 'aiplacement_modgen'), 3);
$mform->display();

echo $OUTPUT->footer();
