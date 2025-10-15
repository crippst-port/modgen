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

class aiplacement_modgen_approve_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'approvedjson');
        $mform->setType('approvedjson', PARAM_RAW);
        $mform->addElement('hidden', 'moduletype');
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->addElement('hidden', 'keepweeklabels');
        $mform->setType('keepweeklabels', PARAM_BOOL);
    $mform->addElement('hidden', 'includeaboutassessments');
    $mform->setType('includeaboutassessments', PARAM_BOOL);
    $mform->addElement('hidden', 'includeaboutlearning');
    $mform->setType('includeaboutlearning', PARAM_BOOL);
        
        $mform->addElement('submit', 'approvebutton', get_string('approveandcreate', 'aiplacement_modgen'));
    }
}
