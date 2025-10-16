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
 * Main placement class for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

/**
 * Module Generator AI placement implementation.
 *
 * This class provides the AI placement functionality for the Module Generator,
 * allowing users to generate module content using AI.
 */
class placement extends \core_ai\placement {

    /**
     * Return the list of actions supported by this placement.
     *
     * @return array List of action classes that this placement supports
     */
    public function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
        ];
    }
}