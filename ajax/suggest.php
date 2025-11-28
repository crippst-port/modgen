<?php

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

// Ensure the ai_service class is available. The class historically lives in
// `classes/local/ai_service.php` but its namespace may be `aiplacement_modgen`
// (not `...\local`). Detect which is present and require the file if needed.
$serviceClass = null;
if (class_exists('\\aiplacement_modgen\\ai_service')) {
    $serviceClass = '\\aiplacement_modgen\\ai_service';
} elseif (class_exists('\\aiplacement_modgen\\local\\ai_service')) {
    $serviceClass = '\\aiplacement_modgen\\local\\ai_service';
} else {
    $aisvcpath = __DIR__ . '/../classes/local/ai_service.php';
    if (file_exists($aisvcpath)) {
        require_once($aisvcpath);
        if (class_exists('\\aiplacement_modgen\\ai_service')) {
            $serviceClass = '\\aiplacement_modgen\\ai_service';
        } elseif (class_exists('\\aiplacement_modgen\\local\\ai_service')) {
            $serviceClass = '\\aiplacement_modgen\\local\\ai_service';
        }
    }
}

defined('MOODLE_INTERNAL') || die();

// If we couldn't locate the ai_service class, return a JSON error immediately.
if ($serviceClass === null) {
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'ai_service class not found']);
    exit(0);
}

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
    $sesskey = optional_param('sesskey', '', PARAM_RAW);

    if (!confirm_sesskey($sesskey)) {
        throw new \moodle_exception('invalidsesskey', 'error');
    }

    $context = context_course::instance($courseid);
    require_capability('moodle/course:update', $context);

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
            file_put_contents('/tmp/modgen_suggest_template_reader_error.log', $e->getMessage() . "\n", FILE_APPEND);
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
        $filtered = array_values(array_filter($sectionmap, function($s) use ($section) {
            $id = isset($s['section']) ? (int)$s['section'] : (int)($s['id'] ?? 0);
            return $id === (int)$section;
        }));
        if (!empty($filtered)) {
            $sectionmap = $filtered;
        }
    }

    $result = $serviceClass::generate_suggestions_from_map($sectionmap, $courseid);

    // Compute current learning-type mix for the requested section (if any)
    // Map common module names to Laurillard learning types to keep consistent with Explore report.
    $learningtype_map = [
        // Acquisition-like resources
        'page' => 'Acquisition', 'book' => 'Acquisition', 'resource' => 'Acquisition', 'label' => 'Acquisition', 'url' => 'Acquisition',
        // Discussion/dialogic
        'forum' => 'Discussion', 'chat' => 'Discussion',
        // Inquiry/interactive
        'choice' => 'Inquiry', 'survey' => 'Inquiry', 'workshop' => 'Inquiry', 'hsuforum' => 'Inquiry',
        // Practice/adaptive
        'lesson' => 'Practice', 'feedback' => 'Practice',
        // Production/collaborative
        'assign' => 'Production', 'assignment' => 'Production', 'quiz' => 'Production', 'scorm' => 'Production',
        // Collaboration (webconferencing)
        'bigbluebuttonbn' => 'Collaboration', 'zoom' => 'Collaboration'
    ];

    $learning_counts = [
        'Acquisition' => 0,
        'Discussion' => 0,
        'Inquiry' => 0,
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
                        $secnum = isset($s->section) ? (int)$s->section : (int)($s->id ?? 0);
                        if ($secnum === (int)$section) {
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
                            $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                            foreach ($dbcms as $dcm) {
                                $modname = $DB->get_field('modules', 'name', ['id' => $dcm->module]);
                                $dcm->modname = $modname ?: '';
                                $cms[] = $dcm;
                            }
                        }
                    } else {
                        // No sequence on the section (empty section) - try DB fallback
                        $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                        foreach ($dbcms as $dcm) {
                            $modname = $DB->get_field('modules', 'name', ['id' => $dcm->module]);
                            $dcm->modname = $modname ?: '';
                            $cms[] = $dcm;
                        }
                    }
                } catch (\Throwable $e) {
                    // If anything goes wrong, fall back to querying course_modules directly
                    $dbcms = $DB->get_records('course_modules', ['section' => $sectionrec->id]);
                    foreach ($dbcms as $dcm) {
                        $modname = $DB->get_field('modules', 'name', ['id' => $dcm->module]);
                        $dcm->modname = $modname ?: '';
                        $cms[] = $dcm;
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

    // Provide chart-friendly arrays
    $labels = array_keys($learning_counts);
    $data = array_values($learning_counts);
    $colors = [
        'Acquisition' => 'rgba(66, 139, 202, 0.9)',
        'Discussion' => 'rgba(40, 167, 69, 0.9)',
        'Inquiry' => 'rgba(255, 152, 0, 0.9)',
        'Practice' => 'rgba(255, 193, 7, 0.9)',
        'Collaboration' => 'rgba(75, 192, 192, 0.9)',
        'Production' => 'rgba(220, 53, 69, 0.9)',
    ];

    $result['current_learning_types'] = [
        'labels' => $labels,
        'data' => $data,
        'colors' => array_map(function($k) use ($colors) { return $colors[$k]; }, $labels),
        'hasActivities' => $hasactivities,
    ];

    // Discard any accidental output and return JSON
    $extra = @ob_get_clean();
    if ($extra !== false && trim($extra) !== '') {
        // Log unexpected output for debugging
        file_put_contents('/tmp/modgen_suggest_extra_output.log', $extra, FILE_APPEND);
        // Attach a base64-encoded debug field so client can see unexpected HTML without breaking JSON.parse
        $result['debug_extra_base64'] = base64_encode($extra);
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    // Capture any buffered output, include in error response for debugging
    $buffered = '';
    if (ob_get_length() !== false) {
        $buffered = @ob_get_clean();
    }
    @header('Content-Type: application/json');
    $msg = $e->getMessage();
    // Log the exception for debugging
    file_put_contents('/tmp/modgen_suggest_error.log', $msg . "\n" . $e->getTraceAsString() . "\nBufferedOutput:\n" . $buffered . "\n", FILE_APPEND);
    $error = ['success' => false, 'error' => $msg];
    if (!empty($buffered)) {
        $error['debug_extra_base64'] = base64_encode($buffered);
    }
    echo json_encode($error);
}
