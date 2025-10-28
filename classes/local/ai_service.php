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
     * Log debug information to a web-accessible location
     */
    private static function debug_log($message) {
        global $CFG;
        $logdir = $CFG->dataroot . '/modgen_logs';
        if (!is_dir($logdir)) {
            mkdir($logdir, 0777, true);
        }
        $logfile = $logdir . '/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logfile, "[$timestamp] $message\n", FILE_APPEND);
    }
    /**
     * Recursively normalise AI responses where some fields may be JSON encoded as strings.
     * This walks arrays/objects and attempts to json_decode string values that look like JSON.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_ai_response($value) {
        // If it's an array, walk each element.
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize_ai_response($v);
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
                    return self::normalize_ai_response($decoded);
                }
            }

            // Try unescaping common escapes (e.g. when AI returns a JSON string inside a JSON field)
            $unescaped = stripslashes($trim);
            if ($unescaped !== $trim) {
                if ((isset($unescaped[0]) && ($unescaped[0] === '{' || $unescaped[0] === '['))) {
                    $decoded = json_decode($unescaped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return self::normalize_ai_response($decoded);
                    }
                }
            }

            // As a last resort, try to extract a JSON blob from within larger text
            if (preg_match('/(\{.*\}|\[.*\])/s', $trim, $m)) {
                $decoded = json_decode($m[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::normalize_ai_response($decoded);
                }
            }

            // Nothing to decode
            return $value;
        }

        // Scalars other than strings left unchanged
        return $value;
    }
    public static function generate_module($prompt, $documents = [], $structure = 'weekly', $template_data = null) {
        global $USER, $COURSE;
        
        // Log whether template_data was passed
        self::debug_log('=== generate_module called ===');
        self::debug_log('template_data is ' . (empty($template_data) ? 'EMPTY/NULL' : 'PRESENT'));
        self::debug_log('template_data type: ' . gettype($template_data));
        
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

            // Compose an instruction-rich prompt with strict JSON schema requirements.
            $structure = ($structure === 'theme') ? 'theme' : 'weekly';
            
            // Start with fixed JSON schema requirements
            $jsonrequirements = "The JSON structure you return must represent a Moodle module for the user's requirements, not just generic activities.\n" .
                "Return ONLY valid JSON matching the schema below. Do not include any commentary or code fences.";
            
            // Get the configurable pedagogical guidance from admin settings
            $pedagogicalguidance = get_config('aiplacement_modgen', 'baseprompt');
            if (empty($pedagogicalguidance)) {
                // Fallback to default if not configured
                $pedagogicalguidance = "You are an expert Moodle learning content designer at a UK higher education institution.\n" .
                    "Your task is to design a Moodle module for the user's input, using activities and resources appropriate for UK HE.\n" .
                    "Design learning activities aligned with UK HE standards, inclusive pedagogy, and clear learning outcomes.";
            }
            
            // Combine pedagogical guidance with JSON requirements
            $roleinstruction = $pedagogicalguidance . "\n\n" . $jsonrequirements;

            $activitymetadata = registry::get_supported_activity_metadata();
            $supportedactivitytypes = array_keys($activitymetadata);

            // Debug: Log what activity types are available
            self::debug_log("AI_SERVICE: Activity metadata: " . print_r($activitymetadata, true));
            self::debug_log("AI_SERVICE: Supported types: " . print_r($supportedactivitytypes, true));

            if ($structure === 'theme') {
                $weekproperties = [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ];
                if (!empty($supportedactivitytypes)) {
                    $weekproperties['activities'] = [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'name'],
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => $supportedactivitytypes,
                                ],
                                'name' => ['type' => 'string'],
                                'intro' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'externalurl' => ['type' => 'string'],
                                'chapters' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'title' => ['type' => 'string'],
                                            'content' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                $schemaspec = [
                    'type' => 'object',
                    'required' => ['themes'],
                    'properties' => [
                        'themes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['title', 'summary', 'weeks'],
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'summary' => ['type' => 'string'],
                                    'weeks' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['title', 'summary'],
                                            'properties' => $weekproperties,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'template' => ['type' => 'string'],
                    ],
                ];
                if (!empty($supportedactivitytypes)) {
                    $schemaspec['properties']['themes']['items']['properties']['activities'] = [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'name'],
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => $supportedactivitytypes,
                                ],
                                'name' => ['type' => 'string'],
                                'intro' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'externalurl' => ['type' => 'string'],
                                'chapters' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'title' => ['type' => 'string'],
                                            'content' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                $formatinstruction = "Schema: " . json_encode($schemaspec) . "\n" .
                    "Output rules: Return a compact JSON object which validates against the schema.\n" .
                    "Each theme includes a 'title', a 'summary', and a 'weeks' array.\n" .
                    "Each week object contains a 'title' and 'summary' giving practical weekly delivery guidance.\n" .
                    "Audience: UK university students. Use British English.";
            } else {
                $sectionproperties = [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'outline' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ];
                if (!empty($supportedactivitytypes)) {
                    $sectionproperties['activities'] = [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'name'],
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => $supportedactivitytypes,
                                ],
                                'name' => ['type' => 'string'],
                                'intro' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'externalurl' => ['type' => 'string'],
                                'chapters' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'title' => ['type' => 'string'],
                                            'content' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                $schemaspec = [
                    'type' => 'object',
                    'required' => ['sections'],
                    'properties' => [
                        'sections' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['title', 'summary', 'outline'],
                                'properties' => $sectionproperties,
                            ],
                        ],
                        'template' => ['type' => 'string'],
                    ],
                ];
                $formatinstruction = "Schema: " . json_encode($schemaspec) . "\n" .
                    "Output rules: Return a compact JSON object which validates against the schema.\n" .
                    "Each section is a teaching week with a 'title', a narrative 'summary', and an 'outline' array of key activities/resources.\n" .
                    "Audience: UK university students. Use British English.";
            }

            if (!empty($activitymetadata)) {
                $activitylines = [];
                foreach ($activitymetadata as $type => $metadata) {
                    $label = get_string($metadata['stringid'], 'aiplacement_modgen');
                    $activitylines[] = "- {$type}: {$metadata['description']} (Moodle {$label}).";
                }
                $formatinstruction .= "\nWhen listing activities, use the optional 'activities' array and only choose from the supported types below:\n" .
                    implode("\n", $activitylines) .
                    "\nDo not invent new activity types beyond this list.";
            } else {
                $formatinstruction .= "\nDo not include an 'activities' array because no supported activity types are available.";
            }

            // Add template guidance if template data is provided
            $template_guidance = '';
            if (!empty($template_data)) {
                self::debug_log('Building template guidance...');
                self::debug_log('Template data keys: ' . implode(', ', array_keys($template_data)));
                $template_guidance = self::build_template_prompt_guidance($template_data);
                self::debug_log('Template guidance built, length: ' . strlen($template_guidance));
                if (strlen($template_guidance) > 0) {
                    self::debug_log('First 300 chars of guidance: ' . substr($template_guidance, 0, 300));
                    self::debug_log('FULL TEMPLATE GUIDANCE:\n' . $template_guidance);
                }
                
                // When template is used, update format instruction to require HTML
                $formatinstruction .= "\n\nTEMPLATE MODE: Each section summary MUST be valid HTML content.\n" .
                    "Use HTML markup with Bootstrap 4/5 classes to structure the section summaries.\n" .
                    "Each 'summary' field must contain formatted HTML, not plain text.\n" .
                    "Example: <div class='card'><div class='card-body'><h5>Content</h5><p>Details here</p></div></div>";
            } else {
                self::debug_log('No template data provided to generate_module');
            }

            $finalprompt = $roleinstruction . "\n\nUser requirements:\n" . trim($prompt) . "\n\n" . $template_guidance . "\n\n" . $formatinstruction;

            // Debug: Log the prompt being sent to AI
            self::debug_log("AI_SERVICE: Final prompt length: " . strlen($finalprompt));
            self::debug_log("AI_SERVICE: Template guidance included in final prompt: " . (strlen($template_guidance) > 0 ? 'YES' : 'NO'));
            self::debug_log("AI_SERVICE: Final prompt being sent:\n" . $finalprompt);

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

            // Debug: Log the AI response
            self::debug_log("AI_SERVICE: AI response data: " . print_r($data, true));

            // Try to decode the provider's generated text as JSON per our schema.
            $text = $data['generatedtext'] ?? ($data['generatedcontent'] ?? '');
            
            // Debug: Log the raw text response
            self::debug_log("AI_SERVICE: Raw AI text response: " . $text);
            
            $jsondecoded = null;
            if (is_string($text)) {
                // First attempt: direct JSON decode.
                $jsondecoded = json_decode($text, true);
                
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
                $jsondecoded = self::normalize_ai_response($jsondecoded);
                // Log if normalisation changed the structure in a meaningful way.
                if (serialize($before) !== serialize($jsondecoded)) {
                    self::debug_log("AI_SERVICE: Normalised AI JSON structure; differences detected.");
                    self::debug_log("AI_SERVICE: Normalised JSON: " . print_r($jsondecoded, true));
                }
            }

            if (is_array($jsondecoded) && (isset($jsondecoded['sections']) || isset($jsondecoded['themes']) || isset($jsondecoded['activities']))) {
                // Provider adhered to format. Attach raw text and prompt for visibility.
                $jsondecoded['raw'] = $text;
                $jsondecoded['debugprompt'] = $finalprompt;
                $jsondecoded['debugresponse'] = $data;
                
                // Debug: Log the final processed JSON
                self::debug_log("AI_SERVICE: Final processed JSON: " . print_r($jsondecoded, true));
                
                return $jsondecoded;
            }

            // Debug: JSON decode failed or invalid structure
            self::debug_log("AI_SERVICE: JSON decode failed or invalid structure. Using fallback.");
            self::debug_log("AI_SERVICE: jsondecoded: " . print_r($jsondecoded, true));

            // Fallback mapping: wrap generated text into a label.
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
     * Produce a concise human-readable summary of the generated module structure.
     *
     * @param array $moduledata The decoded JSON returned by the AI generator.
     * @param string $structure Either 'weekly' or 'theme'.
     * @return string Summary text or empty string if unavailable.
     */
    public static function summarise_module(array $moduledata, string $structure = 'weekly'): string {
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

            $structure = ($structure === 'theme') ? 'theme' : 'weekly';
            $jsonpayload = json_encode($moduledata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($jsonpayload === false) {
                return '';
            }

            $instruction = "You are an instructional designer generating a concise summary of a Moodle module plan.\n" .
                "Summarise what will be created in no more than 80 words, focusing on learner experience and structure.\n" .
                "Refer to the module as a '{$structure}' style offering.\n" .
                "Do not use bullet points or markdown headings. Respond with plain sentences.";

            $prompt = $instruction . "\n\nModule plan JSON:\n" . $jsonpayload;

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
            return '';
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
        self::debug_log('=== generate_module_with_template called ===');
        self::debug_log('template_data type: ' . gettype($template_data));
        self::debug_log('template_data is empty: ' . (empty($template_data) ? 'YES' : 'NO'));
        if (is_array($template_data)) {
            self::debug_log('template_data keys: ' . implode(', ', array_keys($template_data)));
        }
        return self::generate_module($prompt, $documents, $structure, $template_data);
    }

    /**
     * Build guidance text about the template for the AI
     *
     * @param array $template_data Template data containing structure and HTML
     * @return string Guidance about the template
     */
    private static function build_template_prompt_guidance($template_data) {
        self::debug_log('build_template_prompt_guidance called');
        self::debug_log('Template data type: ' . gettype($template_data));
        if (is_array($template_data)) {
            self::debug_log('Template data keys: ' . implode(', ', array_keys($template_data)));
            foreach ($template_data as $key => $value) {
                if (is_array($value)) {
                    self::debug_log("  {$key}: array with " . count($value) . " items");
                } elseif (is_string($value)) {
                    self::debug_log("  {$key}: string, length " . strlen($value));
                } else {
                    self::debug_log("  {$key}: " . gettype($value));
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
        self::debug_log('Checking template_html: ' . (!empty($template_data['template_html']) ? 'YES (length: ' . strlen($template_data['template_html']) . ')' : 'NO'));
        if (!empty($template_data['template_html'])) {
            self::debug_log('Template HTML found, length: ' . strlen($template_data['template_html']));
            self::debug_log('First 500 chars: ' . substr($template_data['template_html'], 0, 500));
            
            $guidance .= "HTML STRUCTURE AND BOOTSTRAP COMPONENTS:\n";
            $guidance .= "The template uses specific HTML and Bootstrap markup. When generating section summaries,\n";
            $guidance .= "include similar HTML structure and Bootstrap classes. The template HTML includes:\n\n";
            
            // Include actual HTML snippets from template
            $html_excerpt = substr($template_data['template_html'], 0, 1000);
            $guidance .= "TEMPLATE HTML EXAMPLES:\n";
            $guidance .= "```html\n";
            $guidance .= $html_excerpt;
            if (strlen($template_data['template_html']) > 1000) {
                $guidance .= "\n... (additional HTML content)\n";
            }
            $guidance .= "```\n\n";
            
            // Extract Bootstrap classes for guidance
            $bootstrap_classes = self::extract_bootstrap_classes_from_html($template_data['template_html']);
            if (!empty($bootstrap_classes)) {
                $guidance .= "Bootstrap classes used in template: " . implode(', ', array_slice($bootstrap_classes, 0, 15)) . "\n";
                $guidance .= "Use these same Bootstrap classes in your generated section summaries.\n\n";
            }
            
            $guidance .= "IMPORTANT: Your section summaries MUST include HTML markup matching the template's style.\n";
            $guidance .= "Structure content with divs using Bootstrap classes like 'container', 'row', 'col-md-6', 'card', etc.\n";
            $guidance .= "Do not output plain text sections - each section summary MUST be formatted as HTML.\n\n";
        }
        
        // Add bootstrap structure if available
        if (!empty($template_data['bootstrap_structure'])) {
            $guidance .= "BOOTSTRAP STRUCTURE ANALYSIS:\n";
            $guidance .= "The template's Bootstrap structure: " . (is_array($template_data['bootstrap_structure']) ? implode(', ', $template_data['bootstrap_structure']) : $template_data['bootstrap_structure']) . "\n\n";
        }
        
        $guidance .= "ADAPTATION INSTRUCTIONS:\n";
        $guidance .= "1. Use the template's structure and organization as your foundation\n";
        $guidance .= "2. Adapt the content to match the user's request while maintaining the same layout\n";
        $guidance .= "3. Match the template's activity distribution and types\n";
        $guidance .= "4. Include similar HTML and Bootstrap markup in section summaries\n";
        $guidance .= "5. Preserve the pedagogical approach and level of detail from the template\n\n";
        
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

