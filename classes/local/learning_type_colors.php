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
 * Learning Type Color Configuration
 *
 * Single source of truth for Laurillard's learning type colors.
 * Used consistently across Suggest and Explore features.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Learning type color definitions and utilities.
 */
class learning_type_colors {

    /**
     * Get colors for low-level activity types (acquisition, inquiry, practice, etc).
     * Used by Suggest feature.
     *
     * @return array Associative array of activity type => rgba color
     */
    public static function get_activity_type_colors(): array {
        return [
            'acquisition' => 'rgb(221, 60, 46)',   // blue (Narrative)
            'inquiry' => 'rgb(181, 202, 75)',        // orange (Interactive)
            'practice' => 'rgb(43, 116, 184)',       // yellow (Adaptive)
            'discussion' => 'rgb(229, 182, 59)',     // green (Dialogic)
            'collaboration' => 'rgb(228, 144, 54)', // teal (custom within palette)
            'production' => 'rgb(41, 59, 141)',     // red (Productive)
        ];
    }

    /**
     * Get colors for high-level learning types (Narrative, Interactive, etc).
     * Used by Explore feature.
     *
     * @return array Associative array of learning type => rgba color
     */
    public static function get_learning_type_colors(): array {
        return [
            'Narrative' => 'rgba(66, 139, 202, 0.8)',      // Blue
            'Dialogic' => 'rgba(40, 167, 69, 0.8)',        // Green
            'Adaptive' => 'rgba(255, 193, 7, 0.8)',        // Yellow
            'Interactive' => 'rgba(255, 152, 0, 0.8)',     // Orange
            'Productive' => 'rgba(220, 53, 69, 0.8)',      // Red
        ];
    }

    /**
     * Get color for a specific learning type.
     *
     * @param string $type Learning type name
     * @param string $level Either 'activity' for activity types or 'learning' for learning types
     * @return string|null RGBA color string or null if type not found
     */
    public static function get_color(string $type, string $level = 'learning'): ?string {
        if ($level === 'activity') {
            $colors = self::get_activity_type_colors();
        } else {
            $colors = self::get_learning_type_colors();
        }

        return $colors[$type] ?? null;
    }

    /**
     * Get all colors as a JSON-serializable array for JavaScript.
     *
     * @param string $level Either 'activity' or 'learning'
     * @return string JSON-encoded color array
     */
    public static function get_colors_json(string $level = 'learning'): string {
        if ($level === 'activity') {
            $colors = self::get_activity_type_colors();
        } else {
            $colors = self::get_learning_type_colors();
        }

        return json_encode($colors);
    }
}
