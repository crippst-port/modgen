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
 * Form for creating/editing CSV templates.
 *
 * @package     aiplacement_modgen
 * @category    form
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Form for managing CSV templates.
 */
class aiplacement_modgen_template_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        // Hidden field for template ID (when editing)
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Template name (required)
        $mform->addElement('text', 'name', get_string('templatename', 'aiplacement_modgen'), 'maxlength="255" size="50"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('name', 'templatename', 'aiplacement_modgen');

        // Template description (optional)
        $mform->addElement('textarea', 'description', get_string('templatedescription', 'aiplacement_modgen'), 'wrap="virtual" rows="3" cols="50"');
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'templatedescription', 'aiplacement_modgen');

        // File upload (CSV only, 1 file, 5MB max)
        $mform->addElement(
            'filemanager',
            'templatefile',
            get_string('csvtemplate', 'aiplacement_modgen'),
            null,
            [
                'subdirs' => 0,
                'maxbytes' => 5242880, // 5MB
                'maxfiles' => 1,
                'accepted_types' => ['.csv'],
            ]
        );
        $mform->addRule('templatefile', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('templatefile', 'csvtemplate', 'aiplacement_modgen');

        // Submit buttons
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function definition_after_data() {
        global $USER;
        parent::definition_after_data();

        // Prepare draft area for template file
        $draftitemid = file_get_submitted_draft_itemid('templatefile');
        $context = context_system::instance();
        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'aiplacement_modgen',
            'templatefile_draft',
            0,
            ['subdirs' => 0, 'maxbytes' => 5242880, 'maxfiles' => 1]
        );
        $this->_form->setDefault('templatefile', $draftitemid);
    }

    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        // Validate CSV file structure
        if (!empty($data['templatefile'])) {
            $draftitemid = $data['templatefile'];
            $fs = get_file_storage();
            $context = context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);

            if (!empty($draftfiles)) {
                $file = reset($draftfiles);

                // Validate CSV structure using existing parser
                require_once(__DIR__ . '/../local/csv_parser.php');

                try {
                    // Pass the file object directly, and use auto-detect for module type
                    $result = \aiplacement_modgen\local\csv_parser::parse_csv_to_structure($file, 'connected_weekly');

                    if (!$result || empty($result)) {
                        $errors['templatefile'] = get_string('invalidcsvstructure', 'aiplacement_modgen', 'Empty result');
                    }
                } catch (\Exception $e) {
                    $errors['templatefile'] = get_string('invalidcsvstructure', 'aiplacement_modgen', $e->getMessage());
                }
            }
        }

        return $errors;
    }
}
