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
 * Settings helper for cached plugin configuration access.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for accessing plugin settings with caching.
 */
class settings_helper {

    /** @var array Cache for settings values */
    private static $cache = [];

    /**
     * Check if AI generation is enabled.
     *
     * @return bool True if AI generation is enabled
     */
    public static function is_ai_enabled(): bool {
        return self::get_bool('enable_ai');
    }

    /**
     * Check if exploration feature is enabled.
     *
     * @return bool True if both AI and exploration are enabled
     */
    public static function is_explore_enabled(): bool {
        return self::is_ai_enabled() && self::get_bool('enable_exploration');
    }

    /**
     * Check if suggest feature is enabled.
     *
     * @return bool True if both AI and suggest are enabled
     */
    public static function is_suggest_enabled(): bool {
        return self::is_ai_enabled() && self::get_bool('enable_suggest');
    }

    /**
     * Get a boolean setting value with caching.
     *
     * @param string $name Setting name
     * @return bool Setting value
     */
    private static function get_bool(string $name): bool {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = !empty(get_config('aiplacement_modgen', $name));
        }
        return self::$cache[$name];
    }

    /**
     * Clear the settings cache.
     * Useful after settings have been updated.
     *
     * @return void
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }
}
