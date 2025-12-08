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

        // Include parent sections checkbox.
        $mform->addElement('advcheckbox', 'includeparents', 
            get_string('includeparentsections', 'aiplacement_modgen'),
            '',
            ['id' => 'includeparents-checkbox'],
            [0, 1]
        );
        $mform->addHelpButton('includeparents', 'includeparentsections', 'aiplacement_modgen');
        $mform->setDefault('includeparents', 0);

        // Add ARIA live region for dynamic updates.
        $mform->addElement('html', '<div id="dates-status" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>');

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
        $html = '<div class="table-responsive mt-3">';
        $html .= '<table class="table table-striped table-sm" id="dates-preview-table">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th scope="col" style="width: 50px;">' . 
            '<input type="checkbox" id="select-all-sections" aria-label="Select all sections" class="form-check-input">' .
            '</th>';
        $html .= '<th scope="col">' . get_string('sectiontype', 'aiplacement_modgen') . '</th>';
        $html .= '<th scope="col">' . get_string('currentname', 'aiplacement_modgen') . '</th>';
        $html .= '<th scope="col">' . get_string('proposedname', 'aiplacement_modgen') . '</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($sections as $section) {
            $sectiontype = !empty($section['is_parent']) ? 
                get_string('themesection', 'aiplacement_modgen') : 
                get_string('weeksection', 'aiplacement_modgen');

            $checkboxid = 'section-' . $section['id'];
            $nameid = 'section-name-' . $section['id'];
            $proposednameid = 'proposed-name-' . $section['id'];

            $currentname = !empty($section['name']) ? s($section['name']) : 
                get_string('sectionname', 'moodle', $section['section']);
            
            $proposedname = $currentname;
            if (!empty($section['formatted_date'])) {
                $proposedname = s($section['formatted_date']) . ' ' . $currentname;
            }

            $html .= '<tr data-section-id="' . $section['id'] . '">';
            
            // Checkbox column.
            $html .= '<td>';
            $html .= '<input type="checkbox" ' .
                'id="' . $checkboxid . '" ' .
                'name="selectedsections[]" ' .
                'value="' . $section['id'] . '" ' .
                'class="form-check-input dates-section-checkbox" ' .
                'data-section-id="' . $section['id'] . '" ' .
                'aria-labelledby="' . $nameid . '" ' .
                'checked>';
            $html .= '</td>';

            // Type column.
            $html .= '<td>' . $sectiontype . '</td>';

            // Current name column.
            $html .= '<td id="' . $nameid . '">' . $currentname . '</td>';

            // Proposed name column.
            $html .= '<td id="' . $proposednameid . '" class="proposed-name-cell">' . $proposedname . '</td>';

            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        // Add select all functionality script.
        $html .= '<script>
            (function() {
                const selectAll = document.getElementById("select-all-sections");
                if (selectAll) {
                    selectAll.addEventListener("change", function() {
                        const checkboxes = document.querySelectorAll(".dates-section-checkbox");
                        checkboxes.forEach(cb => cb.checked = this.checked);
                    });
                }
            })();
        </script>';

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
