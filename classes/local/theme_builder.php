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

use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Theme builder service class.
 */
class theme_builder {
    /** @var int Sections each week adds: the week itself and its three session subsections. */
    public const SECTIONS_PER_WEEK = 4;

    /**
     * How many sections a Quick Add theme build will create.
     *
     * @param int $themecount Number of themes.
     * @param int $weekspertheme Number of weeks in each theme.
     * @return int
     */
    public static function projected_sections_for_themes(int $themecount, int $weekspertheme): int {
        return $themecount + ($themecount * $weekspertheme * self::SECTIONS_PER_WEEK);
    }

    /**
     * How many sections a Quick Add week build will create.
     *
     * @param int $weekcount Number of weeks.
     * @return int
     */
    public static function projected_sections_for_weeks(int $weekcount): int {
        return $weekcount * self::SECTIONS_PER_WEEK;
    }

    /**
     * Enforce the per-course total-section safety limit.
     *
     * Counts the course's existing sections and refuses the operation if adding $projectedsections
     * would push the total past the configured maximum ('maxtotalsections', default constants::MAX_TOTAL_SECTIONS).
     * This guards every creation entry point — Quick Add, template/JSON and any retry — against the
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

        // Custom sections and Weekly sections also have the site's own cap, and core counts subsections
        // against it: once a course's highest section number reaches it, nobody can add a section or a
        // subsection by hand. Section 0 is not counted.
        if (course_formats::is_core((int) $courseid)) {
            $coremax = (int) course_get_format($courseid)->get_max_sections();
            if ($total - 1 > $coremax) {
                throw new \moodle_exception('coresectionlimitexceeded', 'aiplacement_modgen', '', (object) [
                    'existing' => $existing - 1,
                    'total' => $total - 1,
                    'max' => $coremax,
                ]);
            }
        }
    }

    /**
     * Take the structure locks for a build, then check the build still fits in the course.
     *
     * The check must come after the locks: a build that held them before us may have used up the
     * room that an earlier check saw. Every build goes through here so none can skip it.
     *
     * @param int $courseid Course ID.
     * @param int $projectedsections Number of sections the build will add.
     * @return array Locks, to pass to release_structure_locks().
     * @throws \moodle_exception If a lock cannot be had or the build no longer fits; no lock is held then.
     */
    public static function acquire_build_locks($courseid, int $projectedsections): array {
        $locks = self::acquire_structure_locks($courseid);
        try {
            self::enforce_section_limit($courseid, $projectedsections);
        } catch (\Throwable $e) {
            self::release_structure_locks($locks);
            throw $e;
        }
        return $locks;
    }

    /**
     * Check, under the build locks, that the section a build was to go inside still exists.
     *
     * @param int $courseid Course ID.
     * @param int|null $parentsectionid Pinned parent section id, null for the top level.
     * @param int $parent Section number the caller asked for, 0 for the top level.
     * @param array $messages Build messages; one is added if the parent has gone.
     * @return int|null The parent section id to build in, null for the top level.
     */
    private static function check_parent_still_exists($courseid, ?int $parentsectionid, $parent, array &$messages): ?int {
        if (($parent > 0 || $parentsectionid) && self::current_section_number($courseid, $parentsectionid) === null) {
            $messages[] = get_string('parentsectiongone', 'aiplacement_modgen');
            return null;
        }
        return $parentsectionid;
    }

    /**
     * Count how many course sections an approved JSON structure will create.
     *
     * Mirrors section_creation_service: each theme is one section, and every week - in a theme
     * or on its own - is one section plus the three session subsections create_week_section()
     * always adds, whatever sessions the JSON lists. The JSON comes back from the browser, so
     * this must not trust its 'sessions' to say how many sections will be made.
     *
     * @param array $json Approved JSON structure.
     * @param string $moduletype Module type (connected_theme, connected_weekly, etc).
     * @return int Projected number of sections.
     */
    public static function count_projected_sections_from_json(array $json, $moduletype) {
        $sectionsperweek = self::SECTIONS_PER_WEEK;
        $count = 0;

        if ($moduletype === 'connected_theme' && !empty($json['themes']) && is_array($json['themes'])) {
            foreach ($json['themes'] as $theme) {
                if (!is_array($theme)) {
                    continue;
                }
                $count++; // Theme section.
                $weeks = (!empty($theme['weeks']) && is_array($theme['weeks'])) ? $theme['weeks'] : [];
                $count += $sectionsperweek * count(array_filter($weeks, 'is_array'));
            }
        } else if (!empty($json['sections']) && is_array($json['sections'])) {
            $count += $sectionsperweek * count(array_filter($json['sections'], 'is_array'));
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
     * @param bool $createsummaryactivities Whether to add the learningactivity summary module to each section
     * @param int|null $parentsectionid Stable parent ID from a queued job; null resolves $parent before waiting for locks
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_themes(
        $courseid,
        $themecount,
        $weeksperthemecount,
        $parent = 0,
        $createsummaryactivities = true,
        ?int $parentsectionid = null
    ) {
        global $DB;

        $messages = [];

        // Themes need Flexible sections: refuse a course that cannot hold them before anything else.
        course_formats::require_supported((int) $courseid);
        course_formats::require_themes((int) $courseid);

        // Refuse before doing any work if this would push the course past the section limit.
        $projectedsections = self::projected_sections_for_themes((int) $themecount, (int) $weeksperthemecount);
        self::enforce_section_limit($courseid, $projectedsections);

        // A caller with only a number: pin it to an id now, so a renumbering during the lock wait
        // cannot move the build. The number is only as current as when the caller read it.
        $parentsectionid = $parentsectionid ?? self::section_id_from_number($courseid, $parent);

        // Acquire every lock guarding this course's structure - including the one
        // format_flexsections uses, which the modgen lock alone does not exclude.
        $locks = self::acquire_build_locks($courseid, $projectedsections);

        try {
            $parentsectionid = self::check_parent_still_exists($courseid, $parentsectionid, $parent, $messages);

            // Check the format again now the locks are held (must be inside try block to ensure lock release).
            self::prepare_course_for_build($courseid, true);

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $courseformat = course_get_format($course);
            // Give any module an earlier build left without a context one now, so it cannot fail this build.
            self::ensure_module_contexts($courseid);
            // Start transaction for entire bulk operation.
            // This ensures all themes and weeks are created atomically - all or nothing.
            $wasintransaction = $DB->is_transaction_started();
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

                // Subsections are course modules: make sure each has a context before commit.
                if ($createdcount > 0) {
                    self::ensure_module_contexts($courseid);
                }

                // Note: flexsections already rebuilds cache after each section creation.
                // No explicit rebuild needed here.

                // Commit all changes - all themes and weeks created successfully.
                $transaction->allow_commit();

                // Numbering tidy-up, after the commit but still under the structure locks:
                // see renumber_into_hierarchy_order(). Deliberately outside the transaction
                // so its own transaction and cache rebuild are not nested inside this one.
                self::renumber_into_hierarchy_order($courseid, true);
            } catch (\Throwable $e) {
                // Delegated transactions do NOT roll back by themselves: left open, later writes
                // (e.g. the task marking the job failed) would be swallowed by the eventual rollback.
                self::rollback_build($transaction, $e, $wasintransaction);
                // Log technical details for administrators only.
                debugging('Theme creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                // Kept as it is when a retry could succeed, so the task can tell.
                if (self::is_transient_failure($e)) {
                    throw $e;
                }
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
     * @param bool $createsummaryactivities Whether to add the learningactivity summary module to each section
     * @param int|null $parentsectionid Stable parent ID from a queued job; null resolves $parent before waiting for locks
     * @return array Result with 'success' boolean and 'messages' array
     */
    public static function create_weeks(
        $courseid,
        $weekcount,
        $parent = 0,
        $createsummaryactivities = true,
        ?int $parentsectionid = null
    ) {
        global $DB;

        $messages = [];

        // Refuse before doing any work if this would push the course past the section limit.
        $projectedsections = self::projected_sections_for_weeks((int) $weekcount);
        self::enforce_section_limit($courseid, $projectedsections);

        // A caller with only a number: pin it to an id now, so a renumbering during the lock wait
        // cannot move the build. The number is only as current as when the caller read it.
        $parentsectionid = $parentsectionid ?? self::section_id_from_number($courseid, $parent);

        // Acquire every lock guarding this course's structure - including the one
        // format_flexsections uses, which the modgen lock alone does not exclude.
        $locks = self::acquire_build_locks($courseid, $projectedsections);

        try {
            $parentsectionid = self::check_parent_still_exists($courseid, $parentsectionid, $parent, $messages);

            // Check the format again now the locks are held (must be inside try block to ensure lock release).
            self::prepare_course_for_build($courseid);

            // A week holds its sessions as subsections, and core formats nest only one level, so a week
            // there cannot go inside another section.
            if ($parentsectionid && !course_formats::is_flexsections((int) $courseid)) {
                throw new \moodle_exception('errorweekstoplevelonly', 'aiplacement_modgen');
            }

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $courseformat = course_get_format($course);

            // Give any module an earlier build left without a context one now, so it cannot fail this build.
            self::ensure_module_contexts($courseid);

            // Start transaction for entire bulk operation.
            // This ensures all weeks are created atomically - all or nothing.
            $wasintransaction = $DB->is_transaction_started();
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

                // Subsections are course modules: make sure each has a context before commit.
                if ($createdcount > 0) {
                    self::ensure_module_contexts($courseid);
                }

                // Note: flexsections already rebuilds cache after each section creation.
                // No explicit rebuild needed here.

                // Commit all changes - all weeks created successfully.
                $transaction->allow_commit();

                // Numbering tidy-up, after the commit but still under the structure locks:
                // see renumber_into_hierarchy_order().
                self::renumber_into_hierarchy_order($courseid, true);
            } catch (\Throwable $e) {
                // Delegated transactions do NOT roll back by themselves: left open, later writes
                // (e.g. the task marking the job failed) would be swallowed by the eventual rollback.
                self::rollback_build($transaction, $e, $wasintransaction);
                // Log technical details for administrators only.
                debugging('Week creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
                // Kept as it is when a retry could succeed, so the task can tell.
                if (self::is_transient_failure($e)) {
                    throw $e;
                }
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
            // Core formats keep their own order: a new section goes before the subsections' sections, and
            // nothing hangs off a section number. All they need is the cache.
            if (!course_formats::is_flexsections((int) $courseid)) {
                rebuild_course_cache($courseid, true, true);
                return;
            }
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
     * Whether a failed build might succeed if it is simply run again.
     *
     * Only failures that depend on what else was happening at the time: a database error, a section
     * write that failed part way, or not getting a lock. Anything else - a missing capability, a
     * switched-off feature, a structure the course cannot take - fails the same way every time.
     *
     * @param \Throwable $e The failure
     * @return bool
     */
    public static function is_transient_failure(\Throwable $e): bool {
        // A record that is missing (the course was deleted, say) stays missing.
        if ($e instanceof \dml_missing_record_exception) {
            return false;
        }
        return $e instanceof \dml_exception
            || $e instanceof structure_write_failed_exception
            || ($e instanceof \moodle_exception && in_array($e->errorcode, ['erroracquiringlock', 'locktimeout'], true));
    }

    /**
     * Give every course module in a course a context, creating any that are missing.
     *
     * Subsections are course modules, and one without a context fails integrity checks and
     * cache rebuilds. Builds call this before they start, in case an earlier one left any, and
     * again before they commit.
     *
     * @param int $courseid Course ID
     */
    public static function ensure_module_contexts(int $courseid): void {
        global $DB;

        $cmids = $DB->get_fieldset_sql(
            "SELECT cm.id
               FROM {course_modules} cm
          LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
              WHERE cm.course = :courseid AND ctx.id IS NULL",
            ['courseid' => $courseid, 'contextlevel' => CONTEXT_MODULE]
        );
        foreach ($cmids as $cmid) {
            \context_module::instance($cmid);
        }
    }

    /**
     * Roll back a delegated transaction after a failure, without masking the original error.
     *
     * rollback() rethrows the exception it is given, and throws its own error if the
     * transaction was already committed or disposed, so both are absorbed here.
     *
     * @param \moodle_transaction $transaction Transaction to roll back
     * @param \Throwable $cause The failure that triggered the rollback
     */
    public static function rollback_quietly(\moodle_transaction $transaction, \Throwable $cause): void {
        if ($transaction->is_disposed()) {
            return;
        }
        try {
            $transaction->rollback($cause);
        } catch (\Throwable $rethrown) {
            // Expected: rollback() rethrows the cause.
            unset($rethrown);
        }
    }

    /**
     * Roll back a whole build after a failure, including anything core left open inside it.
     *
     * Moodle only really rolls back when the transaction being rolled back is the innermost one
     * still open. Core opens its own transactions inside a build (add_moduleinfo() for every
     * subsection and summary activity, move_section_to()) and abandons them when something inside
     * throws, so the build's rollback is then only marked: the database transaction stays open,
     * and whatever runs next - the task marking the job failed - is written inside it and lost
     * when cron finally rolls everything back. A permanent failure would also be retried, as cron
     * treats a task that leaves a transaction open as failed.
     *
     * When the build started the transaction, nothing outside it is in one, so everything still
     * open is the build's and is rolled back here. Inside a caller's transaction that is the
     * caller's to end.
     *
     * @param \moodle_transaction $transaction The build's transaction
     * @param \Throwable $cause The failure
     * @param bool $wasintransaction Whether a transaction was already open when the build started its own
     */
    public static function rollback_build(\moodle_transaction $transaction, \Throwable $cause, bool $wasintransaction): void {
        global $DB;
        self::rollback_quietly($transaction, $cause);
        if (!$wasintransaction && $DB->is_transaction_started()) {
            $DB->force_transaction_rollback();
        }
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
     * The flexsections keys are only taken for a Flexible sections course, so a build in a core
     * format does not hold up section deletes in Flexible sections courses across the site.
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
        ];
        if (course_formats::is_flexsections((int) $courseid)) {
            $wanted[] = ['format_flexsections_delete_section', 'course_modification_lock'];
            $wanted[] = ['format_flexsections_delete_section', 'course_' . $courseid];
        }
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

        // Fails first, before anything is written, for a course that does not exist.
        \context_course::instance($courseid);

        // Themes hold weeks that hold sessions: only Flexible sections nests that deep.
        if (!$courseformat || $courseformat->get_format() !== course_formats::FLEXSECTIONS) {
            throw new \moodle_exception('errorthemesneedflexsections', 'aiplacement_modgen');
        }
        self::require_buildable_format($courseformat);

        // Store the text as given. FORMAT_PLAIN means Moodle escapes it when the section is shown, so
        // escaping here as well would display '&amp;' and '&lt;i&gt;' literally. Tags in the title are
        // stripped by create_section_with_parent().
        $themetitle = trim((string) $title);
        $sectionhtml = trim((string) $summary) !== '' ? (string) $summary : '';

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
     * @param string $summary Week summary (plain text, or HTML when 'summaryformat' => FORMAT_HTML)
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

        // Fails first, before anything is written, for a course that does not exist.
        \context_course::instance($courseid);
        self::require_buildable_format($courseformat);

        // Store the title as given; it is escaped when shown (see create_theme_section()).
        $weektitle = trim((string) $title);

        // Keep the summary unless an activity carrying the replacement instructions is actually created.
        $weekmetadata = $options['metadata'] ?? [];

        // Plain text is stored as given and escaped when shown. A caller that has already built
        // sanitised HTML (the weekly import adds an outline list) says so with 'summaryformat'.
        $weeksummaryformat = ($options['summaryformat'] ?? FORMAT_PLAIN) === FORMAT_HTML ? FORMAT_HTML : FORMAT_PLAIN;
        $weeksectionhtml = '';
        if (trim((string) $summary) !== '') {
            $weeksectionhtml = (string) $summary;
        }

        // Create section with explicit parent and collapsed option using centralized helper.
        $collapsed = $options['collapsed'] ?? 1;
        $weeksection = self::create_section_with_parent(
            $courseid,
            $courseformat,
            $parentsectionnum,
            $weektitle,
            $weeksectionhtml,
            $weeksummaryformat,
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
            $weekactivityname = \core_text::substr(
                !empty($weekmetadata['name']) ? $weekmetadata['name'] : $title,
                0,
                255
            );

            $summarycmid = session_creator::create_learningactivity_metadata(
                $courseid,
                self::current_section_number($courseid, $weeksectionid) ?? $weeksectionnum,
                'section',
                $weekactivityname,
                $weekmetadata
            );
            // Judge by the instructions the activity was given, after validation: markup-only or
            // blank instructions are dropped, and the summary must then stay. Clear it through
            // the format so the section event fires and the cached copy is purged.
            $validatedmetadata = learningactivity_validator::validate_metadata($weekmetadata);
            if ($summarycmid !== null && $validatedmetadata['instructions'] !== '') {
                $weeksectionrecord = $DB->get_record('course_sections', ['id' => $weeksectionid], '*', MUST_EXIST);
                course_update_section($courseid, $weeksectionrecord, ['summary' => '']);
            }
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
     * Name section 0, once, in a course that has not been set up.
     *
     * Section 0 is named 'Introduction & General Information' while it still has no name of its own,
     * as in a new course; a name the teacher gave it is kept. Builds add no other sections here: the
     * Assessments section is added only when asked for, with add_assessments_section().
     *
     * @param int $courseid Course ID
     */
    public static function initialize_core_sections($courseid) {
        global $DB;

        $section0 = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0], '*', MUST_EXIST);
        if (trim((string) $section0->name) !== '') {
            return;
        }

        $DB->update_record('course_sections', [
            'id' => $section0->id,
            'name' => get_string('introductionsectionname', 'aiplacement_modgen'),
            'timemodified' => time(),
        ]);
    }

    /**
     * Add an 'Assessments' section at the top of the course, straight after section 0.
     *
     * Quick Add's Assessments entry. Runs under the build locks and the section limit, and sets the
     * course up, like any other build; refuses if the course already has a top-level section with
     * that name, in any case.
     *
     * @param int $courseid Course ID
     * @return \stdClass The new section's record.
     * @throws \moodle_exception If the course already has one, is full, or a lock cannot be had.
     */
    public static function add_assessments_section($courseid) {
        global $DB;

        $assessmentsname = get_string('assessmentssectionname', 'aiplacement_modgen');
        $locks = self::acquire_build_locks($courseid, 1);
        try {
            // Only a top-level section counts, in any case: a week inside a theme may share the name, and so may
            // a core subsection, whose section is delegated to the activity.
            $exists = $DB->record_exists_sql(
                "SELECT 1
                   FROM {course_sections} cs
              LEFT JOIN {course_format_options} cfo
                     ON cfo.sectionid = cs.id AND cfo.name = 'parent' AND cfo.format = 'flexsections'
                  WHERE cs.course = :courseid AND cs.section > 0 AND cs.component IS NULL
                        AND (cfo.id IS NULL OR " . $DB->sql_compare_text('cfo.value') . " IN ('', '0'))
                        AND " . $DB->sql_equal('cs.name', ':name', false),
                ['courseid' => $courseid, 'name' => $assessmentsname]
            );
            if ($exists) {
                throw new \moodle_exception('assessmentsexists', 'aiplacement_modgen', '', $assessmentsname);
            }

            // Checked and set up like every other build.
            self::prepare_course_for_build($courseid);
            $courseformat = course_get_format($DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST));

            // Create at top level, then move it to position 1.
            $section = self::create_section_with_parent(
                $courseid,
                $courseformat,
                0, // Top level (no parent section).
                $assessmentsname,
                '', // No summary.
                FORMAT_HTML,
                [] // No additional format options.
            );

            try {
                if ($courseformat->get_format() === course_formats::FLEXSECTIONS) {
                    $courseformat->move_section($section->section, 0, 1);
                } else {
                    move_section_to($courseformat->get_course(), $section->section, 1);
                }
            } catch (\Throwable $e) {
                // If move fails, section will remain at end but still be created.
                debugging('Failed to move Assessments section: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            // Other builds end with a renumbering that rebuilds the cache; this one has to do it itself.
            rebuild_course_cache($courseid, true, true);
        } finally {
            self::release_structure_locks($locks);
        }

        return $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
    }

    /**
     * Get a course ready for a build: check its format can take the build, then name section 0.
     *
     * The plugin never changes a course's format; see course_formats.
     *
     * @param int $courseid Course ID
     * @param bool $withthemes Whether the build adds themes, which only Flexible sections can hold
     * @throws \moodle_exception If the course's format cannot take the build
     */
    public static function prepare_course_for_build($courseid, bool $withthemes = false) {
        course_formats::require_supported((int) $courseid);
        if ($withthemes) {
            course_formats::require_themes((int) $courseid);
        }
        // Each session is a Subsection activity there. Checked now, not left to the first session: a refusal
        // in the middle of a section write would read as a failed write, which is retried.
        if (course_formats::is_core((int) $courseid)) {
            $context = \context_course::instance($courseid);
            require_capability('moodle/course:manageactivities', $context);
            require_capability('mod/subsection:addinstance', $context);
        }
        self::initialize_core_sections($courseid);
    }

    /**
     * Refuse a course format object the builder cannot write sections through.
     *
     * @param object|null $courseformat Course format object
     * @throws \moodle_exception If the format is not supported
     */
    private static function require_buildable_format($courseformat): void {
        $format = $courseformat ? $courseformat->get_format() : '';
        if ($format === course_formats::FLEXSECTIONS) {
            if (!method_exists($courseformat, 'create_new_section')) {
                throw new \moodle_exception('errorflexsectionsmissingmethod', 'aiplacement_modgen');
            }
            return;
        }
        if (!in_array($format, course_formats::CORE_FORMATS, true)) {
            throw new \moodle_exception('errorformatnotsupported', 'aiplacement_modgen');
        }
    }

    /**
     * Validate parameters for section creation, before anything is written.
     *
     * A new section has no children, so it cannot close a loop of parent links: only the parent,
     * and the depth the new section would be at, need checking.
     *
     * @param int $courseid Course ID
     * @param object $courseformat Course format object
     * @param int $parentsectionnum Parent section number (0 for top-level)
     * @throws \moodle_exception If validation fails
     */
    private static function validate_section_creation_params($courseid, $courseformat, $parentsectionnum) {
        global $DB;

        // Basic parameter validation.
        if (!is_numeric($courseid) || $courseid <= 0) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }

        if (!is_numeric($parentsectionnum) || $parentsectionnum < 0) {
            throw new \moodle_exception('invalidsectionparent', 'aiplacement_modgen');
        }

        self::require_buildable_format($courseformat);

        if ($parentsectionnum > 0) {
            $parentsection = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $parentsectionnum]);
            if (!$parentsection) {
                throw new \moodle_exception('invalidparentsection', 'aiplacement_modgen', '', $parentsectionnum);
            }

            // Core formats nest one level: a subsection goes in a section, never in another subsection.
            if ($courseformat->get_format() !== course_formats::FLEXSECTIONS) {
                if (!empty($parentsection->component)) {
                    throw new \moodle_exception('maxsectiondepthreached', 'aiplacement_modgen', '', 2);
                }
                return;
            }

            // The format's depth limit. Worked out from the stored parent links rather than
            // get_fast_modinfo(), which would rebuild the course cache for every section of a build.
            if (method_exists($courseformat, 'get_max_section_depth')) {
                $maxdepth = $courseformat->get_max_section_depth();
                if (self::stored_section_depth($courseid, $parentsectionnum) + 1 > $maxdepth) {
                    throw new \moodle_exception('maxsectiondepthreached', 'aiplacement_modgen', '', $maxdepth);
                }
            }
        }
    }

    /**
     * How deep a section is, counted as Flexible sections counts it: 1 at the top level.
     *
     * Follows the stored parent links. Like the format, a link to a section that is not before
     * this one is treated as broken and ends the walk, so bad data cannot make this loop.
     *
     * @param int $courseid Course ID
     * @param int $sectionnum Section number
     * @return int Depth
     */
    private static function stored_section_depth($courseid, $sectionnum) {
        global $DB;

        $depth = 1;
        while ($sectionnum > 0) {
            $parent = $DB->get_field_sql(
                "SELECT cfo.value
                   FROM {course_sections} cs
                   JOIN {course_format_options} cfo ON cfo.sectionid = cs.id AND cfo.name = 'parent'
                  WHERE cs.course = ? AND cs.section = ?",
                [$courseid, $sectionnum]
            );
            $parent = trim((string) $parent);
            if (!ctype_digit($parent) || (int) $parent <= 0 || (int) $parent >= $sectionnum) {
                break;
            }
            $depth++;
            $sectionnum = (int) $parent;
        }
        return $depth;
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

        // Basic validation (parent existence and hierarchy depth), before anything is written.
        self::validate_section_creation_params($courseid, $courseformat, $parentsectionnum);

        // Additional validation for section name.
        if (empty(trim($name))) {
            throw new \moodle_exception('invalidsectionname', 'aiplacement_modgen');
        }

        // Validate summary format - only allow safe formats.
        if (!in_array($summaryformat, [FORMAT_PLAIN, FORMAT_MARKDOWN, FORMAT_HTML, FORMAT_MOODLE])) {
            debugging('Invalid summary format ' . $summaryformat . ', defaulting to FORMAT_HTML', DEBUG_DEVELOPER);
            $summaryformat = FORMAT_HTML;
        }

        // Start transaction for atomic operation.
        $wasintransaction = $DB->is_transaction_started();
        $transaction = $DB->start_delegated_transaction();

        try {
            if ($courseformat->get_format() !== course_formats::FLEXSECTIONS) {
                $section = self::create_core_section($courseid, (int) $parentsectionnum, $name, $summary, $summaryformat);
                $transaction->allow_commit();
                return $section;
            }

            // Step 1: Create section at top level first.
            // Note: We create at top level (0) then set parent manually to avoid nested transaction issues.
            $sectionnum = $courseformat->create_new_section(0, null);

            // Step 2: Get full section record.
            $section = $DB->get_record('course_sections', [
                'course' => $courseid,
                'section' => $sectionnum,
            ], '*', MUST_EXIST);

            // Step 3: Update section properties (name, summary) with XSS protection.
            // Strip HTML/JS from names, and keep to the 255 characters the column holds: a longer title from
            // a file would otherwise fail the whole build with a database error.
            $section->name = \core_text::substr(clean_param($name, PARAM_TEXT), 0, 255);
            $section->summary = $summary; // Keep raw - will be sanitized on display.
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
        } catch (\Throwable $e) {
            self::rollback_build($transaction, $e, $wasintransaction);
            // Log technical details for administrators only.
            debugging('Section creation failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);

            // The rollback has also doomed any enclosing transaction, so this must end the build:
            // see structure_write_failed_exception. The user-facing message has no technical details.
            throw new structure_write_failed_exception($name, $e->getMessage());
        }
    }

    /**
     * Create a section in Custom sections or Weekly sections.
     *
     * At the top level this is an ordinary section. Inside a section it is a core subsection: a
     * Subsection activity in that section, which holds a section of its own (a delegated section).
     * Core numbers both kinds itself, putting ordinary sections before delegated ones, so the
     * caller must hold the result by id, not by number. The caller holds the transaction.
     *
     * @param int $courseid Course ID
     * @param int $parentsectionnum Section number to create the subsection in, 0 for the top level
     * @param string $name Section name
     * @param string $summary Section summary
     * @param int $summaryformat Summary format, already checked
     * @return \stdClass The new section's record
     */
    private static function create_core_section(
        int $courseid,
        int $parentsectionnum,
        string $name,
        string $summary,
        int $summaryformat
    ): \stdClass {
        global $DB;

        // Strip HTML/JS from names, and keep to the 255 characters the column holds.
        $name = \core_text::substr(clean_param($name, PARAM_TEXT), 0, 255);

        if ($parentsectionnum > 0) {
            // The core create_module() checks the user may add a Subsection here, and that the activity is enabled.
            $module = create_module((object) [
                'modulename' => 'subsection',
                'course' => $courseid,
                'section' => $parentsectionnum,
                'visible' => 1,
                'name' => $name,
            ]);
            $sectionid = (int) $DB->get_field('course_sections', 'id', [
                'course' => $courseid,
                'component' => 'mod_subsection',
                'itemid' => $module->instance,
            ], MUST_EXIST);
        } else {
            $sectionid = (int) course_create_section($courseid)->id;
        }

        // Through core's update, as the course page does: it keeps a subsection's own name in step with its
        // section's, logs the change and refreshes the cached course.
        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
        course_update_section($courseid, $section, [
            'name' => $name,
            'summary' => $summary, // Kept raw: sanitised when shown.
            'summaryformat' => $summaryformat,
        ]);

        return $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
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
