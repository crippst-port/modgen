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
    public static function generate_module($prompt, $orgparams, $documents = [], $structure = 'weekly') {
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

            // Compose an instruction-rich prompt with strict JSON schema requirements.
            $structure = ($structure === 'theme') ? 'theme' : 'weekly';
            $roleinstruction = "You are an expert Moodle learning content designer at a UK higher education institution.\n" .
                "Your task is to design a Moodle module for the user's input, using activities and resources appropriate for UK HE.\n" .
                "The JSON structure you return must represent a Moodle module for the user's requirements, not just generic activities.\n" .
                "Design learning activities aligned with UK HE standards, inclusive pedagogy, and clear learning outcomes.\n" .
                "Return ONLY valid JSON matching the schema below. Do not include any commentary or code fences.";

            $activitymetadata = registry::get_supported_activity_metadata();
            $supportedactivitytypes = array_keys($activitymetadata);

            // Debug: Log what activity types are available
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: Activity metadata: " . print_r($activitymetadata, true) . "\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: Supported types: " . print_r($supportedactivitytypes, true) . "\n", FILE_APPEND);

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

            $finalprompt = $roleinstruction . "\n\nUser requirements:\n" . trim($prompt) . "\n\n" . $formatinstruction;

            // Debug: Log the prompt being sent to AI
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: Final prompt being sent:\n" . $finalprompt . "\n\n", FILE_APPEND);

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
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: AI response data: " . print_r($data, true) . "\n", FILE_APPEND);

            // Try to decode the provider's generated text as JSON per our schema.
            $text = $data['generatedtext'] ?? ($data['generatedcontent'] ?? '');
            
            // Debug: Log the raw text response
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: Raw AI text response: " . $text . "\n\n", FILE_APPEND);
            
            $jsondecoded = null;
            if (is_string($text)) {
                // First attempt: direct JSON decode.
                $jsondecoded = json_decode($text, true);
                // Second attempt: extract a JSON object/array from the text if provider added commentary.
                if (!is_array($jsondecoded)) {
                    if (preg_match('/(\{.*\}|\[.*\])/s', $text, $m)) {
                        $jsondecoded = json_decode($m[1], true);
                    }
                }
            }

            if (is_array($jsondecoded) && (isset($jsondecoded['sections']) || isset($jsondecoded['themes']) || isset($jsondecoded['activities']))) {
                // Provider adhered to format. Attach raw text and prompt for visibility.
                $jsondecoded['raw'] = $text;
                $jsondecoded['debugprompt'] = $finalprompt;
                $jsondecoded['debugresponse'] = $data;
                
                // Debug: Log the final processed JSON
                file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: Final processed JSON: " . print_r($jsondecoded, true) . "\n\n", FILE_APPEND);
                
                return $jsondecoded;
            }

            // Debug: JSON decode failed or invalid structure
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: JSON decode failed or invalid structure. Using fallback.\n", FILE_APPEND);
            file_put_contents('/tmp/modgen_debug.log', "AI_SERVICE: jsondecoded: " . print_r($jsondecoded, true) . "\n", FILE_APPEND);

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
     * @param string $orgparams Organization parameters
     * @param array $template_data Template data structure
     * @param array $documents Supporting documents
     * @param string $structure Module structure
     * @return array Response from AI service
     */
    public static function generate_module_with_template($prompt, $orgparams, $template_data, $documents = [], $structure = 'weekly') {
        $enhanced_prompt = self::build_template_prompt($prompt, $orgparams, $template_data, $structure);
        return self::generate_module($enhanced_prompt, $orgparams, $documents, $structure);
    }

    /**
     * Build enhanced prompt that includes template data as context.
     *
     * @param string $user_prompt Original user prompt
     * @param string $orgparams Organization parameters
     * @param array $template_data Template structure data
     * @param string $structure Module structure
     * @return string Enhanced prompt with template context
     */
    private static function build_template_prompt($user_prompt, $orgparams, $template_data, $structure) {
        $template_json = json_encode($template_data, JSON_PRETTY_PRINT);
        
        $enhanced_prompt = "You are a Moodle course designer. Use the following CURRICULUM TEMPLATE as a structural and pedagogical guide, then adapt it based on the user's request.

CURRICULUM TEMPLATE STRUCTURE:
{$template_json}

USER REQUEST: {$user_prompt}

ORGANIZATION CONTEXT: {$orgparams}

INSTRUCTIONS:
1. Use the template's structure, organization, and pedagogical approach as your foundation
2. Adapt the content to match the user's topic/subject while maintaining the template's quality and depth
3. If the template has quizzes with specific question types and patterns, create similar activities for the new topic
4. Preserve the template's section organization and activity distribution unless the user requests changes
5. Match the template's level of detail and instructional design principles
6. Maintain the same course format and learning progression as the template
7. Follow the template's naming conventions and activity types

STRUCTURE: Create a {$structure} module structure.

Please provide a complete module structure that adapts the template to the user's request while maintaining the same pedagogical quality and organization.";

        return $enhanced_prompt;
    }
}
