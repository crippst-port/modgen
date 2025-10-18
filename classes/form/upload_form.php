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
 * File upload form for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for uploading content files and creating activities.
 * 
 * This form allows users to upload documents (.doc, .docx, .odt)
 * to be converted into course activities.
 */
class aiplacement_modgen_upload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }
        
        // Use Moodle's filepicker
        $mform->addElement('filepicker', 'contentfile', 
            get_string('contentfile', 'aiplacement_modgen'),
            null,
            ['accepted_types' => ['.docx', '.doc', '.odt']]
        );
        $mform->addRule('contentfile', null, 'required', null, 'client');
        
        $activities = [
            'book' => get_string('activitytype_book', 'aiplacement_modgen') . ' - ' . 
                      get_string('bookdescription', 'aiplacement_modgen'),
        ];
        $mform->addElement('select', 'activitytype', 
            get_string('selectactivitytype', 'aiplacement_modgen'), $activities);
        $mform->setType('activitytype', PARAM_ALPHA);
        $mform->setDefault('activitytype', 'book');
        
        $mform->addElement('text', 'activityname', get_string('name', 'moodle'));
        $mform->setType('activityname', PARAM_TEXT);
        $mform->addRule('activityname', null, 'required', null, 'client');
        
        $mform->addElement('hidden', 'sectionnumber', 0);
        $mform->setType('sectionnumber', PARAM_INT);
        
        $mform->addElement('textarea', 'activityintro', 
            get_string('activityintro', 'aiplacement_modgen'), 'rows="3" cols="60"');
        $mform->setType('activityintro', PARAM_RAW);
        
        $this->add_action_buttons(false, get_string('uploadandcreate', 'aiplacement_modgen'));
    }
}
