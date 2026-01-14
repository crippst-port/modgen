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

// Note: formslib.php and filelib.php are loaded by the calling context (lib.php fragment callback)
// Classes in classes/* are autoloaded and should not require_once global dependencies

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
        
        // Get course context for capability checks
        $coursecontext = context_course::instance($this->_customdata['courseid']);
        $canusesuggest = has_capability('aiplacement/modgen:usesuggest', $coursecontext);
        
        // Check if AI is enabled early so we can control visibility
        $ai_enabled = get_config('aiplacement_modgen', 'enable_ai');
        
        // === SELECT OR UPLOAD TEMPLATE FILE SECTION ===
        $templates = $this->get_available_templates();
        if (count($templates) > 1) { // Only show select if templates exist (more than just "None selected")
            $mform->addElement('header', 'selectoruploadtemplateheader', get_string('selectoruploadtemplate', 'aiplacement_modgen'));
            $mform->setExpanded('selectoruploadtemplateheader', true);
            
            $mform->addElement('select', 'selected_template_id', get_string('csvtemplate', 'aiplacement_modgen'), $templates);
            $mform->addHelpButton('selected_template_id', 'csvtemplate', 'aiplacement_modgen');
            
            // Download button only (form submit handles using the template)
            $buttonhtml = '<div class="form-group row">
                <div class="col-md-3"></div>
                <div class="col-md-9">
                    <button type="button" class="btn btn-secondary" id="id_download_template" disabled>'.
                        get_string('downloadtemplate', 'aiplacement_modgen').'</button>
                </div>
            </div>';
            $mform->addElement('html', $buttonhtml);
        } else {
            // If no templates available, still create the header for file upload
            $mform->addElement('header', 'selectoruploadtemplateheader', get_string('selectoruploadtemplate', 'aiplacement_modgen'));
            $mform->setExpanded('selectoruploadtemplateheader', true);
        }
        
        // Existing module selection - allows user to base generation on existing module structure
        // Only show if admin has enabled this feature AND AI is enabled
        if ($ai_enabled && get_config('aiplacement_modgen', 'enable_existing_modules')) {
            // Support up to 3 templates via multiselect
            $existingmodules = $this->get_editable_courses();
            
            $mform->addElement('select', 'existing_modules', get_string('existingmodule', 'aiplacement_modgen'), $existingmodules,
                ['multiple' => 'multiple', 'size' => 4]);
            $mform->setType('existing_modules', PARAM_SEQUENCE);
            $mform->addHelpButton('existing_modules', 'existingmodule', 'aiplacement_modgen');
        }

        // File upload for CSV structure file (optional) - using filemanager for standalone pages
        // Note: The modal version uses a simple HTML input instead
        $mform->addElement('filemanager', 'supportingfiles', get_string('supportingfiles', 'aiplacement_modgen'), null,
            array('subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 1, 'accepted_types' => array('.csv')));
        $mform->addHelpButton('supportingfiles', 'supportingfiles', 'aiplacement_modgen');
        
        // === SUGGESTED CONTENT SECTION === (only if AI enabled AND user has usesuggest capability)
        if ($ai_enabled && $canusesuggest) {
        $mform->addElement('header', 'suggestedcontentheader', get_string('suggestedcontent', 'aiplacement_modgen'));
        $mform->setExpanded('suggestedcontentheader', true);
        
        // Expand on themes option - enhances section titles and descriptions
        $mform->addElement('advcheckbox', 'expandonthemes', get_string('expandonthemes', 'aiplacement_modgen'));
        $mform->addHelpButton('expandonthemes', 'expandonthemes', 'aiplacement_modgen');
        $mform->setType('expandonthemes', PARAM_BOOL);
        $mform->setDefault('expandonthemes', 0); // Default to OFF
        
        // Single consolidated checkbox for all example content
        $mform->addElement('advcheckbox', 'generateexamplecontent', get_string('generateexamplecontent', 'aiplacement_modgen'));
        $mform->addHelpButton('generateexamplecontent', 'generateexamplecontent', 'aiplacement_modgen');
        $mform->setType('generateexamplecontent', PARAM_BOOL);
        $mform->setDefault('generateexamplecontent', 0);
        
        $mform->closeHeaderBefore('contentplacementheader');
        } // End AI-enabled suggested content section
        
        // === CONTENT PLACEMENT HEADER === (always visible)
        $mform->addElement('header', 'contentplacementheader', get_string('contentplacement', 'aiplacement_modgen'));
        $mform->setExpanded('contentplacementheader', true);
        
        // Checkbox to hide existing sections and place new content at top
        $mform->addElement('advcheckbox', 'hideexistingsections', get_string('hideexistingsections', 'aiplacement_modgen'));
        $mform->addHelpButton('hideexistingsections', 'hideexistingsections', 'aiplacement_modgen');
        $mform->setType('hideexistingsections', PARAM_BOOL);
        $mform->setDefault('hideexistingsections', 0);
        
        $mform->closeHeaderBefore('buttonar');
        
        // Add both submit button and debug button (debug button only if AI and existing modules enabled)
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit', 'aiplacement_modgen'));
        if (get_config('aiplacement_modgen', 'enable_ai') && get_config('aiplacement_modgen', 'enable_existing_modules')) {
            $buttonarray[] = $mform->createElement('submit', 'debugbutton', 'DEBUG: Show Template Data');
        }
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        
        // Always load template selector JavaScript for button state management
        global $PAGE;
        $PAGE->requires->js_call_amd('aiplacement_modgen/template_selector', 'init', [
            ['downloadUrl' => (new moodle_url('/ai/placement/modgen/download_template.php', ['courseid' => $this->_customdata['courseid']]))->out(false)]
        ]);
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
        global $USER;
        $errors = parent::validation($data, $files);
        
        // Either prompt, files, template, or existing module must be provided
        $hasPrompt = !empty(trim($data['prompt'] ?? ''));
        // For filemanager, check if draft area has files
        $hasFiles = false;
        if (!empty($data['supportingfiles'])) {
            $draftitemid = $data['supportingfiles'];
            $fs = get_file_storage();
            $draftfiles = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid, 'id', false);
            $hasFiles = !empty($draftfiles);
        }
        $hasTemplate = !empty($data['selected_template_id']) && $data['selected_template_id'] > 0;
        $hasExistingModules = !empty($data['existing_modules']) && is_array($data['existing_modules']) && count(array_filter($data['existing_modules'])) > 0;
        
        if (!$hasPrompt && !$hasFiles && !$hasTemplate && !$hasExistingModules) {
            $errors['prompt'] = get_string('inputrequired', 'aiplacement_modgen');
        }
        
        return $errors;
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
    
    /**
     * Get list of available CSV templates from database.
     *
     * @return array Array with key 0 => "None selected", then templateid => name (description)
     */
    private function get_available_templates() {
        global $DB;
        
        $options = [0 => get_string('notemplateselected', 'aiplacement_modgen')];
        
        $templates = $DB->get_records('aiplacement_modgen_templates', null, 'sortorder ASC');
        
        foreach ($templates as $template) {
            $description = !empty($template->description) ? ' - ' . substr($template->description, 0, 50) : '';
            $options[$template->id] = $template->name . $description;
        }
        
        return $options;
    }
}
