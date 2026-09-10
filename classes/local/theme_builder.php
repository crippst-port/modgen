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

        // Get handler.
        $handler = registry::get_handler('learningactivity');
        if (!$handler) {
            // Learningactivity handler not found.
            return null;
        }

        // Get course.
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Prepare activity data.
        $activitydata = new \stdClass();
        $activitydata->sectiontype = $sectiontype;
        $activitydata->name = $name;

        // Merge additional metadata - skip null/empty values.
        foreach ($metadata as $key => $value) {
            // Skip null values and empty strings to avoid issues with learningactivity module.
            if ($value !== null && $value !== '') {
                $activitydata->$key = $value;
            }
        }

        // Create instance.
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
            // Expected in test environment; log for real failures elsewhere.
            debugging('Failed to create learningactivity: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
                // Connected-weekly courses always materialise 3 session subsections.
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
     * @param int $weeksperthemecount Number of weeks per theme (1-10)
     * @param int $parent Parent section number (0 = top level, N = nested under section N)
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_themes(
        $courseid,
        $themecount,
        $weeksperthemecount,
        $parent = 0,
        $createsummaryactivities = true
    ) {
        global $DB;

        $messages = [];

        // Refuse before doing any work if this would push the course past the section
        // limit. Each theme is 1 section; each week is 1 + 3 session subsections.
        self::enforce_section_limit($courseid, $themecount + ($themecount * $weeksperthemecount * 4));

        // Acquire every lock guarding this course's structure - including the one
        // format_flexsections uses, which the modgen lock alone does not exclude.
        $locks = self::acquire_structure_locks($courseid);

        try {
            // Pin the caller's parent to a section ID before ANYTHING renumbers the course.
            // The number arrives from a form or an adhoc task and is only valid at this
            // instant; ensure_flexsections_format() below creates the Assessments section
            // and moves it to position 1, which shifts every number after it. Resolving the
            // number later would resolve it against the new numbering and attach the new
            // content to whatever had slid into that position.
            $parentsectionid = self::section_id_from_number($courseid, $parent);
            if ($parent > 0 && $parentsectionid === null) {
                $messages[] = get_string('parentsectiongone', 'aiplacement_modgen');
            }

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
                'contextlevel' => CONTEXT_MODULE,
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
                $createdcount = 0; // Track sections created for cache rebuild.

                for ($i = 1; $i <= $themecount; $i++) {
                    // Re-read on every iteration: the previous theme renumbered the course.
                    $parentnum = self::current_section_number($courseid, $parentsectionid) ?? 0;
                    $themetitle = get_string('defaultthemename', 'aiplacement_modgen', $i);
                    $themesummary = get_string('defaultthemesummary', 'aiplacement_modgen');

                    // Create the theme. $parent is honoured: Quick Add can target an
                    // existing section, and 0 (the default) means top level.
                    $options = ['collapsed' => 1]; // Theme appears as link.
                    $themesectionnum = self::create_theme_section(
                        $courseid,
                        $courseformat,
                        $themetitle,
                        $themesummary,
                        $options,
                        false, // Defer cache rebuild.
                        $parentnum
                    );
                    $createdcount++;

                    // Hold the theme by id, not by number. Section creation does not itself
                    // renumber (see renumber_into_hierarchy_order()), but the tidy-up at the
                    // end of this method does, and so does anything else touching the course
                    // structure concurrently, so a number is only safe until the next write.
                    $themesectionid = self::section_id_from_number($courseid, $themesectionnum);

                    $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $themetitle);

                    // Create weeks under this theme.
                    for ($w = 1; $w <= $weeksperthemecount; $w++) {
                        $weektitle = get_string('defaultweekname', 'aiplacement_modgen', [
                            'theme' => $i,
                            'week' => $w,
                        ]);
                        $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');

                        $weekoptions = [
                            'collapsed' => 1, // Week appears as link.
                            'createsummaryactivities' => $createsummaryactivities,
                        ];
                        // Re-read the theme's number rather than trusting the captured one.
                        $currentthemenum = self::current_section_number($courseid, $themesectionid);
                        if ($currentthemenum === null) {
                            throw new \moodle_exception('themecreationfailed', 'aiplacement_modgen');
                        }
                        $weeksectionnum = self::create_week_section(
                            $courseid,
                            $courseformat,
                            $currentthemenum,
                            $weektitle,
                            $weeksummary,
                            $weekoptions,
                            false  // Defer cache rebuild.
                        );
                        $createdcount += 4; // Week + 3 session subsections.

                        $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $weektitle);

                        // Sessions created inside create_week_section, add messages.
                        $sessiontypes = [
                            get_string('presession', 'aiplacement_modgen'),
                            get_string('session', 'aiplacement_modgen'),
                            get_string('postsession', 'aiplacement_modgen'),
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
                        'contextlevel' => CONTEXT_MODULE,
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
                        'contextlevel' => CONTEXT_MODULE,
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

                // Numbering tidy-up, after the commit but still under the structure locks:
                // see renumber_into_hierarchy_order(). Deliberately outside the transaction
                // so its own transaction and cache rebuild are not nested inside this one.
                self::renumber_into_hierarchy_order($courseid, true);
            } catch (\Exception $e) {
                // Transaction automatically rolls back on exception.
                // Log technical details for administrators only.
                debugging('Theme creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                throw new \moodle_exception('themecreationfailed', 'aiplacement_modgen');
            }
        } finally {
            self::release_structure_locks($locks);
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
    public static function create_weeks($courseid, $weekcount, $parent = 0, $createsummaryactivities = true) {
        global $DB;

        $messages = [];

        // Refuse before doing any work if this would push the course past the section
        // limit. Each week is 1 section + 3 session subsections.
        self::enforce_section_limit($courseid, $weekcount * 4);

        // Acquire every lock guarding this course's structure - including the one
        // format_flexsections uses, which the modgen lock alone does not exclude.
        $locks = self::acquire_structure_locks($courseid);

        try {
            // Pin the caller's parent to a section ID before ANYTHING renumbers the course.
            // The number arrives from a form or an adhoc task and is only valid at this
            // instant; ensure_flexsections_format() below creates the Assessments section
            // and moves it to position 1, which shifts every number after it. Resolving the
            // number later would resolve it against the new numbering and attach the new
            // content to whatever had slid into that position.
            $parentsectionid = self::section_id_from_number($courseid, $parent);
            if ($parent > 0 && $parentsectionid === null) {
                $messages[] = get_string('parentsectiongone', 'aiplacement_modgen');
            }

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
                'contextlevel' => CONTEXT_MODULE,
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
                $createdcount = 0; // Track sections created for cache rebuild.

                for ($i = 1; $i <= $weekcount; $i++) {
                    $weektitle = get_string('defaultstandaloneweekname', 'aiplacement_modgen', $i);
                    $weeksummary = get_string('defaultweeksummary', 'aiplacement_modgen');

                    $weekoptions = [
                        'collapsed' => 1, // Week appears as link.
                        'createsummaryactivities' => $createsummaryactivities,
                    ];
                    // Re-read the parent's number each time: the previous week renumbered
                    // the course, so the number captured before the loop may now be someone
                    // else's. A deleted parent falls back to top level.
                    $parentnum = self::current_section_number($courseid, $parentsectionid) ?? 0;
                    $weeksectionnum = self::create_week_section(
                        $courseid,
                        $courseformat,
                        $parentnum,
                        $weektitle,
                        $weeksummary,
                        $weekoptions,
                        false  // Defer cache rebuild.
                    );
                    $createdcount += 4; // Week + 3 session subsections.

                    $messages[] = get_string('sectioncreated', 'aiplacement_modgen', $weektitle);

                    // Sessions created inside create_week_section, add messages.
                    $sessiontypes = [
                        get_string('presession', 'aiplacement_modgen'),
                        get_string('session', 'aiplacement_modgen'),
                        get_string('postsession', 'aiplacement_modgen'),
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
                        'contextlevel' => CONTEXT_MODULE,
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

                // Numbering tidy-up, after the commit but still under the structure locks:
                // see renumber_into_hierarchy_order().
                self::renumber_into_hierarchy_order($courseid, true);
            } catch (\Exception $e) {
                // Transaction automatically rolls back on exception.
                // Log technical details for administrators only.
                debugging('Week creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                throw new \moodle_exception('themecreationfailed', 'aiplacement_modgen');
            }
        } finally {
            self::release_structure_locks($locks);
        }

        return [
            'success' => true,
            'messages' => $messages,
        ];
    }

    /**
     * Put the course's section numbers back into flexsections' hierarchy order after a build.
     *
     * Every section this class creates is appended at top level by create_new_section(0, null)
     * and then reparented by writing the 'parent' format option directly (see
     * create_section_with_parent()). That append is a positional no-op, which
     * can_move_section_to() rejects, so format_flexsections::move_section() returns without
     * renumbering anything: nothing in a modgen build ever renumbers the course.
     *
     * A straight build gets away with it, because themes, their weeks and each week's sessions
     * are created depth-first, which is the order flexsections would have numbered them in
     * anyway. Adding content under a section that is not currently last does not: the new
     * subtree lands at the end of the course instead of immediately after its parent, so the
     * numbering stops matching the hierarchy even though every parent reference is correct.
     * That is worth repairing rather than tolerating, because format_flexsections deletes a
     * section by removing everything numbered after it: on a course in that state, deleting a
     * section silently removes unrelated content, or strands the descendants of the section
     * it did delete. See integrity_checker::check_section_order().
     *
     * Renumbering per section via move_section() would fix that but reintroduces the ~O(n^2)
     * reorder cost enforce_section_limit() exists to bound. This runs once per build instead,
     * and is a no-op when the numbering already agrees with the hierarchy — which is the
     * common case, so the ordinary paths pay only for the check.
     *
     * Never fails the build: the content is already committed by the time this runs, so
     * losing the tidy-up is strictly better than losing the build. It does need to be
     * noticed, though, given what an un-renumbered course does to section deletion, so a
     * failure raises \aiplacement_modgen\event\section_renumber_failed and
     * check_structure.php's "Renumber Sections" repairs it after the fact.
     *
     * Catches \Throwable, not \Exception: an Error raised below (a TypeError from a format
     * plugin, an exhausted recursion limit on a pathological tree) would otherwise escape
     * past the commit and fail a build whose content is already saved - the one outcome this
     * method exists to prevent - and would do so without logging the course that needs
     * repairing.
     *
     * @param int $courseid Course ID
     * @param bool $locksheld True when the caller already holds the structure locks, so
     *   fix_section_order() must not try to acquire them again.
     */
    public static function renumber_into_hierarchy_order($courseid, $locksheld = false) {
        try {
            integrity_checker::fix_section_order($courseid, $locksheld);
        } catch (\Throwable $e) {
            self::report_renumber_failure((int) $courseid, $e);
        }
    }

    /**
     * Record an abandoned renumber where an administrator will find it.
     *
     * debugging() alone is not enough here: it is silent on any site that is not in developer
     * mode, which is every production site, and this is precisely the failure that must not
     * pass unnoticed - the course is left in a state where deleting a section can remove
     * unrelated content with no error shown to the teacher who does it. The event lands in
     * the standard log store, so it survives the request and is reportable site-wide.
     *
     * Reporting a failure must not itself become one, so every step is guarded: the event
     * cannot be built if the course has been removed underneath the build, and triggering it
     * touches a log store that may be misconfigured or full. A lost log line is bad; an
     * exception thrown out of the error handler, past a commit, would be worse.
     *
     * @param int $courseid Course whose numbering was left out of order
     * @param \Throwable $e What stopped the renumber
     */
    private static function report_renumber_failure(int $courseid, \Throwable $e) {
        debugging('Section renumber after build failed: ' . $e->getMessage(), DEBUG_DEVELOPER);

        try {
            $event = \aiplacement_modgen\event\section_renumber_failed::build($courseid, $e);
            if ($event !== null) {
                $event->trigger();
            }
        } catch (\Throwable $ignored) {
            // Deliberately swallowed - see the docblock above.
            debugging(
                'Could not log the section renumber failure for course ' . $courseid . ': '
                    . $ignored->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Re-read a section's CURRENT number from its id.
     *
     * Section numbers in format_flexsections are positional, not identities: moving, deleting
     * or merging any section renumbers the whole course (see
     * format_flexsections::move_section()), as does this plugin's own post-build renumber.
     * Plain section creation does not, but relying on that distinction is not worth it: a
     * number captured before any write may name a different section afterwards. Anything
     * that has to survive a write must be held as a section id and converted back through
     * this method at the point of use.
     *
     * Reads the database directly rather than modinfo: this is called from inside bulk
     * creation transactions where the cached modinfo can lag the rows just written.
     *
     * @param int $courseid Course ID
     * @param int|null $sectionid Section id, or null
     * @return int|null Current section number, or null if the section no longer exists
     */
    public static function current_section_number($courseid, $sectionid) {
        global $DB;
        if (empty($sectionid)) {
            return null;
        }
        $num = $DB->get_field('course_sections', 'section', ['course' => $courseid, 'id' => $sectionid]);
        return ($num === false) ? null : (int) $num;
    }

    /**
     * Resolve a section id from its current number, for holding across writes.
     *
     * @param int $courseid Course ID
     * @param int $sectionnum Section number, 0 for top level
     * @return int|null Section id, or null for top level / not found
     */
    public static function section_id_from_number($courseid, $sectionnum) {
        global $DB;
        if ((int) $sectionnum <= 0) {
            return null;
        }
        $id = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => (int) $sectionnum]);
        return ($id === false) ? null : (int) $id;
    }

    /**
     * Acquire every lock that guards this course's section structure.
     *
     * The modgen lock only serialises modgen against itself. format_flexsections guards
     * its own delete path with a different factory and key, so without also holding that
     * one a bulk build can interleave with a teacher deleting a section - and every
     * section number this code is holding would shift underneath it.
     *
     * Both flexsections key forms are taken so this works against any build of the plugin:
     * upstream uses the fixed string, a per-course key is the sane variant.
     *
     * KNOWN TRADEOFF - 'course_modification_lock' has no course id in it, so holding it for
     * the duration of a build blocks section deletion in EVERY course on the site, not just
     * this one (format_flexsections::delete_section_with_children() waits only 10s on it and
     * then throws 'locktimeout'). This is deliberate and cannot be fixed from here: upstream
     * flexsections guards a per-course operation with a global key, and dropping the key is
     * what would actually remove the exclusion, since the per-course key below is one nothing
     * else takes. Narrowing the hold does not help either - the whole locked region is the
     * structural section creation the lock exists to protect. The real fix is upstream: give
     * delete_section_with_children() a per-course key, then drop the global one here.
     *
     * @param int $courseid Course ID
     * @param int $timeout Seconds to wait per lock
     * @return array Held locks (release with release_structure_locks())
     * @throws \moodle_exception if any lock cannot be obtained
     */
    public static function acquire_structure_locks($courseid, $timeout = 60) {
        $locks = [];
        $wanted = [
            ['core_course_edit', 'course_edit_' . $courseid],
            ['format_flexsections_delete_section', 'course_modification_lock'],
            ['format_flexsections_delete_section', 'course_' . $courseid],
        ];
        foreach ($wanted as [$type, $key]) {
            $factory = \core\lock\lock_config::get_lock_factory($type);
            $lock = $factory->get_lock($key, $timeout);
            if (!$lock) {
                foreach ($locks as $held) {
                    $held->release();
                }
                throw new \moodle_exception('erroracquiringlock', 'aiplacement_modgen');
            }
            $locks[] = $lock;
        }
        return $locks;
    }

    /**
     * Release locks obtained from acquire_structure_locks().
     *
     * @param array $locks
     */
    public static function release_structure_locks(array $locks) {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }

    /**
     * Create a theme section (top-level by default, or nested under a parent).
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object
     * @param string $title Theme title
     * @param string $summary Theme summary (plain text)
     * @param array $options Optional settings (e.g., ['collapsed' => 1])
     * @param bool $rebuildcache Whether to rebuild course cache after creation (default true)
     * @param int $parent Parent section number (0 for top-level)
     * @return int Section number of created theme
     */
    public static function create_theme_section(
        $courseid,
        $courseformat,
        $title,
        $summary,
        $options = [],
        $rebuildcache = true,
        $parent = 0
    ) {
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
        // Use format_string for title (XSS-safe) and FORMAT_PLAIN for summary to prevent XSS from AI-generated content.
        $themetitle = format_string($title, true, ['context' => $context]);
        $sectionhtml = trim($summary) !== '' ? format_text($summary, FORMAT_PLAIN, ['context' => $context]) : '';

        // Themes are top-level by default, but Quick Add can target an existing section.
        $collapsed = $options['collapsed'] ?? 1;
        $themesection = self::create_section_with_parent(
            $courseid,
            $courseformat,
            (int) $parent,
            $themetitle,
            $sectionhtml,
            FORMAT_PLAIN,
            ['collapsed' => $collapsed],
            $rebuildcache  // Pass through rebuild cache parameter.
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
    public static function create_week_section(
        $courseid,
        $courseformat,
        $parentsectionnum,
        $title,
        $summary,
        $options = [],
        $rebuildcache = true
    ) {
        global $DB;

        $context = \context_course::instance($courseid);

        // Verify flexsections format.
        if (!$courseformat || get_class($courseformat) !== 'format_flexsections') {
            throw new \moodle_exception('errorformatnotflexsections', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // Format week title.
        $weektitle = format_string($title, true, ['context' => $context]);

        // Prepare metadata for week-level learningactivity.
        // If metadata includes instructions, use that instead of setting summary on section.
        $weekmetadata = $options['metadata'] ?? [];
        $usemetadataforintro = !empty($weekmetadata['instructions']);

        // If using metadata for intro, don't set summary on section (leave it minimal/empty).
        // Otherwise, use the provided summary on the section.
        // Use FORMAT_PLAIN to prevent XSS from AI-generated content.
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
            false  // Don't rebuild cache yet - wait until session subsections created.
        );

        $weeksectionnum = $weeksection->section;
        // Hold the week by id: creating the metadata module and the three session
        // subsections below each renumber the course.
        $weeksectionid = (int) $weeksection->id;

        // Whether to create the learningactivity "section summary" placeholder modules.
        // Quick Add can switch these off (they hold no metadata there and are pure
        // clutter); the AI/JSON path leaves it on so the modules carry real metadata.
        $createsummaryactivities = $options['createsummaryactivities'] ?? true;

        // Create learningactivity metadata module at the start of the week.
        // Use custom name from metadata if provided, otherwise use the week title.
        if ($createsummaryactivities) {
            $weekactivityname = !empty($weekmetadata['name']) ? $weekmetadata['name'] : $title;

            $weekcmid = self::create_learningactivity_metadata(
                $courseid,
                self::current_section_number($courseid, $weeksectionid) ?? $weeksectionnum,
                'section',
                $weekactivityname,
                $weekmetadata
            );
        }

        // Create session subsections using shared helper.
        $sessiondata = $options['sessiondata'] ?? null;
        session_creator::create_session_subsections(
            $courseformat,
            self::current_section_number($courseid, $weeksectionid) ?? $weeksectionnum,
            $courseid,
            $sessiondata,
            $createsummaryactivities
        );

        // Note: flexsections already rebuilds cache after each section creation.
        // $rebuildcache parameter kept for interface consistency but not used.

        // Return the week's number as it stands NOW: the sessions just created renumbered
        // the course, so the number captured at creation time may be out of date.
        return self::current_section_number($courseid, $weeksectionid) ?? $weeksectionnum;
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
                'name' => $assessmentsname,
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
                'timemodified' => time(),
            ]);
        }

        // Create Assessments section if it doesn't exist.
        $assessmentsname = get_string('assessmentssectionname', 'aiplacement_modgen');
        $existing = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => $assessmentsname,
        ]);

        if (!$existing) {
            // Create new section after section 0.
            if (method_exists($courseformat, 'create_new_section')) {
                // Flexsections format - create at top level using centralized helper.
                $assessmentssection = self::create_section_with_parent(
                    $courseid,
                    $courseformat,
                    0, // Top level (no parent section).
                    $assessmentsname,
                    '', // No summary.
                    FORMAT_HTML,
                    [] // No additional format options.
                );

                $assessmentssectionnum = $assessmentssection->section;

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
                    'timemodified' => time(),
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
                'format' => 'flexsections',
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
    private static function validate_section_creation_params(
        $courseid,
        $courseformat,
        $parentsectionnum,
        $childsectionnum = null,
        $skipdepthcheck = false
    ) {
        global $DB;

        // Basic parameter validation.
        if (!is_numeric($courseid) || $courseid <= 0) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }

        if (!is_numeric($parentsectionnum) || $parentsectionnum < 0) {
            throw new \moodle_exception('invalidsectionparent', 'aiplacement_modgen');
        }

        if (!method_exists($courseformat, 'create_new_section')) {
            throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
        }

        // PROTECTION 1: Prevent section from being its own parent.
        if ($childsectionnum !== null && $parentsectionnum === $childsectionnum) {
            debugging("Prevented circular reference: Section {$childsectionnum} cannot be its own parent", DEBUG_DEVELOPER);
            throw new \moodle_exception(
                'circularsectionparent',
                'aiplacement_modgen',
                '',
                ['child' => $childsectionnum, 'parent' => $parentsectionnum]
            );
        }

        // Validate parent section exists if not top-level.
        if ($parentsectionnum > 0) {
            $parentsection = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $parentsectionnum,
            ]);

            if (!$parentsection) {
                throw new \moodle_exception('invalidparentsection', 'aiplacement_modgen', '', $parentsectionnum);
            }

            // PROTECTION 2: Check for circular references in parent chain.
            $wouldloop = $childsectionnum !== null &&
                self::would_create_circular_reference($courseid, $parentsectionnum, $childsectionnum);
            if ($wouldloop) {
                debugging(
                    "Prevented circular reference: Section {$childsectionnum} with parent " .
                    "{$parentsectionnum} would create a loop",
                    DEBUG_DEVELOPER
                );
                throw new \moodle_exception(
                    'circularsectionchain',
                    'aiplacement_modgen',
                    '',
                    ['child' => $childsectionnum, 'parent' => $parentsectionnum]
                );
            }

            // PERFORMANCE OPTIMIZATION: Skip depth check during bulk operations.
            // get_fast_modinfo() triggers expensive cache rebuilds (200-500 queries each).
            // During bulk operations with deferred cache rebuilds, depth violations will be
            // caught by the final rebuild anyway, making this check redundant and expensive.
            if (
                !$skipdepthcheck && method_exists($courseformat, 'get_section_depth') &&
                method_exists($courseformat, 'get_max_section_depth')
            ) {
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
    public static function create_section_with_parent(
        $courseid,
        $courseformat,
        $parentsectionnum,
        $name,
        $summary,
        $summaryformat,
        $options = [],
        $rebuildcache = true
    ) {
        global $DB;

        // Basic validation (parent existence and hierarchy depth).
        // Note: Cannot check circular refs yet as child section doesn't exist.
        // PERFORMANCE: Skip depth check if deferring cache rebuild (bulk operation).
        $skipdepthcheck = !$rebuildcache; // If deferring rebuild, skip expensive validation.
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
                'section' => $sectionnum,
            ], '*', MUST_EXIST);

            // PROTECTION: Now that we have the child section number, validate no circular reference.
            // Skip depth check here too since we already skipped it above.
            self::validate_section_creation_params($courseid, $courseformat, $parentsectionnum, $sectionnum, $skipdepthcheck);

            // Step 3: Update section properties (name, summary) with XSS protection.
            $section->name = clean_param($name, PARAM_TEXT); // Strip HTML/JS from names.
            $section->summary = $summary; // Keep raw - will be sanitized on display.
            // Validate summary format - only allow safe formats.
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
            throw new \moodle_exception(
                'sectorcreationfailed',
                'aiplacement_modgen',
                '',
                clean_param($name, PARAM_TEXT)
            ); // Sanitized name in user message.
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
            'section' => $sectionnumber,
        ]);

        if (!$section) {
            return false;
        }

        // Get parent option.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $courseid,
            'sectionid' => $section->id,
            'name' => 'parent',
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
