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
            // Reasonable range: between 2 and 12 themes
            if ($count >= 2 && $count <= 12) {
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
        ];
        
        $result = $str;
        foreach ($replacements as $pattern => $replacement) {
            $result = str_replace($pattern, $replacement, $result);
        }
        
        return $result;
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
        
        // Debug: Log template data status
        error_log('DEBUG: generate_module called with template_data: ' . (empty($template_data) ? 'EMPTY' : 'PRESENT (' . count((array)$template_data) . ' keys)'));
        if (!empty($template_data) && is_array($template_data)) {
            error_log('DEBUG: template_data keys: ' . implode(', ', array_keys($template_data)));
            error_log('DEBUG: template_data[structure] type: ' . gettype($template_data['structure'] ?? null) . ' count: ' . count((array)($template_data['structure'] ?? [])));
            error_log('DEBUG: template_data[activities] type: ' . gettype($template_data['activities'] ?? null) . ' count: ' . count((array)($template_data['activities'] ?? [])));
        }
        
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
                $pedagogicalguidance = "You are an expert Moodle learning content designer at a UK higher education institution designing a Moodle module for the user's input using activities appropriate for UK HE.";
            }
            
            // Build compact roleinstruction without redundancy
            // Customize based on whether we're working with template data (existing module) or file content
            $istemplate = !empty($template_data);
            $source_label = $istemplate ? 'the existing module structure provided' : 'the curriculum file';
            
            $roleinstruction = $pedagogicalguidance . "\n\n" .
                "CRITICAL REQUIREMENTS:\n" .
                "1. Return ONLY valid JSON at the top level ({\"themes\": [...]} or {\"sections\": [...]}). No commentary, code blocks, or wrapping.\n" .
                "2. Include ALL content from " . $source_label . " - do NOT truncate, omit, or use placeholder text.\n" .
                "3. Every field must contain actual content from the source (no 'Week X', 'Theme Name', '...', etc).\n" .
                "4. Week and theme titles MUST be descriptive of the content - include the topic/concept being taught. Examples: 'Week 1: Introduction to Cloud Computing' or 'Theme: Data Analysis Fundamentals'. Never use generic titles like 'Week 1' or 'Week 1 (Jan 6-12, 2025)' alone.\n" .
                "5. Use ONLY the supported activity types provided in the list below. Unsupported types will cause creation to FAIL.\n\n";

            // Add file parsing and theme instructions only for theme structure
            if ($structure === 'theme') {
                // Check if user specified a requested theme count
                $requestedthemecount = self::extract_requested_theme_count($prompt, $structure);
                
                if (!empty($requestedthemecount)) {
                    // User specified a specific number of themes - use that as the OVERRIDE
                    $roleinstruction .= "GENERATE THEMED STRUCTURE:\n" .
                        "*** USER SPECIFIED: Generate EXACTLY {$requestedthemecount} themes - this is a HARD REQUIREMENT ***\n" .
                        "Divide ALL topics into {$requestedthemecount} coherent theme groups (typically 2-4 weeks per theme).\n" .
                        "- Do NOT generate more or fewer than {$requestedthemecount} themes\n" .
                        "- This overrides any other guidance about theme count (like 'typically 3-6')\n" .
                        "- Theme titles MUST describe the actual content/topic - NEVER use generic names like 'Theme 1', 'Theme 2', 'Module 1', etc.\n" .
                        "- CRITICAL: Each theme must have a descriptive title (e.g., 'Theme: Data Analysis Fundamentals', 'Theme: Cloud Computing Architecture', 'Theme: User Interface Design')\n" .
                        "- NEVER output: 'Theme 1', 'Theme 2', 'Theme 3', 'Module 1', 'Part 1', or any generic numbered theme titles\n" .
                        "- Week titles must include topic/concept (e.g., 'Week 1: Data Structures and Types')\n" .
                        "- Verify every topic from source appears in at least one week\n" .
                        "- Theme summary: 2-3 sentence student introduction\n" .
                        "- Week summary: brief overview of learning outcomes\n" .
                        "- Distribute activities across pre-session/session/post-session appropriately\n\n";
                } else {
                    // No specific count requested - use flexible guidance
                    $roleinstruction .= "GENERATE THEMED STRUCTURE:\n" .
                        "Determine appropriate theme count (typically 3-6) based on source topics. Group all topics into coherent themes (2-4 weeks per theme).\n" .
                        "- Theme titles MUST describe the actual content/topic - NEVER use generic names like 'Theme 1', 'Theme 2', 'Module 1', etc.\n" .
                        "- CRITICAL: Each theme must have a descriptive title (e.g., 'Theme: Database Design Principles', 'Theme: Network Security', 'Theme: Software Testing Methodology')\n" .
                        "- NEVER output: 'Theme 1', 'Theme 2', 'Theme 3', 'Module 1', 'Part 1', or any generic numbered theme titles\n" .
                        "- Week titles must include topic/concept (e.g., 'Week 2: Normalization and Schema Design')\n" .
                        "- Verify every topic from source appears in at least one week\n" .
                        "- Theme summary: 2-3 sentence student introduction\n" .
                        "- Week summary: brief overview of learning outcomes\n" .
                        "- Distribute activities across pre-session/session/post-session appropriately\n\n";
                }
            } else {
                $roleinstruction .= "GENERATE WEEKLY STRUCTURE:\n" .
                    "Create one section for each major topic. Section titles MUST describe the topic/concept (e.g., 'Week 1: Introduction to Cloud Computing', not 'Week 1').\n" .
                    "Include outline array with 3-5 key points, relevant activities, and brief summary.\n" .
                    "Include ALL content from source - do NOT skip any topics.\n\n";
            }


            $activitymetadata = registry::get_supported_activity_metadata();
            $supportedactivitytypes = array_keys($activitymetadata);

            // Build concise format instructions - minimal example, repeat pattern for all content
            if ($structure === 'theme') {
                if ($includesessions) {
                    $formatinstruction = "JSON OUTPUT STRUCTURE (Theme-Based):\n" .
                        "{\"themes\": [\n" .
                        "  {\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [\n" .
                        "    {\"title\": \"Week N\", \"summary\": \"Overview\", \"sessions\": {\n" .
                        "      \"presession\": {\"description\": \"Student instructions for pre-session\", \"activities\": [{\"type\": \"url\", \"name\": \"Activity\"}]},\n" .
                        "      \"session\": {\"description\": \"Student instructions for session\", \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]},\n" .
                        "      \"postsession\": {\"description\": \"Student instructions for post-session\", \"activities\": [{\"type\": \"forum\", \"name\": \"Activity\"}]}\n" .
                        "    }}\n" .
                        "  ]}\n" .
                        "]}\n" .
                        "IMPORTANT: Generate ALL themes needed to cover ALL topics in the curriculum.\n" .
                        "IMPORTANT: Each theme must have multiple weeks (at least 2-3 weeks minimum).\n" .
                        "IMPORTANT: Include EVERY topic from the file - do not skip or leave out any content.\n" .
                        "IMPORTANT: Do not truncate - continue until all themes and all weeks are complete.\n";
                } else {
                    $formatinstruction = "JSON OUTPUT STRUCTURE (Theme-Based):\n" .
                        "{\"themes\": [\n" .
                        "  {\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [\n" .
                        "    {\"title\": \"Week N\", \"summary\": \"Overview\", \"outline\": [\"key point 1\", \"key point 2\"]}\n" .
                        "  ]}\n" .
                        "]}\n" .
                        "IMPORTANT: Generate ALL themes needed to cover ALL topics in the curriculum.\n" .
                        "IMPORTANT: Each theme must have multiple weeks (at least 2-3 weeks minimum).\n" .
                        "IMPORTANT: Include EVERY topic from the file - do not skip or leave out any content.\n" .
                        "IMPORTANT: Do not truncate - continue until all themes and all weeks are complete.\n" .
                        "IMPORTANT: Do NOT include 'sessions' object or 'activities' arrays. Only include week titles, summaries, and outlines.\n";
                }
            } else {
                $formatinstruction = "JSON OUTPUT STRUCTURE (Weekly):\n" .
                    "{\"sections\": [\n" .
                    "  {\"title\": \"Week N\", \"summary\": \"Overview\", \"outline\": [\"key 1\", \"key 2\"], \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]}\n" .
                    "]}\n" .
                    "IMPORTANT: Repeat this structure for EVERY week in the curriculum.\n" .
                    "IMPORTANT: Include ALL weeks - do not truncate.\n";
            }

            if (!empty($activitymetadata) && $includeactivities) {
                $formatinstruction .= "\n\nSUPPORTED ACTIVITY TYPES (use ONLY these):\n";
                foreach ($activitymetadata as $type => $metadata) {
                    $label = get_string($metadata['stringid'], 'aiplacement_modgen');
                    $formatinstruction .= "- {$type}: {$metadata['description']}\n";
                }
                $formatinstruction .= "\nAny unsupported types (resource, scorm, choice, etc.) will cause generation to FAIL. Use closest equivalent if needed.\n";
                
                // Add detailed field specifications for each activity type
                $formatinstruction .= "\nACTIVITY FIELD SPECIFICATIONS:\n" .
                    "For EACH activity in the activities array, include these fields:\n" .
                    "- type: (required) The activity type from the list above\n" .
                    "- name: (required) Descriptive title of the activity\n" .
                    "- intro: (optional) Description or context for the activity\n" .
                    "- url: (required for 'url' type only) Full URL starting with http:// or https:// (e.g., \"https://example.com/article\")\n" .
                    "- chapters: (required for 'book' type only) Array of chapter objects with 'title' and 'content' fields\n" .
                    "\nEXAMPLE FORMATS:\n" .
                    "{\"type\": \"url\", \"name\": \"Read Article on Topic X\", \"intro\": \"Background material\", \"url\": \"https://example.com/article\"}\n" .
                    "{\"type\": \"quiz\", \"name\": \"Knowledge Check\", \"intro\": \"Test your understanding\"}\n" .
                    "{\"type\": \"forum\", \"name\": \"Discussion: Topic X\", \"intro\": \"Share your thoughts\"}\n" .
                    "{\"type\": \"book\", \"name\": \"Learning Guide\", \"chapters\": [{\"title\": \"Chapter 1\", \"content\": \"...\"}, {\"title\": \"Chapter 2\", \"content\": \"...\"}]}\n" .
                    "{\"type\": \"label\", \"name\": \"Important Note\", \"intro\": \"Information for students\"}\n";
            } elseif ($includeactivities === false) {
                // Explicitly tell AI not to include activities to save tokens
                $formatinstruction .= "\n\nIMPORTANT: Do NOT include 'activities' arrays in the response. Only include themes/sections with titles and summaries. Omitting activities will save processing tokens.\n";
            }

            // Add week date guidance if courseid is provided
            $weekdateguidance = '';
            if (!empty($courseid)) {
                // Generate example dates for first few weeks
                $exampledate1 = self::get_week_date_range(1, $courseid);
                $exampledate2 = self::get_week_date_range(2, $courseid);
                $exampledate3 = self::get_week_date_range(3, $courseid);
                
                $weekdateguidance = "\n\nWEEK DATES (Based on Course Start Date):\n" .
                    "The course has a start date set in Moodle. Each week is 7 days from the previous week.\n" .
                    "Include the week date range in each week's title using this format:\n" .
                    "Instead of: \"Week 1\"\n" .
                    "Use: \"Week 1 ({$exampledate1})\"\n" .
                    "Then: \"Week 2 ({$exampledate2})\"\n" .
                    "Then: \"Week 3 ({$exampledate3})\"\n" .
                    "And so on for each subsequent week.\n" .
                    "IMPORTANT: Use the exact date format shown above (e.g., \"Jan 6 - Jan 12, 2025\").\n" .
                    "IMPORTANT: Each week is exactly 7 days after the previous week.\n";
            }
            
            $formatinstruction .= $weekdateguidance;


            // Add template guidance if template data is provided
            $template_guidance = '';
            if (!empty($template_data) && is_array($template_data)) {
                $template_guidance = self::build_template_prompt_guidance($template_data);
            } else {
                // Even if template_data is empty, note that we're supposed to be using template mode
                if ($template_data === null) {
                    // No template data at all
                } else {
                    $template_guidance = "NOTE: Template mode activated but template_data is empty or invalid.\n";
                }
            }

            // Incorporate supporting documents with aggressive truncation
            $documents_text = '';
            if (!empty($documents) && is_array($documents)) {
                $documents_text .= "\nFILE CONTENT:\n";
                foreach ($documents as $doc) {
                    $dname = isset($doc['filename']) ? $doc['filename'] : 'unnamed';
                    $dcontent = isset($doc['content']) ? $doc['content'] : '';
                    // Aggressive truncation: 80k chars max per document to keep prompt lean
                    if (is_string($dcontent) && strlen($dcontent) > 80000) {
                        $dcontent = substr($dcontent, 0, 80000) . "\n[file truncated]";
                    }
                    $documents_text .= "--- {$dname} ---\n";
                    $documents_text .= trim((string)$dcontent) . "\n\n";
                }
            }

            if (empty($roleinstruction) || empty($formatinstruction)) {
                return [
                    'activities' => [],
                    'template' => 'AI error: Prompt construction failed - missing required prompt components'
                ];
            }

            // Build final prompt with emphasis on completeness
            $finalprompt = $roleinstruction . "\n\n" . 
                $documents_text . "\n" .
                "User requirements:\n" . trim($prompt) . "\n\n" .
                $template_guidance . "\n" .
                $formatinstruction . "\n\n";
            
            // Add structure-specific final reminder
            if ($structure === 'theme') {
                $finalreminder = "FINAL REMINDER - THEME STRUCTURE:\n" .
                    "- Generate the COMPLETE module with ALL themes needed\n" .
                    "- Include EVERY topic and subtopic from the source above\n" .
                    "- Each theme MUST contain multiple weeks (at least 2-3 weeks per theme)\n" .
                    "- Every topic from the source MUST appear in at least one week\n" .
                    "- Do NOT stop early, do NOT truncate, do NOT omit content\n";
                
                // If a specific theme count was requested, add it to the final reminder as an explicit requirement
                if (!empty($requestedthemecount)) {
                    $finalreminder .= "*** CRITICAL: You must generate EXACTLY {$requestedthemecount} themes - no more, no fewer ***\n" .
                        "- This is a user requirement that must be honored\n" .
                        "- If you cannot fit all content into {$requestedthemecount} themes, inform the user\n" .
                        "- Do NOT reduce the theme count or generate a different number\n";
                }
                
                $finalreminder .= "- Return ONLY JSON - no other text.\n";
                $finalprompt .= $finalreminder;
            } else {
                $finalprompt .= "FINAL REMINDER - WEEKLY STRUCTURE:\n" .
                    "- Generate the COMPLETE module with all weeks\n" .
                    "- Include EVERY topic from the source above\n" .
                    "- Do NOT stop early, do NOT truncate, do NOT omit content\n" .
                    "- Return ONLY JSON - no other text.\n";
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
                
                // If still not valid, try double-decoding in case JSON was stringified
                if (!is_array($jsondecoded) && is_string($text)) {
                    $doubledecode = json_decode($text, true);
                    if (is_array($doubledecode) && isset($doubledecode['themes'])) {
                        $jsondecoded = $doubledecode;
                    }
                }
            }

            // Attempt to normalise nested/stringified JSON that may be embedded in string fields.
            if (is_array($jsondecoded)) {
                $before = $jsondecoded;
                
                $jsondecoded = self::normalize_ai_response($jsondecoded, true);
                
                // Log if normalisation changed the structure in a meaningful way.
                if (serialize($before) !== serialize($jsondecoded)) {
                }
                
                // DEBUG: Log the response to check for session descriptions
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
    private static function build_template_prompt_guidance($template_data) {
        $guidance = "";
        
        // Add course info
        if (!empty($template_data['course_info'])) {
            $course = $template_data['course_info'];
            $guidance .= "EXISTING MODULE INFORMATION:\n";
            $guidance .= "Module Name: " . (!empty($course['name']) ? $course['name'] : 'Unnamed') . "\n";
            $guidance .= "Format: " . (!empty($course['format']) ? $course['format'] : 'Unknown') . "\n";
            if (!empty($course['summary'])) {
                $guidance .= "Summary: " . substr($course['summary'], 0, 300) . "\n";
            }
            $guidance .= "\n";
        }
        
        // Add structure details
        if (!empty($template_data['structure']) && is_array($template_data['structure'])) {
            $guidance .= "EXISTING SECTION STRUCTURE:\n";
            $sectioncount = 0;
            foreach ($template_data['structure'] as $section) {
                $sectioncount++;
                if (is_array($section)) {
                    $section_name = $section['name'] ?? 'Unnamed Section';
                    $guidance .= "Section {$sectioncount}: " . $section_name . "\n";
                    if (!empty($section['activity_count'])) {
                        $guidance .= "  - Contains " . $section['activity_count'] . " activities\n";
                    }
                    if (!empty($section['summary'])) {
                        $summary_preview = substr($section['summary'], 0, 150);
                        $guidance .= "  - Summary: " . $summary_preview . (strlen($section['summary']) > 150 ? '...' : '') . "\n";
                    }
                }
            }
            $guidance .= "\n";
        }
        
        // Add activities list - these are the ACTUAL activities in the existing module
        if (!empty($template_data['activities']) && is_array($template_data['activities'])) {
            $guidance .= "EXISTING ACTIVITIES IN MODULE:\n";
            foreach ($template_data['activities'] as $activity) {
                if (is_array($activity)) {
                    $type = $activity['type'] ?? 'unknown';
                    $name = $activity['name'] ?? 'Unnamed';
                    $section_name = $activity['section'] ?? 'Unknown Section';
                    $guidance .= "- [{$type}] " . $name . " (in section: " . $section_name . ")\n";
                }
            }
            $guidance .= "\n";
        }
        
        // If we have structure or activities, add the translation task
        if (!empty($template_data['structure']) || !empty($template_data['activities'])) {
            $guidance .= "TASK: You are analyzing an EXISTING Moodle module structure.\n";
            $guidance .= "The sections and activities listed above represent the ACTUAL content of the existing module.\n";
            $guidance .= "Use this content to generate a transformed module structure in the requested format.\n";
            $guidance .= "Preserve all section titles and activity names - do NOT invent new content.\n";
            $guidance .= "Base your output entirely on the existing structure provided above.\n";
        } else {
            // Fallback if we don't have structure or activities data
            $guidance .= "NOTE: Template data provided but no section structure or activities found.\n";
            $guidance .= "Ensure you process the existing module content properly.\n";
        }
        
        return $guidance;
    }

    /**
     * Extract Bootstrap classes from HTML
     *
     * @param string $html HTML content
     * @return array Array of Bootstrap class names found
     */
    private static function extract_bootstrap_classes_from_html($html) {
        $classes = [];
        $pattern = '/class=["\']([^"\']*)/i';
        
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $class_string) {
                $class_list = explode(' ', $class_string);
                foreach ($class_list as $class) {
                    $class = trim($class);
                    if (!empty($class) && self::is_bootstrap_class($class)) {
                        if (!isset($classes[$class])) {
                            $classes[$class] = 0;
                        }
                        $classes[$class]++;
                    }
                }
            }
        }
        
        return array_keys($classes);
    }

    /**
     * Check if a CSS class is a Bootstrap class
     *
     * @param string $class Class name to check
     * @return bool True if it's a Bootstrap class
     */
    private static function is_bootstrap_class($class) {
        $prefixes = [
            'col-', 'row', 'card', 'btn', 'nav', 'tab', 'accordion', 
            'alert', 'badge', 'list', 'grid', 'container', 'flex', 
            'justify', 'align', 'text-', 'bg-', 'border', 'shadow',
            'rounded', 'p-', 'm-', 'ml-', 'mr-', 'mt-', 'mb-',
            'd-', 'w-', 'h-', 'gap-', 'ms-', 'me-', 'ps-', 'pe-',
            'modal', 'form', 'input', 'label', 'dropdown', 'button'
        ];
        
        foreach ($prefixes as $prefix) {
            if (strpos($class, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Build guidance text about Bootstrap components used in the template.
     * This helps the AI understand visual/structural patterns to replicate.
     *
     * @param array $template_data Template data
     * @return string Guidance text about Bootstrap usage
     */
    private static function build_bootstrap_guidance($template_data) {
        // Check if template data contains Bootstrap structure hints
        $guidance = "";

        // Log what we're receiving
        error_log('Bootstrap structure data: ' . print_r($template_data['bootstrap_structure'] ?? 'NOT FOUND', true));

        if (!empty($template_data['bootstrap_structure']['components'])) {
            $components = $template_data['bootstrap_structure']['components'];
            error_log('Found Bootstrap components: ' . implode(', ', $components));
            
            $guidance = "TEMPLATE VISUAL STRUCTURE:\n";
            $guidance .= "The template uses the following Bootstrap HTML components to structure content:\n";
            $guidance .= "- " . implode("\n- ", $components) . "\n\n";
            $guidance .= "GUIDANCE: When creating section summaries and activity descriptions, include similar Bootstrap HTML patterns. ";
            $guidance .= "For example:\n";

            if (in_array('Bootstrap tabs', $components)) {
                $guidance .= "- Use <div class=\"nav nav-tabs\"> and <div class=\"tab-content\"> markup for tabbed content\n";
            }
            if (in_array('Bootstrap cards', $components)) {
                $guidance .= "- Use <div class=\"card\"><div class=\"card-body\"> markup for content blocks\n";
            }
            if (in_array('Bootstrap accordion', $components)) {
                $guidance .= "- Use <div class=\"accordion\"> markup for expandable sections\n";
            }
            if (in_array('Bootstrap grid layout', $components)) {
                $guidance .= "- Use <div class=\"row\"><div class=\"col-md-6\"> classes for responsive column layouts\n";
            }
            $guidance .= "\nIMPORTANT: Include the actual HTML/CSS markup in your section summaries to match these patterns.\n\n";
        } else {
            $guidance = "TEMPLATE VISUAL STRUCTURE: Standard Moodle layout without special Bootstrap components.\n\n";
            error_log('No Bootstrap components found in template');
        }

        error_log('Bootstrap guidance: ' . $guidance);
        return $guidance;
    }

    /**
     * Build guidance about HTML structure with placeholders for the AI
     *
     * @param array $template_data Template data
     * @return string Guidance about structure template
     */
    private static function build_html_structure_guidance($template_data) {
        if (empty($template_data['template_html'])) {
            return "";
        }

        // Parse the template HTML to extract structure
        $parser = new template_structure_parser();
        $structure_info = $parser->extract_structure_and_placeholders($template_data['template_html']);

        if (empty($structure_info['structure_template'])) {
            return "";
        }

        $guidance = "HTML STRUCTURE TEMPLATE:\n";
        $guidance .= "The template has a specific HTML structure that should be preserved exactly. ";
        $guidance .= "Below is the template structure with {{CONTENT_N}} placeholders for content areas:\n\n";
        $guidance .= $structure_info['structure_template'] . "\n\n";
        $guidance .= "STRUCTURE INSTRUCTIONS:\n";
        $guidance .= "1. Preserve this exact HTML structure in your output\n";
        $guidance .= "2. Replace each {{CONTENT_N}} placeholder with generated content that fits in that section\n";
        $guidance .= "3. Do NOT modify any Bootstrap classes, div structures, or HTML formatting\n";
        $guidance .= "4. Do NOT add or remove any HTML elements from the template\n";
        $guidance .= "5. Only change the text content between the HTML tags\n\n";

        return $guidance;
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
                error_log('AI classes not available for analyze_module');
                return '';
            }

            $contextid = !empty($COURSE->id)
                ? \context_course::instance($COURSE->id)->id
                : \context_system::instance()->id;

            $aimanager = new \core_ai\manager();
            if (!$aimanager->get_user_policy_status($USER->id)) {
                error_log('User has not accepted AI policy - analyze_module');
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
            
            error_log('AI response data keys: ' . implode(', ', array_keys($data)));
            error_log('AI response text length: ' . (is_string($text) ? strlen($text) : 'NOT A STRING'));
            
            if (is_string($text)) {
                return trim($text);
            }
            error_log('AI response text is not a string, type: ' . gettype($text));
            return '';
        } catch (\Throwable $e) {
            error_log('AI analysis error: ' . $e->getMessage());
            error_log('AI analysis error stack: ' . $e->getTraceAsString());
            return '';
        }
    }
}

