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

        // Build a map of section IDs to section data for hierarchy lookup.
        $sectionmap = [];
        foreach ($sections as $section) {
            $sectionmap[$section['id']] = $section;
        }

        // Group weeks under their parent themes, maintaining section order.
        $themegroups = [];
        $orphanweeks = [];
        
        foreach ($sections as $section) {
            // Prepare display data.
            $sectiondata = [
                'id' => $section['id'],
                'section' => $section['section'],
                'name' => $section['name'],
                'formatted_date' => $section['formatted_date'] ?? '',
                'is_parent' => !empty($section['is_parent']),
                'currentname' => s($section['name']),
                'proposedname' => '',
            ];

            // Build proposed name.
            if (!empty($section['formatted_date'])) {
                $sectiondata['proposedname'] = s($section['formatted_date']) . ' ' . s($section['name']);
            } else {
                $sectiondata['proposedname'] = s($section['name']);
            }

            if (!empty($section['is_parent'])) {
                // This is a theme - create a new group.
                $themegroups[$section['id']] = [
                    'theme' => $sectiondata,
                    'weeks' => [],
                    'hasWeeks' => false,
                    'section_order' => $section['section'], // Track order for sorting
                ];
            } else {
                // This is a week - try to find its parent theme.
                $parentid = $section['parent_id'] ?? null;
                
                if ($parentid && isset($themegroups[$parentid])) {
                    // Add to parent theme's weeks.
                    $themegroups[$parentid]['weeks'][] = $sectiondata;
                    $themegroups[$parentid]['hasWeeks'] = true;
                } else {
                    // Orphan week (no parent theme found).
                    $orphanweeks[] = $sectiondata;
                }
            }
        }

        // Sort theme groups by section order to maintain course structure.
        $sortedthemegroups = $themegroups;
        usort($sortedthemegroups, function($a, $b) {
            return $a['section_order'] <=> $b['section_order'];
        });

        // Prepare template data.
        $templatedata = [
            'courseid' => $sections[0]['course'] ?? 0,
            'selectsections_label' => get_string('selectsections', 'aiplacement_modgen'),
            'currentname_label' => get_string('currentname', 'aiplacement_modgen'),
            'proposedname_label' => get_string('proposedname', 'aiplacement_modgen'),
            'themegroups' => $sortedthemegroups,
            'orphanweeks' => $orphanweeks,
            'hasThemeGroups' => !empty($sortedthemegroups),
            'hasOrphanWeeks' => !empty($orphanweeks),
            'nosectionsavailable' => !empty($sections) ? '' : get_string('nosectionsavailable', 'aiplacement_modgen'),
        ];

        // Render template.
        $output = $PAGE->get_renderer('core');
        return $output->render_from_template('aiplacement_modgen/dates_for_sections_form', $templatedata);
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
