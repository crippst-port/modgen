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
 * Form for applying dates to course sections.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for applying dates to sections.
 */
class aiplacement_modgen_dates_for_sections_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        // Hidden course ID.
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        // Section selection intro.
        $mform->addElement('html', '<p class="mt-3">' . get_string('selectsections', 'aiplacement_modgen') . '</p>');

        // Build accessible table of sections.
        if (!empty($customdata['sections'])) {
            $tablehtml = $this->build_sections_table($customdata['sections']);
            $mform->addElement('html', $tablehtml);
        } else {
            $mform->addElement('html', '<p class="alert alert-info">' . 
                get_string('nosectionsavailable', 'aiplacement_modgen') . '</p>');
        }

        // Hidden field to store preview data for JS.
        $mform->addElement('hidden', 'preview_data');
        $mform->setType('preview_data', PARAM_RAW);
        if (!empty($customdata['sections'])) {
            $mform->setDefault('preview_data', json_encode($customdata['sections']));
        }

        // Action buttons group.
        $buttongroup = [];
        $buttongroup[] = $mform->createElement('submit', 'submitbutton', 
            get_string('applydates', 'aiplacement_modgen'),
            ['class' => 'btn btn-primary']);
        $buttongroup[] = $mform->createElement('submit', 'removedates', 
            get_string('removealldates', 'aiplacement_modgen'),
            ['class' => 'btn btn-warning']);
        $buttongroup[] = $mform->createElement('cancel');
        
        $mform->addGroup($buttongroup, 'buttonar', '', [' '], false);
        $mform->setType('buttonar', PARAM_RAW);
    }

    /**
     * Build accessible HTML table for section selection.
     *
     * @param array $sections Array of section data
     * @return string HTML table
     */
    private function build_sections_table($sections) {
        // Separate themes and weeks for better organization.
        $themes = [];
        $weeks = [];
        
        foreach ($sections as $section) {
            if (!empty($section['is_parent'])) {
                $themes[] = $section;
            } else {
                $weeks[] = $section;
            }
        }
        
        $html = '<div class="table-responsive mt-3">';
        
        // Themes section (if any).
        if (!empty($themes)) {
            $html .= '<h5 class="mb-3">' . get_string('themedSections', 'aiplacement_modgen') . '</h5>';
            $html .= '<table class="table table-striped table-sm mb-4" id="themes-preview-table">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th scope="col" style="width: 50px;">' . 
                '<input type="checkbox" id="select-all-themes" aria-label="Select all themes" class="form-check-input">' .
                '</th>';
            $html .= '<th scope="col">' . get_string('currentname', 'aiplacement_modgen') . '</th>';
            $html .= '<th scope="col">' . get_string('proposedname', 'aiplacement_modgen') . '</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            foreach ($themes as $section) {
                $html .= $this->build_section_row($section, 'theme');
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
        }
        
        // Weeks section.
        if (!empty($weeks)) {
            $html .= '<h5 class="mb-3">' . get_string('weekSections', 'aiplacement_modgen') . '</h5>';
            $html .= '<table class="table table-striped table-sm" id="weeks-preview-table">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th scope="col" style="width: 50px;">' . 
                '<input type="checkbox" id="select-all-weeks" aria-label="Select all weeks" class="form-check-input">' .
                '</th>';
            $html .= '<th scope="col">' . get_string('currentname', 'aiplacement_modgen') . '</th>';
            $html .= '<th scope="col">' . get_string('proposedname', 'aiplacement_modgen') . '</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            foreach ($weeks as $section) {
                $html .= $this->build_section_row($section, 'week');
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
        }
        
        $html .= '</div>';

        // Add select all functionality script.
        $html .= '<script>
            (function() {
                // Select all themes.
                const selectAllThemes = document.getElementById("select-all-themes");
                if (selectAllThemes) {
                    selectAllThemes.addEventListener("change", function() {
                        const checkboxes = document.querySelectorAll(".section-checkbox.theme-section");
                        checkboxes.forEach(cb => cb.checked = this.checked);
                    });
                }
                
                // Select all weeks.
                const selectAllWeeks = document.getElementById("select-all-weeks");
                if (selectAllWeeks) {
                    selectAllWeeks.addEventListener("change", function() {
                        const checkboxes = document.querySelectorAll(".section-checkbox.week-section");
                        checkboxes.forEach(cb => cb.checked = this.checked);
                    });
                }
            })();
        </script>';

        return $html;
    }

    /**
     * Build a single section row.
     *
     * @param array $section Section data
     * @param string $type 'theme' or 'week'
     * @return string HTML table row
     */
    private function build_section_row($section, $type) {
        $checkboxid = 'section-' . $section['id'];
        $nameid = 'section-name-' . $section['id'];
        $proposednameid = 'proposed-name-' . $section['id'];

        $currentname = !empty($section['name']) ? s($section['name']) : 
            get_string('sectionname', 'moodle', $section['section']);
        
        $proposedname = $currentname;
        if (!empty($section['formatted_date'])) {
            $proposedname = s($section['formatted_date']) . ' ' . $currentname;
        }

        $html = '<tr data-section-id="' . $section['id'] . '" class="' . $type . '-row">';
        
        // Checkbox column.
        $html .= '<td>';
        $html .= '<input type="checkbox" ' .
            'id="' . $checkboxid . '" ' .
            'name="selectedsections[]" ' .
            'value="' . $section['id'] . '" ' .
            'class="form-check-input section-checkbox ' . $type . '-section" ' .
            'data-section-id="' . $section['id'] . '" ' .
            'aria-labelledby="' . $nameid . '" ' .
            'checked>';
        $html .= '</td>';

        // Current name column.
        $html .= '<td id="' . $nameid . '">' . $currentname . '</td>';

        // Proposed name column.
        $html .= '<td id="' . $proposednameid . '" class="proposed-name-cell">' . $proposedname . '</td>';

        $html .= '</tr>';
        
        return $html;
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

        if (empty($data['selectedsections']) || !is_array($data['selectedsections'])) {
            $errors['selectedsections'] = get_string('nosectionsselected', 'aiplacement_modgen');
        }

        return $errors;
    }
}
