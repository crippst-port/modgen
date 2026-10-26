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

// Note: formslib.php and filelib.php are loaded by the calling context (lib.php fragment callback)
// Classes in classes/* are autoloaded and should not require_once global dependencies.

/**
 * Form for generating module structure and content.
 *
 * This form captures user input for AI-powered module generation,
 * including template selection, module type, and generation options.
 */
class aiplacement_modgen_generator_form extends moodleform
{
    /**
     * Define the form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }

        // Check if AI is enabled early so we can control visibility.
        $aienabled = get_config('aiplacement_modgen', 'enable_ai');

        // Select or upload template file section. The whole CSV route - template chooser,
        // download button and the upload field further down - is behind the
        // 'enable_structurefromfile' setting. validation() reads both fields with !empty(),
        // so their absence simply means "no file supplied" rather than an error.
        $structurefromfile = \aiplacement_modgen\local\settings_helper::is_structurefromfile_enabled();

        $templates = $structurefromfile ? $this->get_available_templates() : [];
        if ($structurefromfile && count($templates) > 1) { // Only show select if templates exist.
            $mform->addElement(
                'header',
                'selectoruploadtemplateheader',
                get_string('selectoruploadtemplate', 'aiplacement_modgen')
            );
            $mform->setExpanded('selectoruploadtemplateheader', true);

            $mform->addElement(
                'select',
                'selected_template_id',
                get_string('csvtemplate', 'aiplacement_modgen'),
                $templates
            );
            $mform->addHelpButton('selected_template_id', 'csvtemplate', 'aiplacement_modgen');

            // Download button only (form submit handles using the template).
            $buttonhtml = '<div class="form-group row">
                <div class="col-md-3"></div>
                <div class="col-md-9">
                    <button type="button" class="btn btn-secondary" id="id_download_template" disabled>' .
                get_string('downloadtemplate', 'aiplacement_modgen') . '</button>
                </div>
            </div>';
            $mform->addElement('html', $buttonhtml);
        } else if ($structurefromfile) {
            // If no templates available, still create the header for file upload.
            $mform->addElement(
                'header',
                'selectoruploadtemplateheader',
                get_string('selectoruploadtemplate', 'aiplacement_modgen')
            );
            $mform->setExpanded('selectoruploadtemplateheader', true);
        }

        // File upload for CSV structure file (optional) - using filemanager for standalone pages
        // Note: The modal version uses a simple HTML input instead.
        if ($structurefromfile) {
            $mform->addElement(
                'filemanager',
                'supportingfiles',
                get_string('supportingfiles', 'aiplacement_modgen'),
                null,
                ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 1, 'accepted_types' => ['.csv']]
            );
            $mform->addHelpButton('supportingfiles', 'supportingfiles', 'aiplacement_modgen');
        }

        // Content placement header (always visible).
        $mform->addElement('header', 'contentplacementheader', get_string('contentplacement', 'aiplacement_modgen'));
        $mform->setExpanded('contentplacementheader', true);

        // Checkbox to add the learningactivity "section summary" placeholder to each
        // section. The help text only mentions the AI-metadata caveat when AI is enabled.
        $mform->addElement('advcheckbox', 'createsummaryactivities', get_string('createsummaryactivities', 'aiplacement_modgen'));
        $summaryhelp = $aienabled ? 'createsummaryactivitiesai' : 'createsummaryactivities';
        $mform->addHelpButton('createsummaryactivities', $summaryhelp, 'aiplacement_modgen');
        $mform->setType('createsummaryactivities', PARAM_BOOL);
        $mform->setDefault('createsummaryactivities', 0);

        $mform->closeHeaderBefore('buttonar');

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit', 'aiplacement_modgen'));
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);

        // Always load template selector JavaScript for button state management.
        global $PAGE;
        $PAGE->requires->js_call_amd('aiplacement_modgen/template_selector', 'init', [
            [
                'downloadUrl' => (new moodle_url('/ai/placement/modgen/download_template.php'))->out(false),
                'courseid' => $this->_customdata['courseid'],
            ],
        ]);
    }

    /**
     * Set up form defaults that depend on submitted/customdata state.
     */
    public function definition_after_data() {
        global $USER;
        parent::definition_after_data();
        // Prepare draft area for supporting files if context provided.
        $draftitemid = file_get_submitted_draft_itemid('supportingfiles');
        $contextid = !empty($this->_customdata['contextid'])
            ? $this->_customdata['contextid']
            : context_user::instance($USER->id)->id;
        file_prepare_draft_area(
            $draftitemid,
            $contextid,
            'aiplacement_modgen',
            'supportingfiles',
            0,
            ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 5]
        );
        $this->_form->setDefault('supportingfiles', $draftitemid);
    }

    /**
     * Validate the submitted form data.
     *
     * @param array $data Submitted form data
     * @param array $files Submitted files
     * @return array Errors keyed by field name
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        // Either prompt, files or template must be provided.
        $hasprompt = !empty(trim($data['prompt'] ?? ''));
        // For filemanager, check if draft area has files.
        $hasfiles = false;
        if (!empty($data['supportingfiles'])) {
            $draftitemid = $data['supportingfiles'];
            $fs = get_file_storage();
            $draftfiles = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid, 'id', false);
            $hasfiles = !empty($draftfiles);
        }
        $hastemplate = !empty($data['selected_template_id']) && $data['selected_template_id'] > 0;

        if (!$hasprompt && !$hasfiles && !$hastemplate) {
            // Shown against the file field: it is the control on this form that can satisfy the rule.
            $errors['supportingfiles'] = get_string('inputrequired', 'aiplacement_modgen');
        }

        return $errors;
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
            $description = !empty($template->description) ? ' - ' . \core_text::substr($template->description, 0, 50) : '';
            $options[$template->id] = $template->name . $description;
        }

        return $options;
    }
}
