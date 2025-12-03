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

    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        
        // Add module type selection
        $moduletypeoptions = [];
        
        // Add Connected Curriculum format options if flexsections is installed
        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }
        
        // Module type selection
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setType('moduletype', PARAM_ALPHANUMEXT);
        $mform->setDefault('moduletype', 'connected_weekly');
        $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');

        // Main content prompt
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="6" cols="60"');
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');

        // === SUGGESTED CONTENT SECTION ===
        $mform->addElement('header', 'suggestedcontentheader', get_string('suggestedcontent', 'aiplacement_modgen'));
        
        // Generate example content option
        $mform->addElement('advcheckbox', 'generateexamplecontent', get_string('generateexamplecontent', 'aiplacement_modgen'));
        $mform->addHelpButton('generateexamplecontent', 'generateexamplecontent', 'aiplacement_modgen');
        $mform->setType('generateexamplecontent', PARAM_BOOL);
        $mform->setDefault('generateexamplecontent', 1);

        $this->add_action_buttons(true, get_string('submit', 'aiplacement_modgen'));
    }
}
