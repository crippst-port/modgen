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

        // Get max sections from config
        $maxsections = (int)get_config('aiplacement_modgen', 'maxquicksections') ?: 10;

        // Number of themes to create (text input).
        $mform->addElement('text', 'themecount', get_string('themecount', 'aiplacement_modgen'), ['size' => 5]);
        $mform->setType('themecount', PARAM_INT);
        $mform->setDefault('themecount', 1);
        $mform->addRule('themecount', null, 'required', null, 'client');
        $mform->addRule('themecount', null, 'numeric', null, 'client');
        $mform->addHelpButton('themecount', 'themecount', 'aiplacement_modgen');

        // Number of weeks per theme (text input).
        $mform->addElement('text', 'weeksperTheme', get_string('weeksperTheme', 'aiplacement_modgen'), ['size' => 5]);
        $mform->setType('weeksperTheme', PARAM_INT);
        $mform->setDefault('weeksperTheme', 1);
        $mform->addRule('weeksperTheme', null, 'required', null, 'client');
        $mform->addRule('weeksperTheme', null, 'numeric', null, 'client');
        $mform->addHelpButton('weeksperTheme', 'weeksperTheme', 'aiplacement_modgen');

        // Action buttons
        $this->add_action_buttons(true, get_string('generatorbutton', 'aiplacement_modgen'));
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

        // Get max sections from config
        $maxsections = (int)get_config('aiplacement_modgen', 'maxquicksections') ?: 10;

        if (empty($data['themecount']) || $data['themecount'] < 1 || $data['themecount'] > $maxsections) {
            $errors['themecount'] = get_string('invalidthemecount', 'aiplacement_modgen', $maxsections);
        }

        if (empty($data['weeksperTheme']) || $data['weeksperTheme'] < 1 || $data['weeksperTheme'] > $maxsections) {
            $errors['weeksperTheme'] = get_string('invalidweeksperTheme', 'aiplacement_modgen', $maxsections);
        }

        return $errors;
    }
}
