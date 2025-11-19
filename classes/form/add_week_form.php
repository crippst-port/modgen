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
 * Form for adding a new week section to a module.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Form for adding a new week.
 */
class aiplacement_modgen_add_week_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden course ID.
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        // Week title.
        $mform->addElement('text', 'title', get_string('title', 'aiplacement_modgen'));
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');

        // Week summary.
        $mform->addElement('textarea', 'summary', get_string('summary', 'aiplacement_modgen'),
            ['rows' => 4, 'cols' => 50]);
        $mform->setType('summary', PARAM_RAW);

        // Submit button.
        $this->add_action_buttons(true, get_string('addweek', 'aiplacement_modgen'));
    }

    /**
     * Form validation.
     *
     * @param array $data Data from the form
     * @param array $files Files uploaded with the form
     * @return array Array of errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['title']) || trim($data['title']) === '') {
            $errors['title'] = get_string('required');
        }

        return $errors;
    }
}
