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

        // Get max sections from config
        $maxsections = (int)get_config('aiplacement_modgen', 'maxquicksections');
        if (!$maxsections) {
            $maxsections = 30;
        }

        // Number of weeks to create (text input).
        $mform->addElement('text', 'weekcount', get_string('weekcount', 'aiplacement_modgen'), ['size' => 5]);
        $mform->setType('weekcount', PARAM_INT);
        $mform->setDefault('weekcount', 1);
        $mform->addRule('weekcount', null, 'required', null, 'client');
        $mform->addRule('weekcount', null, 'numeric', null, 'client');
        $mform->addHelpButton('weekcount', 'weekcount', 'aiplacement_modgen');
        // Add hint text showing the maximum
        $hinttext = 'Enter a number between 1 and ' . $maxsections;
        $mform->addElement('static', 'weekcount_hint', '',
            html_writer::tag('small', $hinttext, ['class' => 'form-text text-muted']));

        // Optionally create the learningactivity "section summary" placeholder modules.
        // The help text only mentions the AI-metadata caveat when AI is enabled.
        $mform->addElement('advcheckbox', 'createsummaryactivities',
            get_string('createsummaryactivities', 'aiplacement_modgen'));
        $mform->setType('createsummaryactivities', PARAM_BOOL);
        $mform->setDefault('createsummaryactivities', 0);
        $summaryhelp = get_config('aiplacement_modgen', 'enable_ai')
            ? 'createsummaryactivitiesai' : 'createsummaryactivities';
        $mform->addHelpButton('createsummaryactivities', $summaryhelp, 'aiplacement_modgen');

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

        if (empty($data['weekcount']) || $data['weekcount'] < 1 || $data['weekcount'] > $maxsections) {
            $errors['weekcount'] = get_string('invalidweekcount', 'aiplacement_modgen', $maxsections);
        }

        return $errors;
    }
}
