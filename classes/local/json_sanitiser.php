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
 * Sanitiser for module structure JSON that comes back from the browser.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Cleans every string in a decoded module structure, whatever shape it has.
 *
 * The approved JSON round-trips through a hidden form field, so it is client-controlled.
 * Cleaning by key rather than by walking a fixed themes/weeks/activities layout means the
 * weekly "sections" shape, sessions and any other nesting are covered too.
 */
class json_sanitiser {
    /** @var string[] Keys whose string values may carry HTML. */
    private const HTML_KEYS = ['summary', 'description', 'intro', 'content', 'text'];

    /**
     * Clean a decoded structure.
     *
     * @param array $data Decoded JSON.
     * @return array The same structure with every string value cleaned.
     */
    public static function clean(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::clean($value);
            } else if (is_string($value)) {
                $data[$key] = self::clean_string(is_string($key) ? $key : '', $value);
            }
        }
        return $data;
    }

    /**
     * Clean a single string according to the key it sits under.
     *
     * @param string $key Array key ('' for list items).
     * @param string $value Raw value.
     * @return string
     */
    private static function clean_string(string $key, string $value): string {
        if (in_array($key, self::HTML_KEYS, true)) {
            return clean_param($value, PARAM_CLEANHTML);
        }
        if ($key === 'type') {
            return clean_param($value, PARAM_ALPHANUMEXT);
        }
        if ($key === 'url') {
            return clean_param($value, PARAM_URL);
        }
        return clean_param($value, PARAM_TEXT);
    }
}
