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
            'theme' => get_string('moduletype_theme', 'aiplacement_modgen'),
        ];
        $mform->addElement('select', 'moduletype', get_string('moduletype', 'aiplacement_modgen'), $moduletypeoptions);
        $mform->setType('moduletype', PARAM_ALPHA);
        $mform->setDefault('moduletype', 'weekly');
        $mform->addHelpButton('moduletype', 'moduletype', 'aiplacement_modgen');
        
        // Format-specific options - keep weekly labels under weekly selector
        $mform->addElement('advcheckbox', 'keepweeklabels', get_string('keepweeklabels', 'aiplacement_modgen'));
        $mform->setType('keepweeklabels', PARAM_BOOL);
        $mform->setDefault('keepweeklabels', 0);
        
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
        
        $mform->addElement('advcheckbox', 'includeaboutassessments', get_string('includeaboutassessments', 'aiplacement_modgen'));
        $mform->setType('includeaboutassessments', PARAM_BOOL);
        $mform->setDefault('includeaboutassessments', 0);
        
        $mform->addElement('advcheckbox', 'includeaboutlearning', get_string('includeaboutlearning', 'aiplacement_modgen'));
        $mform->setType('includeaboutlearning', PARAM_BOOL);
        $mform->setDefault('includeaboutlearning', 0);
        
        // Main content prompt
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'aiplacement_modgen'), 'rows="4" cols="60"');
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'aiplacement_modgen');
        
        // Generation options
        $mform->addElement('advcheckbox', 'createsuggestedactivities', get_string('createsuggestedactivities', 'aiplacement_modgen'));
        $mform->addHelpButton('createsuggestedactivities', 'createsuggestedactivities', 'aiplacement_modgen');
        $mform->setType('createsuggestedactivities', PARAM_BOOL);
        $mform->setDefault('createsuggestedactivities', 1);
        
        // Add warning about processing time
        $mform->addElement('static', 'processingwarning', '', 
            '<div class="alert alert-info"><i class="fa fa-info-circle"></i> ' . 
            get_string('longquery', 'aiplacement_modgen') . '</div>');
        
        $this->add_action_buttons(false, get_string('submit', 'aiplacement_modgen'));
        
        // Add inline script to handle field visibility based on module type
        $mform->addElement('static', 'jshandler', '', '
            <script>
            (function() {
                function setupFormVisibility() {
                    const moduleTypeSelect = document.querySelector("select[name=\"moduletype\"]");
                    if (!moduleTypeSelect) return;
                    
                    const updateFieldVisibility = function() {
                        const isWeekly = moduleTypeSelect.value === "weekly";
                        
                        // Find keepweeklabels field by searching form items
                        const formItems = document.querySelectorAll(".fitem");
                        formItems.forEach(function(item) {
                            const label = item.querySelector("label");
                            if (label && label.textContent.includes("Keep dated headings")) {
                                item.style.display = isWeekly ? "" : "none";
                                const checkbox = item.querySelector("input[name=\"keepweeklabels\"]");
                                if (checkbox && !isWeekly) checkbox.checked = false;
                            }
                        });
                    };
                    
                    moduleTypeSelect.addEventListener("change", updateFieldVisibility);
                    updateFieldVisibility();
                }
                
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", setupFormVisibility);
                } else {
                    setupFormVisibility();
                }
            })();
            </script>
        ');
    }
}
