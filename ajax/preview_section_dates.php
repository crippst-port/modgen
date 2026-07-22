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
$selectedsections = optional_param('selectedsections', '', PARAM_RAW);
$startdate = optional_param('startdate', 0, PARAM_INT);

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('moodle/course:view', $context);

// Set page context.
$PAGE->set_context($context);

try {
    global $DB;

    // Parse selected sections JSON.
    $selectedids = [];
    if (!empty($selectedsections)) {
        $decoded = json_decode($selectedsections, true);
        if (is_array($decoded)) {
            $selectedids = array_map('intval', $decoded);
        }
    }

    // If no sections selected, return empty
    if (empty($selectedids)) {
        ajax_response::success(['sections' => []]);
    }

    // Get course and parse holidays
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $holidayconfig = get_config('aiplacement_modgen', 'holiday_dates');
    $holidays = date_calculator::parse_holidays($holidayconfig);

    // Get start date
    $coursestartdate = $startdate > 0 ? $startdate : (!empty($course->startdate) ? $course->startdate : time());

    // Get section info for selected sections
    $modinfo = get_fast_modinfo($courseid);
    $allsections = $modinfo->get_section_info_all();

    // Build map of section ID to section object
    $sectionmap = [];
    foreach ($allsections as $section) {
        $sectionmap[$section->id] = $section;
    }

    // Calculate dates for selected sections in order
    $results = [];
    $currentdate = $coursestartdate;
    $weekcounter = 1;

    foreach ($selectedids as $sectionid) {
        if (!isset($sectionmap[$sectionid])) {
            continue;
        }

        $section = $sectionmap[$sectionid];

        // Calculate week start date and detect holidays
        $weekstartresult = date_calculator::calculate_week_start($currentdate, $holidays);
        $weekstartdate = $weekstartresult['start'];
        $skipedholidays = $weekstartresult['skipped_holidays'];
        $weekenddate = strtotime('+6 days', $weekstartdate);

        // Format dates in UK style, including holiday names
        $formatteddate = date_calculator::format_date_range_uk($weekstartdate, $weekenddate, $skipedholidays);

        // Remove any existing date from the section name
        $cleanname = date_calculator::remove_existing_date($section->name);

        $results[] = [
            'id' => $section->id,
            'section' => $section->section,
            'name' => $cleanname,
            'formatted_date' => $formatteddate,
            'week_number' => $weekcounter,
        ];

        // Move to next week
        $currentdate = strtotime('+7 days', $weekstartdate);
        $weekcounter++;
    }

    $sections = $results;

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
                'week_number' => $sectiondata['week_number'],
            ];
        }
    }

    ajax_response::success([
        'sections' => $filteredsections,
    ]);
} catch (Exception $e) {
    ajax_response::error($e->getMessage(), 'exception');
}
