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
                error_log("MISPLACED CONTENT EXTRACTED: Full module structure was in theme[{$idx}].summary (decoded)\n", 3, '/tmp/modgen_token_usage.log');
                
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
                        error_log("MISPLACED CONTENT EXTRACTED: Full module structure was in theme[{$idx}].summary (string)\n", 3, '/tmp/modgen_token_usage.log');
                        
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
                "5. TEMPLATE METADATA section describes the 'template' JSON field ONLY - do NOT add this to theme/week summaries.\n\n";

            // Add structure-specific guidance - simplified
            if ($structure === 'theme') {
                $requestedthemecount = self::extract_requested_theme_count($prompt, $structure);
                
                if (!empty($requestedthemecount)) {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate EXACTLY {$requestedthemecount} themes (non-negotiable).\n" .
                        "Each theme must have 2-4 weeks. Theme titles must be descriptive (e.g., 'Data Analysis Fundamentals'), never generic ('Theme 1').\n\n";
                } else {
                    $roleinstruction .= "THEME GENERATION:\n" .
                        "Generate 3-6 themes based on content. Each theme must have 2-4 weeks.\n" .
                        "Theme titles must be descriptive, never generic.\n\n";
                }
            } else {
                $roleinstruction .= "WEEKLY GENERATION:\n" .
                    "Create one section per major topic. Section titles must be descriptive (e.g., 'Week 1: Cloud Computing Basics').\n\n";
            }

            $activitymetadata = registry::get_supported_activity_metadata();

            // Simplified format instruction with minimal example
            if ($structure === 'theme') {
                if ($includesessions) {
                    $formatinstruction = "JSON FORMAT:\n" .
                        "{\"themes\": [{\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [{\"title\": \"Week N\", \"summary\": \"Brief\", \"sessions\": {\"presession\": {\"description\": \"Instructions\", \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]}, \"session\": {\"description\": \"...\", \"activities\": [...]}, \"postsession\": {\"description\": \"...\", \"activities\": [...]}}}]}]}\n\n";
                } else {
                    $formatinstruction = "JSON FORMAT:\n" .
                        "{\"themes\": [{\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [{\"title\": \"Week N\", \"summary\": \"Brief\", \"outline\": [\"point1\", \"point2\"]}]}]}\n\n";
                }
            } else {
                $formatinstruction = "JSON FORMAT:\n" .
                    "{\"sections\": [{\"title\": \"Week N\", \"summary\": \"Brief\", \"outline\": [\"point1\", \"point2\"], \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]}]}\n\n";
            }

            // Add supported activity types only
            if (!empty($activitymetadata) && $includeactivities) {
                $formatinstruction .= "SUPPORTED ACTIVITY TYPES:\n";
                foreach ($activitymetadata as $type => $metadata) {
                    $formatinstruction .= "- {$type}: {$metadata['description']}\n";
                }
                $formatinstruction .= "\nActivity fields: type (required), name (required), intro (optional), url (for url type), chapters (for book type).\n";
            } elseif ($includeactivities === false) {
                $formatinstruction .= "Do NOT include activities - only sections with titles, summaries, and outlines.\n";
            }

            // Add week date guidance if courseid provided
            $weekdateguidance = '';
            if (!empty($courseid)) {
                $exampledate1 = self::get_week_date_range(1, $courseid);
                $weekdateguidance = "\nInclude week dates in titles: \"Week 1 ({$exampledate1})\" instead of just \"Week 1\".\n";
            }
            
            $formatinstruction .= $weekdateguidance;

            // Add template guidance if template data is provided
            $template_guidance = '';
            if (!empty($template_data) && is_array($template_data)) {
                $template_guidance = self::build_template_prompt_guidance($template_data);
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
                    $finalprompt .= "\n*** COMPLETENESS REQUIREMENT ***\n";
                    $finalprompt .= "Generate EXACTLY {$requestedthemecount} themes with ALL topics covered.\n";
                    $finalprompt .= "Do NOT stop early. Do NOT truncate. Complete output required.\n";
                    $finalprompt .= "Return ONLY valid JSON.\n";
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
            
            // Log to debug file
            error_log("=== AI Generation Debug ===\n" .
                "Prompt tokens (est): " . round($prompt_tokens) . "\n" .
                "Response tokens (est): " . round($response_tokens) . "\n" .
                "Total tokens (est): " . round($total_tokens) . "\n" .
                "Response length: " . strlen($text) . " chars\n" .
                "Looks truncated: " . ($response_looks_truncated ? 'YES' : 'NO') . "\n" .
                "Response ends with: " . substr($text, -50) . "\n" .
                "---\n", 3, '/tmp/modgen_token_usage.log');
            
            if ($response_looks_truncated) {
                error_log("WARNING: Response appears truncated! May be hitting token limit.\n", 3, '/tmp/modgen_token_usage.log');
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
                error_log("DEEP UNESCAPE: Applied recursive JSON string decoding\n", 3, '/tmp/modgen_token_usage.log');
            }

            // Log double-encoding detection
            $is_double_encoded = false;
            if (is_array($jsondecoded) && count($jsondecoded) === 1) {
                $first_val = reset($jsondecoded);
                if (is_string($first_val) && (strpos($first_val, '{"themes"') !== false || strpos($first_val, '{"sections"') !== false)) {
                    $is_double_encoded = true;
                    error_log("DOUBLE ENCODING DETECTED: Top-level key contains JSON string\n", 3, '/tmp/modgen_token_usage.log');
                }
            }

            // Attempt to normalise nested/stringified JSON that may be embedded in string fields.
            if (is_array($jsondecoded)) {
                $before = $jsondecoded;
                
                $jsondecoded = self::normalize_ai_response($jsondecoded, true);
                
                // Log if normalisation changed the structure
                if (serialize($before) !== serialize($jsondecoded)) {
                    error_log("NORMALIZATION CHANGED STRUCTURE\n", 3, '/tmp/modgen_token_usage.log');
                }
                
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
    private static function build_template_prompt_guidance($template_data) {
        $guidance = "";
        
        // Detect multiple modules
        $is_multiple = !empty($template_data['module_count']) && $template_data['module_count'] > 1;
        
        // CRITICAL: This section is ONLY about the "template" field in the JSON output
        // It is NOT content to include in themes or summaries
        $guidance .= "\n*** OUTPUT 'template' FIELD ONLY ***\n";
        $guidance .= "The 'template' field should describe what you used to generate this module:\n";
        
        if ($is_multiple) {
            $guidance .= "Set 'template' value to: \"Combining {$template_data['module_count']} existing modules\"\n";
        } else {
            $guidance .= "Set 'template' value to: \"Based on existing module template\"\n";
        }
        
        $guidance .= "\nDo NOT include this template metadata in any theme summary or week summary.\n";
        $guidance .= "Only the themes/sections array should contain the actual course content.\n";
        
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

