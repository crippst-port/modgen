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

    public static function generate_module($prompt, $documents = [], $structure = 'weekly', $template_data = null) {
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
                $pedagogicalguidance = "You are an expert Moodle learning content designer at a UK higher education institution designing a Moodle module for the user's input using activities appropriate for UK HE.";
            }
            
            // Build compact roleinstruction without redundancy
            $roleinstruction = $pedagogicalguidance . "\n\n" .
                "CRITICAL REQUIREMENTS:\n" .
                "1. Return ONLY valid JSON. No commentary, code blocks, or wrapping.\n" .
                "2. Generate the COMPLETE module structure from the curriculum file.\n" .
                "3. Do NOT omit, truncate, or stop early - include ALL content from the file.\n" .
                "4. Do NOT include example data or placeholder text like 'Week X', 'Theme Name', or '...'.\n" .
                "5. Every field MUST contain actual content from the curriculum.\n" .
                "6. Return as pure JSON object at the top level: {\"themes\": [...]} OR {\"sections\": [...]}\n\n";

            // Add file parsing and theme instructions only for theme structure
            if ($structure === 'theme') {
                // Check if user specified a requested theme count
                $requestedthemecount = self::extract_requested_theme_count($prompt, $structure);
                
                if (!empty($requestedthemecount)) {
                    // User specified a specific number of themes - use that as the OVERRIDE
                    $roleinstruction .= "GENERATE COMPLETE THEMED STRUCTURE:\n" .
                        "*** USER HAS SPECIFIED: Create EXACTLY {$requestedthemecount} themes ***\n" .
                        "This is a REQUIREMENT - do NOT deviate. Use {$requestedthemecount} themes, not more or fewer.\n\n" .
                        "STEP 1: Parse the ENTIRE file and list EVERY single topic and subtopic\n" .
                        "STEP 2: Divide all topics into EXACTLY {$requestedthemecount} coherent theme groups\n" .
                        "STEP 3: Ensure ALL topics are covered - each topic goes into exactly one theme\n" .
                        "STEP 4: For each of the {$requestedthemecount} themes, create weeks (typically 2-4 weeks per theme) covering all subtopics\n" .
                        "STEP 5: For each week, create presession/session/postsession activities\n" .
                        "STEP 6: Verify ALL topics from the file are included in your {$requestedthemecount} themes\n" .
                        "CRITICAL: Generate EXACTLY {$requestedthemecount} themes - this overrides any other guidance\n" .
                        "CRITICAL: Every topic from the file MUST appear in at least one week of one theme\n" .
                        "- Each theme summary: 2-3 sentence introduction for students\n" .
                        "- Each week summary: brief overview of that week's learning\n\n";
                } else {
                    // No specific count requested - use flexible guidance
                    $roleinstruction .= "GENERATE COMPLETE THEMED STRUCTURE:\n" .
                        "STEP 1: Parse the ENTIRE file and list EVERY single topic and subtopic\n" .
                        "STEP 2: Count all topics to determine theme count (typically 3-6 themes needed to cover all topics)\n" .
                        "STEP 3: Group ALL topics into coherent themes - ensure NO topic is left out\n" .
                        "STEP 4: For each theme, create weeks (typically 2-4 weeks per theme) covering all subtopics\n" .
                        "STEP 5: For each week, create presession/session/postsession activities\n" .
                        "STEP 6: Verify ALL topics from the file are included in your themes\n" .
                        "CRITICAL: Do NOT skip any content - include every topic from the curriculum file\n" .
                        "CRITICAL: Every topic from the file MUST appear in at least one week of one theme\n" .
                        "- Each theme summary: 2-3 sentence introduction for students\n" .
                        "- Each week summary: brief overview of that week's learning\n\n";
                }
            } else {
                $roleinstruction .= "GENERATE COMPLETE WEEKLY STRUCTURE:\n" .
                    "- Parse the ENTIRE file and extract ALL topics and sections\n" .
                    "- Create one week/section for each major topic in the file\n" .
                    "- For each week, include outline array with key points\n" .
                    "- Add activities relevant to that week\n" .
                    "- Do NOT skip any content - include everything from the curriculum file\n" .
                    "- Each section summary: overview of that week's content\n\n";
            }


            $activitymetadata = registry::get_supported_activity_metadata();
            $supportedactivitytypes = array_keys($activitymetadata);

            // Build concise format instructions - minimal example, repeat pattern for all content
            if ($structure === 'theme') {
                $formatinstruction = "JSON OUTPUT STRUCTURE (Theme-Based):\n" .
                    "{\"themes\": [\n" .
                    "  {\"title\": \"Theme Name\", \"summary\": \"2-3 sentences\", \"weeks\": [\n" .
                    "    {\"title\": \"Week N\", \"summary\": \"Overview\", \"sessions\": {\n" .
                    "      \"presession\": {\"activities\": [{\"type\": \"url\", \"name\": \"Activity\"}]},\n" .
                    "      \"session\": {\"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]},\n" .
                    "      \"postsession\": {\"activities\": [{\"type\": \"forum\", \"name\": \"Activity\"}]}\n" .
                    "    }}\n" .
                    "  ]}\n" .
                    "]}\n" .
                    "IMPORTANT: Generate ALL themes needed to cover ALL topics in the curriculum.\n" .
                    "IMPORTANT: Each theme must have multiple weeks (at least 2-3 weeks minimum).\n" .
                    "IMPORTANT: Include EVERY topic from the file - do not skip or leave out any content.\n" .
                    "IMPORTANT: Do not truncate - continue until all themes and all weeks are complete.\n";
            } else {
                $formatinstruction = "JSON OUTPUT STRUCTURE (Weekly):\n" .
                    "{\"sections\": [\n" .
                    "  {\"title\": \"Week N\", \"summary\": \"Overview\", \"outline\": [\"key 1\", \"key 2\"], \"activities\": [{\"type\": \"quiz\", \"name\": \"Activity\"}]}\n" .
                    "]}\n" .
                    "IMPORTANT: Repeat this structure for EVERY week in the curriculum.\n" .
                    "IMPORTANT: Include ALL weeks - do not truncate.\n";
            }

            if (!empty($activitymetadata)) {
                $formatinstruction .= "\nSupported activity types:\n";
                foreach ($activitymetadata as $type => $metadata) {
                    $label = get_string($metadata['stringid'], 'aiplacement_modgen');
                    $formatinstruction .= "  - {$type}: {$metadata['description']}\n";
                }
                $formatinstruction .= "\nUse ONLY these activity types. Do not invent new ones.";
            }


            // Add template guidance if template data is provided
            $template_guidance = '';
            if (!empty($template_data)) {
                $template_guidance = self::build_template_prompt_guidance($template_data);
                if (strlen($template_guidance) > 0) {
                }
                
                // When template is used, update format instruction to require HTML
                $formatinstruction .= "\n\nTEMPLATE MODE: Each section summary MUST be valid HTML content.\n" .
                    "Use HTML markup with Bootstrap 4/5 classes to structure the section summaries.\n" .
                    "Each 'summary' field must contain formatted HTML, not plain text.\n" .
                    "Example: <div class='card'><div class='card-body'><h5>Content</h5><p>Details here</p></div></div>";
            } else {
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
                $finalprompt .= "FINAL REMINDER - THEME STRUCTURE:\n" .
                    "- Generate the COMPLETE module with ALL themes needed\n" .
                    "- Include EVERY topic and subtopic from the file above\n" .
                    "- Each theme MUST contain multiple weeks (at least 2-3 weeks per theme)\n" .
                    "- Every topic from the curriculum file MUST appear in at least one week\n" .
                    "- Do NOT stop early, do NOT truncate, do NOT omit content\n" .
                    "- Return ONLY JSON - no other text.\n";
            } else {
                $finalprompt .= "FINAL REMINDER - WEEKLY STRUCTURE:\n" .
                    "- Generate the COMPLETE module with all weeks\n" .
                    "- Include EVERY topic from the file above\n" .
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
     * @return array Response from AI service
     */
    public static function generate_module_with_template($prompt, $template_data, $documents = [], $structure = 'weekly') {
        return self::generate_module($prompt, $documents, $structure, $template_data);
    }

    /**
     * Build guidance text about the template for the AI
     *
     * @param array $template_data Template data containing structure and HTML
     * @return string Guidance about the template
     */
    private static function build_template_prompt_guidance($template_data) {
        if (is_array($template_data)) {
            foreach ($template_data as $key => $value) {
                if (is_array($value)) {
                } elseif (is_string($value)) {
                } else {
                }
            }
        }
        
        $guidance = "";
        
        // Add course info guidance
        if (!empty($template_data['course_info'])) {
            $course = $template_data['course_info'];
            $guidance .= "CURRICULUM TEMPLATE INFORMATION:\n";
            $guidance .= "Template Name: " . (!empty($course['name']) ? $course['name'] : 'Unnamed') . "\n";
            $guidance .= "Template Format: " . (!empty($course['format']) ? $course['format'] : 'Unknown') . "\n";
            if (!empty($course['summary'])) {
                $guidance .= "Template Summary: " . substr($course['summary'], 0, 300) . "\n";
            }
            $guidance .= "\n";
        }
        
        // Add structure guidance
        if (!empty($template_data['structure']) && is_array($template_data['structure'])) {
            $guidance .= "TEMPLATE STRUCTURE:\n";
            $guidance .= "The template is organized into " . count($template_data['structure']) . " sections:\n";
            foreach ($template_data['structure'] as $section) {
                $section_name = is_array($section) && !empty($section['name']) ? $section['name'] : 'Unknown Section';
                $activity_count = is_array($section) && !empty($section['activity_count']) ? $section['activity_count'] : 0;
                $guidance .= "- {$section_name} ({$activity_count} activities)\n";
            }
            $guidance .= "\n";
        }
        
        // Add activities guidance
        if (!empty($template_data['activities']) && is_array($template_data['activities'])) {
            $guidance .= "TEMPLATE ACTIVITIES:\n";
            $guidance .= "The template uses the following activity types and patterns:\n";
            $activity_types = [];
            $activity_details = [];
            foreach ($template_data['activities'] as $activity) {
                if (is_array($activity)) {
                    $type = $activity['type'] ?? 'unknown';
                    $activity_types[$type] = ($activity_types[$type] ?? 0) + 1;
                    $activity_details[] = "  - " . ($activity['name'] ?? 'Unnamed') . " (type: {$type})";
                }
            }
            foreach ($activity_types as $type => $count) {
                $guidance .= "- {$type}: {$count} instance(s)\n";
            }
            if (!empty($activity_details)) {
                $guidance .= "\nDetailed Activities:\n" . implode("\n", array_slice($activity_details, 0, 15)) . "\n";
            }
            $guidance .= "Follow this same activity pattern in your generated module.\n\n";
        }
        
        // Add Bootstrap structure guidance if HTML is available
        if (!empty($template_data['template_html'])) {
            $guidance .= "CRITICAL: EXACT HTML STRUCTURE REPLICATION REQUIRED\n";
            $guidance .= str_repeat("=", 70) . "\n\n";

            $guidance .= "You MUST copy the HTML structure below EXACTLY for EVERY section you create.\n";
            $guidance .= "Do NOT simplify, do NOT modify the structure, do NOT change Bootstrap classes.\n";
            $guidance .= "The ONLY thing you change is the TEXT CONTENT inside the HTML tags.\n";
            $guidance .= "All divs, classes, structure, and layout MUST be identical to this template.\n\n";

            $guidance .= "TEMPLATE HTML STRUCTURE TO COPY EXACTLY:\n";
            $guidance .= str_repeat("-", 70) . "\n";
            $guidance .= "```html\n";

            // Show the FULL template HTML, not just an excerpt
            $guidance .= $template_data['template_html'];

            $guidance .= "\n```\n";
            $guidance .= str_repeat("-", 70) . "\n\n";

            // Extract Bootstrap classes for emphasis
            $bootstrap_classes = self::extract_bootstrap_classes_from_html($template_data['template_html']);
            if (!empty($bootstrap_classes)) {
                $guidance .= "Bootstrap classes in template (MUST use these exact classes):\n";
                $guidance .= implode(', ', $bootstrap_classes) . "\n\n";
            }

            $guidance .= "STEP-BY-STEP INSTRUCTIONS:\n";
            $guidance .= "1. Copy the ENTIRE HTML structure above character-for-character\n";
            $guidance .= "2. Keep ALL div tags, classes, and attributes EXACTLY as shown\n";
            $guidance .= "3. Keep ALL Bootstrap classes EXACTLY as shown (container, row, col-md-*, nav-tabs, etc.)\n";
            $guidance .= "4. Keep HTML attributes (role, data-toggle, aria-*, style, etc.) EXACTLY as shown\n";
            $guidance .= "5. CRITICAL: Make HTML 'id' and 'href' attributes UNIQUE for each section/week you create\n";
            $guidance .= "   - REASON: Multiple sections with identical IDs will cause Bootstrap components to break\n";
            $guidance .= "   - METHOD: Add a unique suffix to every id and corresponding href value\n";
            $guidance .= "   - If template has id=\"week1Tabs\", change to: id=\"week2Tabs\", id=\"week3Tabs\", id=\"theme1Tabs\", etc.\n";
            $guidance .= "   - If template has id=\"pre-tab\", change to: id=\"pre-tab-w2\", id=\"pre-tab-w3\", id=\"pre-tab-t1\", etc.\n";
            $guidance .= "   - If template has href=\"#pre\", change to: href=\"#pre-w2\", href=\"#pre-w3\", href=\"#pre-t1\", etc.\n";
            $guidance .= "   - Use week number (w1, w2, w3) or theme number (t1, t2, t3) or section number as suffix\n";
            $guidance .= "   - EVERY id in a section must have the same suffix pattern for that section\n";
            $guidance .= "   - Matching href values must use the same suffix (if id=\"pre-w2\" then href=\"#pre-w2\")\n";
            $guidance .= "6. ONLY change the actual text content between tags to match your new topic\n";
            $guidance .= "7. If the template has tabs, your output MUST have tabs with the same structure (with unique IDs)\n";
            $guidance .= "8. If the template has cards, your output MUST have cards with the same structure\n";
            $guidance .= "9. If the template has badges, your output MUST have badges with the same structure\n";
            $guidance .= "10. If the template has accordions, your output MUST have accordions (with unique IDs)\n";
            $guidance .= "11. Maintain the SAME nesting depth and tag hierarchy\n";
            $guidance .= "12. Every section summary you generate MUST use this EXACT structure with unique IDs\n\n";

            $guidance .= "EXAMPLE 1 - Basic structure:\n";
            $guidance .= "Template:\n";
            $guidance .= "<div class='container my-4'>\n";
            $guidance .= "  <h5>Introduction</h5>\n";
            $guidance .= "  <p>This week introduces macronutrients...</p>\n";
            $guidance .= "</div>\n\n";
            $guidance .= "Your output for Week 2:\n";
            $guidance .= "<div class='container my-4'>\n";
            $guidance .= "  <h5>Getting Started</h5>\n";
            $guidance .= "  <p>This week explores programming basics...</p>\n";
            $guidance .= "</div>\n\n";

            $guidance .= "EXAMPLE 2 - Tabs with IDs (CRITICAL FOR FUNCTIONALITY):\n";
            $guidance .= "Template (Week 1):\n";
            $guidance .= "<ul id=\"week1Tabs\" class=\"nav nav-tabs\" role=\"tablist\">\n";
            $guidance .= "  <li class=\"nav-item\">\n";
            $guidance .= "    <a id=\"pre-tab\" class=\"nav-link active\" href=\"#pre\" data-toggle=\"tab\">Pre-session</a>\n";
            $guidance .= "  </li>\n";
            $guidance .= "</ul>\n";
            $guidance .= "<div class=\"tab-content\">\n";
            $guidance .= "  <div id=\"pre\" class=\"tab-pane active\">Content here</div>\n";
            $guidance .= "</div>\n\n";

            $guidance .= "Your output for Week 2 (note unique IDs):\n";
            $guidance .= "<ul id=\"week2Tabs\" class=\"nav nav-tabs\" role=\"tablist\">\n";
            $guidance .= "  <li class=\"nav-item\">\n";
            $guidance .= "    <a id=\"pre-tab-w2\" class=\"nav-link active\" href=\"#pre-w2\" data-toggle=\"tab\">Pre-session</a>\n";
            $guidance .= "  </li>\n";
            $guidance .= "</ul>\n";
            $guidance .= "<div class=\"tab-content\">\n";
            $guidance .= "  <div id=\"pre-w2\" class=\"tab-pane active\">New content here</div>\n";
            $guidance .= "</div>\n\n";

            $guidance .= "Your output for Theme 1 (note unique IDs with different suffix):\n";
            $guidance .= "<ul id=\"theme1Tabs\" class=\"nav nav-tabs\" role=\"tablist\">\n";
            $guidance .= "  <li class=\"nav-item\">\n";
            $guidance .= "    <a id=\"pre-tab-t1\" class=\"nav-link active\" href=\"#pre-t1\" data-toggle=\"tab\">Pre-session</a>\n";
            $guidance .= "  </li>\n";
            $guidance .= "</ul>\n";
            $guidance .= "<div class=\"tab-content\">\n";
            $guidance .= "  <div id=\"pre-t1\" class=\"tab-pane active\">Theme content here</div>\n";
            $guidance .= "</div>\n\n";

            $guidance .= "WRONG - DO NOT copy IDs exactly:\n";
            $guidance .= "<ul id=\"week1Tabs\">... <!-- WRONG: Same ID as template! -->\n";
            $guidance .= "  <a href=\"#pre\" ... <!-- WRONG: Same href as template! -->\n\n";

            $guidance .= "FORBIDDEN ACTIONS:\n";
            $guidance .= "❌ DO NOT simplify the HTML structure\n";
            $guidance .= "❌ DO NOT remove divs or container elements\n";
            $guidance .= "❌ DO NOT change Bootstrap class names\n";
            $guidance .= "❌ DO NOT remove CSS classes\n";
            $guidance .= "❌ DO NOT copy id and href attributes without making them unique\n";
            $guidance .= "❌ DO NOT use the same IDs across multiple sections (causes JavaScript conflicts)\n";
            $guidance .= "❌ DO NOT modify HTML attributes except id/href (role, data-toggle stay the same)\n";
            $guidance .= "❌ DO NOT create your own structure\n";
            $guidance .= "❌ DO NOT use plain text without HTML\n";
            $guidance .= "❌ DO NOT change the layout or visual structure\n\n";

            $guidance .= "REQUIRED ACTIONS:\n";
            $guidance .= "✓ Copy the HTML structure EXACTLY\n";
            $guidance .= "✓ Use ALL the same Bootstrap classes\n";
            $guidance .= "✓ Maintain ALL div containers and wrappers\n";
            $guidance .= "✓ Make id and href attributes UNIQUE per section (add suffix like -w2, -w3, -t1, -t2)\n";
            $guidance .= "✓ Keep matching pairs consistent (if id=\"pre-w2\" then href=\"#pre-w2\")\n";
            $guidance .= "✓ Keep other HTML attributes unchanged (role, data-toggle, aria-*, style)\n";
            $guidance .= "✓ Only change the text content inside tags\n";
            $guidance .= "✓ Apply this SAME structure to EVERY section/week\n";
            $guidance .= "✓ Match the visual layout exactly\n\n";
        }

        // Add bootstrap structure if available
        if (!empty($template_data['bootstrap_structure'])) {
            $guidance .= "BOOTSTRAP COMPONENTS IN TEMPLATE:\n";
            if (is_array($template_data['bootstrap_structure']) && !empty($template_data['bootstrap_structure']['components'])) {
                $guidance .= "The template uses these Bootstrap components:\n";
                foreach ($template_data['bootstrap_structure']['components'] as $component) {
                    $guidance .= "  - {$component}\n";
                }
                $guidance .= "\nYour generated content MUST include these same components with identical structure.\n\n";
            }
        }

        $guidance .= "FINAL REMINDER:\n";
        $guidance .= "The user expects the generated sections to look VISUALLY IDENTICAL to the template.\n";
        $guidance .= "This means copying the HTML structure EXACTLY, not just \"similar\" or \"inspired by\".\n";
        $guidance .= "Think of it as a fill-in-the-blank exercise where you:\n";
        $guidance .= "  1. Fill in the text content (what the section is about)\n";
        $guidance .= "  2. Make IDs unique (so Bootstrap components don't conflict)\n";
        $guidance .= "  3. Keep everything else identical (structure, classes, attributes)\n";
        $guidance .= "This is NOT creative freedom to design your own layout.\n\n";

        $guidance .= "ID UNIQUENESS CHECK:\n";
        $guidance .= "Before finalizing each section, verify:\n";
        $guidance .= "  • Every id attribute has a unique suffix for this section\n";
        $guidance .= "  • Every href attribute targeting an ID has the matching suffix\n";
        $guidance .= "  • No two sections have the same ID values\n";
        $guidance .= "  • Tab/accordion functionality will work (unique IDs prevent conflicts)\n\n";
        
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

