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
 * Template manager for CSV template library.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages CSV template CRUD operations and file storage.
 */
class template_manager {
    
    /**
     * Create a new template with uploaded file.
     *
     * @param string $name Template name
     * @param string $description Template description
     * @param stored_file $file Uploaded CSV file
     * @return int Template ID
     * @throws \moodle_exception If creation fails
     */
    public static function create($name, $description, $file) {
        global $DB;
        
        $context = \context_system::instance();
        $fs = get_file_storage();
        
        // Prepare file record for permanent storage
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'aiplacement_modgen',
            'filearea' => 'csvtemplates',
            'itemid' => 0,  // Will be updated after template creation
            'filepath' => '/',
            'filename' => clean_filename($file->get_filename()),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        
        // Copy file to permanent storage
        $storedfile = $fs->create_file_from_storedfile($filerecord, $file);
        
        // Get next sort order
        $maxsortorder = $DB->get_field('aiplacement_modgen_templates', 'MAX(sortorder)', []);
        $sortorder = $maxsortorder !== false ? $maxsortorder + 1 : 0;
        
        // Create template record
        $template = new \stdClass();
        $template->name = $name;
        $template->description = $description;
        $template->fileid = $storedfile->get_id();
        $template->sortorder = $sortorder;
        $template->timecreated = time();
        $template->timemodified = time();
        
        $templateid = $DB->insert_record('aiplacement_modgen_templates', $template);
        
        return $templateid;
    }
    
    /**
     * Update an existing template.
     *
     * @param int $id Template ID
     * @param string $name Template name
     * @param string $description Template description
     * @param stored_file|null $file New CSV file (optional)
     * @return bool Success
     * @throws \moodle_exception If update fails
     */
    public static function update($id, $name, $description, $file = null) {
        global $DB;
        
        $template = $DB->get_record('aiplacement_modgen_templates', ['id' => $id], '*', MUST_EXIST);
        
        // Update file if provided
        if ($file) {
            $context = \context_system::instance();
            $fs = get_file_storage();
            
            // Delete old file
            $oldfile = $fs->get_file_by_id($template->fileid);
            if ($oldfile) {
                $oldfile->delete();
            }
            
            // Store new file
            $filerecord = [
                'contextid' => $context->id,
                'component' => 'aiplacement_modgen',
                'filearea' => 'csvtemplates',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => clean_filename($file->get_filename()),
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            
            $storedfile = $fs->create_file_from_storedfile($filerecord, $file);
            $template->fileid = $storedfile->get_id();
        }
        
        // Update template record
        $template->name = $name;
        $template->description = $description;
        $template->timemodified = time();
        
        return $DB->update_record('aiplacement_modgen_templates', $template);
    }
    
    /**
     * Delete a template and its file.
     *
     * @param int $id Template ID
     * @return bool Success
     * @throws \moodle_exception If deletion fails
     */
    public static function delete($id) {
        global $DB;
        
        $template = $DB->get_record('aiplacement_modgen_templates', ['id' => $id], '*', MUST_EXIST);
        
        // Delete file
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($template->fileid);
        if ($file) {
            $file->delete();
        }
        
        // Delete template record
        return $DB->delete_records('aiplacement_modgen_templates', ['id' => $id]);
    }
    
    /**
     * Reorder template up or down.
     *
     * @param int $id Template ID
     * @param string $direction 'up' or 'down'
     * @return bool Success
     */
    public static function reorder($id, $direction) {
        global $DB;
        
        $template = $DB->get_record('aiplacement_modgen_templates', ['id' => $id], '*', MUST_EXIST);
        
        if ($direction === 'up') {
            // Find template above this one
            $records = $DB->get_records_select(
                'aiplacement_modgen_templates',
                'sortorder < ?',
                [$template->sortorder],
                'sortorder DESC',
                '*',
                0,
                1
            );
            $swap = !empty($records) ? reset($records) : false;
        } else {
            // Find template below this one
            $records = $DB->get_records_select(
                'aiplacement_modgen_templates',
                'sortorder > ?',
                [$template->sortorder],
                'sortorder ASC',
                '*',
                0,
                1
            );
            $swap = !empty($records) ? reset($records) : false;
        }
        
        if (!$swap) {
            return false; // Already at top/bottom
        }
        
        // Swap sort orders
        $tempsortorder = $template->sortorder;
        $template->sortorder = $swap->sortorder;
        $swap->sortorder = $tempsortorder;
        
        $DB->update_record('aiplacement_modgen_templates', $template);
        $DB->update_record('aiplacement_modgen_templates', $swap);
        
        return true;
    }
    
    /**
     * Get all templates ordered by sortorder.
     *
     * @return array Array of template records
     */
    public static function get_all() {
        global $DB;
        return $DB->get_records('aiplacement_modgen_templates', null, 'sortorder ASC');
    }
    
    /**
     * Get a single template by ID.
     *
     * @param int $id Template ID
     * @return \stdClass Template record
     * @throws \dml_exception If template not found
     */
    public static function get_by_id($id) {
        global $DB;
        return $DB->get_record('aiplacement_modgen_templates', ['id' => $id], '*', MUST_EXIST);
    }
}
