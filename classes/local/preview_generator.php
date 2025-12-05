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
 * @category    local
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

        // Determine if this is a theme-based format
        $isthemeformat = strpos($structure, 'theme') !== false;

        if ($isthemeformat) {
            $preview = self::build_theme_preview($moduledata, $preview);
        } else {
            $preview = self::build_weekly_preview($moduledata, $preview);
        }

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

        foreach ($moduledata['themes'] as $theme) {
            if (!is_array($theme)) {
                continue;
            }

            $themeitem = [
                'title' => !empty($theme['title']) ? s($theme['title']) : get_string('themefallback', 'aiplacement_modgen'),
                'summary' => !empty($theme['summary']) ? s($theme['summary']) : '',
                'weeks' => [],
                'hasweeks' => false,
            ];

            if (!empty($theme['weeks']) && is_array($theme['weeks'])) {
                foreach ($theme['weeks'] as $week) {
                    if (!is_array($week)) {
                        continue;
                    }

                    $weekitem = self::build_week_item($week);
                    $themeitem['weeks'][] = $weekitem;
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

        foreach ($moduledata['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $weekitem = self::build_week_item($section);
            $preview['weeks'][] = $weekitem;
        }

        return $preview;
    }

    /**
     * Build a standardized week item from a week/section object.
     *
     * Handles both session-based and outline-based structures.
     *
     * @param array $weekdata The week or section data.
     * @return array Structured week item with activities.
     */
    private static function build_week_item(array $weekdata): array {
        $weekitem = [
            'title' => !empty($weekdata['title']) ? s($weekdata['title']) : get_string('weekfallback', 'aiplacement_modgen'),
            'summary' => !empty($weekdata['summary']) ? s($weekdata['summary']) : '',
            'activities' => [],
            'hasactivities' => false,
            'sessions' => [],
            'hassessions' => false,
        ];

        // Try to build sessions (for connected_theme/connected_weekly)
        $hasSessions = self::build_sessions($weekdata, $weekitem);

        // If no sessions, try to use outline format
        if (!$hasSessions && !empty($weekdata['outline']) && is_array($weekdata['outline'])) {
            foreach ($weekdata['outline'] as $activity) {
                if (is_string($activity) && trim($activity) !== '') {
                    $weekitem['activities'][] = [
                        'name' => s($activity),
                        'type' => '',
                        'session' => 'outline',
                    ];
                }
            }
        }

        // Mark if we have any activities or sessions
        if (!empty($weekitem['activities'])) {
            $weekitem['hasactivities'] = true;
        }

        if (!empty($weekitem['sessions'])) {
            $weekitem['hasactivities'] = true;
            $weekitem['hassessions'] = true;
        }

        return $weekitem;
    }

    /**
     * Build sessions structure for a week.
     *
     * Handles both nested formats:
     * - {sessions: {presession: {activities: [...]}, ...}}
     * - {presession: {...}, session: {...}, postsession: {...}}
     *
     * @param array $weekdata The week data containing sessions.
     * @param array &$weekitem The week item to populate with sessions.
     * @return bool True if sessions were found and built, false otherwise.
     */
    private static function build_sessions(array $weekdata, array &$weekitem): bool {
        // Check for sessions structure
        $sessionsData = $weekdata['sessions'] ?? [
            'presession' => $weekdata['presession'] ?? [],
            'session' => $weekdata['session'] ?? [],
            'postsession' => $weekdata['postsession'] ?? [],
        ];

        // Filter out empty session types
        $sessionsData = array_filter($sessionsData);

        if (empty($sessionsData)) {
            return false;
        }

        $sessionLabels = [
            'presession' => 'Pre-session',
            'session' => 'Session',
            'postsession' => 'Post-session',
        ];
        $orderedSessionTypes = ['presession', 'session', 'postsession'];

        foreach ($orderedSessionTypes as $sessiontype) {
            if (!isset($sessionsData[$sessiontype])) {
                continue;
            }

            $sessiondata = $sessionsData[$sessiontype];

            // Extract activities from this session
            $activities = self::extract_activities_from_session($sessiondata);

            if (!empty($activities)) {
                $weekitem['sessions'][] = [
                    'type' => $sessiontype,
                    'label' => $sessionLabels[$sessiontype] ?? $sessiontype,
                    'activities' => $activities,
                ];
            }
        }

        return !empty($weekitem['sessions']);
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

        // Check if activities are nested in an 'activities' key
        $activityList = [];
        if (isset($sessiondata['activities']) && is_array($sessiondata['activities'])) {
            $activityList = $sessiondata['activities'];
        } else if (!isset($sessiondata['activities']) && !isset($sessiondata['description'])) {
            // Direct array of activities (no nested structure)
            $activityList = $sessiondata;
        }

        // Process each activity
        foreach ($activityList as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $activities[] = [
                'name' => !empty($activity['name']) ? s($activity['name']) : '',
                'type' => !empty($activity['type']) ? s($activity['type']) : '',
            ];
        }

        return $activities;
    }
}
