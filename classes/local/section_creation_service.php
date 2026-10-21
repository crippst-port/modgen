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
 * Section creation service for Module Generator.
 *
 * Handles creation of course sections and modules from approved JSON structure.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/subsection/classes/manager.php');

/**
 * Service for creating course sections from approved JSON structure.
 */
class section_creation_service {
    /**
     * Create sections from approved JSON structure.
     *
     * Uses transactions and locking for atomic operations. Cache rebuild deferred until after commit.
     *
     * @param array $json The approved JSON structure
     * @param int $courseid Course ID
     * @param string $moduletype Module type (connected_theme, connected_weekly, etc)
     * @return array Array with 'results' and 'warnings' keys
     * @throws \moodle_exception If lock acquisition or section creation fails
     */
    public function create_sections_from_json(
        array $json,
        int $courseid,
        string $moduletype,
        bool $createsummaryactivities = true
    ): array {
        global $DB;

        $results = [];
        $activitywarnings = [];
        $needscacherefresh = false;

        // Refuse before doing any work if this structure would push the course past
        // the section limit (flexsections section creation is ~O(n^2) in total
        // sections). Fails fast and cheap, before locking or creating anything.
        \aiplacement_modgen\local\theme_builder::enforce_section_limit(
            $courseid,
            \aiplacement_modgen\local\theme_builder::count_projected_sections_from_json($json, $moduletype)
        );

        // Lock the course to prevent concurrent access.
        $lockkey = 'aiplacement_modgen_building_' . $courseid;
        $lock = \core\lock\lock_config::get_lock_factory('aiplacement_modgen')->get_lock(
            $lockkey,
            constants::GENERATION_LOCK_TIMEOUT
        );

        // get_lock() returns false on timeout. Report that here, before any work: the release
        // in the finally below is reached on every path, and a false lock there would fatal on
        // false->release() rather than surfacing the timeout.
        if (!$lock) {
            throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
        }

        try {
            // Ensure course format is flexsections.
            $this->ensure_flexsections_format($courseid);

            // Initialize core sections (section 0 and Assessments).
            \aiplacement_modgen\local\theme_builder::initialize_core_sections($courseid);

            $course = get_course($courseid, true);
            $context = \context_course::instance($courseid);
            $courseformat = course_get_format($course);

            // Start transaction for entire JSON processing operation.
            // This ensures all sections and activities are created atomically - all or nothing.
            $transaction = $DB->start_delegated_transaction();

            try {
                // Process based on module type.
                if ($moduletype === 'connected_theme' && !empty($json['themes']) && is_array($json['themes'])) {
                    $result = $this->create_theme_structure(
                        $json['themes'],
                        $course,
                        $context,
                        $courseformat,
                        $activitywarnings,
                        $createsummaryactivities
                    );
                    $results = array_merge($results, $result);
                    $needscacherefresh = true;
                } else if (!empty($json['sections']) && is_array($json['sections'])) {
                    $result = $this->create_weekly_structure(
                        $json['sections'],
                        $course,
                        $context,
                        $courseformat,
                        $moduletype,
                        $activitywarnings,
                        $createsummaryactivities
                    );
                    $results = array_merge($results, $result);
                }

                // Ensure all course modules have contexts before committing transaction.
                // This prevents integrity warnings during cache rebuild.
                $sql = "SELECT cm.id
                        FROM {course_modules} cm
                        LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                        WHERE cm.course = :courseid AND ctx.id IS NULL";
                $orphaned = $DB->get_records_sql($sql, [
                    'courseid' => $courseid,
                    'contextlevel' => CONTEXT_MODULE,
                ]);

                if (!empty($orphaned)) {
                    foreach ($orphaned as $cm) {
                        \context_module::instance($cm->id);
                    }
                }

                // Commit all changes - all sections and activities created successfully.
                $transaction->allow_commit();

                // Put section numbers back into hierarchy order now the content is committed.
                // Sections are appended at top level and reparented afterwards, which never
                // renumbers; building into an existing course therefore leaves the numbering
                // out of step with the tree. Done once here rather than per section, and a
                // no-op when the order is already correct. Cannot fail the build: the helper
                // swallows its own errors, leaving the "Renumber Sections" repair in
                // check_structure.php as the fallback.
                \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order($courseid);
            } catch (\Throwable $e) {
                // Roll back explicitly: delegated transactions do not do it on their own.
                \aiplacement_modgen\local\theme_builder::rollback_quietly($transaction, $e);
                throw new \moodle_exception(
                    'jsonsectionscreationfailed',
                    'aiplacement_modgen',
                    '',
                    null,
                    'Failed to create sections from JSON: ' . $e->getMessage()
                );
            }
        } catch (\Exception $e) {
            // Outer catch - rethrow after cleanup in finally block.
            throw $e;
        } finally {
            // Only rebuild cache if transaction committed successfully.
            if ($needscacherefresh) {
                try {
                    rebuild_course_cache($courseid, true, true);
                } catch (\Exception $e) {
                    debugging(get_string('cacherebuildfailed', 'aiplacement_modgen', $e->getMessage()), DEBUG_DEVELOPER);
                }
            }
            if ($lock) {
                $lock->release();
            }
        }

        return [
            'results' => $results,
            'warnings' => $activitywarnings,
        ];
    }

    /**
     * Ensure course format is set to flexsections.
     *
     * @param int $courseid Course ID
     * @throws \Exception If flexsections plugin is not available
     */
    private function ensure_flexsections_format(int $courseid): void {
        $pluginmanager = \core_plugin_manager::instance();
        $flexsectionsplugin = $pluginmanager->get_plugin_info('format_flexsections');

        if (empty($flexsectionsplugin)) {
            throw new \Exception(
                'The flexsections course format plugin is required but not installed. ' .
                'Please install the flexsections plugin before using this feature.'
            );
        }

        $course = get_course($courseid, true);

        if ($course->format !== 'flexsections') {
            $update = new \stdClass();
            $update->id = $courseid;
            $update->format = 'flexsections';

            update_course($update);

            // Fallback: Direct DB update if update_course() failed.
            $course = get_course($courseid, true);
            if ($course->format !== 'flexsections') {
                global $DB;
                $DB->set_field('course', 'format', 'flexsections', ['id' => $courseid]);
                $course = get_course($courseid, true);
            }

            // Single rebuild after all format changes complete.
            rebuild_course_cache($courseid, true, true);
            $course = get_course($courseid, true);

            if ($course->format !== 'flexsections') {
                throw new \Exception(
                    'Failed to set course format to flexsections. Current format: ' . $course->format
                );
            }
        }
    }

    /**
     * Create theme-based structure (connected_theme).
     *
     * @param array $themes Array of theme data
     * @param \stdClass $course Course object
     * @param \context_course $context Course context
     * @param \course_format $courseformat Course format instance
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_theme_structure(
        array $themes,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        array &$activitywarnings,
        bool $createsummaryactivities = true
    ): array {
        global $DB;
        $results = [];

        foreach ($themes as $themeindex => $theme) {
            if (!is_array($theme)) {
                continue;
            }

            $title = $theme['title'] ?? get_string('themefallback', 'aiplacement_modgen');
            $summary = $theme['summary'] ?? '';
            $weeks = !empty($theme['weeks']) && is_array($theme['weeks']) ? $theme['weeks'] : [];

            // Titles and plain-text summaries are stored as given; Moodle escapes them when shown.
            $themetitle = trim((string) $title);
            $sectionhtml = '';
            $aienabled = get_config('aiplacement_modgen', 'enable_ai');
            if (!$aienabled && trim((string) $summary) !== '') {
                $sectionhtml = (string) $summary;
            }

            try {
                // Create theme section using centralized helper.
                $themesection = \aiplacement_modgen\local\theme_builder::create_section_with_parent(
                    $course->id,
                    $courseformat,
                    0, // Parent = 0 (top level).
                    $themetitle,
                    $sectionhtml,
                    FORMAT_PLAIN,
                    ['collapsed' => 1],
                    false  // Defer cache rebuild until after all themes created.
                );

                $themesectionnum = $themesection->section;
                // Hold the theme by id. Section creation does not itself renumber, but the
                // tidy-up at the end of this method does, and so does anything else touching
                // the course structure concurrently, so this number is only good until the
                // next write. Re-read it before each use.
                $themesectionid = (int) $themesection->id;
            } catch (\Exception $e) {
                $activitywarnings[] = "Failed to create theme section: " . $e->getMessage();
                continue;
            }

            $results[] = get_string('sectioncreated', 'aiplacement_modgen', $themetitle);

            // Create nested week subsections.
            if (!empty($weeks)) {
                foreach ($weeks as $weekindex => $week) {
                    if (!is_array($week)) {
                        continue;
                    }

                    $weektitle = $week['title'] ?? "Week " . ($weekindex + 1);
                    $weeksummary = $week['summary'] ?? '';
                    $weekmetadata = !empty($week['learningactivity_metadata']) && is_array($week['learningactivity_metadata'])
                        ? $week['learningactivity_metadata']
                        : [];
                    $sessions = !empty($week['sessions']) && is_array($week['sessions']) ? $week['sessions'] : [];

                    try {
                        $currentthemenum = \aiplacement_modgen\local\theme_builder::current_section_number(
                            $course->id,
                            $themesectionid
                        );
                        if ($currentthemenum === null) {
                            $activitywarnings[] = "Theme section disappeared while adding weeks; skipping remaining weeks.";
                            break;
                        }
                        $weeksectionnum = \aiplacement_modgen\local\theme_builder::create_week_section(
                            $course->id,
                            $courseformat,
                            $currentthemenum,
                            $weektitle,
                            $weeksummary,
                            [
                                'collapsed' => 1,
                                'sessiondata' => $sessions,
                                'metadata' => $weekmetadata,
                                'createsummaryactivities' => $createsummaryactivities,
                            ]
                        );

                        $results[] = get_string('subsectioncreated', 'aiplacement_modgen', $weektitle);
                    } catch (\Exception $e) {
                        $activitywarnings[] = "Failed to create week '{$weektitle}': " . $e->getMessage();
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Create weekly structure (connected_weekly or weekly).
     *
     * @param array $sections Array of section data
     * @param \stdClass $course Course object
     * @param \context_course $context Course context
     * @param object $courseformat Course format object
     * @param string $moduletype Module type
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_weekly_structure(
        array $sections,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        string $moduletype,
        array &$activitywarnings,
        bool $createsummaryactivities = true
    ): array {
        $results = [];

        foreach ($sections as $sectiondata) {
            if (!is_array($sectiondata)) {
                continue;
            }

            $title = $sectiondata['title'] ?? get_string('aigensummary', 'aiplacement_modgen');
            $summary = $sectiondata['summary'] ?? '';
            $outline = !empty($sectiondata['outline']) && is_array($sectiondata['outline']) ? $sectiondata['outline'] : [];

            $summaryhtml = trim(format_text($summary, FORMAT_HTML, ['context' => $context]));
            if (!empty($outline)) {
                $items = '';
                foreach ($outline as $entry) {
                    if (is_array($entry) && isset($entry['text'])) {
                        $items .= '<li>' . s($entry['text']) . '</li>';
                    } else if (is_string($entry)) {
                        $items .= '<li>' . s($entry) . '</li>';
                    }
                }
                if ($items !== '') {
                    $summaryhtml .= '<ul class="course-outline">' . $items . '</ul>';
                }
            }

            $hassessions = !empty($sectiondata['sessions']) && is_array($sectiondata['sessions']);

            if ($moduletype === 'connected_weekly') {
                $hassessions = true;
                if (empty($sectiondata['sessions']) || !is_array($sectiondata['sessions'])) {
                    $sectiondata['sessions'] = [
                        ['phase' => 'pre', 'description' => '', 'activities' => []],
                        ['phase' => 'session', 'description' => '', 'activities' => []],
                        ['phase' => 'post', 'description' => '', 'activities' => []],
                    ];
                }
            }

            try {
                $weeksessiondata = $hassessions ? $sectiondata['sessions'] : null;
                $haslearningactivitymetadata = !empty($sectiondata['learningactivity_metadata'])
                    && is_array($sectiondata['learningactivity_metadata']);
                $weekmetadata = $haslearningactivitymetadata ? $sectiondata['learningactivity_metadata'] : [];

                $weeksectionnum = \aiplacement_modgen\local\theme_builder::create_week_section(
                    $course->id,
                    $courseformat,
                    0,
                    $title,
                    $summaryhtml,
                    [
                        'collapsed' => $hassessions ? 1 : 0,
                        'summaryformat' => FORMAT_HTML, // $summaryhtml is sanitised HTML with an optional outline list.
                        'sessiondata' => $weeksessiondata,
                        'metadata' => $weekmetadata,
                        'createsummaryactivities' => $createsummaryactivities,
                    ]
                );

                $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            } catch (\Exception $e) {
                $activitywarnings[] = "Failed to create week section '{$title}': " . $e->getMessage();
            }
        }

        return $results;
    }
}
