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
 * CSV processing decision service.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Service class for determining CSV processing mode.
 *
 * Consolidates the complex boolean logic repeated 4 times in prompt.php
 * to decide whether to use pure CSV parsing or AI enhancement.
 */
class csv_processing_service {
    /**
     * Determine if pure CSV parsing should be used (without AI enhancement).
     *
     * Pure CSV parsing is used only if:
     * - AI is disabled in plugin settings, OR
     * - AI is enabled AND has CSV file AND no user prompt
     *
     * @param bool $aienabled Whether AI is enabled in plugin settings
     * @param bool $hascsvfile Whether a CSV file is present
     * @param bool $hasuserprompt Whether user provided a text prompt
     * @return bool True if pure CSV parsing should be used
     */
    public function should_use_pure_csv_mode(
        bool $aienabled,
        bool $hascsvfile,
        bool $hasuserprompt
    ): bool {
        // If AI is completely disabled, always use pure CSV.
        if (!$aienabled) {
            return true;
        }

        // AI is enabled - use pure CSV only if there is a CSV and no prompt.
        return $hascsvfile && !$hasuserprompt;
    }

    /**
     * Get CSV file from template or uploaded files.
     *
     * @param \stored_file|null $templatecsvfile CSV file from template (if selected)
     * @param int $draftitemid Draft item ID from file upload
     * @param int $contextid User context ID
     * @return \stored_file|null CSV file or null if not found
     */
    public function get_csv_file(?\stored_file $templatecsvfile, int $draftitemid, int $contextid): ?\stored_file {
        // If CSV file provided (uploaded file takes priority over template), use it.
        if ($templatecsvfile !== null) {
            return $templatecsvfile;
        }

        // Otherwise, try to find CSV in uploaded files.
        if (empty($draftitemid)) {
            return null;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'user', 'draft', $draftitemid, 'filename', false);

        if (empty($files)) {
            return null;
        }

        // Find first CSV file.
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                return $file;
            }
        }

        // If no CSV found, return first file (might be processable as CSV).
        return array_shift($files);
    }
}
