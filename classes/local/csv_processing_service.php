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
 * Decides whether to use pure CSV parsing or AI enhancement.
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
}
