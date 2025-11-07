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
 * Module generation form for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Form for generating module structure and content.
 * 
 * This form captures user input for AI-powered module generation,
 * including template selection, module type, and generation options.
 */
class aiplacement_modgen_generator_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }
        
        // Add module type selection
        $moduletypeoptions = [
            'weekly' => get_string('moduletype_weekly', 'aiplacement_modgen'),
        ];
        
        // Add Connected Curriculum format options if flexsections is installed
        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }
        
        // === TEMPLATE SETUP SECTION ===
        $mform->addElement('header', 'templatesettingsheader', get_string('templatesettings', 'aiplacement_modgen'));
        
        // Module type selection
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->setDefault('moduletype', 'weekly');
        $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');
        
        // Format-specific options - keep weekly labels under weekly selector
        $mform->addElement('advcheckbox', 'keepweeklabels', get_string('keepweeklabels', 'aiplacement_modgen'));
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->setDefault('keepweeklabels', 0);
        
        // Show keepweeklabels only when weekly or connected_weekly is selected
        $mform->hideIf('keepweeklabels', 'moduletype', 'eq', 'connected_theme');
        
        // Add curriculum template selection if enabled
        if (get_config('aiplacement_modgen', 'enable_templates')) {
            $template_reader = new \aiplacement_modgen\local\template_reader();
            $curriculum_templates = $template_reader->get_curriculum_templates();
            
            if (!empty($curriculum_templates)) {
                $template_options = ['' => get_string('nocurriculum', 'aiplacement_modgen')] + $curriculum_templates;
                $mform->addElement('select', 'curriculum_template', 
                    get_string('selectcurriculum', 'aiplacement_modgen'), $template_options);
                $mform->setType('curriculum_template', PARAM_TEXT);
                $mform->addHelpButton('curriculum_template', 'curriculumtemplates', 'aiplacement_modgen');
            }
        }
        
        // Main content prompt
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="4" cols="60"');
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');
        
        // File upload for supporting documents (optional)
        $returntypes = defined('FILE_INTERNAL') ? FILE_INTERNAL : 2;
        $fileoptions = [
            'subdirs' => 0,
            'maxbytes' => 10485760, // 10MB per file
            'maxfiles' => 5,
            'accepted_types' => '*',
            'return_types' => $returntypes,
        ];
        $mform->addElement('filemanager', 'supportingfiles', get_string('supportingfiles', 'aiplacement_modgen'), null, $fileoptions);
        $mform->addHelpButton('supportingfiles', 'supportingfiles', 'aiplacement_modgen');

        // === SUGGESTED CONTENT SECTION ===
        $mform->addElement('header', 'suggestedcontentheader', get_string('suggestedcontent', 'aiplacement_modgen'));
        
        // Theme introductions option
        $mform->addElement('advcheckbox', 'generatethemeintroductions', get_string('generatethemeintroductions', 'aiplacement_modgen'));
        $mform->addHelpButton('generatethemeintroductions', 'generatethemeintroductions', 'aiplacement_modgen');
        $mform->setType('generatethemeintroductions', PARAM_BOOL);
        $mform->setDefault('generatethemeintroductions', 0);
        
        // Only show theme introductions option for connected_theme
        $mform->hideIf('generatethemeintroductions', 'moduletype', 'ne', 'connected_theme');
        
        // Generation options
        $mform->addElement('advcheckbox', 'createsuggestedactivities', get_string('createsuggestedactivities', 'aiplacement_modgen'));
        $mform->addHelpButton('createsuggestedactivities', 'createsuggestedactivities', 'aiplacement_modgen');
        $mform->setType('createsuggestedactivities', PARAM_BOOL);
        $mform->setDefault('createsuggestedactivities', 1);
        
        $this->add_action_buttons(false, get_string('submit', 'aiplacement_modgen'));
    }

    public function definition_after_data() {
        global $USER;
        parent::definition_after_data();
        // Prepare draft area for supporting files if context provided.
        $draftitemid = file_get_submitted_draft_itemid('supportingfiles');
        $contextid = !empty($this->_customdata['contextid']) ? $this->_customdata['contextid'] : context_user::instance($USER->id)->id;
        file_prepare_draft_area($draftitemid, $contextid, 'aiplacement_modgen', 'supportingfiles', 0, array('subdirs'=>0,'maxbytes'=>10485760,'maxfiles'=>5));
        $this->_form->setDefault('supportingfiles', $draftitemid);
    }
}
