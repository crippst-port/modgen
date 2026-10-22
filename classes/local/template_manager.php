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

        // Get next sort order.
        $maxsortorder = $DB->get_field('aiplacement_modgen_templates', 'MAX(sortorder)', []);
        $sortorder = $maxsortorder !== false ? $maxsortorder + 1 : 0;

        // Create template record.
        $template = new \stdClass();
        $template->name = $name;
        $template->description = $description;
        $template->fileid = 0; // Set once the file is stored, below.
        $template->sortorder = $sortorder;
        $template->timecreated = time();
        $template->timemodified = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $templateid = $DB->insert_record('aiplacement_modgen_templates', $template);

            // The template id is the file's item id, so two templates can be uploaded under the same file name.
            $storedfile = self::store_file($templateid, $file);
            $DB->set_field('aiplacement_modgen_templates', 'fileid', $storedfile->get_id(), ['id' => $templateid]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        \aiplacement_modgen\event\template_created::for_template($templateid, (string) $name)->trigger();

        return $templateid;
    }

    /**
     * Copy an uploaded file into the library's file area, keyed by the template it belongs to.
     *
     * @param int $templateid Template id, used as the file item id.
     * @param stored_file $file Uploaded file.
     * @return stored_file The stored copy.
     */
    private static function store_file(int $templateid, $file) {
        return get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'aiplacement_modgen',
            'filearea' => 'csvtemplates',
            'itemid' => $templateid,
            'filepath' => '/',
            'filename' => clean_filename($file->get_filename()),
            'timecreated' => time(),
            'timemodified' => time(),
        ], $file);
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

        // Update file if provided.
        if ($file) {
            $fs = get_file_storage();

            // Delete old file.
            $oldfile = $fs->get_file_by_id($template->fileid);
            if ($oldfile) {
                $oldfile->delete();
            }

            // Store new file.
            $template->fileid = self::store_file((int) $id, $file)->get_id();
        }

        // Update template record.
        $template->name = $name;
        $template->description = $description;
        $template->timemodified = time();

        $updated = $DB->update_record('aiplacement_modgen_templates', $template);
        \aiplacement_modgen\event\template_updated::for_template((int) $id, (string) $name)->trigger();

        return $updated;
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

        // Delete file.
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($template->fileid);
        if ($file) {
            $file->delete();
        }

        // Delete template record.
        $deleted = $DB->delete_records('aiplacement_modgen_templates', ['id' => $id]);
        \aiplacement_modgen\event\template_deleted::for_template((int) $id, (string) $template->name)->trigger();

        return $deleted;
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
            // Find template above this one.
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
            // Find template below this one.
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
            return false; // Already at top/bottom.
        }

        // Swap sort orders.
        $tempsortorder = $template->sortorder;
        $template->sortorder = $swap->sortorder;
        $swap->sortorder = $tempsortorder;

        $DB->update_record('aiplacement_modgen_templates', $template);
        $DB->update_record('aiplacement_modgen_templates', $swap);

        \aiplacement_modgen\event\template_moved::for_template((int) $id, (string) $template->name, ['direction' => $direction])
            ->trigger();

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
