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
 * AJAX endpoint for previewing section dates.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\date_calculator;

// Require login and valid session.
require_login();
require_sesskey();

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$excludedsections = optional_param('excludedsections', '', PARAM_RAW);
$includeparents = optional_param('includeparents', 0, PARAM_INT);

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('moodle/course:view', $context);

// Set page context.
$PAGE->set_context($context);

try {
    // Parse excluded sections JSON.
    $excludedids = [];
    if (!empty($excludedsections)) {
        $decoded = json_decode($excludedsections, true);
        if (is_array($decoded)) {
            $excludedids = array_map('intval', $decoded);
        }
    }

    // Calculate dates with exclusions.
    $sections = date_calculator::calculate_section_dates($courseid, $excludedids, (bool)$includeparents);

    // Filter out special sections.
    $introsectionname = get_string('introductionsectionname', 'aiplacement_modgen');
    $assessmentssectionname = get_string('assessmentssectionname', 'aiplacement_modgen');

    $filteredsections = [];
    foreach ($sections as $sectiondata) {
        if ($sectiondata['name'] !== $introsectionname && $sectiondata['name'] !== $assessmentssectionname) {
            // Build proposed name - prepend date.
            $proposedname = $sectiondata['name'];
            if (!empty($sectiondata['formatted_date'])) {
                $proposedname = $sectiondata['formatted_date'] . ' ' . $sectiondata['name'];
            }

            $filteredsections[] = [
                'id' => $sectiondata['id'],
                'section' => $sectiondata['section'],
                'name' => $sectiondata['name'],
                'formatted_date' => $sectiondata['formatted_date'],
                'proposed_name' => $proposedname,
                'is_parent' => $sectiondata['is_parent'],
                'week_number' => $sectiondata['week_number']
            ];
        }
    }

    ajax_response::success([
        'sections' => $filteredsections
    ]);

} catch (Exception $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
