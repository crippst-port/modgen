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
        $moduletypeoptions = [];
        
        // Add Connected Curriculum format options if flexsections is installed
        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }
        
        // === TEMPLATE SETUP SECTION ===
        $mform->addElement('header', 'templatesettingsheader', get_string('templatesettings', 'aiplacement_modgen'));
        
        // Existing module selection - allows user to base generation on existing module structure
        // Only show if admin has enabled this feature AND AI is enabled
        if (get_config('aiplacement_modgen', 'enable_ai') && get_config('aiplacement_modgen', 'enable_existing_modules')) {
            // Support up to 3 templates via multiselect
            $existingmodules = $this->get_editable_courses();
            
            $mform->addElement('select', 'existing_modules', get_string('existingmodule', 'aiplacement_modgen'), $existingmodules, 
                ['multiple' => 'multiple', 'size' => 4]);
            $mform->setType('existing_modules', PARAM_INT);
            $mform->addHelpButton('existing_modules', 'existingmodule', 'aiplacement_modgen');
        }
        
        // Module type selection - store options as fixed to ensure they're available during form processing
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setType('moduletype', PARAM_ALPHANUMEXT);
        $mform->setDefault('moduletype', 'connected_weekly');
        $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');
        
        // Store the module type options in customdata for validation
        $this->_moduletypeoptions = $moduletypeoptions;
        
        // File upload for supporting documents (optional) - moved before prompt
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
        
        // Check if AI is enabled
        $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
        
        // Main content prompt - only show if AI is enabled
        if ($ai_enabled) {
            $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="4" cols="60"');
            $mform->setType('prompt', PARAM_TEXT);
            // Prompt is conditionally required - either prompt OR files must be provided
            // Actual validation is in validation() method
            $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');
        }

        // === SUGGESTED CONTENT SECTION === (only if AI enabled)
        if ($ai_enabled) {
        $mform->addElement('header', 'suggestedcontentheader', get_string('suggestedcontent', 'aiplacement_modgen'));
        
        // Theme introductions option
        $mform->addElement('advcheckbox', 'generatethemeintroductions', get_string('generatethemeintroductions', 'aiplacement_modgen'));
        $mform->addHelpButton('generatethemeintroductions', 'generatethemeintroductions', 'aiplacement_modgen');
        $mform->setType('generatethemeintroductions', PARAM_BOOL);
        $mform->setDefault('generatethemeintroductions', 1);
        
        // Only show theme introductions option for connected_theme
        $mform->hideIf('generatethemeintroductions', 'moduletype', 'ne', 'connected_theme');
        
        // Generation options
        $mform->addElement('advcheckbox', 'createsuggestedactivities', get_string('createsuggestedactivities', 'aiplacement_modgen'));
        $mform->addHelpButton('createsuggestedactivities', 'createsuggestedactivities', 'aiplacement_modgen');
        $mform->setType('createsuggestedactivities', PARAM_BOOL);
        $mform->setDefault('createsuggestedactivities', 0);
        
        // Session instructions option
        $mform->addElement('advcheckbox', 'generatesessioninstructions', get_string('generatesessioninstructions', 'aiplacement_modgen'));
        $mform->addHelpButton('generatesessioninstructions', 'generatesessioninstructions', 'aiplacement_modgen');
        $mform->setType('generatesessioninstructions', PARAM_BOOL);
        $mform->setDefault('generatesessioninstructions', 0);
        
        $mform->closeHeaderBefore('buttonar');
        } // End AI-enabled section
        
        // Add both submit button and debug button (debug button only if AI and existing modules enabled)
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit', 'aiplacement_modgen'));
        if (get_config('aiplacement_modgen', 'enable_ai') && get_config('aiplacement_modgen', 'enable_existing_modules')) {
            $buttonarray[] = $mform->createElement('submit', 'debugbutton', 'DEBUG: Show Template Data');
        }
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
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
    
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        // Rebuild the moduletype options to match what's in definition()
        $moduletypeoptions = [
            'weekly' => get_string('moduletype_weekly', 'aiplacement_modgen'),
        ];
        
        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }
        
        // Validate moduletype is in the allowed options
        if (!empty($data['moduletype']) && !isset($moduletypeoptions[$data['moduletype']])) {
            $errors['moduletype'] = 'Invalid module type selected';
        }
        
        // Either prompt, files, or existing module must be provided
        $hasPrompt = !empty(trim($data['prompt'] ?? ''));
        $hasFiles = !empty($data['supportingfiles']);
        $hasExistingModules = !empty($data['existing_modules']) && is_array($data['existing_modules']) && count(array_filter($data['existing_modules'])) > 0;
        
        if (!$hasPrompt && !$hasFiles && !$hasExistingModules) {
            $errors['prompt'] = get_string('promptorrequired', 'aiplacement_modgen', 'Please provide a prompt, upload files, or select one or more existing modules to base this on');
        }
        
        return $errors;
    }
    
    public function get_data($slashed = true) {
        $data = parent::get_data($slashed);
        
        // Return null if form wasn't submitted (parent returns null)
        if ($data === null) {
            return null;
        }
        
        // Manually add the moduletype from POST if it's missing from $data
        // This handles the case where the select field validation filters it out
        if (!isset($data->moduletype) || empty($data->moduletype)) {
            if (!empty($_POST['moduletype'])) {
                $data->moduletype = $_POST['moduletype'];
            }
        }
        
        return $data;
    }

    /**
     * Get list of courses the user can edit, formatted as options for select dropdown.
     *
     * @return array Array with key 0 => "Create from scratch", then courseid => fullname for editable courses
     */
    private function get_editable_courses() {
        global $DB, $USER;
        
        $options = [0 => get_string('createfromscratch', 'aiplacement_modgen')];
        
        // Get courses where user has course update capability (can edit course)
        $sql = "SELECT c.id, c.fullname, c.shortname
                FROM {course} c
                JOIN {role_assignments} ra ON ra.contextid = (
                    SELECT id FROM {context} WHERE contextlevel = ? AND instanceid = c.id
                )
                WHERE ra.userid = ? AND ra.roleid IN (
                    SELECT id FROM {role} WHERE archetype IN ('editingteacher', 'teacher', 'manager')
                )
                ORDER BY c.fullname ASC";
        
        $courses = $DB->get_records_sql($sql, [CONTEXT_COURSE, $USER->id]);
        
        foreach ($courses as $course) {
            $options[$course->id] = $course->fullname . ' (' . $course->shortname . ')';
        }
        
        return $options;
    }
}
