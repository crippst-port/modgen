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
 * Validator for learningactivity metadata fields.
 *
 * Ensures AI-generated or CSV-provided metadata matches the valid values
 * accepted by the learningactivity module.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();
class learningactivity_validator {

    /**
     * Get valid activity icons from learningactivity module.
     *
     * @return array Array of valid icon class strings
     */
    public static function get_valid_icons() {
        return [
            '', 'fa-book', 'fa-book-open', 'fa-graduation-cap', 'fa-chalkboard', 
            'fa-chalkboard-user', 'fa-flask', 'fa-microscope', 'fa-laptop-code', 
            'fa-pen-to-square', 'fa-comments', 'fa-users', 'fa-lightbulb', 
            'fa-puzzle-piece', 'fa-clipboard-check', 'fa-file-pen', 'fa-robot'
        ];
    }

    /**
     * Get valid learning modes from learningactivity language strings.
     *
     * @return array Array of valid learning mode strings
     */
    public static function get_valid_learningmodes() {
        static $modes = null;
        if ($modes === null) {
            $modesstring = get_string('learningmodes', 'mod_learningactivity');
            $modes = array_map('trim', explode(',', $modesstring));
        }
        return $modes;
    }

    /**
     * Get valid learning types from learningactivity language strings.
     *
     * @return array Array of valid learning type strings
     */
    public static function get_valid_learningtypes() {
        static $types = null;
        if ($types === null) {
            $typesstring = get_string('learningtypes', 'mod_learningactivity');
            $types = array_map('trim', explode(',', $typesstring));
        }
        return $types;
    }

    /**
     * Validate and sanitize learningactivity metadata.
     *
     * Ensures all fields match expected types and values. Invalid values are
     * replaced with safe defaults.
     *
     * @param array|object $metadata Metadata to validate
     * @return array Sanitized metadata array
     */
    public static function validate_metadata($metadata) {
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $validated = [];

        // Name (string, no validation needed)
        $validated['name'] = isset($metadata['name']) ? trim((string) $metadata['name']) : '';

        // Activity icon (must be in valid list)
        $validated['activityicon'] = '';
        if (isset($metadata['activityicon'])) {
            $icon = trim($metadata['activityicon']);
            $validicons = self::get_valid_icons();
            if (in_array($icon, $validicons, true)) {
                $validated['activityicon'] = $icon;
            }
        }

        // Instructions (string, no validation needed)
        $validated['instructions'] = isset($metadata['instructions']) ? (string) $metadata['instructions'] : '';

        // Duration (integer/string, should be numeric)
        $validated['duration'] = null;
        if (isset($metadata['duration'])) {
            $duration = trim($metadata['duration']);
            if (is_numeric($duration) && $duration > 0) {
                $validated['duration'] = (string) intval($duration);
            }
        }

        // Learningmode (must be in valid list)
        $validated['learningmode'] = null;
        if (isset($metadata['learningmode'])) {
            $mode = trim($metadata['learningmode']);
            $validmodes = self::get_valid_learningmodes();
            if (in_array($mode, $validmodes, true)) {
                // Store as index for form compatibility
                $validated['learningmode'] = array_search($mode, $validmodes);
            }
        }

        // Group activity (boolean)
        $validated['groupactivity'] = null;
        if (isset($metadata['groupactivity'])) {
            $validated['groupactivity'] = (bool) $metadata['groupactivity'] ? 1 : 0;
        }

        // Learningtypes (comma-separated string, validate each type)
        $validated['learningtypes'] = null;
        if (isset($metadata['learningtypes']) && !empty($metadata['learningtypes'])) {
            $types = is_array($metadata['learningtypes']) 
                ? $metadata['learningtypes']
                : array_map('trim', explode(',', $metadata['learningtypes']));
            
            $validtypes = self::get_valid_learningtypes();
            $sanitizedtypes = [];
            
            foreach ($types as $type) {
                $type = trim($type);
                if (in_array($type, $validtypes, true)) {
                    $sanitizedtypes[] = $type;
                }
            }
            
            if (!empty($sanitizedtypes)) {
                $validated['learningtypes'] = implode(',', $sanitizedtypes);
            }
        }

        // Learning outcomes weekly (string with newlines, no validation needed)
        $validated['learningoutcomes_weekly'] = isset($metadata['learningoutcomes_weekly']) ? (string) $metadata['learningoutcomes_weekly'] : '';

        return $validated;
    }

    /**
     * Get default metadata structure for backward compatibility.
     *
     * @return array Default metadata with all fields set to null/empty
     */
    public static function get_default_metadata() {
        return [
            'name' => '',
            'activityicon' => '',
            'instructions' => '',
            'duration' => null,
            'learningmode' => null,
            'groupactivity' => null,
            'learningtypes' => null,
            'learningoutcomes_weekly' => '',
        ];
    }
}
