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
 * @category    local
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use aiplacement_modgen\activitytype\registry;

require_once(__DIR__ . '/../activitytype/registry.php');

defined('MOODLE_INTERNAL') || die();

class ai_service {

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
    /**
     * Extract requested theme count from user prompt if specified.
     * Looks for patterns like "X themes", "X themed sections", "divide into X themes", etc.
     * 
     * @param string $prompt User's input prompt
     * @param string $structure The structure type (theme or weekly)
     * @return int|null The requested theme count, or null if not specified
     */
    private static function extract_requested_theme_count($prompt, $structure) {
        // Only applicable for theme-based structures
        if ($structure !== 'theme') {
            return null;
        }
        
        // Look for patterns like:
        // "5 themes", "5-themed", "divide into 5 themes", "create 5 themes"
        // "5 themed sections", "using 5 themes", "total of 5 themes"
        if (preg_match('/(\d+)\s*(?:themes?|themed\s+sections?|theme\s+groups?)/i', $prompt, $matches)) {
            $count = intval($matches[1]);
            // Reasonable range: between 2 and 20 themes
            if ($count >= 2 && $count <= 20) {
                return $count;
            }
        }
        
        return null;
    }

    private static function validate_module_structure($data, $structure) {
        $structure = ($structure === 'theme') ? 'theme' : 'weekly';

        // Check if we have the expected top-level key
        if ($structure === 'theme' && !isset($data['themes'])) {
            return ['valid' => false, 'error' => 'Response missing "themes" array'];
        }
        if ($structure === 'weekly' && !isset($data['sections'])) {
            return ['valid' => false, 'error' => 'Response missing "sections" array'];
        }

        $items = $structure === 'theme' ? ($data['themes'] ?? []) : ($data['sections'] ?? []);

        // Check if array is empty
        if (empty($items)) {
            return ['valid' => false, 'error' => 'Response contains no themes/sections'];
        }

        // Check each theme/section for malformed structure
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                return ['valid' => false, 'error' => 'Invalid structure: theme/section is not an array'];
            }

            // For theme structure, check weeks
            if ($structure === 'theme') {
                if (!isset($item['weeks'])) {
                    return ['valid' => false, 'error' => 'Theme missing "weeks" array'];
                }
                if (!is_array($item['weeks'])) {
                    return ['valid' => false, 'error' => 'Theme "weeks" is not an array'];
                }

                // Check each week
                foreach ($item['weeks'] as $widx => $week) {
                    if (!is_array($week)) {
                        return ['valid' => false, 'error' => 'Week structure is not an array'];
                    }
                }
            }

            // Check if title exists and is not empty
            if (!isset($item['title']) || trim($item['title']) === '') {
                return ['valid' => false, 'error' => 'Theme/section missing title'];
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
     * @param bool $isTopLevel Whether this is the top-level call (used for structure extraction)
     * @return mixed
     */
    private static function normalize_ai_response($value, $isTopLevel = false) {
        // If it's an array, walk each element.
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize_ai_response($v, false);
            }
            
            // Top-level extraction: check for wrapped structure pattern
            if ($isTopLevel && !empty($out)) {
                // Pattern 1: Single entry that contains the actual structure
                if (count($out) === 1) {
                    foreach ($out as $key => $item) {
                        // If we have a structure wrapped (e.g., {theme: {themes: [...]}}), unwrap it
                        if (is_array($item) && (isset($item['themes']) || isset($item['sections']))) {
                            return $item;
                        }
                    }
                }
                
                // Pattern 2: themes/sections array where first item's summary contains actual structure
                if ((isset($out['themes']) || isset($out['sections'])) && is_array($out['themes'] ?? $out['sections'])) {
                    $itemsArray = $out['themes'] ?? $out['sections'];
                    $firstItem = $itemsArray[0] ?? null;
                    
                    if ($firstItem && is_array($firstItem) && isset($firstItem['summary']) && is_string($firstItem['summary'])) {
                        $summary = trim($firstItem['summary']);
                        // Check if the summary contains the full structure (may have escaped newlines/quotes)
                        if (strlen($summary) > 0 && ($summary[0] === '{' || $summary[0] === '[')) {
                            // Try direct decode first
                            $decoded = json_decode($summary, true);
                            if (json_last_error() === JSON_ERROR_NONE && 
                                (isset($decoded['themes']) || isset($decoded['sections']))) {
                                return self::normalize_ai_response($decoded, false);
                            }
                            
                            // Try with common escape patterns: literal \n, \t, \\", etc.
                            $unescaped = self::unescape_json_string($summary);
                            if ($unescaped !== $summary) {
                                $decoded = json_decode($unescaped, true);
                                if (json_last_error() === JSON_ERROR_NONE && 
                                    (isset($decoded['themes']) || isset($decoded['sections']))) {
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

            // Fast check: starts with { or [ -> likely JSON
            if (($trim[0] === '{') || ($trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Recursively normalise decoded payload
                    return self::normalize_ai_response($decoded, false);
                }
                
                // If direct decode failed, try unescaping first
                $unescaped = self::unescape_json_string($trim);
                if ($unescaped !== $trim) {
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return self::normalize_ai_response($decoded, false);
                    }
                }
            }

            // Try unescaping common escapes (e.g. when AI returns a JSON string inside a JSON field)
            $unescaped = stripslashes($trim);
            if ($unescaped !== $trim) {
                if ((isset($unescaped[0]) && ($unescaped[0] === '{' || $unescaped[0] === '['))) {
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return self::normalize_ai_response($decoded, false);
                    }
                }
            }

            // As a last resort, try to extract a JSON blob from within larger text
            if (preg_match('/(\{.*\}|\[.*\])/s', $trim, $m)) {
                $decoded = json_decode($m[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::normalize_ai_response($decoded, false);
                }
            }

            // Nothing to decode
            return $value;
        }

        // Scalars other than strings left unchanged
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
        
        // Check if we have themes array
        if (empty($data['themes']) || !is_array($data['themes'])) {
            return $data;
        }
        
        // Look through themes for misplaced content
        foreach ($data['themes'] as $idx => $theme) {
            if (!is_array($theme) || empty($theme['summary'])) {
                continue;
            }
            
            $summary = $theme['summary'];
            
            // Check if summary is already decoded to an array (from deep_unescape_stringified_json)
            if (is_array($summary) && (isset($summary['themes']) || isset($summary['sections']))) {
                // This is misplaced content already decoded!
                // Use the extracted content as the main data, preserving template field
                $template_value = $data['template'] ?? "Generated via AI subsystem";
                $data = $summary;
                $data['template'] = $template_value;
                
                return $data;
            }
            
            // Also check if it's a string containing JSON
            if (is_string($summary)) {
                $summary = trim($summary);
                
                // Check if summary is a stringified JSON object containing full structure
                if (strlen($summary) > 100 && 
                    (strpos($summary, '{"themes"') === 0 || strpos($summary, '{"sections"') === 0) && 
                    (substr($summary, -1) === '}' || substr($summary, -1) === ']')) {
                    
                    // Try to parse it
                    $parsed = json_decode($summary, true);
                    if (is_array($parsed) && (isset($parsed['themes']) || isset($parsed['sections']))) {
                        // This is misplaced content!
                        // Use the extracted content as the main data, preserving template field
                        $template_value = $data['template'] ?? "Generated via AI subsystem";
                        $data = $parsed;
                        $data['template'] = $template_value;
                        
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
        // but not properly interpreted
        $replacements = [
            '\\\\n' => "\n",      // Literal \n -> newline
            '\\\\t' => "\t",      // Literal \t -> tab
            '\\\\r' => "\r",      // Literal \r -> carriage return
            '\\\"' => '"',        // Escaped quote -> quote
            "\\\\" => "\\",       // Escaped backslash -> backslash
            '\\/' => '/',         // Escaped forward slash -> slash
        ];
        
        $result = $str;
        foreach ($replacements as $pattern => $replacement) {
            $result = str_replace($pattern, $replacement, $result);
        }
        
        // Also handle cases where we have double-escaped quotes or backslashes
        // e.g., \\\\" should become \"
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
        // Prevent infinite recursion
        if ($depth > 10) {
            return $data;
        }
        
        if (!is_array($data)) {
            return $data;
        }
        
        foreach ($data as $key => &$value) {
            if (is_string($value)) {
                // Check if string looks like it might be JSON
                $trimmed = trim($value);
                if ((strpos($trimmed, '{') === 0 && strrpos($trimmed, '}') === strlen($trimmed) - 1) ||
                    (strpos($trimmed, '[') === 0 && strrpos($trimmed, ']') === strlen($trimmed) - 1)) {
                    
                    // Try to decode as-is first
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        // Successfully decoded! Recursively process the decoded content
                        $value = self::deep_unescape_stringified_json($decoded, $depth + 1);
                    } else {
                        // Try unescaping first, then decoding
                        $unescaped = self::unescape_json_string($trimmed);
                        if ($unescaped !== $trimmed) {
                            $decoded = json_decode($unescaped, true);
                            if (is_array($decoded)) {
                                $value = self::deep_unescape_stringified_json($decoded, $depth + 1);
                            }
                        }
                    }
                }
            } elseif (is_array($value)) {
                // Recursively process nested arrays
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
        // If already has themes or no sections, return as-is
        if (!isset($data['sections']) || !is_array($data['sections'])) {
            return $data;
        }

        $sections = $data['sections'];
        if (empty($sections)) {
            return $data;
        }

        // Group sections into themes based on pedagogical similarity
        // Strategy: Look for section titles that suggest thematic boundaries
        // Keywords that might start a new theme: "Module", "Unit", "Theme", "Section", "Part"
        $themes = [];
        $currentTheme = null;

        foreach ($sections as $section) {
            $title = $section['title'] ?? 'Untitled';
            
            // Check if this section should start a new theme
            // (titles containing "Theme", "Unit", "Module" suggest a new thematic grouping)
            $lowerTitle = strtolower($title);
            $isThemeStarter = preg_match('/^(theme|unit|module|part|section)\s+\d+|^(week|session)\s+\d+/i', $title);
            
            if (empty($themes) || $isThemeStarter) {
                // Start a new theme
                $themeName = preg_match('/^(theme|unit|module)\s+(\d+)/i', $title, $m) 
                    ? ucfirst($m[1]) . ' ' . $m[2]
                    : (preg_match('/^part\s+(\w+)/i', $title, $m2) ? 'Part: ' . $m2[1] : $title);
                
                $currentTheme = [
                    'title' => $themeName,
                    'summary' => $section['summary'] ?? '',
                    'weeks' => [],
                ];
                $themes[] = $currentTheme;
                $themeIdx = count($themes) - 1;
            } else {
                $themeIdx = count($themes) - 1;
            }

            // Convert section into week structure
            $week = [
                'title' => $title,
                'summary' => $section['summary'] ?? '',
            ];

            // If section has outline, convert to activities structure
            if (isset($section['outline']) && is_array($section['outline'])) {
                $week['sessions'] = [
                    'session' => [
                        'title' => 'Main Session',
                        'summary' => implode("\n", $section['outline']),
                        'activities' => $section['activities'] ?? [],
                    ],
                ];
            } elseif (isset($section['activities']) && is_array($section['activities'])) {
                $week['sessions'] = [
                    'session' => [
                        'title' => 'Main Session',
                        'summary' => '',
                        'activities' => $section['activities'],
                    ],
                ];
            }

            $themes[$themeIdx]['weeks'][] = $week;
        }

        // Return converted structure
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
        
        // Get course object if courseid provided
        if (!empty($courseid)) {
            $course = get_course($courseid);
        } else {
            $course = $COURSE;
        }
        
        // Get course start date
        $startdate = !empty($course->startdate) ? $course->startdate : time();
        
        // Calculate week start date (Monday of week number)
        // Week 1 starts on the course start date
        $weekstartdate = $startdate + (($weeknumber - 1) * 7 * 24 * 60 * 60);
        $weekenddate = $weekstartdate + (6 * 24 * 60 * 60); // 6 days later = Sunday
        
        // Format as "Mon Date - Mon Date" (e.g., "Jan 6 - 12")
        $startformatted = userdate($weekstartdate, '%b %d', 99999);
        $endformatted = userdate($weekenddate, '%d', 99999);
        
        return "{$startformatted} - {$endformatted}";
    }

    /**
     * Generate module structure and content using AI.
     * 
     * @param string $prompt User's input requirements
     * @param array $documents Supporting documents/files
     * @param string $structure Module structure type (weekly/theme)
     * @param array $template_data Optional template data
     * @param int $courseid Optional course ID for week date calculations
     * @param bool $includeactivities Whether to request activities in the response (default: true)
     * @param bool $includesessions Whether to request session instructions (default: true)
     * @return array Module structure JSON
     */
    public static function generate_module($prompt, $documents = [], $structure = 'weekly', $template_data = null, $courseid = null, $includeactivities = true, $includesessions = true) {
        global $USER, $COURSE;
        
        // Integrate with Moodle AI Subsystem Manager using generate_text action.
        try {
            if (!class_exists('\\core_ai\\manager') || !class_exists('\\core_ai\\aiactions\\generate_text')) {
                throw new \moodle_exception('AI subsystem not available');
            }

            $contextid = !empty($COURSE->id)
                ? \context_course::instance($COURSE->id)->id
                : \context_system::instance()->id;

            // Instantiate Manager and ensure AI User Policy is accepted per subsystem design.
            $aimanager = new \core_ai\manager();
            if (!$aimanager->get_user_policy_status($USER->id)) {
                return [
                    'activities' => [],
                    'template' => 'AI error: User has not accepted the AI User Policy.',
                    'raw' => '',
                    'debugprompt' => trim($prompt)
                ];
            }

            // Compose instruction-rich prompt with strict JSON schema requirements.
            // Normalize format types: connected_weekly -> weekly, connected_theme -> theme
            $normalizedStructure = $structure;
            if ($structure === 'connected_weekly') {
                $normalizedStructure = 'weekly';
            } elseif ($structure === 'connected_theme') {
                $normalizedStructure = 'theme';
            }
            $structure = ($normalizedStructure === 'theme') ? 'theme' : 'weekly';
            
            // Get the configurable pedagogical guidance from admin settings
            $pedagogicalguidance = get_config('aiplacement_modgen', 'baseprompt');
            if (empty($pedagogicalguidance)) {
                // Fallback to default if not configured
                $pedagogicalguidance = "You are an expert Moodle learning content designer at a UK higher education institution designing a Moodle module.";
            }
            
            // Build simplified role instruction
            $istemplate = !empty($template_data);
            $source_label = $istemplate ? 'the existing module structure' : 'the curriculum file';
            
            $roleinstruction = $pedagogicalguidance . "\n\n" .
                "CRITICAL - MUST FOLLOW:\n" .
                "1. COMPLETE output: Generate all topics/sections. Do NOT stop early or truncate.\n" .
                "2. Include ALL content from " . $source_label . " - nothing can be omitted.\n" .
                "3. Return ONLY valid JSON - no commentary or code blocks.\n" .
                "4. Use ONLY supported activity types - unsupported types will FAIL.\n" .
                "5. TEMPLATE METADATA section describes the 'template' JSON field ONLY - do NOT add this to theme/week summaries.\n" .
                "6. NEVER include JSON strings inside field values. All fields must contain plain text or arrays, NOT JSON-encoded strings.\n\n";

            // Add structure-specific guidance - simplified
            if ($structure === 'theme') {
                $requestedthemecount = self::extract_requested_theme_count($prompt, $structure);
                
                if (!empty($requestedthemecount)) {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate EXACTLY {$requestedthemecount} themes (non-negotiable).\n" .
                        "Theme titles must be descriptive (e.g., 'Data Analysis Fundamentals'), never generic ('Theme 1').\n" .
                        "Week titles must include BOTH date range AND descriptive topic (e.g., 'Oct 18 - 24: Introduction to Cloud Computing').\n" .
                        "Each week structure MUST match the BASE STRUCTURE shown above - do NOT add or remove weeks from any theme.\n\n";
                } else {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate 4-7 themes based on content.\n" .
                        "Theme titles must be descriptive, never generic.\n" .
                        "Week titles must include BOTH date range AND descriptive topic (e.g., 'Oct 18 - 24: Introduction to Cloud Computing').\n\n";
                }
            } else {
                $roleinstruction .= "WEEKLY GENERATION:\n" .
                    "Create one section per major topic. Section titles must be descriptive (e.g., 'Week 1: Cloud Computing Basics').\n\n";
            }

            $activitymetadata = registry::get_supported_activity_metadata();

            // Explicit JSON format instruction with detailed structure
            if ($structure === 'theme') {
                if ($includesessions) {
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
                        "          \"sessions\": {\n" .
                        "            \"presession\": {\n" .
                        "              \"description\": \"5-8 sentences of student-facing guidance for pre-session preparation\",\n" .
                        "              \"activities\": [{\"type\": \"forum\", \"name\": \"Activity Name\"}]\n" .
                        "            },\n" .
                        "            \"session\": {\n" .
                        "              \"description\": \"5-8 sentences of student-facing guidance for main session activities\",\n" .
                        "              \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity Name\"}]\n" .
                        "            },\n" .
                        "            \"postsession\": {\n" .
                        "              \"description\": \"5-8 sentences of student-facing guidance for post-session reflection\",\n" .
                        "              \"activities\": [{\"type\": \"assignment\", \"name\": \"Activity Name\"}]\n" .
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
                        "- Each sessions.description must be 5-8 plain sentences (NO JSON inside)\n" .
                        "- All field values must be strings or arrays of objects, NEVER strings containing JSON\n" .
                        "- Themes array MUST contain all themes\n" .
                        "- Each theme MUST have a weeks array with complete week structures\n\n";
                } else {
                    $formatinstruction = "*** REQUIRED JSON RETURN FORMAT ***\n" .
                        "Your response MUST be ONLY a valid JSON object (no text before/after):\n" .
                        "{\"themes\": [{\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [{\"title\": \"Week N (Oct 18 - 24)\", \"summary\": \"Brief overview\"}]}]}\n\n";
                }
            } else {
                // Weekly structure with sessions
                if ($includesessions) {
                    $formatinstruction = "*** REQUIRED JSON RETURN FORMAT ***\n" .
                        "Your response MUST be ONLY a valid JSON object with this exact structure:\n" .
                        "{\n" .
                        "  \"sections\": [\n" .
                        "    {\n" .
                        "      \"title\": \"Week 1 (Oct 18 - 24): Introduction to Cloud Computing\",\n" .
                        "      \"summary\": \"Brief overview of the week's learning outcomes\",\n" .
                        "      \"sessions\": {\n" .
                        "        \"presession\": {\n" .
                        "          \"description\": \"5-8 sentences of student-facing guidance for pre-session preparation\",\n" .
                        "          \"activities\": [{\"type\": \"forum\", \"name\": \"Activity Name\"}]\n" .
                        "        },\n" .
                        "        \"session\": {\n" .
                        "          \"description\": \"5-8 sentences of student-facing guidance for main session activities\",\n" .
                        "          \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity Name\"}]\n" .
                        "        },\n" .
                        "        \"postsession\": {\n" .
                        "          \"description\": \"5-8 sentences of student-facing guidance for post-session reflection\",\n" .
                        "          \"activities\": [{\"type\": \"assignment\", \"name\": \"Activity Name\"}]\n" .
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
                        "- Each sessions.description must be 5-8 plain sentences (NO JSON inside)\n" .
                        "- All field values must be strings or arrays of objects, NEVER strings containing JSON\n" .
                        "- Sections array MUST contain all sections\n" .
                        "- Each section MUST have a sessions object with presession, session, and postsession\n\n";
                } else {
                    $formatinstruction = "*** REQUIRED JSON RETURN FORMAT ***\n" .
                        "Your response MUST be ONLY a valid JSON object (no text before/after):\n" .
                        "{\"sections\": [{\"title\": \"Oct 18 - 24: Topic\", \"summary\": \"Brief overview\", \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]}]}\n\n";
                }
            }

            // Add supported activity types only
            if (!empty($activitymetadata) && $includeactivities) {
                $formatinstruction .= "SUPPORTED ACTIVITY TYPES:\n";
                foreach ($activitymetadata as $type => $metadata) {
                    $formatinstruction .= "- {$type}: {$metadata['description']}\n";
                }
                $formatinstruction .= "\nACTIVITY FIELD REQUIREMENTS:\n";
                $formatinstruction .= "- ALL activities MUST have: type (required), name (required)\n";
                $formatinstruction .= "- ALL activities SHOULD have: intro (description text for students)\n";
                $formatinstruction .= "- URL activities MUST ALSO have: url (the external web address)\n";
                $formatinstruction .= "  Example URL activity: {\"type\": \"url\", \"name\": \"Course Reading\", \"intro\": \"Read this article\", \"url\": \"https://example.com/article\"}\n";
                $formatinstruction .= "- BOOK activities can have: chapters (array of chapter objects with title and content)\n";
            } elseif ($includeactivities === false) {
                $formatinstruction .= "Do NOT include activities - only sections with titles, summaries, and outlines.\n";
            }

            // Add week date guidance if courseid provided
            $weekdateguidance = '';
            if (!empty($courseid)) {
                $exampledate1 = self::get_week_date_range(1, $courseid);
                $weekdateguidance = "\nInclude week dates in titles: \"{$exampledate1}\" instead of just \"Week 1\".\n";
            }
            
            $formatinstruction .= $weekdateguidance;

            // Add template guidance if template data is provided
            $template_guidance = '';
            if (!empty($template_data) && is_array($template_data)) {
                $template_guidance = self::build_template_prompt_guidance($template_data, $structure);
            }

            // Incorporate supporting documents with truncation
            $documents_text = '';
            if (!empty($documents) && is_array($documents)) {
                $documents_text .= "\nCONTENT:\n";
                foreach ($documents as $doc) {
                    $dname = isset($doc['filename']) ? $doc['filename'] : 'file';
                    $dcontent = isset($doc['content']) ? $doc['content'] : '';
                    if (is_string($dcontent) && strlen($dcontent) > 50000) {
                        $dcontent = substr($dcontent, 0, 50000) . "\n[truncated]";
                    }
                    $documents_text .= "--- {$dname} ---\n" . trim((string)$dcontent) . "\n\n";
                }
            }

            if (empty($roleinstruction) || empty($formatinstruction)) {
                return [
                    'activities' => [],
                    'template' => 'AI error: Prompt construction failed'
                ];
            }

            // Build final prompt - lean and direct
            // IMPORTANT: Put template guidance AFTER format instruction so it clearly describes the output
            $finalprompt = $roleinstruction . 
                $documents_text . 
                "User request: " . trim($prompt) . "\n\n" .
                $formatinstruction .
                $template_guidance;
            
            // Add completeness enforcement at END (has most weight)
            if ($structure === 'theme') {
                if (!empty($requestedthemecount)) {
                    $finalprompt .= "\n*** CRITICAL THEME COUNT REQUIREMENT ***\n";
                    $finalprompt .= "YOU MUST GENERATE EXACTLY {$requestedthemecount} THEMES - NO MORE, NO LESS.\n";
                    $finalprompt .= "This is MANDATORY and NON-NEGOTIABLE.\n";
                    $finalprompt .= "Count your themes before returning: if you don't have {$requestedthemecount} themes, you have FAILED.\n";
                    $finalprompt .= "REQUIRED THEME COUNT: {$requestedthemecount}\n";
                    $finalprompt .= "Do NOT stop early. Do NOT truncate. Do NOT generate fewer than {$requestedthemecount} themes.\n";
                    $finalprompt .= "Return ONLY valid JSON with {$requestedthemecount} themes in the themes array.\n";
                } else {
                    $finalprompt .= "\n*** COMPLETENESS REQUIREMENT ***\n";
                    $finalprompt .= "Generate all themes needed to cover ALL topics and content.\n";
                    $finalprompt .= "Do NOT stop early or truncate output.\n";
                    $finalprompt .= "Return ONLY valid JSON.\n";
                }
            } else {
                $finalprompt .= "\n*** COMPLETENESS REQUIREMENT ***\n";
                $finalprompt .= "Include EVERY topic from content. Do NOT truncate or stop early.\n";
                $finalprompt .= "Generate complete response with all content.\n";
                $finalprompt .= "Return ONLY valid JSON.\n";
            }

            // Instantiate the generate_text action with required parameters.
            $action = new \core_ai\aiactions\generate_text(
                $contextid,
                $USER->id,
                $finalprompt
            );

            // Optionally attach documents or orgparams if your provider/action supports it.
            // e.g., $action->set_documents($documents); // Pseudo-code, depends on API.

            // Process the action through the Manager.
            $response = $aimanager->process_action($action);
            $data = $response->get_response_data();

            // Debug: if response is null or empty, return error
            if (empty($data)) {
                return [
                    'activities' => [],
                    'template' => 'AI error: The AI service returned an empty response. The service may be unavailable or not configured.'
                ];
            }

            // Try to decode the provider's generated text as JSON per our schema.
            // Check multiple possible response keys - generatedcontent takes priority for OpenAI
            $text = $data['generatedcontent'] ?? ($data['generatedtext'] ?? ($data['text'] ?? ($data['content'] ?? '')));
            
            // If we got no text at all, return error
            if (empty($text) || !is_string($text)) {
                return [
                    'activities' => [],
                    'template' => 'AI error: The AI service did not return any generated text. Response keys: ' . implode(', ', array_keys($data ?? []))
                ];
            }
            
            // Log token usage and potential truncation
            $prompt_tokens = strlen($finalprompt) / 4; // Rough estimate: 1 token ≈ 4 chars
            $response_tokens = strlen($text) / 4;
            $total_tokens = $prompt_tokens + $response_tokens;
            
            // Check if response looks truncated (ends mid-sentence, incomplete JSON, etc)
            $response_looks_truncated = false;
            if (strlen($text) > 100) {
                // Check for incomplete JSON (missing closing braces)
                $open_braces = substr_count($text, '{') + substr_count($text, '[');
                $close_braces = substr_count($text, '}') + substr_count($text, ']');
                if ($open_braces > $close_braces) {
                    $response_looks_truncated = true;
                }
            }
            
            // Response truncation check
            if ($response_looks_truncated) {
                // WARNING: Response may be truncated due to token limits
            }
            
            $jsondecoded = null;
            if (is_string($text)) {
                // First attempt: direct JSON decode.
                $jsondecoded = json_decode($text, true);
                
                // If decode failed, check why
                if (!is_array($jsondecoded)) {
                    $jsonError = json_last_error_msg();
                    // Don't report every JSON error, just continue to next attempt
                }
                
                // Second attempt: extract a JSON object/array from the text if provider added commentary.
                if (!is_array($jsondecoded)) {
                    // Try to find JSON wrapped in code blocks or quoted strings
                    if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $text, $m)) {
                        $jsondecoded = json_decode($m[1], true);
                    } elseif (preg_match('/(\{.*\}|\[.*\])/s', $text, $m)) {
                        $jsondecoded = json_decode($m[1], true);
                    }
                }
                
                // Third attempt: if still not decoded, try unescaping the entire response first
                if (!is_array($jsondecoded)) {
                    $unescaped = self::unescape_json_string($text);
                    if ($unescaped !== $text) {
                        $jsondecoded = json_decode($unescaped, true);
                    }
                }
            }

            // Fourth attempt: After successful decode, deeply unescape any stringified JSON within the structure
            // This catches cases like {"themes": [{"summary": "{\"themes\": [...]}"}]}
            if (is_array($jsondecoded)) {
                $jsondecoded = self::deep_unescape_stringified_json($jsondecoded);
            }

            // Log double-encoding detection
            $is_double_encoded = false;
            if (is_array($jsondecoded) && count($jsondecoded) === 1) {
                $first_val = reset($jsondecoded);
                if (is_string($first_val) && (strpos($first_val, '{"themes"') !== false || strpos($first_val, '{"sections"') !== false)) {
                    $is_double_encoded = true;
                }
            }

            // Attempt to normalise nested/stringified JSON that may be embedded in string fields.
            if (is_array($jsondecoded)) {
                $jsondecoded = self::normalize_ai_response($jsondecoded, true);
                
                // Extract stringified JSON from summary fields that looks like full content
                $jsondecoded = self::extract_misplaced_content_from_summaries($jsondecoded);
                
                // DEBUG: Log the response
                file_put_contents('/tmp/modgen_ai_response.json', json_encode($jsondecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), FILE_APPEND);
            }

            if (is_array($jsondecoded) && (isset($jsondecoded['sections']) || isset($jsondecoded['themes']) || isset($jsondecoded['activities']))) {
                // If theme structure is requested but we have sections, attempt conversion
                if ($structure === 'theme' && isset($jsondecoded['sections']) && !isset($jsondecoded['themes'])) {
                    $jsondecoded = self::convert_sections_to_themes($jsondecoded);
                }

                // Validate the structure to catch malformed responses
                $validation = self::validate_module_structure($jsondecoded, $structure);

                if (!$validation['valid']) {
                    // Return error response that will prevent approval
                    return [
                        $structure === 'theme' ? 'themes' : 'sections' => [],
                        'validation_error' => $validation['error'],
                        'template' => 'AI error: ' . $validation['error'],
                        'raw' => $text,
                        'debugprompt' => $finalprompt,
                        'debugresponse' => $data
                    ];
                }

                // Provider adhered to format. Attach raw text and prompt for visibility.
                $jsondecoded['raw'] = $text;
                $jsondecoded['debugprompt'] = $finalprompt;
                $jsondecoded['debugresponse'] = $data;

                return $jsondecoded;
            }

            // Debug: JSON decode failed or invalid structure
            // For theme structure, attempt to convert sections to themes before falling back
            if ($structure === 'theme' && is_array($jsondecoded) && isset($jsondecoded['sections'])) {
                $jsondecoded = self::convert_sections_to_themes($jsondecoded);
                
                // Validate the converted structure
                $validation = self::validate_module_structure($jsondecoded, $structure);
                if ($validation['valid']) {
                    $jsondecoded['raw'] = $text;
                    $jsondecoded['debugprompt'] = $finalprompt;
                    $jsondecoded['debugresponse'] = $data;
                    return $jsondecoded;
                }
            }

            // Last resort: wrap generated text into a label
            // For theme structure, still wrap as themes but note this is fallback
            $revised = $data['revisedprompt'] ?? '';
            return [
                $structure === 'theme' ? 'themes' : 'sections' => [
                    ['title' => get_string('aigensummary', 'aiplacement_modgen'), 'summary' => $text ?: ''],
                ],
                'template' => $revised ?: 'Generated via AI subsystem (non-JSON response)',
                'raw' => $text,
                'debugprompt' => $finalprompt,
                'debugresponse' => $data
            ];
        } catch (\Throwable $e) {
            // Fallback: return error info in template
            return [
                'activities' => [],
                'template' => 'AI error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate module content using a curriculum template.
     *
     * @param string $prompt User prompt
     * @param array $template_data Template data structure
     * @param array $documents Supporting documents
     * @param string $structure Module structure
     * @param bool $includeactivities Whether to request activities (default: true)
     * @param bool $includesessions Whether to request session instructions (default: true)
     * @return array Response from AI service
     */
    public static function generate_module_with_template($prompt, $template_data, $documents = [], $structure = 'weekly', $courseid = null, $includeactivities = true, $includesessions = true) {
        return self::generate_module($prompt, $documents, $structure, $template_data, $courseid, $includeactivities, $includesessions);
    }

    /**
     * Build guidance text about the template for the AI
     *
     * @param array $template_data Template data containing structure and activities
     * @return string Guidance about the template with simplified structure
     */
    /**
     * Build template guidance for the AI based on extracted module structure.
     *
     * @param array $template_data Extracted template data
     * @param string $structure 'theme' or 'weekly'
     * @return string Guidance text for AI prompt
     */
    private static function build_template_prompt_guidance($template_data, $structure = 'weekly') {
        $guidance = "";
        
        // Detect multiple modules
        $is_multiple = !empty($template_data['module_count']) && $template_data['module_count'] > 1;
        
        // Create compact structure representation
        $compact = self::create_compact_template_structure($template_data);
        
        // Mode-specific instructions
        if ($structure === 'theme') {
            // Count the actual weeks in the template
            $template_week_count = 0;
            if (!empty($compact['sections']) && is_array($compact['sections'])) {
                $template_week_count = count($compact['sections']);
            }
            
            // THEME MODE: AI should analyze and reorganize into themes
            $guidance .= "\n*** TEMPLATE-BASED THEME GENERATION ***\n";
            $guidance .= "You are converting content into a thematic structure based on this template:\n\n";
            $guidance .= json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            $guidance .= "UNDERSTANDING THE TEMPLATE:\n";
            $guidance .= "- The 'organizational_pattern' shows how content was organized in the original (for context only)\n";
            $guidance .= "- The 'label_sequence' shows headings like 'Learning Resources', 'Activities', 'Assessment'\n";
            $guidance .= "- Use these labels to UNDERSTAND what type of content each activity represents\n";
            $guidance .= "- DO NOT recreate these labels in your output\n\n";
            
            if ($template_week_count > 0) {
                $guidance .= "*** MANDATORY WEEK COUNT ***\n";
                $guidance .= "The template has EXACTLY {$template_week_count} sections/weeks.\n";
                $guidance .= "Your output MUST contain EXACTLY {$template_week_count} weeks (no more, no less).\n";
                $guidance .= "EVERY template section MUST map to EXACTLY ONE output week.\n";
                $guidance .= "This is NON-NEGOTIABLE. Failure to include all {$template_week_count} weeks is an error.\n\n";
            }
            
            $guidance .= "YOUR TASK:\n";
            $guidance .= "1. Count the template sections: {$template_week_count} sections = {$template_week_count} output weeks required\n";
            $guidance .= "2. Group these {$template_week_count} weeks into natural thematic clusters (e.g., 3 themes × 3-4 weeks each, or 5 themes × 2 weeks each)\n";
            $guidance .= "3. Create descriptive theme titles that reflect each group's content\n";
            $guidance .= "4. CRITICAL - DIRECT 1-to-1 WEEK MAPPING (NON-NEGOTIABLE):\n";
            $guidance .= "   - Template section 1 → Output week 1 (within appropriate theme)\n";
            $guidance .= "   - Template section 2 → Output week 2 (within appropriate theme)\n";
            $guidance .= "   - Template section N → Output week N (within appropriate theme)\n";
            $guidance .= "   - TOTAL OUTPUT WEEKS MUST EQUAL {$template_week_count}\n";
            $guidance .= "   - Each template week's content goes into ONE output week's pre/session/post structure\n";
            $guidance .= "   - NEVER skip a template week, NEVER combine multiple weeks, NEVER split one week\n";
            $guidance .= "   - Structure: Theme → Week (with date) → Pre-session/Session/Post-session\n";
            $guidance .= "5. WEEK TITLES - Preserve original names:\n";
            $guidance .= "   - Each output week title MUST include BOTH the date AND the original section name\n";
            $guidance .= "   - Format: \"Week N (date range): Original Section Title\"\n";
            $guidance .= "   - Example: If template section is \"Introduction to the course\", output title is \"Week 1 (Oct 18 - 24): Introduction to the course\"\n";
            $guidance .= "   - Example: If template section is \"Fun in the sun\", output title is \"Week 2 (Oct 25 - 31): Fun in the sun\"\n";
            $guidance .= "   - Use the EXACT original section title from the template - do not modify or paraphrase it\n";
            $guidance .= "6. Map activities from EACH template week into that week's three subsections:\n";
            $guidance .= "   - Activities under 'Learning Resources' labels → Pre-session (reading/preparation)\n";
            $guidance .= "   - Activities under 'Activities' labels → Session (main activities)\n";
            $guidance .= "   - Activities under 'Assessment' labels → Post-session (assignments/reflection)\n";
            $guidance .= "7. If session instructions are requested, generate appropriate descriptions for pre/session/post sections\n\n";
        } else {
            // Count the actual weeks in the template
            $template_week_count = 0;
            if (!empty($compact['sections']) && is_array($compact['sections'])) {
                $template_week_count = count($compact['sections']);
            }
            
            // WEEKLY/CONNECTED MODE: AI should preserve structure
            $guidance .= "\n*** TEMPLATE-BASED WEEKLY GENERATION ***\n";
            $guidance .= "You are recreating content in weekly structure based on this template:\n\n";
            $guidance .= json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            $guidance .= "UNDERSTANDING THE TEMPLATE:\n";
            $guidance .= "- The 'organizational_pattern' shows the original content organization\n";
            $guidance .= "- The 'label_sequence' shows headings used to organize activities\n";
            $guidance .= "- Use these to understand content structure and types\n\n";
            
            if ($template_week_count > 0) {
                $guidance .= "*** MANDATORY SECTION COUNT ***\n";
                $guidance .= "The template has EXACTLY {$template_week_count} sections.\n";
                $guidance .= "Your output MUST contain EXACTLY {$template_week_count} sections (no more, no less).\n";
                $guidance .= "EVERY template section MUST map to EXACTLY ONE output section.\n";
                $guidance .= "This is NON-NEGOTIABLE. Failure to include all {$template_week_count} sections is an error.\n\n";
            }
            
            $guidance .= "YOUR TASK:\n";
            $guidance .= "1. Count the template sections: {$template_week_count} sections = {$template_week_count} output sections required\n";
            $guidance .= "2. CRITICAL - DIRECT 1-to-1 SECTION MAPPING (NON-NEGOTIABLE):\n";
            $guidance .= "   - Template section 1 → Output section 1\n";
            $guidance .= "   - Template section 2 → Output section 2\n";
            $guidance .= "   - Template section N → Output section N\n";
            $guidance .= "   - TOTAL OUTPUT SECTIONS MUST EQUAL {$template_week_count}\n";
            $guidance .= "   - NEVER skip a template section, NEVER combine multiple sections, NEVER split one section\n";
            $guidance .= "3. SECTION TITLES - Preserve original names:\n";
            $guidance .= "   - Each output section title MUST include the original section name\n";
            $guidance .= "   - Format: \"Week N (date range): Original Section Title\"\n";
            $guidance .= "   - Example: If template section is \"Introduction to the course\", output title is \"Week 1 (Oct 18 - 24): Introduction to the course\"\n";
            $guidance .= "   - Example: If template section is \"Fun in the sun\", output title is \"Week 2 (Oct 25 - 31): Fun in the sun\"\n";
            $guidance .= "   - Use the EXACT original section title from the template - do not modify or paraphrase it\n";
            $guidance .= "4. SECTION STRUCTURE - Each section has three subsections:\n";
            $guidance .= "   - Create 'presession', 'session', and 'postsession' subsections for EACH week\n";
            $guidance .= "   - Map template activities based on their pedagogical purpose:\n";
            $guidance .= "     * 'Learning Resources' → Pre-session (reading/preparation)\n";
            $guidance .= "     * 'Activities' → Session (main activities)\n";
            $guidance .= "     * 'Assessment' → Post-session (assignments/reflection)\n";
            $guidance .= "5. If session instructions are requested, generate appropriate descriptions for pre/session/post sections\n";
            $guidance .= "6. Use Moodle's flexible sections format for output\n\n";
        }
        
        // Output field instruction
        $guidance .= "*** OUTPUT 'template' FIELD ***\n";
        if ($is_multiple) {
            $guidance .= "Set 'template' value to: \"Combining {$template_data['module_count']} existing modules\"\n";
        } else {
            $guidance .= "Set 'template' value to: \"Based on existing module template\"\n";
        }
        $guidance .= "\nDo NOT include this template metadata in theme/section summaries.\n";
        
        return $guidance;
    }
    
    /**
     * Create compact template structure for AI consumption.
     *
     * @param array $template_data Raw extracted template data
     * @return array Compact structure with organizational patterns
     */
    public static function create_compact_template_structure($template_data) {
        $compact = [
            'source' => !empty($template_data['module_count']) && $template_data['module_count'] > 1 
                ? 'multiple_modules' 
                : 'single_module',
            'organizational_pattern' => self::extract_organizational_pattern($template_data),
            'sections' => []
        ];
        
        // Process each section from the structure
        if (!empty($template_data['structure']) && is_array($template_data['structure'])) {
            foreach ($template_data['structure'] as $section) {
                $section_data = [
                    'number' => $section['id'] ?? 0,
                    'title' => $section['name'] ?? 'Untitled',
                    'content' => []
                ];
                
                // Add section summary as initial context if present
                if (!empty($section['summary'])) {
                    $section_data['summary'] = substr($section['summary'], 0, 200); // Truncate for tokens
                }
                
                // Find activities for this section
                if (!empty($template_data['activities']) && is_array($template_data['activities'])) {
                    foreach ($template_data['activities'] as $activity) {
                        // Match activities to this section by section name
                        if (isset($activity['section']) && $activity['section'] === $section_data['title']) {
                            $activity_item = [
                                'type' => $activity['type'] ?? 'unknown'
                            ];
                            
                            // For labels, include the intro (these are headings)
                            if ($activity['type'] === 'label' && !empty($activity['intro'])) {
                                $activity_item['text'] = $activity['intro'];
                            } else {
                                // For other activities, just the name
                                $activity_item['name'] = $activity['name'] ?? 'Untitled';
                            }
                            
                            $section_data['content'][] = $activity_item;
                        }
                    }
                }
                
                $compact['sections'][] = $section_data;
            }
        }
        
        return $compact;
    }
    
    /**
     * Extract organizational patterns from template data.
     *
     * @param array $template_data Template data
     * @return array Patterns (label sequence, typical activity count, etc.)
     */
    private static function extract_organizational_pattern($template_data) {
        $pattern = [
            'label_sequence' => [],
            'activity_types_used' => [],
            'typical_activities_per_section' => 0
        ];
        
        if (empty($template_data['activities']) || !is_array($template_data['activities'])) {
            return $pattern;
        }
        
        $label_sequence = [];
        $activity_types = [];
        $section_counts = [];
        $current_section = null;
        
        foreach ($template_data['activities'] as $activity) {
            $type = $activity['type'] ?? 'unknown';
            
            // Track section for counting
            $section = $activity['section'] ?? 'unknown';
            if (!isset($section_counts[$section])) {
                $section_counts[$section] = 0;
            }
            $section_counts[$section]++;
            
            // Extract label sequence from first occurrence
            if ($type === 'label' && !empty($activity['intro'])) {
                if (!in_array($activity['intro'], $label_sequence)) {
                    $label_sequence[] = $activity['intro'];
                }
            }
            
            // Track activity types
            if (!in_array($type, $activity_types)) {
                $activity_types[] = $type;
            }
        }
        
        $pattern['label_sequence'] = $label_sequence;
        $pattern['activity_types_used'] = $activity_types;
        
        // Calculate average activities per section
        if (!empty($section_counts)) {
            $pattern['typical_activities_per_section'] = (int) round(array_sum($section_counts) / count($section_counts));
        }
        
        return $pattern;
    }

    /**
     * Analyze a module using a custom prompt.
     *
     * @param string $prompt The analysis prompt
     * @return string Analysis text
     */
    public static function analyze_module(string $prompt): string {
        global $USER, $COURSE;

        try {
            if (!class_exists('\\core_ai\\manager') || !class_exists('\\core_ai\\aiactions\\generate_text')) {
                return '';
            }

            $contextid = !empty($COURSE->id)
                ? \context_course::instance($COURSE->id)->id
                : \context_system::instance()->id;

            $aimanager = new \core_ai\manager();
            if (!$aimanager->get_user_policy_status($USER->id)) {
                return '';
            }

            $action = new \core_ai\aiactions\generate_text(
                $contextid,
                $USER->id,
                $prompt
            );

            $response = $aimanager->process_action($action);
            $data = $response->get_response_data();
            $text = $data['generatedtext'] ?? ($data['generatedcontent'] ?? '');
            
            if (is_string($text)) {
                return trim($text);
            }
            return '';
        } catch (\Throwable $e) {
            // AI analysis error occurred
            return '';
        }
    }

    /**
     * Generate suggestion items for a given section map.
     *
     * Accepts a compact map of sections and returns an array of suggestion objects:
     * [ { id: string, activity: {type, name}, rationale: string, supported: bool }, ... ]
     *
     * @param array $sectionmap
     * @param int|null $courseid
     * @return array
     */
    public static function generate_suggestions_from_map(array $sectionmap, $courseid = null): array {
        global $USER;

        try {
            if (!class_exists('\core_ai\manager') || !class_exists('\core_ai\aiactions\generate_text')) {
                return ['success' => false, 'error' => 'AI subsystem not available'];
            }

            $contextid = !empty($courseid)
                ? \context_course::instance($courseid)->id
                : \context_system::instance()->id;

            $aimanager = new \core_ai\manager();
            if (!$aimanager->get_user_policy_status($USER->id)) {
                return ['success' => false, 'error' => 'AI policy not accepted'];
            }

            // Determine supported activity types from the registry and build a compact
            // prompt describing the selected section and surrounding context.
            $supported = registry::get_supported_activity_metadata();
            $allowedtypes = array_keys($supported);
            // Build a mapping of normalized labels to canonical type keys to help
            // match AI-returned free-text types back to supported keys.
            $normmap = [];
            foreach ($allowedtypes as $t) {
                $label = (string)($supported[$t]['description'] ?? $t);
                $stringid = (string)($supported[$t]['stringid'] ?? '');
                $normforms = [];
                $normforms[] = preg_replace('/[^a-z0-9]+/', '', \core_text::strtolower($t));
                if ($stringid !== '') {
                    $normforms[] = preg_replace('/[^a-z0-9]+/', '', \core_text::strtolower($stringid));
                }
                if ($label !== '') {
                    $normforms[] = preg_replace('/[^a-z0-9]+/', '', \core_text::strtolower($label));
                }
                foreach ($normforms as $nf) {
                    if ($nf === '') {
                        continue;
                    }
                    $normmap[$nf] = $t;
                }
            }

            // Build a compact prompt describing the selected section and surrounding context.
            $sectionsummary = '';
            foreach ($sectionmap as $s) {
                $idx = $s['section'] ?? ($s['id'] ?? '');
                $title = $s['name'] ?? ($s['title'] ?? '');
                $summary = isset($s['summary']) ? substr($s['summary'], 0, 1000) : '';
                $sectionsummary .= "Section: {$idx} - {$title}\nSummary: {$summary}\n\n";
            }

            $prompt = "You are an expert learning designer. Given course section context below, propose up to 6 suggested Moodle activities for the selected section. RETURN ONLY a JSON array of suggestion objects with keys: id (string), activity: {type: '<one of the allowed activity type keys>', name: '<activity name>'}, rationale: '<brief pedagogical rationale>', supported: true|false. Use only the allowed activity type keys listed below in the activity.type field — do NOT invent new types. Do NOT include any other commentary.\n\nAllowed activity types (key => description):\n";
            foreach ($allowedtypes as $t) {
                $prompt .= "- {$t} => " . ($supported[$t]['description'] ?? '') . "\n";
            }
            $prompt .= "\nContext:\n\n";
            $prompt .= $sectionsummary;

            $action = new \core_ai\aiactions\generate_text($contextid, $USER->id, $prompt);
            $response = $aimanager->process_action($action);
            $data = $response->get_response_data();
            $text = $data['generatedcontent'] ?? ($data['generatedtext'] ?? ($data['text'] ?? ''));

            if (empty($text) || !is_string($text)) {
                return ['success' => false, 'error' => 'AI returned no text'];
            }

            // Try to decode JSON from the response
            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                // Try to extract JSON block from code fences or inline
                if (preg_match('/```(?:json)?\s*(\[.*\])\s*```/s', $text, $m)) {
                    $decoded = json_decode($m[1], true);
                } elseif (preg_match('/(\[\s*\{.*\}\s*\])/s', $text, $m2)) {
                    $decoded = json_decode($m2[1], true);
                }
            }

            if (!is_array($decoded)) {
                return ['success' => false, 'error' => 'Unable to parse AI suggestions', 'raw' => $text];
            }

            // Normalize suggestions to expected shape and restrict to supported types
            $out = [];
            foreach ($decoded as $i => $s) {
                if (!is_array($s)) {
                    continue;
                }
                $id = isset($s['id']) ? (string)$s['id'] : (string)($i + 1);
                $activity = $s['activity'] ?? [];
                $rationale = $s['rationale'] ?? ($s['reason'] ?? '');
                $supported = isset($s['supported']) ? (bool)$s['supported'] : true;
                // Attempt to normalise the returned type and match it to a supported key.
                $rawtype = (string)($activity['type'] ?? ($activity['activity'] ?? ''));
                $cand = preg_replace('/[^a-z0-9]+/', '', \core_text::strtolower($rawtype));
                $matched = $normmap[$cand] ?? null;
                if ($matched === null) {
                    // If AI returned a human label that matches a description/stringid,
                    // try matching by normalised content against the normmap.
                    foreach ($normmap as $nf => $key) {
                        if ($nf === $cand) {
                            $matched = $key;
                            break;
                        }
                    }
                }

                // If still no canonical match was found, keep the suggestion but mark it unsupported.
                $is_supported = true;
                $type_to_use = $matched;
                if ($matched === null || $matched === '') {
                    $is_supported = false;
                    // Preserve the raw type string so the UI can show and allow edits.
                    $type_to_use = $rawtype ?: '';
                }

                $out[] = [
                    'id' => $id,
                    'activity' => (object)[
                        'type' => $type_to_use,
                        'name' => $activity['name'] ?? ($activity['title'] ?? 'Suggested Activity')
                    ],
                    'rationale' => $rationale,
                    'supported' => $is_supported,
                    'raw_type' => $rawtype,
                ];
            }

            return ['success' => true, 'suggestions' => $out, 'raw' => $text];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

