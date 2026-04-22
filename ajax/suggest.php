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
 * AJAX endpoint for AI activity suggestions.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Resolve Moodle config.php from plugin ajax directory.
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    // Ensure clients always get JSON rather than a PHP warning/fatal HTML page.
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'config.php not found', 'path' => $configpath]);
    exit(0);
}
require_once($configpath);
require_once(__DIR__ . '/../lib.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\ai_service;

require_once(__DIR__ . '/../classes/local/ai_service.php');

defined('MOODLE_INTERNAL') || die();

// Prevent PHP from outputting HTML errors directly to the response
@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Buffer any unexpected output so we can return clean JSON
@ob_start();

try {
    // Immediately set JSON content-type so clients always see the correct header
    header('Content-Type: application/json');

    require_login();

    $courseid = required_param('courseid', PARAM_INT);
    $section = optional_param('section', 0, PARAM_INT);
    $sesskey = optional_param('sesskey', '', PARAM_ALPHANUM);

    if (!confirm_sesskey($sesskey)) {
        ajax_response::error('Invalid session key', 'invalidsesskey');
    }

    $context = context_course::instance($courseid);
    require_capability('aiplacement/modgen:usesuggest', $context);

    $modinfo = get_fast_modinfo($courseid);
    $sectionmap = [];

    // Prefer using the template_reader to obtain richer structure and label/content extraction
    $templatereaderavailable = false;
    try {
        $templclass = 'aiplacement_modgen\\local\\template_reader';
        if (class_exists($templclass)) {
            $templatereaderavailable = true;
        } elseif (file_exists(__DIR__ . '/../classes/local/template_reader.php')) {
            require_once(__DIR__ . '/../classes/local/template_reader.php');
            $templatereaderavailable = class_exists($templclass);
        }
    } catch (\Throwable $e) {
        $templatereaderavailable = false;
    }

    if ($templatereaderavailable) {
        try {
            $classname = 'aiplacement_modgen\\local\\template_reader';
            $reader = new $classname();
            $template = $reader->extract_curriculum_template($courseid . '|' . $section);
            if (!empty($template['structure']) && is_array($template['structure'])) {
                foreach ($template['structure'] as $s) {
                    $sectionmap[] = [
                        'section' => $s['id'] ?? 0,
                        'name' => $s['name'] ?? '',
                        'summary' => $s['summary'] ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fall back to modinfo if template reader fails
            $templatereaderavailable = false;
        }
    }

    if (empty($sectionmap)) {
        $sections = $modinfo->get_section_info_all();
        foreach ($sections as $s) {
            $sectionmap[] = [
                'section' => $s->section,
                'name' => !empty($s->name) ? $s->name : get_string('sectionname', 'moodle', $s->section),
                'summary' => $s->summary ?? '',
            ];
        }
    }

    // If a specific section was requested, filter the map to only that section
    if (!empty($section) && is_int($section) && $section > 0) {
        $filtered = array_values(array_filter($sectionmap, function ($s) use ($section) {
            $id = isset($s['section']) ? (int) $s['section'] : (int) ($s['id'] ?? 0);
            return $id === (int) $section;
        }));
        if (!empty($filtered)) {
            $sectionmap = $filtered;
        }
    }

    $result = ai_service::generate_suggestions_from_map($sectionmap, $courseid);

    // Limit suggestions to prevent unlimited processing (Performance optimization).
    if (!empty($result['suggestions']) && is_array($result['suggestions'])) {
        $result['suggestions'] = array_slice($result['suggestions'], 0, 20);
    }

    // Check if generation failed
    if (!empty($result['error'])) {
        ajax_response::error($result['error'], 'generation_failed', $result);
    }

    // Compute current learning-type mix for the requested section (if any)
    // Map common module names to Laurillard learning types to keep consistent with Explore report.
    $learningtype_map = [
        // Acquisition-like resources
        'page' => 'Acquisition',
        'book' => 'Acquisition',
        'resource' => 'Acquisition',
        'label' => 'Acquisition',
        'url' => 'Acquisition',
        // Discussion/dialogic
        'forum' => 'Discussion',
        'chat' => 'Discussion',
        // Investigation/interactive
        'choice' => 'Investigation',
        'survey' => 'Investigation',
        'workshop' => 'Investigation',
        'hsuforum' => 'Investigation',
        // Practice/adaptive
        'lesson' => 'Practice',
        'feedback' => 'Practice',
        // Production/collaborative
        'assign' => 'Production',
        'assignment' => 'Production',
        'quiz' => 'Production',
        'scorm' => 'Production',
        // Collaboration (webconferencing)
        'bigbluebuttonbn' => 'Collaboration',
        'zoom' => 'Collaboration'
    ];

    $learning_counts = [
        'Acquisition' => 0,
        'Discussion' => 0,
        'Investigation' => 0,
        'Practice' => 0,
        'Collaboration' => 0,
        'Production' => 0,
    ];

    $hasactivities = false;
    if (!empty($section) && is_int($section) && $section > 0) {
        // Find course_sections record for this section number
        $sectionrec = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $section]);
        if ($sectionrec) {
            // Try to obtain the course modules for this section using the already-loaded
            // $modinfo (fast, avoids extra DB queries). If that fails fall back to a
            // direct DB query for course_modules linked to this section id.
            $cms = [];
            try {
                $sections = $modinfo->get_section_info_all();
                $target = null;
                foreach ($sections as $s) {
                    $secnum = isset($s->section) ? (int) $s->section : (int) ($s->id ?? 0);
                    if ($secnum === (int) $section) {
                        $target = $s;
                        break;
                    }
                }
                if ($target && !empty($target->sequence)) {
                    $cmids = array_filter(array_map('intval', explode(',', $target->sequence)));
                    // modinfo may expose cms as an array of cm_info objects.
                    if (!empty($modinfo->cms) && is_array($modinfo->cms)) {
                        foreach ($cmids as $cmid) {
                            if (isset($modinfo->cms[$cmid])) {
                                $cms[] = $modinfo->cms[$cmid];
                            } else if (method_exists($modinfo, 'get_cm')) {
                                $maybe = $modinfo->get_cm($cmid);
                                if ($maybe) {
                                    $cms[] = $maybe;
                                }
                            }
                        }
                    } else {
                        // As a last resort build lightweight cms array from DB course_modules
                        // PERFORMANCE FIX: Batch fetch module names to avoid N+1 queries
                        $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                        if (!empty($dbcms)) {
                            $moduleids = array_unique(array_column((array) $dbcms, 'module'));
                            list($insql, $params) = $DB->get_in_or_equal($moduleids);
                            $modules = $DB->get_records_select('modules', "id $insql", $params);

                            foreach ($dbcms as $dcm) {
                                $dcm->modname = isset($modules[$dcm->module]) ? $modules[$dcm->module]->name : '';
                                $cms[] = $dcm;
                            }
                        }
                    }
                } else {
                    // No sequence on the section (empty section) - try DB fallback
                    // PERFORMANCE FIX: Batch fetch module names to avoid N+1 queries
                    $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                    if (!empty($dbcms)) {
                        $moduleids = array_unique(array_column((array) $dbcms, 'module'));
                        list($insql, $params) = $DB->get_in_or_equal($moduleids);
                        $modules = $DB->get_records_select('modules', "id $insql", $params);

                        foreach ($dbcms as $dcm) {
                            $dcm->modname = isset($modules[$dcm->module]) ? $modules[$dcm->module]->name : '';
                            $cms[] = $dcm;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // If anything goes wrong, fall back to querying course_modules directly
                // PERFORMANCE FIX: Batch fetch module names to avoid N+1 queries
                $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                if (!empty($dbcms)) {
                    $moduleids = array_unique(array_column((array) $dbcms, 'module'));
                    list($insql, $params) = $DB->get_in_or_equal($moduleids);
                    $modules = $DB->get_records_select('modules', "id $insql", $params);

                    foreach ($dbcms as $dcm) {
                        $dcm->modname = isset($modules[$dcm->module]) ? $modules[$dcm->module]->name : '';
                        $cms[] = $dcm;
                    }
                }
            }

            if (!empty($cms) && is_array($cms)) {
                foreach ($cms as $cm) {
                    $modname = '';
                    if (!empty($cm->modname)) {
                        $modname = strtolower($cm->modname);
                    } else if (!empty($cm->module) && is_string($cm->module)) {
                        $modname = strtolower($cm->module);
                    }
                    $lt = $learningtype_map[$modname] ?? 'Production';
                    if (!isset($learning_counts[$lt])) {
                        $learning_counts[$lt] = 0;
                    }
                    $learning_counts[$lt]++;
                    $hasactivities = true;
                }
            }
        }
    }

    // Provide chart-friendly arrays using centralized color configuration
    $labels = array_keys($learning_counts);
    $data = array_values($learning_counts);

    // Use centralized learning type colors instead of hardcoded array
    $colorclass = 'aiplacement_modgen\\local\\learning_type_colors';
    if (!class_exists($colorclass)) {
        require_once(__DIR__ . '/../classes/local/learning_type_colors.php');
    }
    $allcolors = $colorclass::get_activity_type_colors();
    // Map activity type names to colors (lowercase keys in config match display names)
    $colors = [];
    foreach ($labels as $label) {
        $key = strtolower($label);
        $colors[$label] = $allcolors[$key] ?? 'rgba(128, 128, 128, 0.9)';
    }

    $result['current_learning_types'] = [
        'labels' => $labels,
        'data' => $data,
        'colors' => array_map(function ($k) use ($colors) {
            return $colors[$k];
        }, $labels),
        'hasActivities' => $hasactivities,
    ];

    // Discard any accidental output and return JSON
    $extra = @ob_get_clean();
    if ($extra !== false && trim($extra) !== '') {


    }

    ajax_response::success($result);
} catch (\Throwable $e) {

    $buffered = '';
    if (ob_get_length() !== false) {
        $buffered = @ob_get_clean();
    }
    ajax_response::error($e->getMessage(), 'exception', !empty($buffered) ? base64_encode($buffered) : null);
}
