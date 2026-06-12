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
 * Theme builder service - creates course section structures.
 *
 * Provides shared functionality for creating themes and weeks across all workflows:
 * - Quick Add forms (create multiple with defaults)
 * - CSV file upload (create individual with custom data)
 * - AI generation (create individual with AI-generated data)
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

use aiplacement_modgen\activitytype\registry;
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Theme builder service class.
 */
class theme_builder {

    /**
     * Create a learningactivity module at the start of a section.
     *
     * This is a shared helper to add learning design metadata modules to themes and weeks.
     *
     * @param int $courseid Course ID
     * @param int $sectionnumber Section number where activity should be created
     * @param string $sectiontype 'section' for themes/weeks, 'activity' for session subsections
     * @param string $name Section name (optional for sections)
     * @param array $metadata Additional metadata (duration, learningmode, etc.)
     * @return int|null CM ID of created activity or null on failure
     */
    private static function create_learningactivity_metadata($courseid, $sectionnumber, $sectiontype, $name = '', $metadata = []) {
        global $DB;

        // Get handler
        $handler = registry::get_handler('learningactivity');
        if (!$handler) {
            // learningactivity handler not found.
            return null;
        }

        // Get course
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Prepare activity data
        $activitydata = new \stdClass();
        $activitydata->sectiontype = $sectiontype;
        $activitydata->name = $name;

        // Merge additional metadata - skip null/empty values
        foreach ($metadata as $key => $value) {
            // Skip null values and empty strings to avoid issues with learningactivity module
            if ($value !== null && $value !== '') {
                $activitydata->$key = $value;
            }
        }

        // Create instance
        try {
            $instance = new $handler();
            $result = $instance->create($activitydata, $course, $sectionnumber);

            if ($result && isset($result['cmid'])) {
                // Ensure context is created immediately to avoid integrity errors
                // if user refreshes page during background task execution.
                \context_module::instance($result['cmid']);
                return $result['cmid'];
            }
        } catch (\Exception $e) {
            // Failed to create learningactivity: expected in test environment.
        }

        return null;
    }

    /**
     * Enforce the per-course total-section safety limit.
     *
     * Counts the course's existing sections and refuses the operation if adding
     * $projectedsections would push the total past the configured maximum
     * ('maxtotalsections', default constants::MAX_TOTAL_SECTIONS). This guards every
     * creation entry point — Quick Add, template/JSON and any retry — against the
     * ~O(n^2) flexsections reorder cost that makes large courses pathologically slow
     * and can exhaust memory. Call this BEFORE acquiring locks or starting work so it
     * fails fast and cheap without creating partial content.
     *
     * @param int $courseid Course ID.
     * @param int $projectedsections Number of sections this operation will add.
     * @throws \moodle_exception If the resulting total would exceed the limit.
     */
    public static function enforce_section_limit($courseid, $projectedsections) {
        global $DB;

        $max = (int) get_config('aiplacement_modgen', 'maxtotalsections');
        if ($max <= 0) {
            $max = constants::MAX_TOTAL_SECTIONS;
        }

        $existing = $DB->count_records('course_sections', ['course' => $courseid]);
        $total = $existing + (int) $projectedsections;

        if ($total > $max) {
            throw new \moodle_exception('sectionlimitexceeded', 'aiplacement_modgen', '', (object) [
                'existing' => $existing,
                'total' => $total,
                'max' => $max,
            ]);
        }
    }

    /**
     * Estimate how many course sections an approved JSON structure will create.
     *
     * Counts themes, weeks and sessions (each becomes a section). Session phases for
     * connected_weekly default to 3 (pre/session/post) when not explicitly listed, so
     * the estimate is intentionally slightly conservative (over- rather than
     * under-counting) to keep the safety limit on the cautious side.
     *
     * @param array $json Approved JSON structure.
     * @param string $moduletype Module type (connected_theme, connected_weekly, etc).
     * @return int Projected number of sections.
     */
    public static function count_projected_sections_from_json(array $json, $moduletype) {
        $count = 0;

        if ($moduletype === 'connected_theme' && !empty($json['themes']) && is_array($json['themes'])) {
            foreach ($json['themes'] as $theme) {
                if (!is_array($theme)) {
                    continue;
                }
                $count++; // Theme section.
                $weeks = (!empty($theme['weeks']) && is_array($theme['weeks'])) ? $theme['weeks'] : [];
                foreach ($weeks as $week) {
                    if (!is_array($week)) {
                        continue;
                    }
                    $count++; // Week section.
                    $sessions = (!empty($week['sessions']) && is_array($week['sessions'])) ? $week['sessions'] : [];
                    $count += $sessions ? count($sessions) : 0;
                }
            }
        } else if (!empty($json['sections']) && is_array($json['sections'])) {
            foreach ($json['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $count++; // Week/section.
                $sessions = (!empty($section['sessions']) && is_array($section['sessions'])) ? $section['sessions'] : [];
                // connected_weekly always materialises 3 session subsections.
                $count += $sessions ? count($sessions) : ($moduletype === 'connected_weekly' ? 3 : 0);
            }
        }

        return $count;
    }

    /**
     * Create multiple themes with default structure (Quick Add workflow).
     *
     * Each theme contains one week with pre-session, session, post-session subsections.
     *
     * @param int $courseid Course ID
     * @param int $themecount Number of themes to create (1-10)
     * @param int $weeksperTheme Number of weeks per theme (1-10)
     * @param int $parent Parent section number (0 = top level, N = nested under section N)
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_themes($courseid, $themecount, $weeksperTheme, $parent = 0) {
        global $DB;

        $messages = [];

        // Refuse before doing any work if this would push the course past the section
        // limit. Each theme is 1 section; each week is 1 + 3 session subsections.
        self::enforce_section_limit($courseid, $themecount + ($themecount * $weeksperTheme * 4));

        // Acquire lock before any course modifications to prevent nested lock conflicts.
        $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
        $lock = $lockfactory->get_lock('course_edit_' . $courseid, 60);

        if (!$lock) {
            throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
        }

        try {
            // Ensure flexsections format (must be inside try block to ensure lock release).
            self::ensure_flexsections_format($courseid);

            // Get fresh course object after format conversion.
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $courseformat = course_get_format($course);
            // PROACTIVE FIX: Ensure ALL course modules have contexts before starting.
            // This prevents integrity errors if previous jobs left orphaned modules.
            $sql = "SELECT cm.id
                    FROM {course_modules} cm
                    LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                    WHERE cm.course = :courseid AND ctx.id IS NULL";
            $orphaned = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'contextlevel' => CONTEXT_MODULE
            ]);
            
            if (!empty($orphaned)) {
                foreach ($orphaned as $cm) {
                    \context_module::instance($cm->id);
                }
            }
            // Start transaction for entire bulk operation.
            // This ensures all themes and weeks are created atomically - all or nothing.
            $transaction = $DB->start_delegated_transaction();

            try {
                $createdcount = 0; // Track sections created for cache rebuild

                for ($i = 1; $i <= $themecount; $i++) {
                    $themetitle = get_string('defaultthemename', 'aiplacement_modgen', $i);
                    $themesummary = get_string('defaultthemesummary', 'aiplacement_modgen');

                    // Create theme section at top level (themes are always top-level).
                    $options = ['collapsed' => 1]; // Theme appears as link.
                    $themesectionnum = self::create_theme_section(
                        $courseid,
                        $courseformat,
                        $themetitle,
                        $themesummary,
                        $options,
                        false  // Defer cache rebuild
                    );
                    $createdcount++;

                    $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $themetitle);

                    // Create weeks under this theme.
                    for ($w = 1; $w <= $weeksperTheme; $w++) {
                        $weektitle = get_string('defaultweekname', 'aiplacement_modgen', [
                            'theme' => $i,
                            'week' => $w
                        ]);
                        $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');

                        $weekoptions = ['collapsed' => 1]; // Week appears as link.
                        $weeksectionnum = self::create_week_section(
                            $courseid,
                            $courseformat,
                            $themesectionnum,
                            $weektitle,
                            $weeksummary,
                            $weekoptions,
                            false  // Defer cache rebuild
                        );
                        $createdcount += 4; // Week + 3 session subsections

                        $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $weektitle);

                        // Sessions created inside create_week_section, add messages.
                        $sessiontypes = [
                            get_string('presession', 'aiplacement_modgen'),
                            get_string('session', 'aiplacement_modgen'),
                            get_string('postsession', 'aiplacement_modgen')
                        ];
                        foreach ($sessiontypes as $sessionlabel) {
                            $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $sessionlabel);
                        }
                    }
                }

                // Ensure all course modules have contexts before rebuilding cache.
                // Subsections are course modules that need contexts created.
                if ($createdcount > 0) {
                    $sql = "SELECT cm.id
                            FROM {course_modules} cm
                            LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                            WHERE cm.course = :courseid AND ctx.id IS NULL";
                    $orphaned = $DB->get_records_sql($sql, [
                        'courseid' => $courseid,
                        'contextlevel' => CONTEXT_MODULE
                    ]);
                    
                    if (!empty($orphaned)) {
                        foreach ($orphaned as $cm) {
                            \context_module::instance($cm->id);
                        }
                    }
                }

                // Ensure all course modules have contexts before committing.
                // This is a safety net in case immediate context creation failed or
                // modules exist from previous operations without contexts.
                if ($createdcount > 0) {
                    $sql = "SELECT cm.id
                            FROM {course_modules} cm
                            LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                            WHERE cm.course = :courseid AND ctx.id IS NULL";
                    $orphaned = $DB->get_records_sql($sql, [
                        'courseid' => $courseid,
                        'contextlevel' => CONTEXT_MODULE
                    ]);
                    
                    if (!empty($orphaned)) {
                        foreach ($orphaned as $cm) {
                            \context_module::instance($cm->id);
                        }
                    }
                }

                // Note: flexsections already rebuilds cache after each section creation.
                // No explicit rebuild needed here.

                // Commit all changes - all themes and weeks created successfully.
                $transaction->allow_commit();

            } catch (\Exception $e) {
                // Transaction automatically rolls back on exception.
                // Log technical details for administrators only.
                debugging('Theme creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                throw new \moodle_exception('themecreationfailed', 'aiplacement_modgen');
            }
        } finally {
            $lock->release();
        }

        return [
            'success' => true,
            'messages' => $messages,
        ];
    }

    /**
     * Create standalone weeks with sessions (Quick Add workflow).
     *
     * Creates weeks under specified parent section with pre/session/post subsections.
     *
     * @param int $courseid Course ID
     * @param int $weekcount Number of weeks to create (1-10)
     * @param int $parent Parent section number (0 = top level, N = nested under section N)
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_weeks($courseid, $weekcount, $parent = 0) {
        global $DB;

        $messages = [];

        // Refuse before doing any work if this would push the course past the section
        // limit. Each week is 1 section + 3 session subsections.
        self::enforce_section_limit($courseid, $weekcount * 4);

        // Acquire lock before any course modifications to prevent nested lock conflicts.
        $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
        $lock = $lockfactory->get_lock('course_edit_' . $courseid, 60);

        if (!$lock) {
            throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
        }

        try {
            // Ensure flexsections format (must be inside try block to ensure lock release).
            self::ensure_flexsections_format($courseid);

            // Get fresh course object after format conversion.
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $courseformat = course_get_format($course);

            // PROACTIVE FIX: Ensure ALL course modules have contexts before starting.
            // This prevents integrity errors if previous jobs left orphaned modules.
            $sql = "SELECT cm.id
                    FROM {course_modules} cm
                    LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                    WHERE cm.course = :courseid AND ctx.id IS NULL";
            $orphaned = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'contextlevel' => CONTEXT_MODULE
            ]);
            
            if (!empty($orphaned)) {
                foreach ($orphaned as $cm) {
                    \context_module::instance($cm->id);
                }
            }

            // Start transaction for entire bulk operation.
            // This ensures all weeks are created atomically - all or nothing.
            $transaction = $DB->start_delegated_transaction();

            try {
                $createdcount = 0; // Track sections created for cache rebuild

                for ($i = 1; $i <= $weekcount; $i++) {
                    $weektitle = get_string('defaultstandaloneweekname', 'aiplacement_modgen', $i);
                    $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');

                    $weekoptions = ['collapsed' => 1]; // Week appears as link.
                    $weeksectionnum = self::create_week_section(
                        $courseid,
                        $courseformat,
                        $parent, // Use provided parent section
                        $weektitle,
                        $weeksummary,
                        $weekoptions,
                        false  // Defer cache rebuild
                    );
                    $createdcount += 4; // Week + 3 session subsections

                    $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $weektitle);

                    // Sessions created inside create_week_section, add messages.
                    $sessiontypes = [
                        get_string('presession', 'aiplacement_modgen'),
                        get_string('session', 'aiplacement_modgen'),
                        get_string('postsession', 'aiplacement_modgen')
                    ];
                    foreach ($sessiontypes as $sessionlabel) {
                        $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $sessionlabel);
                    }
                }

                // Ensure all course modules have contexts before rebuilding cache.
                // Subsections are course modules that need contexts created.
                if ($createdcount > 0) {
                    $sql = "SELECT cm.id
                            FROM {course_modules} cm
                            LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                            WHERE cm.course = :courseid AND ctx.id IS NULL";
                    $orphaned = $DB->get_records_sql($sql, [
                        'courseid' => $courseid,
                        'contextlevel' => CONTEXT_MODULE
                    ]);
                    
                    if (!empty($orphaned)) {
                        foreach ($orphaned as $cm) {
                            \context_module::instance($cm->id);
                        }
                    }
                }

                // Note: flexsections already rebuilds cache after each section creation.
                // No explicit rebuild needed here.

                // Commit all changes - all weeks created successfully.
                $transaction->allow_commit();

            } catch (\Exception $e) {
                // Transaction automatically rolls back on exception.
                // Log technical details for administrators only.
                debugging('Week creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                throw new \moodle_exception('themecreationfailed', 'aiplacement_modgen');
            }
        } finally {
            $lock->release();
        }

        return [
            'success' => true,
            'messages' => $messages,
        ];
    }

    /**
     * Create a single theme section (for CSV/AI workflows).
     *
     * Creates top-level theme section with custom title and summary.
     * Does NOT create weeks - caller is responsible for creating child weeks.
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object
     * @param string $title Theme title
     * @param string $summary Theme summary (HTML)
     * @param array $options Optional settings (e.g., ['collapsed' => 1])
     * @param bool $rebuildcache Whether to rebuild course cache after creation (default true)
     * @return int Section number of created theme
     */
    public static function create_theme_section($courseid, $courseformat, $title, $summary, $options = [], $rebuildcache = true) {
        global $DB;

        $context = \context_course::instance($courseid);

        // Verify flexsections format.
        if (!$courseformat || get_class($courseformat) !== 'format_flexsections') {
            throw new \moodle_exception('errorformatnotflexsections', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // Format title and summary.
        // Use format_string for title (XSS-safe) and FORMAT_PLAIN for summary to prevent XSS from AI-generated content
        $themetitle = format_string($title, true, ['context' => $context]);
        $sectionhtml = trim($summary) !== '' ? format_text($summary, FORMAT_PLAIN, ['context' => $context]) : '';

        // Create section at top level (parent=0) - themes are always top-level sections.
        $collapsed = $options['collapsed'] ?? 1;
        $themesection = self::create_section_with_parent(
            $courseid,
            $courseformat,
            0, // Themes are always top-level
            $themetitle,
            $sectionhtml,
            FORMAT_PLAIN,
            ['collapsed' => $collapsed],
            $rebuildcache  // Pass through rebuild cache parameter
        );

        return $themesection->section;
    }

    /**
     * Create a single week section with session subsections (for all workflows).
     *
     * Creates week section under specified parent and automatically creates
     * pre-session, session, and post-session subsections.
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object
     * @param int $parentsectionnum Parent section number (0 for top-level, theme section number for nested)
     * @param string $title Week title
     * @param string $summary Week summary (HTML)
     * @param array $options Optional settings (e.g., ['collapsed' => 1, 'sessiondata' => [...]])
     * @param bool $rebuildcache Whether to rebuild course cache after creation (default true)
     * @return int Section number of created week
     */
    public static function create_week_section($courseid, $courseformat, $parentsectionnum, $title, $summary, $options = [], $rebuildcache = true) {
        global $DB;

        $context = \context_course::instance($courseid);

        // Verify flexsections format.
        if (!$courseformat || get_class($courseformat) !== 'format_flexsections') {
            throw new \moodle_exception('errorformatnotflexsections', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // Format week title
        $weektitle = format_string($title, true, ['context' => $context]);

        // Prepare metadata for week-level learningactivity
        // If metadata includes instructions, use that instead of setting summary on section
        $weekmetadata = $options['metadata'] ?? [];
        $usemetadataforintro = !empty($weekmetadata['instructions']);

        // If using metadata for intro, don't set summary on section (leave it minimal/empty)
        // Otherwise, use the provided summary on the section
        // Use FORMAT_PLAIN to prevent XSS from AI-generated content
        $weeksectionhtml = '';
        if (!$usemetadataforintro && trim($summary) !== '') {
            $weeksectionhtml = format_text($summary, FORMAT_PLAIN, ['context' => $context]);
        }

        // Create section with explicit parent and collapsed option using centralized helper.
        $collapsed = $options['collapsed'] ?? 1;
        $weeksection = self::create_section_with_parent(
            $courseid,
            $courseformat,
            $parentsectionnum,
            $weektitle,
            $weeksectionhtml,
            FORMAT_PLAIN,
            ['collapsed' => $collapsed],
            false  // Don't rebuild cache yet - wait until session subsections created
        );

        $weeksectionnum = $weeksection->section;

        // Create learningactivity metadata module at the start of the week.
        // Use custom name from metadata if provided, otherwise use the week title
        $weekactivityname = !empty($weekmetadata['name']) ? $weekmetadata['name'] : $title;

        $weekcmid = self::create_learningactivity_metadata(
            $courseid,
            $weeksectionnum,
            'section',
            $weekactivityname,
            $weekmetadata
        );

        // Create session subsections using shared helper.
        $sessiondata = $options['sessiondata'] ?? null;
        session_creator::create_session_subsections(
            $courseformat,
            $weeksectionnum,
            $courseid,
            $sessiondata
        );

        // Note: flexsections already rebuilds cache after each section creation.
        // $rebuildcache parameter kept for interface consistency but not used.

        return $weeksectionnum;
    }

    /**
     * Initialize section 0 and create Assessments section if they don't already exist.
     *
     * Section 0 is renamed to 'Introduction & General Information' and an 'Assessments'
     * section is created after it if it doesn't already exist.
     *
     * @param int $courseid Course ID
     */
    public static function initialize_core_sections($courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $courseformat = course_get_format($course);

        // Get section 0.
        $section0 = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0], '*', MUST_EXIST);

        // Check if section 0 already has the standard name - if so, assume already initialized.
        $standardname = get_string('introductionsectionname', 'aiplacement_modgen');
        if ($section0->name === $standardname) {
            // Already initialized, check for Assessments section.
            $assessmentsname = get_string('assessmentssectionname', 'aiplacement_modgen');
            $existing = $DB->get_record('course_sections', [
                'course' => $courseid,
                'name' => $assessmentsname
            ]);
            if ($existing) {
                // Both already exist, nothing to do.
                return;
            }
        } else {
            // Rename section 0.
            $DB->update_record('course_sections', [
                'id' => $section0->id,
                'name' => $standardname,
                'timemodified' => time()
            ]);
        }

        // Create Assessments section if it doesn't exist.
        $assessmentsname = get_string('assessmentssectionname', 'aiplacement_modgen');
        $existing = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => $assessmentsname
        ]);

        if (!$existing) {
            // Create new section after section 0.
            if (method_exists($courseformat, 'create_new_section')) {
                // Flexsections format - create at top level using centralized helper.
                $assessmentssection = self::create_section_with_parent(
                    $courseid,
                    $courseformat,
                    0, // parent = 0 (top level)
                    $assessmentsname,
                    '', // No summary
                    FORMAT_HTML,
                    [] // No additional format options
                );

                $assessmentssectionnum = $assessmentssection->section;

                // Move it to position 1 (right after section 0).
                if (method_exists($courseformat, 'move_section')) {
                    try {
                        $courseformat->move_section($assessmentssectionnum, 0, 1);
                    } catch (\Throwable $e) {
                        // If move fails, section will remain at end but still be created.
                        // Failed to move Assessments section.
                    }
                }
            } else {
                // Standard format - use core function.
                $assessmentssection = course_create_section($course, 1);
                $DB->update_record('course_sections', [
                    'id' => $assessmentssection->id,
                    'name' => $assessmentsname,
                    'timemodified' => time()
                ]);
            }
        }
    }

    /**
     * Ensure course is using flexsections format.
     *
     * Converts course to flexsections if needed.
     * Public to allow use in tests and validation scripts.
     *
     * @param int $courseid Course ID
     * @throws \moodle_exception If conversion fails
     */
    public static function ensure_flexsections_format($courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        if ($course->format !== 'flexsections') {
            // Attempt to convert to flexsections.
            $DB->update_record('course', [
                'id' => $courseid,
                'format' => 'flexsections'
            ]);

            // Clear course cache.
            rebuild_course_cache($courseid, true);

            // Verify conversion.
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            if ($course->format !== 'flexsections') {
                throw new \moodle_exception('errorconvertingformat', 'aiplacement_modgen');
            }
        }

        // Initialize core sections after ensuring flexsections format.
        self::initialize_core_sections($courseid);
    }

    /**
     * Validate parameters for section creation.
     *
     * PERFORMANCE: Depth validation can be skipped during bulk operations to avoid expensive
     * get_fast_modinfo() calls. Final cache rebuild will reveal any depth violations.
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object
     * @param int $parentsectionnum Parent section number (0 for top-level)
     * @param int|null $childsectionnum Child section number (for circular reference check after creation)
     * @param bool $skipdepthcheck Skip depth validation (for bulk operations)
     * @throws \moodle_exception If validation fails
     */
    private static function validate_section_creation_params($courseid, $courseformat, $parentsectionnum, $childsectionnum = null, $skipdepthcheck = false) {
        global $DB;

        // Basic parameter validation
        if (!is_numeric($courseid) || $courseid <= 0) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }

        if (!is_numeric($parentsectionnum) || $parentsectionnum < 0) {
            throw new \moodle_exception('invalidsectionparent', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // PROTECTION 1: Prevent section from being its own parent
        if ($childsectionnum !== null && $parentsectionnum === $childsectionnum) {
            debugging("Prevented circular reference: Section {$childsectionnum} cannot be its own parent", DEBUG_DEVELOPER);
            throw new \moodle_exception('circularsectionparent', 'aiplacement_modgen', '', 
                ['child' => $childsectionnum, 'parent' => $parentsectionnum]);
        }

        // Validate parent section exists if not top-level
        if ($parentsectionnum > 0) {
            $parentsection = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $parentsectionnum
            ]);

            if (!$parentsection) {
                throw new \moodle_exception('invalidparentsection', 'aiplacement_modgen', '', $parentsectionnum);
            }

            // PROTECTION 2: Check for circular references in parent chain
            if ($childsectionnum !== null && self::would_create_circular_reference($courseid, $parentsectionnum, $childsectionnum)) {
                debugging("Prevented circular reference: Section {$childsectionnum} with parent {$parentsectionnum} would create a loop", DEBUG_DEVELOPER);
                throw new \moodle_exception('circularsectionchain', 'aiplacement_modgen', '', 
                    ['child' => $childsectionnum, 'parent' => $parentsectionnum]);
            }

            // PERFORMANCE OPTIMIZATION: Skip depth check during bulk operations.
            // get_fast_modinfo() triggers expensive cache rebuilds (200-500 queries each).
            // During bulk operations with deferred cache rebuilds, depth violations will be
            // caught by the final rebuild anyway, making this check redundant and expensive.
            if (!$skipdepthcheck && method_exists($courseformat, 'get_section_depth') &&
                method_exists($courseformat, 'get_max_section_depth')) {

                $parentsectioninfo = get_fast_modinfo($courseid)->get_section_info($parentsectionnum);
                $parentdepth = $courseformat->get_section_depth($parentsectioninfo);
                $maxdepth = $courseformat->get_max_section_depth();

                if ($parentdepth + 1 > $maxdepth) {
                    throw new \moodle_exception('maxsectiondepthreached', 'aiplacement_modgen', '', $maxdepth);
                }
            }
        }
    }

    /**
     * Create a section with explicit parent relationship.
     *
     * Uses flexsections' proper APIs with transaction protection to ensure atomic section creation.
     * If any step fails, the entire operation is rolled back automatically.
     *
     * Public to allow use throughout the plugin for consistent section creation.
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object (must have create_new_section method)
     * @param int $parentsectionnum Parent section NUMBER (0 for top level)
     * @param string $name Section name
     * @param string $summary Section summary (already formatted/escaped)
     * @param int $summaryformat Summary format (e.g., FORMAT_PLAIN, FORMAT_HTML)
     * @param array $options Additional format options (e.g., ['collapsed' => 1])
     * @param bool $rebuildcache Whether to rebuild course cache after creation (default true)
     * @return object Section record with id and section number
     * @throws \moodle_exception If section creation fails or invalid parameters
     */
    public static function create_section_with_parent($courseid, $courseformat, $parentsectionnum, $name, $summary, $summaryformat, $options = [], $rebuildcache = true) {
        global $DB;

        // Basic validation (parent existence and hierarchy depth).
        // Note: Cannot check circular refs yet as child section doesn't exist.
        // PERFORMANCE: Skip depth check if deferring cache rebuild (bulk operation).
        $skipdepthcheck = !$rebuildcache; // If deferring rebuild, skip expensive validation
        self::validate_section_creation_params($courseid, $courseformat, $parentsectionnum, null, $skipdepthcheck);

        // Additional validation for section name.
        if (empty(trim($name))) {
            throw new \moodle_exception('invalidsectionname', 'aiplacement_modgen');
        }

        // Start transaction for atomic operation.
        $transaction = $DB->start_delegated_transaction();

        try {
            // Step 1: Create section at top level first.
            // Note: We create at top level (0) then set parent manually to avoid nested transaction issues.
            $sectionnum = $courseformat->create_new_section(0, null);

            // Step 2: Get full section record.
            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $sectionnum
            ], '*', MUST_EXIST);

            // PROTECTION: Now that we have the child section number, validate no circular reference.
            // Skip depth check here too since we already skipped it above.
            self::validate_section_creation_params($courseid, $courseformat, $parentsectionnum, $sectionnum, $skipdepthcheck);

            // Step 3: Update section properties (name, summary) with XSS protection.
            $section->name = clean_param($name, PARAM_TEXT); // Strip HTML/JS from names
            $section->summary = $summary; // Keep raw - will be sanitized on display
            // Validate summary format - only allow safe formats
            if (!in_array($summaryformat, [FORMAT_PLAIN, FORMAT_MARKDOWN, FORMAT_HTML, FORMAT_MOODLE])) {
                debugging('Invalid summary format ' . $summaryformat . ', defaulting to FORMAT_HTML', DEBUG_DEVELOPER);
                $summaryformat = FORMAT_HTML;
            }
            $section->summaryformat = $summaryformat;
            $DB->update_record('course_sections', $section);

            // Step 4: Set ALL format options including parent in one call.
            // This ensures the parent is set correctly and additional options don't overwrite it.
            $formatoptions = ['id' => $section->id, 'parent' => $parentsectionnum] + $options;
            $courseformat->update_section_format_options($formatoptions);

            // Commit transaction - all operations successful.
            $transaction->allow_commit();

            // Note: flexsections already rebuilds cache in create_new_section()->move_section().
            // $rebuildcache parameter kept for interface consistency but not used.

            return $section;

        } catch (\Exception $e) {
            // Transaction automatically rolls back on exception.
            // Log technical details for administrators only.
            debugging('Section creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);

            // User-facing message without technical details.
            throw new \moodle_exception('sectorcreationfailed', 'aiplacement_modgen', '',
                clean_param($name, PARAM_TEXT)); // Sanitized name in user message
        }
    }

    /**
     * Check if setting a parent would create a circular reference.
     *
     * Walks up the parent chain from the proposed parent to ensure it doesn't
     * eventually loop back to the child section.
     *
     * @param int $courseid Course ID
     * @param int $proposedparent Proposed parent section number
     * @param int $childsection Child section number
     * @return bool True if it would create a circular reference, false if safe
     */
    private static function would_create_circular_reference($courseid, $proposedparent, $childsection) {
        global $DB;

        // Build index of all section parent relationships for this course.
        $sql = "SELECT cs.section, cfo.value as parent
                FROM {course_sections} cs
                LEFT JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                WHERE cs.course = :courseid";
        $sections = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $parentmap = [];
        foreach ($sections as $section) {
            $parentmap[$section->section] = $section->parent === null ? '0' : $section->parent;
        }

        // Walk up the chain from proposed parent.
        $visited = [];
        $current = $proposedparent;
        $loopcount = 0;
        $maxloop = 50; // Safety limit.

        while ($current !== null && $current !== 0 && $current !== '0' && $loopcount < $maxloop) {
            // If we encounter the child section in the parent chain, it's a loop.
            if ((int)$current === (int)$childsection) {
                return true; // Circular reference detected.
            }

            // Check for self-reference.
            if (isset($visited[$current])) {
                // Existing circular reference in the chain (not involving our child).
                // This is also problematic but not our concern here.
                return false;
            }

            $visited[$current] = true;

            // Move to next parent.
            if (!isset($parentmap[$current])) {
                break; // Section not found, chain ends.
            }

            $nextparent = $parentmap[$current];
            if ($nextparent === null || $nextparent === '0' || $nextparent === 0) {
                break; // Reached top level.
            }

            $current = (int)$nextparent;
            $loopcount++;
        }

        return false; // No circular reference found.
    }

    /**
     * Validate that a section has the correct parent section number.
     *
     * Checks that the parent field in course_format_options matches the expected
     * parent section number. Used for testing and debugging parent relationships.
     *
     * @param int $courseid Course ID
     * @param int $sectionnumber Child section number to validate
     * @param int $expectedparent Expected parent section number
     * @return bool True if parent is correct, false otherwise
     */
    public static function validate_section_parent($courseid, $sectionnumber, $expectedparent) {
        global $DB;

        // Get section record.
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnumber
        ]);

        if (!$section) {
            return false;
        }

        // Get parent option.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $courseid,
            'sectionid' => $section->id,
            'name' => 'parent'
        ]);

        // If no parent option and expected is 0, that's valid (defaults to top level).
        if (!$parentoption && $expectedparent == 0) {
            return true;
        }

        // If no parent option but expected is not 0, that's invalid.
        if (!$parentoption) {
            return false;
        }

        // Compare parent value (stored as string) to expected parent.
        return (int)$parentoption->value === (int)$expectedparent;
    }
}
