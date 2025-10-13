<?php
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
