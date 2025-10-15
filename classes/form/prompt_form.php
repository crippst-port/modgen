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
 * Prompt capture form for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class aiplacement_modgen_prompt_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $creditoptions = [
            30 => get_string('connectedcurriculum30', 'aiplacement_modgen'),
            60 => get_string('connectedcurriculum60', 'aiplacement_modgen'),
            120 => get_string('connectedcurriculum120', 'aiplacement_modgen'),
        ];
        $mform->addElement('text', 'prompt', get_string('prompt', 'aiplacement_modgen'));
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addElement('select', 'credits', get_string('connectedcurriculumcredits', 'aiplacement_modgen'), $creditoptions);
        $mform->setDefault('credits', 30);
        $mform->setType('credits', PARAM_INT);
        $moduletypeoptions = [
            'weekly' => get_string('moduletype_weekly', 'aiplacement_modgen'),
            'theme' => get_string('moduletype_theme', 'aiplacement_modgen'),
        ];
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setDefault('moduletype', 'weekly');
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->addElement('advcheckbox', 'keepweeklabels', get_string('keepweeklabels', 'aiplacement_modgen'));
        $mform->setDefault('keepweeklabels', 0);
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->hideIf('keepweeklabels', 'moduletype', 'neq', 'weekly');
    $mform->addElement('advcheckbox', 'includeaboutsubsections', get_string('includeaboutsubsections', 'aiplacement_modgen'));
    $mform->setDefault('includeaboutsubsections', 0);
    $mform->setType('includeaboutsubsections', PARAM_BOOL);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('submit', 'submitbutton', get_string('submit', 'aiplacement_modgen'));
    }
}
