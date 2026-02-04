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
                return $result['cmid'];
            }
        } catch (\Exception $e) {
            // Failed to create learningactivity: expected in test environment.
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

        // Create section with parent=0 (top level) and collapsed option.
        $collapsed = $options['collapsed'] ?? 1;
        $themesection = self::create_section_with_parent(
            $courseid,
            $courseformat,
            0, // parent = 0 (top level)
            $themetitle,
            $sectionhtml,
            FORMAT_PLAIN,
            ['collapsed' => $collapsed]
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
     * @return int Section number of created week
     */
    public static function create_week_section($courseid, $courseformat, $parentsectionnum, $title, $summary, $options = []) {
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
            ['collapsed' => $collapsed]
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

            rebuild_course_cache($courseid, true);
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
     * Create a section with explicit parent relationship.
     *
     * Centralized method that encapsulates the two-step section creation pattern:
     * 1. Create section at top level with create_new_section(0, null)
     * 2. Explicitly set parent field via update_section_format_options
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
     * @return object Section record with id and section number
     * @throws \moodle_exception If section creation fails or invalid parameters
     */
    public static function create_section_with_parent($courseid, $courseformat, $parentsectionnum, $name, $summary, $summaryformat, $options = []) {
        global $DB;

        // Validate parameters.
        if (!is_numeric($courseid) || $courseid <= 0) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        if (!is_numeric($parentsectionnum) || $parentsectionnum < 0) {
            throw new \moodle_exception('invalidsectionparent', 'aiplacement_modgen');
        }
        if (empty(trim($name))) {
            throw new \moodle_exception('invalidsectionname', 'aiplacement_modgen');
        }
        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }
        if (!method_exists($courseformat, 'update_section_format_options')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // Step 1: Create section at top level.
        $sectionnum = $courseformat->create_new_section(0, null);

        // Step 2: Get full section record.
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum
        ], '*', MUST_EXIST);

        // Step 3: Update section properties.
        $section->name = $name;
        $section->summary = $summary;
        $section->summaryformat = $summaryformat;
        $DB->update_record('course_sections', $section);

        // Step 4: CRITICAL - Explicitly set parent relationship and all format options.
        // The parent value must be the section NUMBER (not ID).
        // Note: We insert directly into course_format_options table because update_section_format_options()
        // doesn't work reliably in all contexts (e.g., test environment).
        
        // First, set the parent field.
        $parentrecord = $DB->get_record('course_format_options', [
            'courseid' => $courseid,
            'sectionid' => $section->id,
            'format' => 'flexsections',
            'name' => 'parent'
        ]);
        
        if ($parentrecord) {
            // Update existing.
            $parentrecord->value = $parentsectionnum;
            $DB->update_record('course_format_options', $parentrecord);
        } else {
            // Insert new.
            $DB->insert_record('course_format_options', (object)[
                'courseid' => $courseid,
                'format' => 'flexsections',
                'sectionid' => $section->id,
                'name' => 'parent',
                'value' => $parentsectionnum
            ]);
        }
        
        // Then set any additional options.
        foreach ($options as $optionname => $optionvalue) {
            $optionrecord = $DB->get_record('course_format_options', [
                'courseid' => $courseid,
                'sectionid' => $section->id,
                'format' => 'flexsections',
                'name' => $optionname
            ]);
            
            if ($optionrecord) {
                $optionrecord->value = $optionvalue;
                $DB->update_record('course_format_options', $optionrecord);
            } else {
                $DB->insert_record('course_format_options', (object)[
                    'courseid' => $courseid,
                    'format' => 'flexsections',
                    'sectionid' => $section->id,
                    'name' => $optionname,
                    'value' => $optionvalue
                ]);
            }
        }

        return $section;
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
