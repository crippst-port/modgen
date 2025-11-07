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
 * Approval form for Module Generator output review.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form for approving and creating generated modules.
 * 
 * This form allows users to review AI-generated module structure
 * before creating it in the course.
 */
class aiplacement_modgen_approve_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', $this->_customdata['courseid']);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
            $mform->setDefault('embedded', 1);
        }
        $mform->addElement('hidden', 'approvedjson', $this->_customdata['approvedjson']);
        $mform->setType('approvedjson', PARAM_RAW);
        $mform->setDefault('approvedjson', $this->_customdata['approvedjson']);
        $mform->addElement('hidden', 'moduletype', $this->_customdata['moduletype']);
        $mform->setType('moduletype', PARAM_ALPHANUMEXT);
        $mform->setDefault('moduletype', $this->_customdata['moduletype']);
        $mform->addElement('hidden', 'keepweeklabels', $this->_customdata['keepweeklabels']);
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->setDefault('keepweeklabels', $this->_customdata['keepweeklabels']);
        $mform->addElement('hidden', 'generatethemeintroductions', !empty($this->_customdata['generatethemeintroductions']) ? $this->_customdata['generatethemeintroductions'] : 0);
        $mform->setType('generatethemeintroductions', PARAM_BOOL);
        $mform->setDefault('generatethemeintroductions', !empty($this->_customdata['generatethemeintroductions']) ? $this->_customdata['generatethemeintroductions'] : 0);
        $mform->addElement('hidden', 'createsuggestedactivities', $this->_customdata['createsuggestedactivities']);
        $mform->setType('createsuggestedactivities', PARAM_BOOL);
        $mform->setDefault('createsuggestedactivities', $this->_customdata['createsuggestedactivities']);
        $mform->addElement('hidden', 'generatedsummary', $this->_customdata['generatedsummary']);
        $mform->setType('generatedsummary', PARAM_RAW);
        $mform->setDefault('generatedsummary', $this->_customdata['generatedsummary']);
        if (isset($this->_customdata['curriculum_template'])) {
            $mform->addElement('hidden', 'curriculum_template', $this->_customdata['curriculum_template']);
            $mform->setType('curriculum_template', PARAM_TEXT);
            $mform->setDefault('curriculum_template', $this->_customdata['curriculum_template']);
        }
        $this->add_action_buttons(false, get_string('approveandcreate', 'aiplacement_modgen'));
    }
}
