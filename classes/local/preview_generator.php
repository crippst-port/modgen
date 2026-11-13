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
 * Preview generator for AI-generated module structures.
 *
 * Converts AI-generated JSON into a standardized preview structure
 * suitable for template rendering. Handles both theme and weekly formats.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Generate structured preview data from AI module JSON.
 */
class preview_generator {
    /**
     * Build a structured preview from AI-generated module data.
     *
     * @param array $moduledata The decoded module JSON from the AI.
     * @param string $structure The module structure type ('theme', 'connected_theme', 'weekly', 'connected_weekly', etc).
     * @return array Structured data with themes/weeks and activities for template rendering.
     */
    public static function generate(array $moduledata, string $structure): array {
        $preview = [
            'structure' => $structure,
            'hasthemes' => false,
            'themes' => [],
            'hasweeks' => false,
            'weeks' => [],
        ];

        // Determine if this is a theme-based format.
        $isthemeformat = strpos($structure, 'theme') !== false;

        if ($isthemeformat) {
            $preview = self::build_theme_preview($moduledata, $preview);
        } else {
            $preview = self::build_weekly_preview($moduledata, $preview);
        }

        $preview['themecount'] = count($preview['themes']);
        $preview['weekcount'] = count($preview['weeks'])
            + array_sum(array_map(fn($theme) => count($theme['weeks']), $preview['themes']));

        return $preview;
    }

    /**
     * Build preview for theme-based structure (connected_theme, theme).
     *
     * @param array $moduledata The module data from AI.
     * @param array $preview The preview structure to populate.
     * @return array The populated preview structure.
     */
    private static function build_theme_preview(array $moduledata, array $preview): array {
        if (empty($moduledata['themes']) || !is_array($moduledata['themes'])) {
            return $preview;
        }

        $preview['hasthemes'] = true;
        $weeknumber = 0;

        foreach ($moduledata['themes'] as $themekey => $theme) {
            if (!is_array($theme)) {
                continue;
            }

            $fallback = get_string('themefallback', 'aiplacement_modgen');
            $themeitem = [
                // Where it sits in the structure, so an edit made on the preview finds it again.
                'key' => (string) $themekey,
                'number' => count($preview['themes']) + 1,
                'title' => !empty($theme['title']) ? self::text($theme['title']) : $fallback,
                'rawtitle' => self::text($theme['title'] ?? ''),
                'placeholder' => $fallback,
                'summary' => !empty($theme['summary']) ? self::text($theme['summary']) : '',
                'weeks' => [],
                'hasweeks' => false,
            ];

            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                foreach ($theme['weeks'] as $weekkey => $week) {
                    if (!is_array($week)) {
                        continue;
                    }

                    $themeitem['weeks'][] = self::build_week_item($week, $themekey . '_' . $weekkey, ++$weeknumber);
                }
            }

            if (!empty($themeitem['weeks'])) {
                $themeitem['hasweeks'] = true;
            }

            $preview['themes'][] = $themeitem;
        }

        return $preview;
    }

    /**
     * Build preview for weekly structure (weekly, connected_weekly).
     *
     * @param array $moduledata The module data from AI.
     * @param array $preview The preview structure to populate.
     * @return array The populated preview structure.
     */
    private static function build_weekly_preview(array $moduledata, array $preview): array {
        if (empty($moduledata['sections']) || !is_array($moduledata['sections'])) {
            return $preview;
        }

        $preview['hasweeks'] = true;

        foreach ($moduledata['sections'] as $sectionkey => $section) {
            if (!is_array($section)) {
                continue;
            }

            $preview['weeks'][] = self::build_week_item($section, (string) $sectionkey, count($preview['weeks']) + 1, true);
        }

        return $preview;
    }

    /**
     * Build a standardized week item from a week/section object.
     *
     * Every week is built with its three sessions, so they are always listed. A weekly section's
     * outline is added to its summary as a list when it is built, so it is shown as well.
     *
     * @param array $weekdata The week or section data.
     * @param string $key Where it sits in the structure: its section key, or theme key and week key joined by _
     * @param int $number Its place among all the weeks, from 1
     * @param bool $withoutline Whether the build shows this week's outline (weekly sections only)
     * @return array Structured week item.
     */
    private static function build_week_item(array $weekdata, string $key = '', int $number = 0, bool $withoutline = false): array {
        $fallback = get_string('weekfallback', 'aiplacement_modgen');
        $outline = $withoutline ? section_creation_service::outline_lines($weekdata['outline'] ?? []) : [];

        return [
            'key' => $key,
            'number' => $number,
            'title' => !empty($weekdata['title']) ? self::text($weekdata['title']) : $fallback,
            'rawtitle' => self::text($weekdata['title'] ?? ''),
            'placeholder' => $fallback,
            'summary' => !empty($weekdata['summary']) ? self::text($weekdata['summary']) : '',
            'sessions' => self::build_sessions($weekdata),
            'hassessions' => true,
            'outline' => array_map(fn($line) => ['text' => $line], $outline),
            'hasoutline' => !empty($outline),
        ];
    }

    /**
     * A value from the structure as plain text. The template escapes it, so it must not be escaped here.
     *
     * @param mixed $value Value from the AI or the file
     * @return string The text, or '' for anything that is not a single value
     */
    private static function text($value): string {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The three sessions of a week, with any activities given for them.
     *
     * Every week is built with a Pre-session, Session and Post-session (session_creator), whatever the
     * structure gives, so all three are always listed. Their activities are read from either:
     * - {sessions: {presession: {activities: [...]}, ...}}
     * - {presession: {...}, session: {...}, postsession: {...}}
     *
     * @param array $weekdata The week data.
     * @return array Sessions, each with type, label and activities.
     */
    private static function build_sessions(array $weekdata): array {
        $sessionsdata = isset($weekdata['sessions']) && is_array($weekdata['sessions']) ? $weekdata['sessions'] : $weekdata;

        $sessions = [];
        foreach (['presession', 'session', 'postsession'] as $sessiontype) {
            $sessions[] = [
                'type' => $sessiontype,
                'label' => get_string($sessiontype, 'aiplacement_modgen'),
                'activities' => self::extract_activities_from_session($sessionsdata[$sessiontype] ?? []),
            ];
        }
        return $sessions;
    }

    /**
     * Extract activities from a session object.
     *
     * Handles both formats:
     * 1. {activities: [...], description: "..."}
     * 2. Direct array of activities
     *
     * @param array|mixed $sessiondata The session data.
     * @return array Array of activity items.
     */
    private static function extract_activities_from_session($sessiondata): array {
        $activities = [];

        if (!is_array($sessiondata)) {
            return $activities;
        }

        // Check if activities are nested in an 'activities' key.
        $activitylist = [];
        if (isset($sessiondata['activities']) && is_array($sessiondata['activities'])) {
            $activitylist = $sessiondata['activities'];
        }

        // If no activities key found, try to treat the sessiondata itself as an array of activities
        // (but skip this if the data has description or other metadata keys).
        if (empty($activitylist) && !isset($sessiondata['activities']) && !isset($sessiondata['description'])) {
            $activitylist = $sessiondata;
        }

        // Process each activity.
        foreach ($activitylist as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $activities[] = [
                'name' => !empty($activity['name']) ? self::text($activity['name']) : '',
                'type' => !empty($activity['type']) ? self::text($activity['type']) : '',
            ];
        }

        return $activities;
    }
}
