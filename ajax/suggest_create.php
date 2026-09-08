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
 * AJAX endpoint for creating AI-suggested activities.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Resolve Moodle config.php from plugin ajax directory. Must check it exists before
// requiring it, so a missing/misconfigured install returns a clean JSON error instead
// of a fatal include error.
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'config.php not found', 'path' => $configpath]);
    exit(0);
}
require_once($configpath);
require_once(__DIR__ . '/../lib.php');

use aiplacement_modgen\activitytype\registry;
use aiplacement_modgen\local\ajax_response;

defined('MOODLE_INTERNAL') || die();

// Prevent PHP from outputting HTML errors directly to the response.
@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Buffer unexpected output so we can always return JSON.
@ob_start();

try {
    // Immediately set JSON content-type so clients always see the correct header.
    header('Content-Type: application/json');

    require_login();

    $courseid = required_param('courseid', PARAM_INT);
    $section = required_param('section', PARAM_INT);
    $selected = required_param('selected', PARAM_RAW);

    $context = context_course::instance($courseid);
    require_capability('aiplacement/modgen:usesuggest', $context);

    $sesskey = required_param('sesskey', PARAM_ALPHANUM);
    if (!confirm_sesskey($sesskey)) {
        ajax_response::error('Invalid session key', 'invalidsesskey');
    }

    $course = get_course($courseid);

    // Decode selected JSON.
    $items = json_decode($selected, true);
    if (!is_array($items)) {
        ajax_response::error('Invalid selected data', 'invalid_json');
    }

    // Normalize incoming suggestions to the flat activity shape expected by the registry.
    // The client sends suggestion objects with an `activity` key; the registry expects
    // each item to be an object/array with a top-level `type` property (and other fields).
    $normalized = [];
    foreach ($items as $idx => $it) {
        // If it's an object decode into array first.
        if ($it instanceof stdClass) {
            $it = (array) $it;
        }
        if (isset($it['activity']) && (is_array($it['activity']) || $it['activity'] instanceof stdClass)) {
            $act = (array) $it['activity'];
        } else if (isset($it['type']) || isset($it['name'])) {
            // Already in the expected flat shape.
            $act = (array) $it;
        } else {
            // Unknown shape: keep as-is to allow registry to report meaningful warnings.
            $act = (array) $it;
        }

        // Ensure type is present and trimmed.
        if (isset($act['type'])) {
            $act['type'] = is_string($act['type']) ? trim($act['type']) : $act['type'];
        }

        $normalized[] = $act;
    }

    // Replace items with the normalized array we will send to registry.
    $items = $normalized;

    // Sanitize all text fields to prevent XSS.
    foreach ($items as &$item) {
        if (isset($item['name'])) {
            $item['name'] = clean_param($item['name'], PARAM_TEXT);
        }
        if (isset($item['description'])) {
            $item['description'] = clean_param($item['description'], PARAM_CLEANHTML);
        }
        if (isset($item['type'])) {
            $item['type'] = clean_param($item['type'], PARAM_ALPHANUMEXT);
        }
        if (isset($item['intro'])) {
            $item['intro'] = clean_param($item['intro'], PARAM_CLEANHTML);
        }
    }
    unset($item);

    // Acquire course editing lock (same mechanism used by theme_builder/prompt flows).
    $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
    $lock = $lockfactory->get_lock('course_edit_' . $courseid, 60);
    if (!$lock) {
        ajax_response::error('Could not acquire course editing lock', 'lock_failed');
    }

    try {
        // Create activities using the shared registry helper.
        $result = registry::create_for_section($items, $course, $section);
    } finally {
        $lock->release();
    }

    // Capture any accidental output.
    $extra = @ob_get_clean();
    $response = ['created' => $result['created'] ?? [], 'warnings' => $result['warnings'] ?? []];
    if ($extra !== false && trim($extra) !== '') {
        debugging('Unexpected output before JSON response: ' . $extra, DEBUG_DEVELOPER);
    }

    ajax_response::success($response);
} catch (\Throwable $e) {
    $buffered = '';
    if (ob_get_length() !== false) {
        $buffered = @ob_get_clean();
    }
    ajax_response::error($e->getMessage(), 'exception', !empty($buffered) ? base64_encode($buffered) : null);
}
