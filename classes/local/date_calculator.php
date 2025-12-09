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
 * Date calculator service for calculating section dates with holiday exclusions.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Service class for calculating section dates with holiday support.
 */
class date_calculator {

    /**
     * Calculate dates for course sections with holiday exclusions.
     *
     * @param int $courseid Course ID
     * @param array $excludedsectionids Section IDs to exclude from calculation
     * @param bool $includeparents Whether to include parent sections with date ranges
     * @return array Array mapping section IDs to date information
     */
    public static function calculate_section_dates($courseid, $excludedsectionids = [], $includeparents = false) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($courseid);
        $sections = $modinfo->get_section_info_all();

        // Parse holidays from config.
        $holidayconfig = get_config('aiplacement_modgen', 'holiday_dates');
        $holidays = self::parse_holidays($holidayconfig);

        // Get course start date (default to now if not set).
        $coursestartdate = !empty($course->startdate) ? $course->startdate : time();

        // Build section hierarchy.
        $sectionhierarchy = self::build_section_hierarchy($sections);

        $results = [];
        $weekcounter = 1;
        $currentdate = $coursestartdate;

        foreach ($sections as $section) {
            // Skip section 0 and excluded sections.
            if ($section->section == 0 || in_array($section->id, $excludedsectionids)) {
                continue;
            }

            // Skip Introduction & General Information and Assessments sections.
            $introsectionname = get_string('introductionsectionname', 'aiplacement_modgen');
            $assessmentssectionname = get_string('assessmentssectionname', 'aiplacement_modgen');
            if ($section->name === $introsectionname || $section->name === $assessmentssectionname) {
                continue;
            }

            $isparent = isset($sectionhierarchy['parents'][$section->id]);
            $hasparent = !empty($section->parent);

            // Detect if this is a session subsection (Pre-session, Session, Post-session).
            $sessionnames = [
                get_string('presession', 'aiplacement_modgen'),
                get_string('session', 'aiplacement_modgen'),
                get_string('postsession', 'aiplacement_modgen')
            ];
            $issession = in_array($section->name, $sessionnames);

            // Skip sessions - we only want weeks and theme sections.
            if ($issession) {
                continue;
            }

            // Determine section type:
            // - Theme with weeks: has children that are NOT sessions (has week children)
            // - Theme without weeks: has children that ARE sessions (sessions are direct children)
            // - Week: has a parent AND may have session children
            // - Standalone week: no parent, no children (flat course structure)
            
            $istheme = $isparent && empty($section->parent);
            
            // Check if this theme has week children or session children
            $hasweekchildren = false;
            if ($istheme && isset($sectionhierarchy['parents'][$section->id])) {
                foreach ($sectionhierarchy['parents'][$section->id] as $childid) {
                    $childindex = $sectionhierarchy['id_to_index'][$childid];
                    $childsection = $sections[$childindex];
                    if (!in_array($childsection->name, $sessionnames)) {
                        $hasweekchildren = true;
                        break;
                    }
                }
            }
            
            // Determine if this should be treated as a week for dating purposes
            $istreatedasweek = false;
            if ($istheme && !$hasweekchildren) {
                // Theme with direct session children - treat as a week
                $istreatedasweek = true;
            } else if ($hasparent && !$istheme) {
                // Has a parent - it's a week in a hierarchy
                $istreatedasweek = true;
            } else if (!$hasparent && !$isparent) {
                // Standalone section (flat course)
                $istreatedasweek = true;
            }

            // Process sections that should get week dates
            if ($istreatedasweek) {
                // Calculate week start date, skipping holidays.
                $weekstartdate = self::calculate_week_start($currentdate, $holidays);
                $weekenddate = strtotime('+6 days', $weekstartdate);

                // Format dates in UK style.
                $formatteddate = self::format_date_range_uk($weekstartdate, $weekenddate);

                // Remove any existing date from the section name.
                $cleanname = self::remove_existing_date($section->name);

                $results[$section->id] = [
                    'id' => $section->id,
                    'section' => $section->section,
                    'name' => $cleanname,
                    'formatted_date' => $formatteddate,
                    'week_number' => $weekcounter,
                    'is_parent' => false,
                    'start_timestamp' => $weekstartdate,
                    'end_timestamp' => $weekenddate,
                    'parent_id' => $section->parent ?? 0
                ];

                // Move to next week (skip holidays).
                $currentdate = strtotime('+7 days', $weekstartdate);
                $weekcounter++;
            }
        }

        // Always process theme sections (top-level parents) for the list, but only add dates if enabled.
        if (!empty($sectionhierarchy['parents'])) {
            foreach ($sectionhierarchy['parents'] as $parentid => $children) {
                $parentsection = $sections[$sectionhierarchy['id_to_index'][$parentid]];

                // Only process theme sections (those with no parent themselves).
                if (!empty($parentsection->parent)) {
                    continue; // Skip week sections that have children (sessions)
                }

                // Remove any existing date from parent section name.
                $cleanname = self::remove_existing_date($parentsection->name);

                if ($includeparents) {
                    // Calculate date range from children.
                    $childstartdates = [];
                    $childenddates = [];

                    foreach ($children as $childid) {
                        if (isset($results[$childid])) {
                            $childstartdates[] = $results[$childid]['start_timestamp'];
                            $childenddates[] = $results[$childid]['end_timestamp'];
                        }
                    }

                    if (!empty($childstartdates)) {
                        $parentstart = min($childstartdates);
                        $parentend = max($childenddates);
                        $formatteddate = self::format_date_range_uk($parentstart, $parentend);

                        $results[$parentid] = [
                            'id' => $parentid,
                            'section' => $parentsection->section,
                            'name' => $cleanname,
                            'formatted_date' => $formatteddate,
                            'week_number' => null,
                            'is_parent' => true,
                            'start_timestamp' => $parentstart,
                            'end_timestamp' => $parentend,
                            'parent_id' => $parentsection->parent ?? 0
                        ];
                    }
                } else {
                    // Include theme section in list but WITHOUT formatted date.
                    // This ensures it appears in the form but won't have dates applied.
                    $results[$parentid] = [
                        'id' => $parentid,
                        'section' => $parentsection->section,
                        'name' => $cleanname,
                        'formatted_date' => '', // Empty - no date will be applied
                        'week_number' => null,
                        'is_parent' => true,
                        'start_timestamp' => 0,
                        'end_timestamp' => 0,
                        'parent_id' => $parentsection->parent ?? 0
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Build section hierarchy map.
     *
     * @param array $sections Array of section info objects
     * @return array Array with 'parents' (parent_id => [child_ids]) and 'id_to_index' mapping
     */
    private static function build_section_hierarchy($sections) {
        $parents = [];
        $idtoindex = [];
        $sectionnumtoid = []; // Map section number to section ID

        foreach ($sections as $index => $section) {
            $idtoindex[$section->id] = $index;
            $sectionnumtoid[$section->section] = $section->id;
        }

        // Second pass: build parent-child relationships using section IDs
        foreach ($sections as $section) {
            if (!empty($section->parent)) {
                // Convert parent section number to parent section ID
                $parentsectionid = $sectionnumtoid[$section->parent] ?? null;
                if ($parentsectionid) {
                    if (!isset($parents[$parentsectionid])) {
                        $parents[$parentsectionid] = [];
                    }
                    $parents[$parentsectionid][] = $section->id;
                }
            }
        }

        return [
            'parents' => $parents,
            'id_to_index' => $idtoindex
        ];
    }

    /**
     * Calculate week start date, skipping holidays.
     *
     * @param int $startdate Starting timestamp
     * @param array $holidays Array of holiday periods
     * @return int Adjusted week start timestamp
     */
    private static function calculate_week_start($startdate, $holidays) {
        $weekstart = $startdate;

        // Ensure start is a Monday.
        $dayofweek = (int)date('N', $weekstart);
        if ($dayofweek !== 1) {
            $daysuntilmonday = (8 - $dayofweek) % 7;
            $weekstart = strtotime("+{$daysuntilmonday} days", $weekstart);
        }

        // Check if week overlaps with any holiday.
        $weekend = strtotime('+6 days', $weekstart);

        foreach ($holidays as $holiday) {
            // Check if this week overlaps with holiday period.
            if (self::date_ranges_overlap($weekstart, $weekend, $holiday['start'], $holiday['end'])) {
                // Skip past the holiday and recalculate.
                $weekstart = strtotime('+1 day', $holiday['end']);
                return self::calculate_week_start($weekstart, $holidays);
            }
        }

        return $weekstart;
    }

    /**
     * Check if two date ranges overlap.
     *
     * @param int $start1 Start of first range
     * @param int $end1 End of first range
     * @param int $start2 Start of second range
     * @param int $end2 End of second range
     * @return bool True if ranges overlap
     */
    private static function date_ranges_overlap($start1, $end1, $start2, $end2) {
        return ($start1 <= $end2 && $end1 >= $start2);
    }

    /**
     * Format date range in UK style.
     *
     * @param int $startdate Start timestamp
     * @param int $enddate End timestamp
     * @return string Formatted date range
     */
    private static function format_date_range_uk($startdate, $enddate) {
        // Format: "Dec 1–7:" (compact style with en-dash)
        $startmonth = userdate($startdate, '%b', 99, false);
        $startday = (int)userdate($startdate, '%d', 99, false);
        $endday = (int)userdate($enddate, '%d', 99, false);
        $endmonth = userdate($enddate, '%b', 99, false);

        // If same month, use format "Dec 1–7:"
        if ($startmonth === $endmonth) {
            return "{$startmonth} {$startday}–{$endday}:";
        } else {
            // Different months: "Dec 28–Jan 3:"
            return "{$startmonth} {$startday}–{$endmonth} {$endday}:";
        }
    }

    /**
     * Remove existing date prefix from section name.
     *
     * Detects and removes various date formats including:
     * - "Dec 1–7:" (current format)
     * - "Nov 29 - Dec 5" (cross-month with space-dash-space)
     * - "Mon 20 Jan - Fri 24 Jan" (old format)
     * - "20/01 - 24/01" (manual format)
     * - "Jan 20-24" (short format)
     * - Dates in parentheses at start or end
     *
     * @param string $name Section name
     * @return string Name with date prefix removed
     */
    public static function remove_existing_date($name) {
        $name = trim($name);

        // Pattern 1: Current format "Dec 1–7:" or "Dec 28–Jan 3:"
        $name = preg_replace('/^[A-Z][a-z]{2}\s+\d{1,2}–([A-Z][a-z]{2}\s+)?\d{1,2}:\s*/i', '', $name);

        // Pattern 2: Cross-month format "Nov 29 - Dec 5" or "Dec 28 - Jan 3"
        $name = preg_replace('/^[A-Z][a-z]{2,9}\s+\d{1,2}\s*[-–—]\s*[A-Z][a-z]{2,9}\s+\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 3: Old verbose format "Mon 20 Jan - Fri 24 Jan"
        $name = preg_replace('/^[A-Z][a-z]{2}\s+\d{1,2}\s+[A-Z][a-z]{2}\s*[-–—]\s*[A-Z][a-z]{2}\s+\d{1,2}\s+[A-Z][a-z]{2}\s*:?\s*/i', '', $name);

        // Pattern 4: Numeric format "20/01 - 24/01" or "20-01 - 24-01"
        $name = preg_replace('/^\d{1,2}[\/\-]\d{1,2}\s*[-–—]\s*\d{1,2}[\/\-]\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 5: Short format "Jan 20-24" or "Jan 20 - 24"
        $name = preg_replace('/^[A-Z][a-z]{2}\s+\d{1,2}\s*[-–—]\s*\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 6: Dates in parentheses at start "(Dec 1-7) "
        $name = preg_replace('/^\([^)]*\d{1,2}[^)]*\)\s*:?\s*/i', '', $name);

        // Pattern 7: Dates in parentheses at end " (Dec 1-7)"
        $name = preg_replace('/\s*\([^)]*\d{1,2}[^)]*\)\s*$/i', '', $name);

        return trim($name);
    }

    /**
     * Parse holiday dates from config text with lenient format acceptance.
     *
     * @param string|null $configtext Configuration text
     * @return array Array of parsed holidays with validation errors tracked
     */
    public static function parse_holidays($configtext) {
        if (empty($configtext)) {
            return [];
        }

        $holidays = [];
        $lines = explode("\n", $configtext);

        foreach ($lines as $linenum => $line) {
            $line = trim($line);

            // Skip empty lines and comments.
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, '//') === 0) {
                continue;
            }

            // Expected format: "Holiday Name: DDMMYYYY-DDMMYYYY"
            // Also accept: DD/MM/YYYY, DD-MM-YYYY variations.
            if (strpos($line, ':') === false) {
                debugging("Invalid holiday format on line " . ($linenum + 1) . ": missing colon separator", DEBUG_DEVELOPER);
                continue;
            }

            list($name, $daterange) = array_map('trim', explode(':', $line, 2));

            if (empty($name) || empty($daterange)) {
                debugging("Invalid holiday format on line " . ($linenum + 1) . ": empty name or date range", DEBUG_DEVELOPER);
                continue;
            }

            // Parse date range (supports multiple separators: -, to, –).
            $daterange = str_replace([' to ', ' – ', '–'], '-', $daterange);
            $dateparts = explode('-', $daterange);

            if (count($dateparts) < 2) {
                debugging("Invalid holiday format on line " . ($linenum + 1) . ": date range must have start and end", DEBUG_DEVELOPER);
                continue;
            }

            $startstr = trim($dateparts[0]);
            $endstr = trim($dateparts[count($dateparts) - 1]); // Handle multiple dashes.

            $startdate = self::parse_date_lenient($startstr);
            $enddate = self::parse_date_lenient($endstr);

            if ($startdate === false || $enddate === false) {
                debugging("Invalid holiday format on line " . ($linenum + 1) . ": could not parse dates", DEBUG_DEVELOPER);
                continue;
            }

            if ($startdate > $enddate) {
                debugging("Invalid holiday on line " . ($linenum + 1) . ": start date is after end date", DEBUG_DEVELOPER);
                continue;
            }

            $holidays[] = [
                'name' => $name,
                'start' => $startdate,
                'end' => $enddate,
                'line' => $linenum + 1
            ];
        }

        return $holidays;
    }

    /**
     * Parse date string with lenient format acceptance.
     *
     * Accepts: DDMMYYYY, DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY
     *
     * @param string $datestr Date string
     * @return int|false Timestamp or false on failure
     */
    private static function parse_date_lenient($datestr) {
        $datestr = trim($datestr);

        // Try standard formats first.
        $formats = [
            'dmY' => '/^(\d{2})(\d{2})(\d{4})$/',           // DDMMYYYY
            'd/m/Y' => '/^(\d{2})\/(\d{2})\/(\d{4})$/',     // DD/MM/YYYY
            'd-m-Y' => '/^(\d{2})-(\d{2})-(\d{4})$/',       // DD-MM-YYYY
            'd.m.Y' => '/^(\d{2})\.(\d{2})\.(\d{4})$/',     // DD.MM.YYYY
        ];

        foreach ($formats as $format => $pattern) {
            if (preg_match($pattern, $datestr, $matches)) {
                $day = (int)$matches[1];
                $month = (int)$matches[2];
                $year = (int)$matches[3];

                // Validate date.
                if (checkdate($month, $day, $year)) {
                    return mktime(0, 0, 0, $month, $day, $year);
                }
            }
        }

        return false;
    }
}
