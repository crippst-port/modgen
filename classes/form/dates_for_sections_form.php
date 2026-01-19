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
        global $PAGE;
        
        $mform = $this->_form;
        $customdata = $this->_customdata;

        // Hidden course ID.
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        
        // Hidden field for selected sections (populated by JavaScript)
        $mform->addElement('hidden', 'selectedsections');
        $mform->setType('selectedsections', PARAM_RAW);

        // Date picker for start date with additional padding
        $mform->addElement('html', '<div style="padding: 0 1rem;">');
        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'aiplacement_modgen'));
        $mform->addHelpButton('startdate', 'startdate', 'aiplacement_modgen');
        $mform->addElement('html', '</div>');

        // Set default to course start date
        if (!empty($customdata['coursestartdate'])) {
            $mform->setDefault('startdate', $customdata['coursestartdate']);
        }

        // Add ARIA live region for dynamic updates.
        $mform->addElement('html', '<div id="dates-status" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>');

        // Render the sections table using Mustache template.
        if (!empty($customdata['sections'])) {
            $tablehtml = $this->render_sections_table($customdata['sections']);
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

        // Action buttons.
        $buttongroup = [];
        $buttongroup[] = $mform->createElement('submit', 'submitbutton', 
            get_string('applydates', 'aiplacement_modgen'),
            ['class' => 'btn btn-primary']);
        $buttongroup[] = $mform->createElement('submit', 'removedates', 
            get_string('removealldates', 'aiplacement_modgen'),
            ['class' => 'btn btn-warning']);
        $buttongroup[] = $mform->createElement('cancel', 'cancel', 
            get_string('cancel'),
            ['class' => 'btn btn-secondary']);
        
        $mform->addGroup($buttongroup, 'buttonar', '', [' '], false);
        $mform->setType('buttonar', PARAM_RAW);
    }

    /**
     * Render sections table using Mustache template.
     *
     * @param array $sections Array of section data
     * @return string Rendered HTML
     */
    private function render_sections_table($sections) {
        global $PAGE;

        // Sort sections by section number to maintain course order.
        usort($sections, function($a, $b) {
            return $a['section'] <=> $b['section'];
        });

        // Build hierarchy structure
        $hierarchy = $this->build_hierarchy($sections);

        // Prepare template data.
        $templatedata = [
            'courseid' => $sections[0]['course'] ?? 0,
            'sections' => $hierarchy,
            'hassections' => !empty($hierarchy),
        ];

        // Render template.
        $output = $PAGE->get_renderer('core');
        return $output->render_from_template('aiplacement_modgen/dates_for_sections_form', $templatedata);
    }

    /**
     * Build hierarchical structure of sections with parent-child relationships.
     *
     * @param array $sections Array of section data
     * @return array Hierarchical array of sections
     */
    private function build_hierarchy($sections) {
        // Build maps for quick lookup
        $sectionmap = [];
        $childrenmap = [];
        
        foreach ($sections as $section) {
            $sectionmap[$section['id']] = $section;
            $childrenmap[$section['id']] = [];
        }
        
        // Build parent-child relationships
        foreach ($sections as $section) {
            if (!empty($section['parent_id'])) {
                $parentid = $section['parent_id'];
                if (isset($childrenmap[$parentid])) {
                    $childrenmap[$parentid][] = $section['id'];
                }
            }
        }
        
        // Build hierarchical structure starting with top-level sections
        $hierarchy = [];
        foreach ($sections as $section) {
            // Only process top-level sections (no parent)
            if (empty($section['parent_id'])) {
                $hierarchy[] = $this->build_section_node($section, $childrenmap, $sectionmap, 0);
            }
        }
        
        return $hierarchy;
    }

    /**
     * Recursively build section node with children.
     *
     * @param array $section Section data
     * @param array $childrenmap Map of parent IDs to child IDs
     * @param array $sectionmap Map of section IDs to section data
     * @param int $level Nesting level
     * @return array Section node with children
     */
    private function build_section_node($section, $childrenmap, $sectionmap, $level) {
        $node = [
            'id' => $section['id'],
            'section' => $section['section'],
            'name' => s($section['name']),
            'level' => $level,
            'indent' => str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level),
            'istoplevel' => ($level === 0),
            'haschildren' => !empty($childrenmap[$section['id']]),
            'children' => [],
        ];
        
        // Recursively add children
        if (!empty($childrenmap[$section['id']])) {
            foreach ($childrenmap[$section['id']] as $childid) {
                if (isset($sectionmap[$childid])) {
                    $node['children'][] = $this->build_section_node(
                        $sectionmap[$childid],
                        $childrenmap,
                        $sectionmap,
                        $level + 1
                    );
                }
            }
        }
        
        return $node;
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

        // selectedsections comes as JSON string from JavaScript
        $selectedsections = [];
        if (!empty($data['selectedsections'])) {
            $decoded = json_decode($data['selectedsections'], true);
            if (is_array($decoded)) {
                $selectedsections = $decoded;
            }
        }

        if (empty($selectedsections)) {
            $errors['selectedsections'] = get_string('nosectionsselected', 'aiplacement_modgen');
        }

        return $errors;
    }
}
