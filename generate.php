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
 * AJAX generator page for modal loading.
 *
 * This is a simplified version of prompt.php designed specifically for
 * loading in modals via AJAX. It only handles form display and submission,
 * without full page headers/footers.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// Enable error logging to specific file
$logfile = '/tmp/modgen_generate_debug.log';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $logfile);
error_log('=== Generate.php called at ' . date('Y-m-d H:i:s') . ' ===');

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

error_log('Config loaded successfully');

// Must be logged in
try {
    require_login();
    error_log('User logged in successfully');
} catch (Exception $e) {
    error_log('Login failed: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Not logged in',
        'redirect' => $CFG->wwwroot . '/login/index.php'
    ]);
    exit;
}

// Clean any output buffering and prevent unwanted output
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Prevent caching of form HTML
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

try {
    // Ensure formslib is loaded
    require_once($CFG->libdir . '/formslib.php');
    
    // Include form class
    require_once(__DIR__ . '/classes/form/generator_form.php');

    // Get course ID
    $courseid = required_param('courseid', PARAM_INT);
    $context = context_course::instance($courseid);

    // Verify permission
    require_capability('moodle/course:update', $context);

    // Set page context
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/ai/placement/modgen/generate.php', ['courseid' => $courseid]));

    // Check AI policy acceptance
    $manager = \core\di::get(\core_ai\manager::class);
    if (!$manager->get_user_policy_status($USER->id)) {
        // Return policy acceptance form
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'html' => $OUTPUT->render_from_template('aiplacement_modgen/ai_policy', [
                'courseid' => $courseid,
                'policytext' => get_string('aipolicyinfo', 'aiplacement_modgen'),
            ]),
        ]);
        exit;
    }

    // Create form
    $formdata = [
        'courseid' => $courseid,
        'embedded' => 1,
        'contextid' => $context->id,
    ];
    $form = new aiplacement_modgen_generator_form(null, $formdata);

    // Handle form cancellation
    if ($form->is_cancelled()) {
        ob_end_clean();
        echo json_encode(['success' => true, 'action' => 'close']);
        exit;
    }

    // Handle form submission
    if ($data = $form->get_data()) {
        // Process the form data (same logic as prompt.php)
        // This will be handled by redirecting to the processing endpoint
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'action' => 'redirect',
            'url' => (new moodle_url('/ai/placement/modgen/prompt.php', [
                'id' => $courseid,
                'embedded' => 1,
            ]))->out(false),
            'formdata' => (array)$data,
        ]);
        exit;
    }

    // Display the form
    ob_start();
    $form->display();
    $formhtml = ob_get_clean();

    // Get any page requirements JS
    ob_start();
    echo $OUTPUT->footer();
    $footer = ob_get_clean();

    // Extract just the JavaScript from footer
    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $footer, $scripts);
    $jsCode = implode("\n", $scripts[1] ?? []);

    // Clean output buffer and return JSON response
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'html' => $formhtml,
        'javascript' => $jsCode,
    ]);

} catch (Exception $e) {
    // Clean output buffer and return error
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(200); // Send 200 so browser can parse JSON
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
} catch (Throwable $e) {
    // Catch PHP 7+ errors as well
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(200); // Send 200 so browser can parse JSON
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
}
