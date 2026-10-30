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
     * @param bool $createsummaryactivities Whether to add the learningactivity summary module to each section
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

        // Refuse a course that cannot take the build, or a structure with themes in a course that cannot hold
        // them, before anything else: the course may have changed format since the job was queued.
        course_formats::require_supported($courseid);
        course_formats::require_structure_fits($courseid, $moduletype, $json);

        // Refuse before doing any work if this structure would push the course past
        // the section limit (flexsections section creation is ~O(n^2) in total
        // sections; core formats also have the site's own cap). Fails fast and cheap,
        // before locking or creating anything.
        $projectedsections = \aiplacement_modgen\local\theme_builder::count_projected_sections_from_json($json, $moduletype);
        \aiplacement_modgen\local\theme_builder::enforce_section_limit($courseid, $projectedsections);

        // Take the same locks as Quick Add, so builds of either kind, the Check Structure repairs and
        // format_flexsections deletes cannot renumber the course under each other, and recheck the
        // limit under them. Throws before any work, holding no lock: the release in the finally
        // below is reached on every path.
        $locks = \aiplacement_modgen\local\theme_builder::acquire_build_locks($courseid, $projectedsections);

        try {
            // Check the format again now the locks are held, and name section 0.
            \aiplacement_modgen\local\theme_builder::prepare_course_for_build($courseid);
            course_formats::require_structure_fits($courseid, $moduletype, $json);

            $course = get_course($courseid, true);
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
                        $courseformat,
                        $moduletype,
                        $activitywarnings,
                        $createsummaryactivities
                    );
                    $results = array_merge($results, $result);
                }

                // Subsections are course modules: make sure each has a context before commit.
                \aiplacement_modgen\local\theme_builder::ensure_module_contexts($courseid);

                // Commit all changes - all sections and activities created successfully.
                $transaction->allow_commit();

                // Put section numbers back into hierarchy order now the content is committed.
                // Sections are appended at top level and reparented afterwards, which never
                // renumbers; building into an existing course therefore leaves the numbering
                // out of step with the tree. Done once here rather than per section, and a
                // no-op when the order is already correct. Cannot fail the build: the helper
                // swallows its own errors, leaving the "Renumber Sections" repair in
                // check_structure.php as the fallback.
                \aiplacement_modgen\local\theme_builder::renumber_into_hierarchy_order($courseid, true);
            } catch (\Throwable $e) {
                // Roll back explicitly: delegated transactions do not do it on their own.
                \aiplacement_modgen\local\theme_builder::rollback_quietly($transaction, $e);
                // Kept as it is when a retry could succeed, so the task can tell.
                if (\aiplacement_modgen\local\theme_builder::is_transient_failure($e)) {
                    throw $e;
                }
                throw new \moodle_exception(
                    'jsonsectionscreationfailed',
                    'aiplacement_modgen',
                    '',
                    $e->getMessage(),
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
            \aiplacement_modgen\local\theme_builder::release_structure_locks($locks);
        }

        return [
            'results' => $results,
            'warnings' => $activitywarnings,
        ];
    }

    /**
     * Re-throw anything that must not be turned into a warning.
     *
     * Creation runs inside one delegated transaction. A failed database query leaves that
     * transaction unusable (PostgreSQL aborts it), and a section write that rolled back its own
     * nested transaction has marked this one for rollback too, so carrying on to the next item
     * would only produce confusing follow-on errors and a half-built structure. Both therefore
     * propagate and roll everything back; other failures are recorded as warnings and the
     * remaining items still get created.
     *
     * @param \Throwable $e The exception caught around one item.
     * @throws \Throwable The same exception when it is not recoverable.
     */
    private static function rethrow_unless_recoverable(\Throwable $e): void {
        if ($e instanceof \dml_exception || $e instanceof structure_write_failed_exception) {
            throw $e;
        }
    }

    /**
     * Create theme-based structure (connected_theme).
     *
     * @param array $themes Array of theme data
     * @param \stdClass $course Course object
     * @param \course_format $courseformat Course format instance
     * @param array &$activitywarnings Array to collect warnings
     * @param bool $createsummaryactivities Whether to add the learningactivity summary module to each section
     * @return array Results messages
     */
    private function create_theme_structure(
        array $themes,
        \stdClass $course,
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
            // A summary supplied for the theme (for example a CSV "Description" row) is kept. AI themes
            // arrive with an empty summary, because ai_service blanks them: themes are only containers.
            $sectionhtml = trim((string) $summary) !== '' ? (string) $summary : '';

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
            } catch (\Throwable $e) {
                self::rethrow_unless_recoverable($e);
                $activitywarnings[] = get_string('warning_themefailed', 'aiplacement_modgen', [
                    'title' => $themetitle,
                    'error' => $e->getMessage(),
                ]);
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
                            $activitywarnings[] = get_string('warning_themedisappeared', 'aiplacement_modgen', $themetitle);
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
                    } catch (\Throwable $e) {
                        self::rethrow_unless_recoverable($e);
                        $activitywarnings[] = get_string('warning_weekfailed', 'aiplacement_modgen', [
                            'title' => $weektitle,
                            'error' => $e->getMessage(),
                        ]);
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
     * @param object $courseformat Course format object
     * @param string $moduletype Module type
     * @param array &$activitywarnings Array to collect warnings
     * @param bool $createsummaryactivities Whether to add the learningactivity summary module to each section
     * @return array Results messages
     */
    private function create_weekly_structure(
        array $sections,
        \stdClass $course,
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

            // Cleaned for storage, not formatted for display: filters run when the summary is shown, and
            // applying them here would store their output (losing, say, the other languages in multilang text).
            $summaryhtml = trim(clean_text((string) $summary, FORMAT_HTML));
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
                        'summaryformat' => FORMAT_HTML, // Content in $summaryhtml is sanitised HTML plus an optional outline list.
                        'sessiondata' => $weeksessiondata,
                        'metadata' => $weekmetadata,
                        'createsummaryactivities' => $createsummaryactivities,
                    ]
                );

                $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            } catch (\Throwable $e) {
                self::rethrow_unless_recoverable($e);
                $activitywarnings[] = get_string('warning_weekfailed', 'aiplacement_modgen', [
                    'title' => $title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
