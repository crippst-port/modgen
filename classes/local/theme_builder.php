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

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');

/**
 * Theme builder service class.
 */
class theme_builder {

    /**
     * Create multiple themes with default structure (Quick Add workflow).
     *
     * Each theme contains one week with pre-session, session, post-session subsections.
     *
     * @param int $courseid Course ID
     * @param int $themecount Number of themes to create (1-10)
     * @param int $weeksperTheme Number of weeks per theme (1-10)
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_themes($courseid, $themecount, $weeksperTheme) {
        global $DB;

        $messages = [];
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Ensure flexsections format.
        self::ensure_flexsections_format($courseid);

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

                // Create theme section.
                $options = ['collapsed' => 1]; // Theme appears as link.
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
     * Creates weeks at top level (not under a theme) with pre/session/post subsections.
     *
     * @param int $courseid Course ID
     * @param int $weekcount Number of weeks to create (1-10)
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_weeks($courseid, $weekcount) {
        global $DB;

        $messages = [];
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Ensure flexsections format.
        self::ensure_flexsections_format($courseid);

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
                    0, // Parent 0 = top level
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

        // Format title and summary.
        $weektitle = format_string($title, true, ['context' => $context]);
        $weeksectionhtml = trim($summary) !== '' ? format_text($summary, FORMAT_HTML, ['context' => $context]) : '';

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
    }
}
