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
 * AJAX endpoint for applying dates to course sections.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use aiplacement_modgen\local\ajax_response;
use aiplacement_modgen\local\date_calculator;

// Require login and valid session.
require_login();
require_sesskey();

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);
$selectedsections = required_param('selectedsections', PARAM_RAW);
$includeparents = optional_param('includeparents', 0, PARAM_INT);
$startdate = optional_param('startdate', 0, PARAM_INT);

// Verify course access and permissions.
$context = context_course::instance($courseid);
require_capability('aiplacement/modgen:managedates', $context);

// Set page context.
$PAGE->set_context($context);

// Acquire course lock.
$lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
$lock = $lockfactory->get_lock('course_edit_' . $courseid, 600);

if (!$lock) {
    ajax_response::error(
        get_string('erroracquiringlock', 'aiplacement_modgen'),
        'lock_failed'
    );
}

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

    if (empty($selectedids)) {
        if ($lock) {
            $lock->release();
        }
        ajax_response::error(
            get_string('nosectionsselected', 'aiplacement_modgen'),
            'no_sections'
        );
    }

    // Get all section IDs to determine which to exclude.
    $modinfo = get_fast_modinfo($courseid);
    $allsections = $modinfo->get_section_info_all();
    $allsectionids = [];
    $sectionmap = [];
    foreach ($allsections as $section) {
        if ($section->section > 0) { // Skip section 0.
            $allsectionids[] = $section->id;
            $sectionmap[$section->id] = $section;
        }
    }

    // Apply dates sequentially to selected sections in order they appear
    require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/date_calculator.php');
    
    // Parse holidays from config
    $holidayconfig = get_config('aiplacement_modgen', 'holiday_dates');
    $holidays = date_calculator::parse_holidays($holidayconfig);
    
    // Get course for start date
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $coursestartdate = $startdate > 0 ? $startdate : (!empty($course->startdate) ? $course->startdate : time());
    
    // Sort selected sections by section number to apply dates in order
    $selectedsections = [];
    foreach ($selectedids as $sectionid) {
        if (isset($sectionmap[$sectionid])) {
            $selectedsections[] = $sectionmap[$sectionid];
        }
    }
    usort($selectedsections, function($a, $b) {
        return $a->section <=> $b->section;
    });

    // Update selected sections with sequential dates
    $updatedcount = 0;
    $updatedsections = [];
    $currentdate = $coursestartdate;

    foreach ($selectedsections as $section) {
        // Calculate week start date, skipping holidays
        $weekstartresult = date_calculator::calculate_week_start($currentdate, $holidays);
        $weekstartdate = $weekstartresult['start'];
        $skipedholidays = $weekstartresult['skipped_holidays'];
        $weekenddate = strtotime('+6 days', $weekstartdate);

        // Format dates in UK style, including holiday names
        $formatteddate = date_calculator::format_date_range_uk($weekstartdate, $weekenddate, $skipedholidays);

        // Remove any existing date from the section name
        $cleanname = date_calculator::remove_existing_date($section->name);

        // Build new name with date prepended
        $newname = $formatteddate . ' ' . $cleanname;

        // Update section in database
        $DB->update_record('course_sections', [
            'id' => $section->id,
            'name' => $newname,
            'timemodified' => time()
        ]);

        $updatedcount++;
        $updatedsections[] = [
            'id' => $section->id,
            'section' => $section->section,
            'name' => $newname,
            'formatted_date' => $formatteddate
        ];

        // Move to next week (skip holidays)
        $currentdate = strtotime('+7 days', $weekstartdate);
    }

    // Rebuild course cache (buffer any output).
    ob_start();
    rebuild_course_cache($courseid, true);
    ob_end_clean();

    // Release lock BEFORE sending response (response calls exit/die).
    if ($lock) {
        $lock->release();
        $lock = null;
    }

    ajax_response::success([
        'updated' => $updatedcount,
        'sections' => $updatedsections,
        'message' => get_string('datesappliedsuccess', 'aiplacement_modgen', $updatedcount),
    ]);

} catch (Exception $e) {
    // Release lock before error response.
    if ($lock) {
        $lock->release();
        $lock = null;
    }
    ajax_response::error($e->getMessage(), 'exception');
}
