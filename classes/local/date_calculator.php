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
     * Detect the layout type of a course module.
     *
     * @param int $courseid Course ID
     * @return array Array with 'type', 'description', and 'details' keys
     *               type: 'theme_based', 'week_based', or 'flat'
     */
    public static function detect_course_layout($courseid) {
        $modinfo = get_fast_modinfo($courseid);
        $sections = $modinfo->get_section_info_all();
        
        // Build section hierarchy.
        $sectionhierarchy = self::build_section_hierarchy($sections);
        
        // Get session names for detection.
        $sessionnames = [
            get_string('presession', 'aiplacement_modgen'),
            get_string('session', 'aiplacement_modgen'),
            get_string('postsession', 'aiplacement_modgen')
        ];
        
        $hasthemes = false;
        $hasweeksunderThemes = false;
        $hasstandaloneweeks = false;
        $toplevelcount = 0;
        
        foreach ($sections as $section) {
            // Skip section 0.
            if ($section->section == 0) {
                continue;
            }
            
            // Skip Introduction & Assessments sections.
            $introsectionname = get_string('introductionsectionname', 'aiplacement_modgen');
            $assessmentssectionname = get_string('assessmentssectionname', 'aiplacement_modgen');
            if ($section->name === $introsectionname || $section->name === $assessmentssectionname) {
                continue;
            }
            
            // Skip session subsections.
            if (in_array($section->name, $sessionnames)) {
                continue;
            }
            
            $isparent = isset($sectionhierarchy['parents'][$section->id]);
            $hasparent = !empty($section->parent);
            
            // Top-level section (no parent).
            if (!$hasparent) {
                $toplevelcount++;
                
                // Check if this top-level section has children.
                if ($isparent) {
                    $hasthemes = true;
                    
                    // Check what kind of children it has.
                    foreach ($sectionhierarchy['parents'][$section->id] as $childid) {
                        $childindex = $sectionhierarchy['id_to_index'][$childid];
                        $childsection = $sections[$childindex];
                        
                        // If child is NOT a session, it's a week.
                        if (!in_array($childsection->name, $sessionnames)) {
                            $hasweeksunderThemes = true;
                            break;
                        }
                    }
                } else {
                    // Top-level section with no children = standalone week.
                    $hasstandaloneweeks = true;
                }
            }
        }
        
        // Determine layout type based on what we found.
        if ($hasthemes && $hasweeksunderThemes) {
            return [
                'type' => 'theme_based',
                'description' => 'Theme-based layout with nested weeks',
                'details' => [
                    'has_themes' => true,
                    'has_weeks_under_themes' => true,
                    'top_level_sections' => $toplevelcount,
                    'hierarchy_levels' => 3, // Theme → Week → Session
                ]
            ];
        } else if ($hasthemes && !$hasweeksunderThemes) {
            return [
                'type' => 'week_based',
                'description' => 'Week-based layout (themes treated as weeks)',
                'details' => [
                    'has_themes' => true,
                    'has_weeks_under_themes' => false,
                    'top_level_sections' => $toplevelcount,
                    'hierarchy_levels' => 2, // Theme (as week) → Session
                ]
            ];
        } else {
            return [
                'type' => 'flat',
                'description' => 'Flat layout with standalone weeks/topics',
                'details' => [
                    'has_themes' => false,
                    'has_weeks_under_themes' => false,
                    'top_level_sections' => $toplevelcount,
                    'hierarchy_levels' => 1, // Week only (may have sessions)
                ]
            ];
        }
    }

    /**
     * Calculate dates for course sections with holiday exclusions.
     *
     * Uses layout detection to intelligently apply dates:
     * - Theme-based: Dates on weeks only, not themes
     * - Week-based: Dates on top-level sections (treated as weeks)
     * - Flat: Dates on all standalone sections
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

        // Detect course layout to determine which sections get dates.
        $layout = self::detect_course_layout($courseid);

        // Parse holidays from config.
        $holidayconfig = get_config('aiplacement_modgen', 'holiday_dates');
        $holidays = self::parse_holidays($holidayconfig);

        // Get course start date (default to now if not set).
        $coursestartdate = !empty($course->startdate) ? $course->startdate : time();

        // Build section hierarchy.
        $sectionhierarchy = self::build_section_hierarchy($sections);

        // Get session names for detection.
        $sessionnames = [
            get_string('presession', 'aiplacement_modgen'),
            get_string('session', 'aiplacement_modgen'),
            get_string('postsession', 'aiplacement_modgen')
        ];

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

            // Skip session subsections.
            $issession = in_array($section->name, $sessionnames);
            if ($issession) {
                continue;
            }

            $isparent = isset($sectionhierarchy['parents'][$section->id]);
            $hasparent = !empty($section->parent);
            $istoplevel = !$hasparent;

            // Determine if this section should get week dates based on layout type.
            $shouldgetdates = false;

            switch ($layout['type']) {
                case 'theme_based':
                    // Only weeks (sections with parents that aren't sessions) get dates.
                    // Themes (top-level parents) do NOT get dates.
                    if ($hasparent && !$issession) {
                        $shouldgetdates = true;
                    }
                    break;

                case 'week_based':
                    // Top-level sections (themes treated as weeks) get dates.
                    if ($istoplevel && $isparent) {
                        $shouldgetdates = true;
                    }
                    break;

                case 'flat':
                    // All top-level sections get dates.
                    if ($istoplevel) {
                        $shouldgetdates = true;
                    }
                    break;
            }

            // Process sections that should get week dates.
            if ($shouldgetdates) {
                // Calculate week start date, skipping holidays.
                $weekstartdate = self::calculate_week_start($currentdate, $holidays);
                $weekenddate = strtotime('+6 days', $weekstartdate);

                // Format dates in UK style.
                $formatteddate = self::format_date_range_uk($weekstartdate, $weekenddate);

                // Remove any existing date from the section name.
                $cleanname = self::remove_existing_date($section->name);

                // Determine if this should be marked as a parent for display purposes.
                // In week_based layouts, top-level sections ARE parents but also get dates.
                $markedasparent = ($layout['type'] === 'week_based' && $istoplevel && $isparent);

                $results[$section->id] = [
                    'id' => $section->id,
                    'section' => $section->section,
                    'name' => $cleanname,
                    'formatted_date' => $formatteddate,
                    'week_number' => $weekcounter,
                    'is_parent' => $markedasparent,
                    'start_timestamp' => $weekstartdate,
                    'end_timestamp' => $weekenddate,
                    'parent_id' => $section->parent ?? 0,
                    'layout_type' => $layout['type']
                ];

                // Move to next week (skip holidays).
                $currentdate = strtotime('+7 days', $weekstartdate);
                $weekcounter++;
            }
        }

        // Process theme sections ONLY for theme-based layouts.
        // In theme-based layouts, themes should NEVER get dates - they're just containers.
        // However, we include them in the results list so they appear in the form.
        if ($layout['type'] === 'theme_based' && !empty($sectionhierarchy['parents'])) {
            foreach ($sectionhierarchy['parents'] as $parentid => $children) {
                $parentsection = $sections[$sectionhierarchy['id_to_index'][$parentid]];

                // Only process theme sections (those with no parent themselves).
                if (!empty($parentsection->parent)) {
                    continue; // Skip week sections that have children (sessions)
                }

                // Skip if theme already processed (shouldn't happen).
                if (isset($results[$parentid])) {
                    continue;
                }

                // Remove any existing date from parent section name.
                $cleanname = self::remove_existing_date($parentsection->name);

                // Calculate date span from child weeks (first week start to last week end).
                $childstartdates = [];
                $childenddates = [];

                foreach ($children as $childid) {
                    if (isset($results[$childid])) {
                        $childstartdates[] = $results[$childid]['start_timestamp'];
                        $childenddates[] = $results[$childid]['end_timestamp'];
                    }
                }

                $themespan = '';
                $themestartts = 0;
                $themeendts = 0;

                if (!empty($childstartdates)) {
                    $themestartts = min($childstartdates);
                    $themeendts = max($childenddates);
                    $themespan = self::format_date_range_uk($themestartts, $themeendts);
                }

                // Include theme with its date span (which can be optionally applied).
                $results[$parentid] = [
                    'id' => $parentid,
                    'section' => $parentsection->section,
                    'name' => $cleanname,
                    'formatted_date' => $themespan, // Date span from first to last week
                    'week_number' => null,
                    'is_parent' => true,
                    'start_timestamp' => $themestartts,
                    'end_timestamp' => $themeendts,
                    'parent_id' => $parentsection->parent ?? 0,
                    'layout_type' => $layout['type']
                ];
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

        // Pattern 1: Month day range format "Dec 1–7:" or "Dec 28–Jan 3:" or "June 1–7:"
        // Handles both short (Dec, Jan) and full (June, July) month names
        $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}–([A-Z][a-z]+\s+)?\d{1,2}:\s*/i', '', $name);

        // Pattern 2: Cross-month format "Nov 29 - Dec 5" or "May 11–June 7"
        // Handles various dash types and full month names
        $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}\s*[-–—]\s*[A-Z][a-z]+\s+\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 3: Old verbose format "Mon 20 Jan - Fri 24 Jan"
        $name = preg_replace('/^[A-Z][a-z]{2}\s+\d{1,2}\s+[A-Z][a-z]+\s*[-–—]\s*[A-Z][a-z]{2}\s+\d{1,2}\s+[A-Z][a-z]+\s*:?\s*/i', '', $name);

        // Pattern 4: Numeric format "20/01 - 24/01" or "20-01 - 24-01"
        $name = preg_replace('/^\d{1,2}[\/\-]\d{1,2}\s*[-–—]\s*\d{1,2}[\/\-]\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 5: Short format "Jan 20-24" or "June 20 - 24"
        $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}\s*[-–—]\s*\d{1,2}\s*:?\s*/i', '', $name);

        // Pattern 6: Dates in parentheses at start "(Dec 1-7) " or "(June 1-7) "
        $name = preg_replace('/^\([^)]*\d{1,2}[^)]*\)\s*:?\s*/i', '', $name);

        // Pattern 7: Dates in parentheses at end " (Dec 1-7)" or " (June 1-7)"
        $name = preg_replace('/\s*\([^)]*\d{1,2}[^)]*\)\s*$/i', '', $name);

        // Apply patterns again to handle doubled dates (e.g., "June 1–7: June 1–7: Title")
        // This ensures we remove all date prefixes if they were applied multiple times
        $previousname = '';
        $iterations = 0;
        while ($name !== $previousname && $iterations < 5) {
            $previousname = $name;
            
            $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}–([A-Z][a-z]+\s+)?\d{1,2}:\s*/i', '', $name);
            $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}\s*[-–—]\s*[A-Z][a-z]+\s+\d{1,2}\s*:?\s*/i', '', $name);
            $name = preg_replace('/^[A-Z][a-z]+\s+\d{1,2}\s*[-–—]\s*\d{1,2}\s*:?\s*/i', '', $name);
            
            $iterations++;
        }

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
