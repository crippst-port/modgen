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
 * Form for adding a new theme section to a module.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Form for adding a new theme.
 */
class aiplacement_modgen_add_theme_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden course ID.
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        // Number of themes to create (1-10).
        $options = [];
        for ($i = 1; $i <= 10; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'themecount', get_string('themecount', 'aiplacement_modgen'), $options);
        $mform->setDefault('themecount', 1);

        // Number of weeks per theme (1-10).
        $mform->addElement('select', 'weeksperTheme', get_string('weeksperTheme', 'aiplacement_modgen'), $options);
        $mform->setDefault('weeksperTheme', 1);

        // Submit button.
        $this->add_action_buttons(true, get_string('addtheme', 'aiplacement_modgen'));
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

        if (empty($data['themecount']) || $data['themecount'] < 1 || $data['themecount'] > 10) {
            $errors['themecount'] = get_string('invalidcount', 'aiplacement_modgen');
        }

        if (empty($data['weeksperTheme']) || $data['weeksperTheme'] < 1 || $data['weeksperTheme'] > 10) {
            $errors['weeksperTheme'] = get_string('invalidcount', 'aiplacement_modgen');
        }

        return $errors;
    }
}
