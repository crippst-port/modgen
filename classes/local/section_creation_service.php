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
     * @param bool $generatethemeintroductions Whether to generate theme introductions
     * @param bool $createsuggestedactivities Whether to create suggested activities
     * @param bool $hideexistingsections Whether to hide existing sections
     * @return array Array with 'results' and 'warnings' keys
     * @throws \moodle_exception If lock acquisition or section creation fails
     */
    public function create_sections_from_json(
        array $json,
        int $courseid,
        string $moduletype,
        bool $generatethemeintroductions,
        bool $createsuggestedactivities,
        bool $hideexistingsections,
        bool $createsummaryactivities = true
    ): array {
        global $DB;

        $results = [];
        $activitywarnings = [];
        $needscacherefresh = false;
        $newtoplevelsectionids = [];

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

        try {
            // Track existing sections if hiding is enabled.
            $existingsectionids = [];
            if ($hideexistingsections) {
                $existingsections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
                foreach ($existingsections as $section) {
                    if ($section->section > 0) {
                        $existingsectionids[] = $section->id;
                    }
                }
            }

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
                        $generatethemeintroductions,
                        $createsuggestedactivities,
                        $hideexistingsections,
                        $newtoplevelsectionids,
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
                        $createsuggestedactivities,
                        $hideexistingsections,
                        $newtoplevelsectionids,
                        $activitywarnings,
                        $createsummaryactivities
                    );
                    $results = array_merge($results, $result);
                }

                // Handle hiding existing sections.
                if ($hideexistingsections && !empty($newtoplevelsectionids)) {
                    $this->hide_and_reorder_sections($courseid, $course, $newtoplevelsectionids);
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
            } catch (\Exception $e) {
                // Transaction automatically rolls back on exception.
                // No partial data will remain in database.
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
            if (isset($lock)) {
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
     * @param bool $generatethemeintroductions Whether to generate theme introductions
     * @param bool $createsuggestedactivities Whether to create activities
     * @param bool $hideexistingsections Whether to hide existing sections
     * @param array &$newtoplevelsectionids Array to track new section IDs
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_theme_structure(
        array $themes,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        bool $generatethemeintroductions,
        bool $createsuggestedactivities,
        bool $hideexistingsections,
        array &$newtoplevelsectionids,
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

            // Format theme title and summary.
            $themetitle = format_string($title, true, ['context' => $context]);
            $sectionhtml = '';
            $aienabled = get_config('aiplacement_modgen', 'enable_ai');
            if ((!$aienabled || $generatethemeintroductions) && trim($summary) !== '') {
                $sectionhtml = format_text($summary, FORMAT_PLAIN, ['context' => $context]);
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

                if ($hideexistingsections) {
                    $newtoplevelsectionids[] = $themesection->id;
                }
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
                        $weeksectionnum = \aiplacement_modgen\local\theme_builder::create_week_section(
                            $course->id,
                            $courseformat,
                            $themesectionnum,
                            $weektitle,
                            $weeksummary,
                            [
                                'collapsed' => 1,
                                'sessiondata' => $sessions,
                                'createactivities' => $createsuggestedactivities,
                                'metadata' => $weekmetadata,
                                'createsummaryactivities' => $createsummaryactivities,
                            ]
                        );

                        // Create activities in sessions if requested.
                        if ($createsuggestedactivities && !empty($sessions)) {
                            $sessionsectionmap = \aiplacement_modgen\local\session_creator::get_session_sections(
                                $weeksectionnum,
                                $course->id
                            );

                            \aiplacement_modgen\local\session_creator::create_session_activities(
                                $sessions,
                                $sessionsectionmap,
                                $course,
                                $results,
                                $activitywarnings
                            );
                        }

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
     * @param bool $hideexistingsections Whether to hide existing sections
     * @param array &$newtoplevelsectionids Array to track new section IDs
     * @param array &$activitywarnings Array to collect warnings
     * @return array Results messages
     */
    private function create_weekly_structure(
        array $sections,
        \stdClass $course,
        \context_course $context,
        $courseformat,
        string $moduletype,
        bool $createsuggestedactivities,
        bool $hideexistingsections,
        array &$newtoplevelsectionids,
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
                        'sessiondata' => $weeksessiondata,
                        'createactivities' => $createsuggestedactivities,
                        'metadata' => $weekmetadata,
                        'createsummaryactivities' => $createsummaryactivities,
                    ]
                );

                // Create activities if requested.
                if ($createsuggestedactivities) {
                    if ($hassessions && !empty($weeksessiondata)) {
                        // Has sessions - create activities in session subsections.
                        $sessionsectionmap = \aiplacement_modgen\local\session_creator::get_session_sections(
                            $weeksectionnum,
                            $course->id
                        );
                        \aiplacement_modgen\local\session_creator::create_session_activities(
                            $weeksessiondata,
                            $sessionsectionmap,
                            $course,
                            $results,
                            $activitywarnings
                        );
                    } else if (!empty($sectiondata['activities']) && is_array($sectiondata['activities'])) {
                        // No sessions - create activities directly in section.
                        $activityoutcome = \aiplacement_modgen\activitytype\registry::create_for_section(
                            $sectiondata['activities'],
                            $course,
                            $weeksectionnum
                        );

                        if (!empty($activityoutcome['created'])) {
                            $results = array_merge($results, $activityoutcome['created']);
                        }
                        if (!empty($activityoutcome['warnings'])) {
                            $activitywarnings = array_merge($activitywarnings, $activityoutcome['warnings']);
                        }
                    }
                }

                if ($hideexistingsections) {
                    global $DB;
                    $weeksectionid = $DB->get_field('course_sections', 'id', [
                        'course' => $course->id,
                        'section' => $weeksectionnum,
                    ]);
                    if ($weeksectionid) {
                        $newtoplevelsectionids[] = $weeksectionid;
                    }
                }

                $results[] = get_string('sectioncreated', 'aiplacement_modgen', $title);
            } catch (\Exception $e) {
                $activitywarnings[] = "Failed to create week section '{$title}': " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Hide existing top-level sections and move new sections to top.
     *
     * Only hides top-level sections (parent = 0). All nested content (sections
     * with parent > 0) remains visible regardless of whether their parent is
     * hidden or newly created.
     *
     * @param int $courseid Course ID
     * @param \stdClass $course Course object
     * @param array $newtoplevelsectionids Array of new section IDs
     */
    private function hide_and_reorder_sections(int $courseid, \stdClass $course, array $newtoplevelsectionids): void {
        global $DB;

        // Single rebuild at start to get fresh section data.
        rebuild_course_cache($courseid, true, true);
        $course = get_course($courseid, true);
        $modinfo = get_fast_modinfo($course);

        // Get Assessments section (should never be hidden).
        $assessmentssection = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => 'Assessments',
        ]);

        // Hide only top-level existing sections. Never hide:
        // - Section 0
        // - Newly created top-level sections
        // - Any nested sections (parent > 0)
        // - Assessments section (core section).
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            // Skip section 0.
            if ($sectioninfo->section == 0) {
                continue;
            }

            // Skip if this is a new top-level section.
            if (in_array($sectioninfo->id, $newtoplevelsectionids, true)) {
                continue;
            }

            // Skip if this is the Assessments section.
            if ($assessmentssection && $sectioninfo->id == $assessmentssection->id) {
                continue;
            }

            // Skip all nested sections (only hide top-level sections).
            if (!empty($sectioninfo->parent) && $sectioninfo->parent > 0) {
                continue;
            }

            // Hide this top-level existing section (batch DB update - no rebuild needed).
            $DB->set_field('course_sections', 'visible', 0, ['id' => $sectioninfo->id]);
        }

        // Move new sections to top (after section 0, before Assessments if it exists).
        if (!empty($newtoplevelsectionids)) {
            $course = get_course($courseid, true);
            $courseformat = course_get_format($course);

            // Find the Assessments section (usually at position 1).
            $assessmentssection = null;
            $modinfo = get_fast_modinfo($course);
            foreach ($modinfo->get_section_info_all() as $sinfo) {
                if ($sinfo->name === 'Assessments') {
                    $assessmentssection = $sinfo;
                    break;
                }
            }

            // Target: move before Assessments, or to position 1 if no Assessments.
            $targetposition = $assessmentssection ? $assessmentssection->section : 1;

            // Move each new section to the top (reverse order for correct final sequence)
            // No cache rebuild in loop - move_section() handles internal updates.
            foreach (array_reverse($newtoplevelsectionids) as $newsectionid) {
                // Use existing modinfo (refreshed after each move by move_section()).
                $modinfo = get_fast_modinfo($course);

                // Find the section to move.
                $sectioninfo = null;
                foreach ($modinfo->get_section_info_all() as $s) {
                    if ($s->id == $newsectionid) {
                        $sectioninfo = $s;
                        break;
                    }
                }

                if ($sectioninfo && $sectioninfo->section != $targetposition && method_exists($courseformat, 'move_section')) {
                    try {
                        // The move_section() call may trigger an internal cache update.
                        $courseformat->move_section($sectioninfo->section, $targetposition, true);
                        // Force lightweight modinfo refresh (no full rebuild needed).
                        $modinfo = get_fast_modinfo($course, $course->id, null, true);
                    } catch (\Exception $e) {
                        // Continue on error.
                        debugging("Failed to move section {$sectioninfo->section}: " . $e->getMessage());
                    }
                }
            }
        }

        // Single rebuild at end (covers all visibility + move operations).
        rebuild_course_cache($courseid, true, true);
    }
}
