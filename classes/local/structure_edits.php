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
 * Titles and descriptions edited on the structure preview.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Writes the titles and descriptions edited on the preview into the approved structure.
 *
 * The preview names each field by where its theme or week sits in the structure (see
 * preview_generator): a theme by its key, a week in a theme by the theme key and week key joined
 * by _, and a week of a weekly structure by its section key. Fields that name nothing are ignored.
 */
class structure_edits {
    /** @var int Longest section name Moodle stores. */
    private const MAX_TITLE_LENGTH = 255;

    /**
     * Apply the edits.
     *
     * @param array $json The approved structure
     * @param array $titles Field key => title, for themes ('t' . key) and weeks ('w' . key)
     * @param array $summaries Field key => description, keyed the same way
     * @return array The structure with the edits in it
     */
    public static function apply(array $json, array $titles, array $summaries): array {
        $istheme = !empty($json['themes']) && is_array($json['themes']);
        foreach (['title' => $titles, 'summary' => $summaries] as $field => $values) {
            foreach ($values as $fieldkey => $value) {
                $path = self::path($json, (string) $fieldkey, $istheme);
                if ($path === null) {
                    continue;
                }
                self::set($json, $path, $field, (string) $value, !$istheme && $field === 'summary');
            }
        }
        return $json;
    }

    /**
     * Find the theme or week a field names.
     *
     * @param array $json The structure
     * @param string $fieldkey 't' . theme key, or 'w' . week key
     * @param bool $istheme Whether the structure is themes of weeks
     * @return array|null Keys down to the item, or null when it names nothing
     */
    private static function path(array $json, string $fieldkey, bool $istheme): ?array {
        $kind = substr($fieldkey, 0, 1);
        $key = substr($fieldkey, 1);
        if ($istheme && $kind === 't' && isset($json['themes'][$key]) && is_array($json['themes'][$key])) {
            return ['themes', $key];
        }
        if ($istheme && $kind === 'w' && preg_match('/^([^_]+)_([^_]+)$/', $key, $m)
                && isset($json['themes'][$m[1]]['weeks'][$m[2]]) && is_array($json['themes'][$m[1]]['weeks'][$m[2]])) {
            return ['themes', $m[1], 'weeks', $m[2]];
        }
        if (!$istheme && $kind === 'w' && isset($json['sections'][$key]) && is_array($json['sections'][$key])) {
            return ['sections', $key];
        }
        return null;
    }

    /**
     * Set one field of an item.
     *
     * A field left as the preview showed it is not touched, even where cleaning would change the
     * original (markup in a description, say). An empty title is removed, so the build gives the
     * section its usual default name. A weekly structure's descriptions are built as HTML, so an
     * edited one is escaped to stay as typed.
     *
     * @param array $json The structure, changed in place
     * @param array $path Keys down to the item
     * @param string $field title or summary
     * @param string $value As typed
     * @param bool $ashtml Whether the field is built as HTML
     */
    private static function set(array &$json, array $path, string $field, string $value, bool $ashtml): void {
        $item = &$json;
        foreach ($path as $key) {
            $item = &$item[$key];
        }
        $clean = $field === 'title' ? [self::class, 'clean_title'] : [self::class, 'clean_summary'];
        $current = isset($item[$field]) && is_scalar($item[$field]) ? (string) $item[$field] : '';
        $value = $clean($value);
        if ($value === $clean($current)) {
            return;
        }
        if ($field === 'title' && $value === '') {
            unset($item['title']);
        } else {
            $item[$field] = $ashtml ? nl2br(s($value), false) : $value;
        }
    }

    /**
     * A title as one line of plain text, no longer than a section name can be.
     *
     * @param string $value As typed
     * @return string
     */
    private static function clean_title(string $value): string {
        $value = trim(preg_replace('/\s+/u', ' ', clean_param($value, PARAM_TEXT)));
        return \core_text::substr($value, 0, self::MAX_TITLE_LENGTH);
    }

    /**
     * A description as plain text, line breaks kept.
     *
     * @param string $value As typed
     * @return string
     */
    private static function clean_summary(string $value): string {
        return trim(str_replace("\r\n", "\n", clean_param($value, PARAM_TEXT)));
    }
}
