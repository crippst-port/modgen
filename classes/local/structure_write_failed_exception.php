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
 * Exception for a section write that failed after it had started.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * A section write failed part way and its transaction was rolled back.
 *
 * Moodle has no savepoints: rolling back a nested delegated transaction marks every enclosing
 * one for rollback too, and their commits then throw. A build that catches this and carries on
 * would only fail later with a confusing error, so callers must treat it as fatal for the whole
 * build. Failures found before anything is written stay plain moodle_exceptions and can be
 * reported as warnings.
 */
class structure_write_failed_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $sectionname Name of the section that could not be created.
     * @param string $debuginfo Technical detail for developers.
     */
    public function __construct(string $sectionname, string $debuginfo = '') {
        parent::__construct('sectorcreationfailed', 'aiplacement_modgen', '', clean_param($sectionname, PARAM_TEXT), $debuginfo);
    }
}
