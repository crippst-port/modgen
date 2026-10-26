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
 * AI service wrapper for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Wraps the core AI subsystem to generate and validate module structures.
 */
class ai_service {
    /**
     * Extract requested theme count from user prompt if specified.
     * Looks for patterns like "X themes", "X themed sections", "divide into X themes", etc.
     *
     * @param string $prompt User's input prompt
     * @param string $structure The structure type (theme or weekly)
     * @return int|null The requested theme count, or null if not specified
     */
    private static function extract_requested_theme_count($prompt, $structure) {
        // Only applicable for theme-based structures.
        if ($structure !== 'theme') {
            return null;
        }

        // Look for phrasing such as: 5 themes, 5-themed, divide into 5 themes, create 5 themes,
        // 5 themed sections, using 5 themes, total of 5 themes.
        if (preg_match('/(\d+)\s*(?:themes?|themed\s+sections?|theme\s+groups?)/i', $prompt, $matches)) {
            $count = intval($matches[1]);
            // Reasonable range: between 2 and 20 themes.
            if ($count >= 2 && $count <= 20) {
                return $count;
            }
        }

        return null;
    }

    /**
     * Validate module structure to catch malformed AI responses.
     *
     * Checks for common issues like empty or malformed theme/section structures.
     * Note: Double-encoded JSON is now handled in normalize_ai_response() which unwraps it automatically.
     *
     * @param array $data The decoded module data
     * @param string $structure Expected structure type ('theme' or 'weekly')
     * @return array ['valid' => bool, 'error' => string]
     */
    private static function validate_module_structure($data, $structure) {
        $structure = ($structure === 'theme') ? 'theme' : 'weekly';

        // Check if we have the expected top-level key.
        if ($structure === 'theme' && !isset($data['themes'])) {
            return ['valid' => false, 'error' => get_string('aivalidation_missingthemes', 'aiplacement_modgen')];
        }
        if ($structure === 'weekly' && !isset($data['sections'])) {
            return ['valid' => false, 'error' => get_string('aivalidation_missingsections', 'aiplacement_modgen')];
        }

        $items = $structure === 'theme' ? ($data['themes'] ?? []) : ($data['sections'] ?? []);

        // Check if array is empty.
        if (empty($items)) {
            return ['valid' => false, 'error' => get_string('aivalidation_noitems', 'aiplacement_modgen')];
        }

        // Check each theme/section for malformed structure.
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                return ['valid' => false, 'error' => get_string('aivalidation_itemnotarray', 'aiplacement_modgen')];
            }

            // For theme structure, check weeks.
            if ($structure === 'theme') {
                if (!isset($item['weeks'])) {
                    return ['valid' => false, 'error' => get_string('aivalidation_missingweeks', 'aiplacement_modgen')];
                }
                if (!is_array($item['weeks'])) {
                    return ['valid' => false, 'error' => get_string('aivalidation_weeksnotarray', 'aiplacement_modgen')];
                }

                // Check each week.
                foreach ($item['weeks'] as $widx => $week) {
                    if (!is_array($week)) {
                        return ['valid' => false, 'error' => get_string('aivalidation_weeknotarray', 'aiplacement_modgen')];
                    }
                }
            }

            // Check if title exists and is not empty.
            if (!isset($item['title']) || trim($item['title']) === '') {
                return ['valid' => false, 'error' => get_string('aivalidation_missingtitle', 'aiplacement_modgen')];
            }
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Recursively normalise AI responses where some fields may be JSON encoded as strings.
     * This walks arrays/objects and attempts to json_decode string values that look like JSON.
     *
     * SPECIAL CASES:
     * 1. If the structure is wrapped (first item in themes/sections array contains
     *    the actual structure in its summary field), this function unwraps it automatically.
     * 2. Handles JSON strings with escaped newlines and quotes within field values.
     * 3. When the entire module structure is nested in a field as a JSON string, extracts it.
     *
     * @param mixed $value
     * @param bool $istoplevel Whether this is the top-level call (used for structure extraction)
     * @return mixed
     */
    private static function normalize_ai_response($value, $istoplevel = false) {
        // If it's an array, walk each element.
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize_ai_response($v, false);
            }

            // Top-level extraction: check for wrapped structure pattern.
            if ($istoplevel && !empty($out)) {
                // Pattern 1: Single entry that contains the actual structure.
                if (count($out) === 1) {
                    foreach ($out as $key => $item) {
                        // If we have a structure wrapped (e.g., {theme: {themes: [...]}}), unwrap it.
                        if (is_array($item) && (isset($item['themes']) || isset($item['sections']))) {
                            return $item;
                        }
                    }
                }

                // Pattern 2: themes/sections array where first item's summary contains actual structure.
                if ((isset($out['themes']) || isset($out['sections'])) && is_array($out['themes'] ?? $out['sections'])) {
                    $itemsarray = $out['themes'] ?? $out['sections'];
                    $firstitem = $itemsarray[0] ?? null;

                    if ($firstitem && is_array($firstitem) && isset($firstitem['summary']) && is_string($firstitem['summary'])) {
                        $summary = trim($firstitem['summary']);
                        // Check if the summary contains the full structure (may have escaped newlines/quotes).
                        if (strlen($summary) > 0 && ($summary[0] === '{' || $summary[0] === '[')) {
                            // Try direct decode first.
                            $decoded = json_decode($summary, true);
                            if (
                                json_last_error() === JSON_ERROR_NONE &&
                                (isset($decoded['themes']) || isset($decoded['sections']))
                            ) {
                                return self::normalize_ai_response($decoded, false);
                            }

                            // Try with common escape patterns: literal \n, \t, \\", etc.
                            $unescaped = self::unescape_json_string($summary);
                            if ($unescaped !== $summary) {
                                $decoded = json_decode($unescaped, true);
                                if (
                                    json_last_error() === JSON_ERROR_NONE &&
                                    (isset($decoded['themes']) || isset($decoded['sections']))
                                ) {
                                    return self::normalize_ai_response($decoded, false);
                                }
                            }
                        }
                    }
                }
            }

            return $out;
        }

        // If it's a string that looks like JSON, try to decode it.
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return $value;
            }

            // Fast check: starts with { or [ -> likely JSON.
            if (($trim[0] === '{') || ($trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Recursively normalise decoded payload.
                    return self::normalize_ai_response($decoded, false);
                }

                // If direct decode failed, try unescaping first.
                $unescaped = self::unescape_json_string($trim);
                if ($unescaped !== $trim) {
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return self::normalize_ai_response($decoded, false);
                    }
                }
            }

            // Try unescaping common escapes (e.g. when AI returns a JSON string inside a JSON field).
            $unescaped = stripslashes($trim);
            if ($unescaped !== $trim) {
                if ((isset($unescaped[0]) && ($unescaped[0] === '{' || $unescaped[0] === '['))) {
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return self::normalize_ai_response($decoded, false);
                    }
                }
            }

            // As a last resort, try to extract a JSON blob from within larger text.
            if (preg_match('/(\{.*\}|\[.*\])/s', $trim, $m)) {
                $decoded = json_decode($m[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::normalize_ai_response($decoded, false);
                }
            }

            // Nothing to decode.
            return $value;
        }

        // Scalars other than strings left unchanged.
        return $value;
    }

    /**
     * Extract full module structure that was misplaced in a summary field.
     * If AI put the entire themes/sections JSON in a theme summary instead of as the main themes array,
     * detect it, extract it, and use it as the actual content.
     *
     * @param array $data The parsed response data
     * @return array The corrected data
     */
    private static function extract_misplaced_content_from_summaries($data) {
        if (!is_array($data)) {
            return $data;
        }

        // Check if we have themes array.
        if (empty($data['themes']) || !is_array($data['themes'])) {
            return $data;
        }

        // Look through themes for misplaced content.
        foreach ($data['themes'] as $idx => $theme) {
            if (!is_array($theme) || empty($theme['summary'])) {
                continue;
            }

            $summary = $theme['summary'];

            // Check if summary is already decoded to an array (from deep_unescape_stringified_json).
            if (is_array($summary) && (isset($summary['themes']) || isset($summary['sections']))) {
                // This is misplaced content already decoded!
                // Use the extracted content as the main data, preserving template field.
                $templatevalue = $data['template'] ?? get_string('aigeneratedvia', 'aiplacement_modgen');
                $data = $summary;
                $data['template'] = $templatevalue;

                return $data;
            }

            // Also check if it's a string containing JSON.
            if (is_string($summary)) {
                $summary = trim($summary);

                // Check if summary is a stringified JSON object containing full structure.
                if (
                    strlen($summary) > 100 &&
                    (strpos($summary, '{"themes"') === 0 || strpos($summary, '{"sections"') === 0) &&
                    (substr($summary, -1) === '}' || substr($summary, -1) === ']')
                ) {
                    // Try to parse it.
                    $parsed = json_decode($summary, true);
                    if (is_array($parsed) && (isset($parsed['themes']) || isset($parsed['sections']))) {
                        // This is misplaced content!
                        // Use the extracted content as the main data, preserving template field.
                        $templatevalue = $data['template'] ?? get_string('aigeneratedvia', 'aiplacement_modgen');
                        $data = $parsed;
                        $data['template'] = $templatevalue;

                        return $data;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Attempt to unescape common JSON escape sequences in string values.
     * Handles cases like literal \n, \t, \", \\ etc.
     *
     * @param string $str The string to unescape
     * @return string The unescaped string
     */
    private static function unescape_json_string($str) {
        // Handle common escape patterns that might be in the string
        // but not properly interpreted.
        $replacements = [
            '\\\\n' => "\n", // Literal \n -> newline.
            '\\\\t' => "\t", // Literal \t -> tab.
            '\\\\r' => "\r", // Literal \r -> carriage return.
            '\\\"' => '"', // Escaped quote -> quote.
            "\\\\" => "\\", // Escaped backslash -> backslash.
            '\\/' => '/', // Escaped forward slash -> slash.
        ];

        $result = $str;
        foreach ($replacements as $pattern => $replacement) {
            $result = str_replace($pattern, $replacement, $result);
        }

        // Also handle cases where we have double-escaped quotes or backslashes
        // e.g., \\\\" should become \".
        $result = str_replace('\\\\\\"', '\\"', $result);
        $result = str_replace('\\\\\\\\', '\\\\', $result);

        return $result;
    }

    /**
     * Recursively scan parsed JSON structure for stringified JSON in string fields.
     * If a string value looks like JSON, try to parse and decode it.
     * This handles cases where AI returns: {"themes": [{"summary": "{\"themes\": [...]}"}]}
     *
     * @param array $data The parsed JSON structure
     * @param int $depth Current recursion depth (to prevent infinite loops)
     * @return array The structure with deeply decoded JSON fields
     */
    private static function deep_unescape_stringified_json($data, $depth = 0) {
        // Prevent infinite recursion.
        if ($depth > 10) {
            return $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => &$value) {
            if (is_string($value)) {
                // Check if string looks like it might be JSON.
                $trimmed = trim($value);
                if (
                    (strpos($trimmed, '{') === 0 && strrpos($trimmed, '}') === strlen($trimmed) - 1) ||
                    (strpos($trimmed, '[') === 0 && strrpos($trimmed, ']') === strlen($trimmed) - 1)
                ) {
                    // Try to decode as-is first.
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        // Successfully decoded! Recursively process the decoded content.
                        $value = self::deep_unescape_stringified_json($decoded, $depth + 1);
                    } else {
                        // Try unescaping first, then decoding.
                        $unescaped = self::unescape_json_string($trimmed);
                        if ($unescaped !== $trimmed) {
                            $decoded = json_decode($unescaped, true);
                            if (is_array($decoded)) {
                                $value = self::deep_unescape_stringified_json($decoded, $depth + 1);
                            }
                        }
                    }
                }
            } else if (is_array($value)) {
                // Recursively process nested arrays.
                $value = self::deep_unescape_stringified_json($value, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * Convert a weekly structure (sections) into a themed structure (themes).
     *
     * Groups sections into themes by analyzing section titles for pedagogical coherence.
     * Creates wrapper themes and nests the sections as weeks within each theme.
     *
     * @param array $data The response data with 'sections' array
     * @return array The converted data with 'themes' array, or original if no sections found
     */
    private static function convert_sections_to_themes($data) {
        // If already has themes or no sections, return as-is.
        if (!isset($data['sections']) || !is_array($data['sections'])) {
            return $data;
        }

        $sections = $data['sections'];
        if (empty($sections)) {
            return $data;
        }

        // Group sections into themes based on pedagogical similarity
        // Strategy: Look for section titles that suggest thematic boundaries
        // Keywords that might start a new theme: "Module", "Unit", "Theme", "Section", "Part".
        $themes = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = $section['title'] ?? 'Untitled';

            // Check if this section should start a new theme
            // (titles containing "Theme", "Unit", "Module" suggest a new thematic grouping).
            $isthemestarter = preg_match('/^(theme|unit|module|part|section)\s+\d+|^(week|session)\s+\d+/i', $title);

            if (empty($themes) || $isthemestarter) {
                // Start a new theme.
                $themename = preg_match('/^(theme|unit|module)\s+(\d+)/i', $title, $m)
                    ? ucfirst($m[1]) . ' ' . $m[2]
                    : (preg_match('/^part\s+(\w+)/i', $title, $m2) ? 'Part: ' . $m2[1] : $title);

                $themes[] = [
                    'title' => $themename,
                    'summary' => $section['summary'] ?? '',
                    'weeks' => [],
                ];
            }
            $themeidx = count($themes) - 1;

            // The section becomes a week, keeping what the build reads from a week: its sessions
            // and learning design metadata.
            $week = [
                'title' => $title,
                'summary' => $section['summary'] ?? '',
            ];
            if (!empty($section['learningactivity_metadata']) && is_array($section['learningactivity_metadata'])) {
                $week['learningactivity_metadata'] = $section['learningactivity_metadata'];
            }

            if (!empty($section['sessions']) && is_array($section['sessions'])) {
                $week['sessions'] = $section['sessions'];
            } else {
                // No sessions of its own: an outline (lines as strings or {text: ...}) becomes the
                // session's description, which is the field the build reads.
                $outline = [];
                foreach ((isset($section['outline']) && is_array($section['outline'])) ? $section['outline'] : [] as $entry) {
                    $line = is_array($entry) ? ($entry['text'] ?? '') : $entry;
                    if (is_scalar($line) && trim((string) $line) !== '') {
                        $outline[] = trim((string) $line);
                    }
                }
                $activities = (isset($section['activities']) && is_array($section['activities'])) ? $section['activities'] : [];
                if ($outline || $activities) {
                    $week['sessions'] = [
                        'session' => [
                            'description' => implode("\n", $outline),
                            'activities' => $activities,
                        ],
                    ];
                }
            }

            $themes[$themeidx]['weeks'][] = $week;
        }

        // Return converted structure.
        $data['themes'] = $themes;
        unset($data['sections']);
        return $data;
    }

    /**
     * Generate formatted week dates based on course start date.
     * Each week is 7 days from the course start date.
     *
     * @param int $weeknumber The week number (1-based)
     * @param int $courseid Optional course ID to get start date from. Uses COURSE global if not provided.
     * @return string Formatted date range for the week (e.g., "Jan 6 - Jan 12, 2025")
     */
    private static function get_week_date_range($weeknumber, $courseid = null) {
        global $COURSE;

        // Get course object if courseid provided.
        if (!empty($courseid)) {
            $course = get_course($courseid);
        } else {
            $course = $COURSE;
        }

        // Get course start date.
        $startdate = !empty($course->startdate) ? $course->startdate : time();

        // Calculate week start date (Monday of week number)
        // Week 1 starts on the course start date.
        $weekstartdate = $startdate + (($weeknumber - 1) * 7 * 24 * 60 * 60);
        $weekenddate = $weekstartdate + (6 * 24 * 60 * 60); // 6 days later = Sunday

        // Format as "Mon Date - Mon Date" (e.g., "Jan 6 - 12").
        $startformatted = userdate($weekstartdate, '%b %d', 99999);
        $endformatted = userdate($weekenddate, '%d', 99999);

        return "{$startformatted} - {$endformatted}";
    }

    /**
     * Empty the summary of every theme in a generated structure.
     *
     * Themes are containers for weeks, so generated themes get no description. Doing it here, where
     * the AI output enters the plugin, lets the section builder keep any theme summary it is given,
     * such as a CSV description.
     *
     * @param array $data Decoded structure with a 'themes' list.
     * @return array The same structure with empty theme summaries.
     */
    public static function blank_theme_summaries(array $data): array {
        foreach ($data['themes'] ?? [] as $index => $theme) {
            if (is_array($theme)) {
                $data['themes'][$index]['summary'] = '';
            }
        }
        return $data;
    }

    /**
     * Count one AI request against the current user's hourly allowance.
     *
     * Uses a fixed window that starts at the first request (writing to the cache again does
     * not move it) and a lock around the read-modify-write so parallel requests cannot all
     * read the same count.
     *
     * @param int $limit Requests allowed per hour.
     * @return bool True if the request may proceed, false if the allowance is used up.
     */
    private static function consume_rate_limit(int $limit): bool {
        global $USER;

        $cache = \cache::make('aiplacement_modgen', 'ai_requests');
        $cachekey = 'user_' . $USER->id;

        $lock = \core\lock\lock_config::get_lock_factory('aiplacement_modgen_ratelimit')->get_lock($cachekey, 5);
        if (!$lock) {
            return false;
        }

        try {
            $window = $cache->get($cachekey);
            if (!is_array($window) || (time() - (int) $window['start']) >= HOURSECS) {
                $window = ['start' => time(), 'count' => 0];
            }
            if ($window['count'] >= $limit) {
                return false;
            }
            $window['count']++;
            $cache->set($cachekey, $window);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Generate module structure and content using AI.
     *
     * @param string $prompt User's input requirements
     * @param string $structure Module structure type (weekly/theme)
     * @param int $courseid Optional course ID for week date calculations
     * @return array Module structure JSON
     */
    public static function generate_module(
        $prompt,
        $structure = 'weekly',
        $courseid = null
    ) {
        global $USER, $COURSE;

        // Honour the admin's AI configuration the way core's placements do: a provider must offer
        // generate_text, and the action must not be switched off for this placement. Checked before
        // the rate limit so a refused request does not use up the user's allowance.
        if (
            !\core_ai\manager::is_action_available(\core_ai\aiactions\generate_text::class)
            || !\core_ai\manager::is_action_enabled('aiplacement_modgen', \core_ai\aiactions\generate_text::class)
        ) {
            return [
                'activities' => [],
                'template' => get_string(
                    'aierror',
                    'aiplacement_modgen',
                    get_string('aierror_actionunavailable', 'aiplacement_modgen')
                ),
                'error' => true,
            ];
        }

        // SECURITY FIX: Check rate limit before making AI request.
        $ratelimit = get_config('aiplacement_modgen', 'ai_rate_limit');
        if ($ratelimit === false || $ratelimit === '') {
            $ratelimit = 10; // Default to 10 requests per hour.
        }
        $ratelimit = (int) $ratelimit;

        if ($ratelimit > 0 && !self::consume_rate_limit($ratelimit)) {
            return [
                'activities' => [],
                'template' => get_string('airatelimitexceeded', 'aiplacement_modgen'),
                'error' => true,
            ];
        }

        // Integrate with Moodle AI Subsystem Manager using generate_text action.
        try {
            if (!class_exists('\\core_ai\\manager') || !class_exists('\\core_ai\\aiactions\\generate_text')) {
                throw new \moodle_exception('aierror_nosubsystem', 'aiplacement_modgen');
            }

            $contextid = !empty($COURSE->id)
                ? \context_course::instance($COURSE->id)->id
                : \context_system::instance()->id;

            // Get the manager from the container (so tests can stand in for the provider) and ensure the
            // AI User Policy is accepted per subsystem design.
            $aimanager = \core\di::get(\core_ai\manager::class);
            if (!\core_ai\manager::get_user_policy_status($USER->id)) {
                return [
                    'activities' => [],
                    'template' => get_string('aierror', 'aiplacement_modgen', get_string('aierror_policy', 'aiplacement_modgen')),
                    'error' => true,
                ];
            }

            // Compose instruction-rich prompt with strict JSON schema requirements.
            // Normalize format types: connected_weekly -> weekly, connected_theme -> theme.
            $normalizedstructure = $structure;
            if ($structure === 'connected_weekly') {
                $normalizedstructure = 'weekly';
            } else if ($structure === 'connected_theme') {
                $normalizedstructure = 'theme';
            }
            $structure = ($normalizedstructure === 'theme') ? 'theme' : 'weekly';

            // Get the configurable pedagogical guidance from admin settings.
            $pedagogicalguidance = get_config('aiplacement_modgen', 'baseprompt');
            if (empty($pedagogicalguidance)) {
                // Fallback to default if not configured.
                $pedagogicalguidance = "You are an expert Moodle learning content designer at a UK higher " .
                    "education institution designing a Moodle module.";
            }

            // Add UK English requirement.
            $pedagogicalguidance .= "\n\nIMPORTANT: Use UK English spelling and conventions throughout all " .
                "generated content (e.g., 'organise' not 'organize', 'colour' not 'color', 'centre' not 'center').";

            // Build simplified role instruction.
            $sourcelabel = 'the curriculum file';

            $roleinstruction = $pedagogicalguidance . "\n\n" .
                "CRITICAL - MUST FOLLOW:\n" .
                "1. COMPLETE output: Generate all topics/sections. Do NOT stop early or truncate.\n" .
                "2. Include ALL content from " . $sourcelabel . " - nothing can be omitted.\n" .
                "3. Return ONLY valid JSON - no commentary or code blocks.\n" .
                "4. TEMPLATE METADATA section describes the 'template' JSON field ONLY - do NOT add this to " .
                "theme/week summaries.\n" .
                "5. NEVER include JSON strings inside field values. All fields must contain plain text or " .
                "arrays, NOT JSON-encoded strings.\n\n";

            // Add structure-specific guidance - simplified.
            if ($structure === 'theme') {
                $requestedthemecount = self::extract_requested_theme_count($prompt, $structure);

                if (!empty($requestedthemecount)) {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate EXACTLY {$requestedthemecount} themes (non-negotiable).\n" .
                        "Theme titles must be descriptive (e.g., 'Data Analysis Fundamentals'), never generic " .
                        "('Theme 1').\n" .
                        "Week titles must include BOTH date range AND descriptive topic (e.g., 'Oct 18 - 24: " .
                        "Introduction to Cloud Computing').\n" .
                        "Each week structure MUST match the BASE STRUCTURE shown above - do NOT add or remove " .
                        "weeks from any theme.\n\n";
                } else {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate 4-7 themes based on content.\n" .
                        "Theme titles must be descriptive, never generic.\n" .
                        "Week titles must include BOTH date range AND descriptive topic (e.g., 'Oct 18 - 24: " .
                        "Introduction to Cloud Computing').\n\n";
                }
            } else {
                $roleinstruction .= "WEEKLY GENERATION:\n" .
                    "Create one section per major topic. Section titles must be descriptive (e.g., 'Week 1: " .
                    "Cloud Computing Basics').\n\n";
            }

            // Explicit JSON format instruction with detailed structure.
            if ($structure === 'theme') {
                $formatinstruction = "*** REQUIRED JSON RETURN FORMAT ***\n" .
                    "Your response MUST be ONLY a valid JSON object with this exact structure:\n" .
                    "{\n" .
                    "  \"themes\": [\n" .
                    "    {\n" .
                    "      \"title\": \"Theme Title (descriptive, not generic)\",\n" .
                    "      \"summary\": \"2-3 sentences introducing the theme topic to students\",\n" .
                    "      \"weeks\": [\n" .
                    "        {\n" .
                    "          \"title\": \"Oct 18 - 24: Introduction to Cloud Computing\",\n" .
                    "          \"summary\": \"Brief overview of the week's learning outcomes\",\n" .
                    "          \"learningactivity_metadata\": {\n" .
                    "            \"name\": \"Welcome to this week\",\n" .
                    "            \"activityicon\": \"fa-graduation-cap\",\n" .
                    "            \"instructions\": \"Week-level introduction providing context and guidance (5-8 " .
                    "sentences)\"\n" .

                    "          },\n" .
                    "          \"sessions\": {\n" .
                    "            \"presession\": {\n" .
                    "              \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "              \"learningactivity_metadata\": {\n" .
                    "                \"name\": \"Pre-session Reading\",\n" .
                    "                \"activityicon\": \"fa-book-open\",\n" .
                    "                \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "                \"duration\": \"30\",\n" .
                    "                \"learningmode\": \"Asynchronous\",\n" .
                    "                \"groupactivity\": false,\n" .
                    "                \"learningtypes\": \"Acquisition,Investigation\",\n" .
                    "                \"learningoutcomes_weekly\": \"Identify key concepts\\nAnalyze reading " .
                    "material\\nSynthesize information\"\n" .

                    "              }\n" .
                    "            },\n" .
                    "            \"session\": {\n" .
                    "              \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "              \"learningactivity_metadata\": {\n" .
                    "                \"name\": \"Live Session Activity\",\n" .
                    "                \"activityicon\": \"fa-chalkboard-user\",\n" .
                    "                \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "                \"duration\": \"60\",\n" .
                    "                \"learningmode\": \"Synchronous\",\n" .
                    "                \"groupactivity\": false,\n" .
                    "                \"learningtypes\": \"Discussion,Practice\",\n" .
                    "                \"learningoutcomes_weekly\": \"Apply concepts through " .
                    "discussion\\nDemonstrate understanding\\nCollaborate with peers\"\n" .

                    "              }\n" .
                    "            },\n" .
                    "            \"postsession\": {\n" .
                    "              \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "              \"learningactivity_metadata\": {\n" .
                    "                \"name\": \"Post-session Reflection\",\n" .
                    "                \"activityicon\": \"fa-pen-to-square\",\n" .
                    "                \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "                \"duration\": \"45\",\n" .
                    "                \"learningmode\": \"Asynchronous\",\n" .
                    "                \"groupactivity\": false,\n" .
                    "                \"learningtypes\": \"Production,Practice\",\n" .
                    "                \"learningoutcomes_weekly\": \"Reflect on learning\\nEvaluate " .
                    "understanding\\nCreate new insights\"\n" .

                    "              }\n" .
                    "            }\n" .
                    "          }\n" .
                    "        }\n" .
                    "      ]\n" .
                    "    }\n" .
                    "  ]\n" .
                    "}\n\n" .
                    "CRITICAL REQUIREMENTS:\n" .
                    "- Return ONLY the JSON object. No additional text before or after.\n" .
                    "- NEVER include JSON as a string value in any field (e.g., summary must NOT contain nested JSON)\n" .
                    "- Each theme.summary must be 2-3 plain sentences (NO JSON inside)\n" .
                    "- Each week.title must include date range AND descriptive topic: 'date: Topic Name'\n" .
                    "- Week-level learningactivity_metadata.name should be welcoming (e.g., 'Welcome to this " .
                    "week', 'Week Overview')\n" .

                    "- Week-level learningactivity_metadata.instructions should provide week introduction and " .
                    "context (5-8 sentences)\n" .

                    "- Week-level learningactivity_metadata.activityicon should use 'fa-graduation-cap' or " .
                    "similar welcoming icon\n" .

                    "- Each sessions.description must be 2-3 plain sentences describing the session phase\n" .
                    "- Session-level learningactivity_metadata.name must be a descriptive activity name (e.g., " .
                    "'Pre-session Reading', 'Live Discussion')\n" .

                    "- learningactivity_metadata.activityicon MUST be one of: '', 'fa-book', 'fa-book-open', " .
                    "'fa-graduation-cap', 'fa-chalkboard', 'fa-chalkboard-user', 'fa-flask', 'fa-microscope', " .
                    "'fa-laptop-code', 'fa-pen-to-square', 'fa-comments', 'fa-users', 'fa-lightbulb', " .
                    "'fa-puzzle-piece', 'fa-clipboard-check', 'fa-file-pen', 'fa-robot'\n" .

                    "- Session-level learningactivity_metadata.instructions must be 5-8 detailed sentences for " .
                    "activity guidance\n" .

                    "- learningactivity_metadata.learningmode MUST be one of: 'Synchronous', 'Asynchronous', " .
                    "'Not specified'\n" .

                    "- learningactivity_metadata.learningtypes MUST be comma-separated list from ONLY these six " .
                    "types: 'Acquisition', 'Collaboration', 'Discussion', 'Investigation', 'Practice', " .
                    "'Production'\n" .

                    "- CRITICAL: 'Reflection' is NOT a valid learning type - use 'Production' or 'Practice' " .
                    "instead for reflective activities\n" .

                    "- learningactivity_metadata.duration MUST be a number (minutes as string)\n" .
                    "- learningactivity_metadata.groupactivity MUST be boolean (true/false)\n" .
                    "- All field values must be strings or arrays of objects, NEVER strings containing JSON\n" .
                    "- Themes array MUST contain all themes\n" .
                    "- Each theme MUST have a weeks array with complete week structures\n\n";
            } else {
                // Weekly structure with sessions.
                $formatinstruction = "*** REQUIRED JSON RETURN FORMAT ***\n" .
                    "Your response MUST be ONLY a valid JSON object with this exact structure:\n" .
                    "{\n" .
                    "  \"sections\": [\n" .
                    "    {\n" .
                    "      \"title\": \"Week 1 (Oct 18 - 24): Introduction to Cloud Computing\",\n" .
                    "      \"summary\": \"Brief overview of the week's learning outcomes\",\n" .
                    "      \"learningactivity_metadata\": {\n" .
                    "        \"name\": \"Welcome to this week\",\n" .
                    "        \"activityicon\": \"fa-graduation-cap\",\n" .
                    "        \"instructions\": \"Week-level introduction providing context and guidance (5-8 sentences)\"\n" .
                    "      },\n" .
                    "      \"sessions\": {\n" .
                    "        \"presession\": {\n" .
                    "          \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "          \"learningactivity_metadata\": {\n" .
                    "            \"name\": \"Pre-session Reading\",\n" .
                    "            \"activityicon\": \"fa-book-open\",\n" .
                    "            \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "            \"duration\": \"30\",\n" .
                    "            \"learningmode\": \"Asynchronous\",\n" .
                    "            \"groupactivity\": false,\n" .
                    "            \"learningtypes\": \"Acquisition,Investigation\",\n" .
                    "            \"learningoutcomes_weekly\": \"Identify key concepts\\nAnalyze reading " .
                    "material\\nSynthesize information\"\n" .

                    "          }\n" .
                    "        },\n" .
                    "        \"session\": {\n" .
                    "          \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "          \"learningactivity_metadata\": {\n" .
                    "            \"name\": \"Live Session Activity\",\n" .
                    "            \"activityicon\": \"fa-chalkboard-user\",\n" .
                    "            \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "            \"duration\": \"60\",\n" .
                    "            \"learningmode\": \"Synchronous\",\n" .
                    "            \"groupactivity\": false,\n" .
                    "            \"learningtypes\": \"Discussion,Practice\",\n" .
                    "            \"learningoutcomes_weekly\": \"Apply concepts through discussion\\nDemonstrate " .
                    "understanding\\nCollaborate with peers\"\n" .

                    "          }\n" .
                    "        },\n" .
                    "        \"postsession\": {\n" .
                    "          \"description\": \"2-3 sentences describing this session phase for students\",\n" .
                    "          \"learningactivity_metadata\": {\n" .
                    "            \"name\": \"Post-session Reflection\",\n" .
                    "            \"activityicon\": \"fa-pen-to-square\",\n" .
                    "            \"instructions\": \"Detailed activity instructions (5-8 sentences)\",\n" .
                    "            \"duration\": \"45\",\n" .
                    "            \"learningmode\": \"Asynchronous\",\n" .
                    "            \"groupactivity\": false,\n" .
                    "            \"learningtypes\": \"Production,Practice\",\n" .
                    "            \"learningoutcomes_weekly\": \"Reflect on learning\\nEvaluate " .
                    "understanding\\nCreate new insights\"\n" .

                    "          }\n" .
                    "        }\n" .
                    "      }\n" .
                    "    }\n" .
                    "  ]\n" .
                    "}\n\n" .
                    "CRITICAL REQUIREMENTS:\n" .
                    "- Return ONLY the JSON object. No additional text before or after.\n" .
                    "- NEVER include JSON as a string value in any field\n" .
                    "- Each section.summary must be 2-3 plain sentences (NO JSON inside)\n" .
                    "- Each section.title must include date range AND descriptive topic: 'date: Topic Name'\n" .
                    "- Section-level learningactivity_metadata.name should be welcoming (e.g., 'Welcome to this " .
                    "week', 'Week Overview')\n" .

                    "- Section-level learningactivity_metadata.instructions should provide week introduction and " .
                    "context (5-8 sentences)\n" .

                    "- Section-level learningactivity_metadata.activityicon should use 'fa-graduation-cap' or " .
                    "similar welcoming icon\n" .

                    "- Each sessions.description must be 2-3 plain sentences describing the session phase\n" .
                    "- Session-level learningactivity_metadata.name must be a descriptive activity name (e.g., " .
                    "'Pre-session Reading', 'Live Discussion')\n" .

                    "- learningactivity_metadata.activityicon MUST be one of: '', 'fa-book', 'fa-book-open', " .
                    "'fa-graduation-cap', 'fa-chalkboard', 'fa-chalkboard-user', 'fa-flask', 'fa-microscope', " .
                    "'fa-laptop-code', 'fa-pen-to-square', 'fa-comments', 'fa-users', 'fa-lightbulb', " .
                    "'fa-puzzle-piece', 'fa-clipboard-check', 'fa-file-pen', 'fa-robot'\n" .

                    "- Session-level learningactivity_metadata.instructions must be 5-8 detailed sentences for " .
                    "activity guidance\n" .

                    "- learningactivity_metadata.learningmode MUST be one of: 'Synchronous', 'Asynchronous', " .
                    "'Not specified'\n" .

                    "- learningactivity_metadata.learningtypes MUST be comma-separated list from ONLY these six " .
                    "types: 'Acquisition', 'Collaboration', 'Discussion', 'Investigation', 'Practice', " .
                    "'Production'\n" .

                    "- CRITICAL: 'Reflection' is NOT a valid learning type - use 'Production' or 'Practice' " .
                    "instead for reflective activities\n" .

                    "- learningactivity_metadata.duration MUST be a number (minutes as string)\n" .
                    "- learningactivity_metadata.groupactivity MUST be boolean (true/false)\n" .
                    "- learningactivity_metadata.learningoutcomes_weekly MUST be 2-3 learning objectives " .
                    "separated by newlines (\\n)\n" .

                    "- All field values must be strings or arrays of objects, NEVER strings containing JSON\n" .
                    "- Sections array MUST contain all sections\n" .
                    "- Each section MUST have a sessions object with presession, session, and postsession\n\n";
            }

            // Activities are not requested: only sections, summaries and session metadata.
            $formatinstruction .= "Do NOT include activities - only sections with titles, summaries, and outlines.\n";

            // Add week date guidance if courseid provided.
            $weekdateguidance = '';
            if (!empty($courseid)) {
                $exampledate1 = self::get_week_date_range(1, $courseid);
                $weekdateguidance = "\nInclude week dates in titles: \"{$exampledate1}\" instead of just \"Week 1\".\n";
            }

            $formatinstruction .= $weekdateguidance;

            if (empty($roleinstruction) || empty($formatinstruction)) {
                return [
                    'activities' => [],
                    'template' => get_string('aierror', 'aiplacement_modgen', get_string('aierror_prompt', 'aiplacement_modgen')),
                    'error' => true,
                ];
            }

            // Build final prompt using array pattern for better performance.
            $promptparts = [
                $roleinstruction,
                "User request: " . trim($prompt) . "\n\n",
                $formatinstruction,
            ];

            // Add completeness enforcement at END (has most weight).
            if ($structure === 'theme') {
                if (!empty($requestedthemecount)) {
                    $promptparts[] = "\n*** CRITICAL THEME COUNT REQUIREMENT ***\n";
                    $promptparts[] = "YOU MUST GENERATE EXACTLY {$requestedthemecount} THEMES - NO MORE, NO LESS.\n";
                    $promptparts[] = "This is MANDATORY and NON-NEGOTIABLE.\n";
                    $promptparts[] = "Count your themes before returning: if you don't have {$requestedthemecount} " .
                        "themes, you have FAILED.\n";
                    $promptparts[] = "REQUIRED THEME COUNT: {$requestedthemecount}\n";
                    $promptparts[] = "Do NOT stop early. Do NOT truncate. Do NOT generate fewer than " .
                        "{$requestedthemecount} themes.\n";
                    $promptparts[] = "Return ONLY valid JSON with {$requestedthemecount} themes in the themes array.\n";
                } else {
                    $promptparts[] = "\n*** COMPLETENESS REQUIREMENT ***\n";
                    $promptparts[] = "Generate all themes needed to cover ALL topics and content.\n";
                    $promptparts[] = "Do NOT stop early or truncate output.\n";
                    $promptparts[] = "Return ONLY valid JSON.\n";
                }
            } else {
                $promptparts[] = "\n*** COMPLETENESS REQUIREMENT ***\n";
                $promptparts[] = "Include EVERY topic from content. Do NOT truncate or stop early.\n";
                $promptparts[] = "Generate complete response with all content.\n";
                $promptparts[] = "Return ONLY valid JSON.\n";
            }

            $finalprompt = implode('', $promptparts);

            // Instantiate the generate_text action with required parameters.
            $action = new \core_ai\aiactions\generate_text(
                $contextid,
                $USER->id,
                $finalprompt
            );

            // Process the action through the Manager.
            $response = $aimanager->process_action($action);
            $data = $response->get_response_data();

            // Debug: if response is null or empty, return error.
            if (empty($data)) {
                return [
                    'activities' => [],
                    'template' => get_string('aierror', 'aiplacement_modgen', get_string('aierror_empty', 'aiplacement_modgen')),
                    'error' => true,
                ];
            }

            // Try to decode the provider's generated text as JSON per our schema.
            // Check multiple possible response keys - generatedcontent takes priority for OpenAI.
            $text = $data['generatedcontent'] ?? ($data['generatedtext'] ?? ($data['text'] ?? ($data['content'] ?? '')));

            // If we got no text at all, return error.
            if (empty($text) || !is_string($text)) {
                return [
                    'activities' => [],
                    'template' => get_string('aierror', 'aiplacement_modgen', get_string(
                        'aierror_notext',
                        'aiplacement_modgen',
                        implode(', ', array_keys($data ?? []))
                    )),
                    'error' => true,
                ];
            }

            // Log token usage and potential truncation.
            $prompttokens = strlen($finalprompt) / 4; // Rough estimate: 1 token ≈ 4 chars.
            $responsetokens = strlen($text) / 4;
            $totaltokens = $prompttokens + $responsetokens;

            $jsondecoded = null;
            if (is_string($text)) {
                // First attempt: direct JSON decode.
                $jsondecoded = json_decode($text, true);

                // Early return if successful - avoid unnecessary parsing attempts.
                if (is_array($jsondecoded)) {
                    // Successfully decoded - skip to deep unescape step.
                    $jsondecoded = self::deep_unescape_stringified_json($jsondecoded);
                } else {
                    // If decode failed, try alternative parsing methods.
                    $jsonerror = json_last_error_msg();
                    // Don't report every JSON error, just continue to next attempt.
                }

                // Second attempt: extract a JSON object/array from the text if provider added commentary.
                if (!is_array($jsondecoded)) {
                    // Try to find JSON wrapped in code blocks or quoted strings.
                    // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- matches markdown code-fence backticks, not a shell command.
                    if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $text, $m)) {
                        $jsondecoded = json_decode($m[1], true);
                    } else if (preg_match('/(\{.*\}|\[.*\])/s', $text, $m)) {
                        $jsondecoded = json_decode($m[1], true);
                    }
                }

                // Third attempt: if still not decoded, try unescaping the entire response first.
                if (!is_array($jsondecoded)) {
                    $unescaped = self::unescape_json_string($text);
                    if ($unescaped !== $text) {
                        $jsondecoded = json_decode($unescaped, true);
                    }
                }

                // Fourth attempt: After successful decode, deeply unescape any stringified JSON within the structure.
                if (is_array($jsondecoded)) {
                    $jsondecoded = self::deep_unescape_stringified_json($jsondecoded);
                }
            }

            // Log double-encoding detection.
            $isdoubleencoded = false;
            if (is_array($jsondecoded) && count($jsondecoded) === 1) {
                $firstval = reset($jsondecoded);
                if (
                    is_string($firstval) && (
                    strpos($firstval, '{"themes"') !== false || strpos($firstval, '{"sections"') !== false
                    )
                ) {
                    $isdoubleencoded = true;
                }
            }

            // Attempt to normalise nested/stringified JSON that may be embedded in string fields.
            if (is_array($jsondecoded)) {
                $jsondecoded = self::normalize_ai_response($jsondecoded, true);

                // Extract stringified JSON from summary fields that looks like full content.
                $jsondecoded = self::extract_misplaced_content_from_summaries($jsondecoded);

                // Debug logging using Moodle's debugging API (only in developer mode)
                // debugging('AI response: ' . json_encode($jsondecoded), DEBUG_DEVELOPER);.
            }

            if (
                is_array($jsondecoded) && (
                isset($jsondecoded['sections']) || isset($jsondecoded['themes']) || isset($jsondecoded['activities'])
                )
            ) {
                // If theme structure is requested but we have sections, attempt conversion.
                if ($structure === 'theme' && isset($jsondecoded['sections']) && !isset($jsondecoded['themes'])) {
                    $jsondecoded = self::convert_sections_to_themes($jsondecoded);
                }

                // Validate the structure to catch malformed responses.
                $validation = self::validate_module_structure($jsondecoded, $structure);

                if (!$validation['valid']) {
                    // Return error response that will prevent approval.
                    return [
                        $structure === 'theme' ? 'themes' : 'sections' => [],
                        'validation_error' => $validation['error'],
                        'template' => get_string('aierror', 'aiplacement_modgen', $validation['error']),
                        'error' => true,
                    ];
                }

                if ($structure === 'theme') {
                    $jsondecoded = self::blank_theme_summaries($jsondecoded);
                }

                return $jsondecoded;
            }

            // Debug: JSON decode failed or invalid structure
            // For theme structure, attempt to convert sections to themes before falling back.
            if ($structure === 'theme' && is_array($jsondecoded) && isset($jsondecoded['sections'])) {
                $jsondecoded = self::convert_sections_to_themes($jsondecoded);

                // Validate the converted structure.
                $validation = self::validate_module_structure($jsondecoded, $structure);
                if ($validation['valid']) {
                    $jsondecoded = self::blank_theme_summaries($jsondecoded);
                    return $jsondecoded;
                }
            }

            // Last resort: wrap generated text into a label
            // For theme structure, still wrap as themes but note this is fallback.
            $revised = $data['revisedprompt'] ?? '';
            $arraykey = $structure === 'theme' ? 'themes' : 'sections';
            return [
                $arraykey => [
                    ['title' => get_string('aigensummary', 'aiplacement_modgen'), 'summary' => $text ?: ''],
                ],
                'template' => $revised ?: get_string('aigeneratedvia_nonjson', 'aiplacement_modgen'),
            ];
        } catch (\Throwable $e) {
            // Fallback: return error info in template.
            return [
                'activities' => [],
                'template' => get_string('aierror', 'aiplacement_modgen', $e->getMessage()),
                'error' => true,
            ];
        }
    }
}
