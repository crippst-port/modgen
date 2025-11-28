<?php

// Resolve Moodle config.php from plugin ajax directory.
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'config.php not found', 'path' => $configpath]);
    exit(0);
}
require_once($configpath);
require_once(__DIR__ . '/../lib.php');

use aiplacement_modgen\activitytype\registry;

defined('MOODLE_INTERNAL') || die();

// Prevent PHP from outputting HTML errors directly to the response
@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Buffer unexpected output so we can always return JSON
@ob_start();

try {
    // Immediately set JSON content-type so clients always see the correct header
    header('Content-Type: application/json');

    require_login();

    $courseid = required_param('courseid', PARAM_INT);
    $section = required_param('section', PARAM_INT);
    $selected = required_param('selected', PARAM_RAW);

    $context = context_course::instance($courseid);
    require_capability('moodle/course:update', $context);

    $sesskey = required_param('sesskey', PARAM_RAW);
    if (!confirm_sesskey($sesskey)) {
        throw new \moodle_exception('invalidsesskey', 'error');
    }

    $course = get_course($courseid);

    // Decode selected JSON
    $items = json_decode($selected, true);
    if (!is_array($items)) {
        throw new Exception('Invalid selected data');
    }

    // Normalize incoming suggestions to the flat activity shape expected by the registry.
    // The client sends suggestion objects with an `activity` key; the registry expects
    // each item to be an object/array with a top-level `type` property (and other fields).
    $normalized = [];
    foreach ($items as $idx => $it) {
        // If it's an object decode into array first
        if ($it instanceof stdClass) {
            $it = (array)$it;
        }
        if (isset($it['activity']) && (is_array($it['activity']) || $it['activity'] instanceof stdClass)) {
            $act = (array)$it['activity'];
        } else if (isset($it['type']) || isset($it['name'])) {
            // Already in the expected flat shape
            $act = (array)$it;
        } else {
            // Unknown shape: keep as-is to allow registry to report meaningful warnings
            $act = (array)$it;
        }

        // Ensure type is present and trimmed
        if (isset($act['type'])) {
            $act['type'] = is_string($act['type']) ? trim($act['type']) : $act['type'];
        }

        $normalized[] = $act;
    }

    // Replace items with the normalized array we will send to registry
    $items = $normalized;

    // Acquire course editing lock (same mechanism used by theme_builder/prompt flows)
    $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
    $lock = $lockfactory->get_lock('course_edit_' . $courseid, 600);
    if (!$lock) {
        throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
    }

    try {
        // Log original and normalized incoming selected items for debugging
        file_put_contents('/tmp/modgen_suggest_create_incoming.json', "--- ORIGINAL\n" . json_encode($selected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n--- NORMALIZED\n" . json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        // Create activities using the shared registry helper
        $result = registry::create_for_section($items, $course, $section);

        // Log result for debugging
        file_put_contents('/tmp/modgen_suggest_create_result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), FILE_APPEND);
    } finally {
        $lock->release();
    }

    // Capture any accidental output
    $extra = @ob_get_clean();
    $response = ['success' => true, 'created' => $result['created'] ?? [], 'warnings' => $result['warnings'] ?? []];
    if ($extra !== false && trim($extra) !== '') {
        file_put_contents('/tmp/modgen_suggest_create_extra_output.log', $extra, FILE_APPEND);
        $response['debug_extra_base64'] = base64_encode($extra);
    }

    echo json_encode($response);
} catch (\Throwable $e) {
    $buffered = '';
    if (ob_get_length() !== false) {
        $buffered = @ob_get_clean();
    }
    @header('Content-Type: application/json');
    $msg = $e->getMessage();
    file_put_contents('/tmp/modgen_suggest_create_error.log', $msg . "\n" . $e->getTraceAsString() . "\nBufferedOutput:\n" . $buffered . "\n", FILE_APPEND);
    $error = ['success' => false, 'error' => $msg];
    if (!empty($buffered)) {
        $error['debug_extra_base64'] = base64_encode($buffered);
    }
    echo json_encode($error);
}
