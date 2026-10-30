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
 * Prompt form for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for generating module structure from a text prompt.
 */
class aiplacement_modgen_prompt_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        // Laid out like the Quick Add forms, wherever it is shown: the modal, or prompt.php after an invalid prompt.
        $mform->updateAttributes(['class' => $mform->getAttribute('class') . ' aiplacement-modgen-qa full-width-labels']);
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        \aiplacement_modgen\local\quick_add_ui::add_intro($mform, 'bolt', get_string('promptintro', 'aiplacement_modgen'));

        // Warn, without refusing, when the module already has content.
        \aiplacement_modgen\local\existing_content::add_warning($mform, (int) $this->_customdata['courseid']);

        // Every supported format takes a weekly module; themes need Flexible sections. With only the weekly
        // module to offer there is nothing to choose, so the type is sent without a field.
        if (\aiplacement_modgen\local\course_formats::supports_themes((int) $this->_customdata['courseid'])) {
            // Module type selection, shown as choice cards (amd/src/quick_add_form.js).
            $mform->addElement(
                'select',
                'moduletype',
                get_string('moduletype', 'aiplacement_modgen'),
                [
                    'connected_weekly' => get_string('moduletype_connected_weekly', 'aiplacement_modgen'),
                    'connected_theme' => get_string('moduletype_connected_theme', 'aiplacement_modgen'),
                ],
                \aiplacement_modgen\local\quick_add_ui::choice_attributes([
                    'connected_weekly' => ['calendar', get_string('moduletypechoice_connected_weekly', 'aiplacement_modgen')],
                    'connected_theme' => ['book', get_string('moduletypechoice_connected_theme', 'aiplacement_modgen')],
                ])
            );
            $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');
        } else {
            $mform->addElement('hidden', 'moduletype');
        }
        $mform->setType('moduletype', PARAM_ALPHANUMEXT);
        $mform->setDefault('moduletype', 'connected_weekly');

        // Main content prompt.
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), [
            'rows' => 6,
            'cols' => 60,
            'placeholder' => get_string('promptplaceholder', 'aiplacement_modgen'),
        ]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');

        $this->add_action_buttons(true, get_string('submit', 'aiplacement_modgen'));
    }

    /**
     * Refuse a prompt that is only whitespace. The required rule lets that through unless the site
     * sets $CFG->strictformsrequired, and an empty prompt would still use up one of the user's AI requests.
     *
     * @param array $data Submitted data
     * @param array $files Submitted files
     * @return array Errors by field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string) ($data['prompt'] ?? '')) === '') {
            $errors['prompt'] = get_string('err_required', 'form');
        }
        return $errors;
    }
}
