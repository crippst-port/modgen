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
 * Modal-optimized generator form for the Module Generator plugin.
 *
 * This form is specifically designed for use in reactive modal interfaces.
 * It uses lightweight HTML file inputs instead of Moodle's filemanager
 * to avoid YUI initialization issues in modals.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Form for generating module structure and content (Modal version).
 *
 * This form is optimized for modal display with reactive UI integration.
 * Uses simple HTML file inputs to avoid filemanager initialization issues.
 */
class aiplacement_modgen_modal_generator_form extends moodleform {
    /**
     * @var array Module type options available for this form
     */
    private $moduletypeoptionsprop = [];

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

        // Add module type selection.
        $moduletypeoptions = [];

        // Add Connected Curriculum format options if flexsections is installed.
        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }

        // Select template section.
        $templates = $this->get_available_templates();
        if (!empty($templates) && count($templates) > 1) { // More than just the "None selected" option.
            $mform->addElement('header', 'selecttemplateheader', get_string('selecttemplate', 'aiplacement_modgen'));

            $mform->addElement('select', 'selected_template_id', get_string('csvtemplate', 'aiplacement_modgen'), $templates);
            $mform->setType('selected_template_id', PARAM_INT);
            $mform->addHelpButton('selected_template_id', 'csvtemplate', 'aiplacement_modgen');

            // Download button only (form submit handles using the template).
            $mform->addElement('html', '<div class="form-group row fitem">
                <div class="col-md-3"></div>
                <div class="col-md-9">
                    <button type="button" class="btn btn-secondary" id="id_download_template" disabled>
                        ' . get_string('downloadtemplate', 'aiplacement_modgen') . '
                    </button>
                </div>
            </div>');
        }

        // Upload template file section.
        $mform->addElement('header', 'uploadtemplatefileheader', get_string('uploadtemplatefile', 'aiplacement_modgen'));

        // Check if AI is enabled early so we can control visibility.
        $aienabled = get_config('aiplacement_modgen', 'enable_ai');

        // Module type selection - ONLY show if AI is enabled.
        if ($aienabled) {
            $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
            $mform->setType('moduletype', PARAM_ALPHANUMEXT);
            $mform->setDefault('moduletype', 'connected_weekly');
            $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');
        }

        // Store the module type options in customdata for validation.
        $this->moduletypeoptionsprop = $moduletypeoptions;

        // File upload for CSV structure file (optional) - using raw HTML to avoid QuickForm file compatibility issues
        // The standard QuickForm 'file' element has PHP 8+ compatibility issues with _findValue()
        // The 'filemanager' element doesn't work properly in modals due to YUI initialization
        // Solution: Use HTML element with a standard file input (optimized for modals).
        $mform->addElement('html', '<div class="form-group row fitem">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label for="id_modal_supportingfiles_files">' . get_string('supportingfiles', 'aiplacement_modgen') . '</label>
            </div>
            <div class="col-md-9 form-inline align-items-start felement">
                <input type="file" id="id_modal_supportingfiles_files" name="supportingfiles_files[]"
                    accept=".csv" class="form-control">
                <div class="form-control-feedback invalid-feedback" id="id_error_modal_supportingfiles"></div>
            </div>
        </div>');

        // Main content prompt - only show if AI is enabled.
        if ($aienabled) {
            $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="4" cols="60"');
            $mform->setType('prompt', PARAM_TEXT);
            // Prompt is conditionally required - either prompt OR files must be provided
            // Actual validation is in validation() method.
            $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');
        }

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('submit', 'aiplacement_modgen'));
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);

        // Load template selector JavaScript if templates exist.
        $templates = $this->get_available_templates();
        if (count($templates) > 1) {
            global $PAGE;
            $PAGE->requires->js_call_amd('aiplacement_modgen/template_selector', 'init', [
                [
                    'downloadUrl' => (new moodle_url('/ai/placement/modgen/download_template.php'))->out(false),
                    'courseid' => $this->_customdata['courseid'],
                ],
            ]);
        }
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

        // Rebuild the moduletype options to match what's in definition().
        $moduletypeoptions = [
            'weekly' => get_string('moduletype_weekly', 'aiplacement_modgen'),
        ];

        $pluginmanager = core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');
        if (!empty($flexsectionsplugin)) {
            $moduletypeoptions['connected_weekly'] = get_string('moduletype_connected_weekly', 'aiplacement_modgen');
            $moduletypeoptions['connected_theme'] = get_string('moduletype_connected_theme', 'aiplacement_modgen');
        }

        // Validate moduletype is in the allowed options.
        if (!empty($data['moduletype']) && !isset($moduletypeoptions[$data['moduletype']])) {
            $errors['moduletype'] = 'Invalid module type selected';
        }

        // Either prompt, files or template must be provided.
        $hasprompt = !empty(trim($data['prompt'] ?? ''));
        // Check for uploaded files via standard HTML file input.
        $hasfiles = !empty($_FILES['supportingfiles_files']['tmp_name'][0]) &&
                    is_uploaded_file($_FILES['supportingfiles_files']['tmp_name'][0]);
        $hastemplate = !empty($data['selected_template_id']) && $data['selected_template_id'] > 0;

        if (!$hasprompt && !$hasfiles && !$hastemplate) {
            $errors['prompt'] = get_string('inputrequired', 'aiplacement_modgen');
        }

        return $errors;
    }

    /**
     * Get the submitted form data, returning null if not submitted.
     *
     * @param bool $slashed Whether to return slashed data
     * @return \stdClass|null Submitted data, or null if the form wasn't submitted
     */
    public function get_data($slashed = true) {
        $data = parent::get_data($slashed);

        // Return null if form wasn't submitted (parent returns null).
        if ($data === null) {
            return null;
        }

        // Remove direct $_POST access. If moduletype is missing, do not attempt to recover from raw POST.
        // The form API should guarantee this is set if present and valid.
        // If not set, leave as null and let validation handle it.
        // (Security: never trust raw $_POST here)
        // if (!isset($data->moduletype) || empty($data->moduletype)) {
        // if (!empty($_POST['moduletype'])) {
        // $data->moduletype = $_POST['moduletype'];
        // }
        // }.

        return $data;
    }

    /**
     * Get available CSV templates from database.
     *
     * @return array Template options [0 => 'None selected', id => name]
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
