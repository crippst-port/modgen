<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

use aiplacement_modgen\activitytype\registry;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');

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
            error_log("[MODGEN] learningactivity handler not found");
            debugging('learningactivity handler not found', DEBUG_DEVELOPER);
            return null;
        }

        // Get course
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Prepare activity data
        $activitydata = new \stdClass();
        $activitydata->sectiontype = $sectiontype;
        $activitydata->name = $name;

        // Merge additional metadata
        foreach ($metadata as $key => $value) {
            $activitydata->$key = $value;
        }

        // Log what we're trying to create
        error_log("[MODGEN] Attempting to create learningactivity: section={$sectionnumber}, type={$sectiontype}, name={$name}");

        // Create instance
        try {
            $instance = new $handler();
            $result = $instance->create($activitydata, $course, $sectionnumber);

            if ($result && isset($result['cmid'])) {
                error_log("[MODGEN] Successfully created learningactivity cmid={$result['cmid']}");
                return $result['cmid'];
            } else {
                error_log("[MODGEN] learningactivity creation returned null or no cmid");
            }
        } catch (\Exception $e) {
            error_log("[MODGEN] Exception creating learningactivity: " . $e->getMessage());
            debugging('Failed to create learningactivity: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
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

        // Ensure flexsections format.
        self::ensure_flexsections_format($courseid);

        // Get fresh course object after format conversion
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Get course format and acquire lock.
        $courseformat = course_get_format($course);
        $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
        $lock = $lockfactory->get_lock('course_edit_' . $courseid, 600);

        if (!$lock) {
            throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
        }

        try {
            for ($i = 1; $i <= $themecount; $i++) {
                $themetitle = get_string('defaultthemename', 'aiplacement_modgen', $i);
                $themesummary = get_string('defaultthemesummary', 'aiplacement_modgen');

                // Create theme section under parent.
                $options = ['collapsed' => 1, 'parent' => $parent]; // Theme appears as link.
                $themesectionnum = self::create_theme_section(
                    $courseid,
                    $courseformat,
                    $themetitle,
                    $themesummary,
                    $options
                );

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
                        $weekoptions
                    );

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

        // Ensure flexsections format.
        self::ensure_flexsections_format($courseid);

        // Get fresh course object after format conversion
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Get course format and acquire lock.
        $courseformat = course_get_format($course);
        $lockfactory = \core\lock\lock_config::get_lock_factory('core_course_edit');
        $lock = $lockfactory->get_lock('course_edit_' . $courseid, 600);

        if (!$lock) {
            throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
        }

        try {
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
                    $weekoptions
                );

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
     * @return int Section number of created theme
     */
    public static function create_theme_section($courseid, $courseformat, $title, $summary, $options = []) {
        global $DB;

        $context = \context_course::instance($courseid);

        // Verify flexsections format.
        if (get_class($courseformat) !== 'format_flexsections') {
            throw new \Exception('Course format must be flexsections to create nested sections');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \Exception('The flexsections course format is not properly supporting nested sections');
        }

        // Create top-level theme section.
        $themesectionnum = $courseformat->create_new_section(0, null); // 0 = top level, null = append.

        // Format title and summary.
        $themetitle = format_string($title, true, ['context' => $context]);
        $sectionhtml = trim($summary) !== '' ? format_text($summary, FORMAT_HTML, ['context' => $context]) : '';

        // Update section.
        $themesectionid = $DB->get_field('course_sections', 'id', [
            'course' => $courseid,
            'section' => $themesectionnum
        ]);

        $DB->update_record('course_sections', [
            'id' => $themesectionid,
            'name' => $themetitle,
            'summary' => $sectionhtml,
            'summaryformat' => FORMAT_HTML,
        ]);

        // Set collapsed option (theme appears as link).
        $collapsed = $options['collapsed'] ?? 1;
        if (method_exists($courseformat, 'update_section_format_options')) {
            $courseformat->update_section_format_options([
                'id' => $themesectionid,
                'collapsed' => $collapsed
            ]);
        }

        return $themesectionnum;
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
     * @return int Section number of created week
     */
    public static function create_week_section($courseid, $courseformat, $parentsectionnum, $title, $summary, $options = []) {
        global $DB;

        $context = \context_course::instance($courseid);

        // Verify flexsections format.
        if (get_class($courseformat) !== 'format_flexsections') {
            throw new \Exception('Course format must be flexsections to create nested sections');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \Exception('The flexsections course format is not properly supporting nested sections');
        }

        // Create week section under parent.
        $weeksectionnum = $courseformat->create_new_section($parentsectionnum, null);

        // Format week title
        $weektitle = format_string($title, true, ['context' => $context]);
        
        // Prepare metadata for week-level learningactivity
        // If metadata includes instructions, use that instead of setting summary on section
        $weekmetadata = $options['metadata'] ?? [];
        $usemetadataforintro = !empty($weekmetadata['instructions']);
        
        // If using metadata for intro, don't set summary on section (leave it minimal/empty)
        // Otherwise, use the provided summary on the section
        $weeksectionhtml = '';
        if (!$usemetadataforintro && trim($summary) !== '') {
            $weeksectionhtml = format_text($summary, FORMAT_HTML, ['context' => $context]);
        }

        // Update week section.
        $weeksectionid = $DB->get_field('course_sections', 'id', [
            'course' => $courseid,
            'section' => $weeksectionnum
        ]);

        $DB->update_record('course_sections', [
            'id' => $weeksectionid,
            'name' => $weektitle,
            'summary' => $weeksectionhtml,
            'summaryformat' => FORMAT_HTML,
        ]);

        // Set collapsed option (week appears as link).
        $collapsed = $options['collapsed'] ?? 1;
        if (method_exists($courseformat, 'update_section_format_options')) {
            $courseformat->update_section_format_options([
                'id' => $weeksectionid,
                'collapsed' => $collapsed
            ]);
        }

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
        
        if (!$weekcmid) {
            debugging("Failed to create learningactivity for week: {$title} (section {$weeksectionnum})", DEBUG_DEVELOPER);
        }

        // Create session subsections using shared helper.
        $sessiondata = $options['sessiondata'] ?? null;
        session_creator::create_session_subsections(
            $courseformat,
            $weeksectionnum,
            $courseid,
            $sessiondata
        );

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
                // Flexsections format - create at top level.
                $assessmentssectionnum = $courseformat->create_new_section(0, null);

                // Update the section name.
                $assessmentssection = $DB->get_record('course_sections', [
                    'course' => $courseid,
                    'section' => $assessmentssectionnum
                ], '*', MUST_EXIST);

                $DB->update_record('course_sections', [
                    'id' => $assessmentssection->id,
                    'name' => $assessmentsname,
                    'summary' => '',
                    'summaryformat' => FORMAT_HTML,
                    'timemodified' => time()
                ]);

                // Move it to position 1 (right after section 0).
                if (method_exists($courseformat, 'move_section')) {
                    try {
                        $courseformat->move_section($assessmentssectionnum, 0, 1);
                    } catch (\Throwable $e) {
                        // If move fails, section will remain at end but still be created.
                        debugging('Failed to move Assessments section: ' . $e->getMessage(), DEBUG_DEVELOPER);
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

            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Ensure course is using flexsections format.
     *
     * Converts course to flexsections if needed.
     *
     * @param int $courseid Course ID
     * @throws \moodle_exception If conversion fails
     */
    private static function ensure_flexsections_format($courseid) {
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
}
