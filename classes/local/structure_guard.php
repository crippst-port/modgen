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
 * Whether a Flexible sections course is free for the next section change.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Whether a Flexible sections course is free for the next section change.
 *
 * Flexible sections renumbers every section on a delete, duplicate, move, merge or add, and two
 * that overlap can leave the course out of order. It cannot be changed to wait for itself, so
 * the course page (amd/src/structure_guard.js) asks here before each change and holds it back
 * while another is under way. Only the browser is held back: a request that does not come from
 * the page, such as a web service call, goes straight through as before.
 *
 * Busy means one of the locks around a change to the course's sections is held: the one
 * Flexible sections takes around a section delete, which a Module Assistant build or repair
 * also holds for its whole run, or the course edit lock some repairs take on its own. A
 * duplicate, move or add takes no lock in Flexible sections, so one from another page is not
 * seen; the page waits for its own.
 */
class structure_guard {
    /** @var string Busy with a section delete, or a build or repair holding the same lock. */
    public const BUSY_SECTIONS = 'sections';

    /** @var string Busy with a Module Assistant build or repair. */
    public const BUSY_BUILD = 'build';

    /**
     * Whether the course page should hold section changes back for this user.
     *
     * The caller checks the page is a course page in editing mode. A page that has stopped
     * applying, with the placement or the guard switched off since it loaded, stops guarding
     * rather than refusing changes (ajax/structure_busy.php).
     *
     * @param int $courseid Course ID
     * @param \context_course $context Course context
     * @return bool
     */
    public static function applies(int $courseid, \context_course $context): bool {
        return settings_helper::is_placement_enabled()
            && settings_helper::is_structureguard_enabled()
            && course_formats::is_flexsections($courseid)
            && has_capability('moodle/course:update', $context);
    }

    /**
     * Why the course is not free for a section change, or null when it is.
     *
     * Each lock is tried without waiting and let go at once, so a change that starts meanwhile
     * waits a moment at most. Flexible sections 4.1.4 to 4.1.6 lock deletes for the whole site, so
     * there a delete in any Flexible sections course counts; it would hold this change up anyway.
     *
     * @param int $courseid Course ID
     * @return string|null BUSY_SECTIONS, BUSY_BUILD or null
     */
    public static function busy_reason(int $courseid): ?string {
        if (
            self::is_held(
                course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE,
                course_formats::flexsections_delete_lock_key($courseid)
            )
        ) {
            // A running build holds this lock too: say so, as it can take much longer than a delete.
            return self::has_running_job($courseid) ? self::BUSY_BUILD : self::BUSY_SECTIONS;
        }
        if (self::is_held('core_course_edit', 'course_edit_' . $courseid)) {
            return self::BUSY_BUILD;
        }
        return null;
    }

    /**
     * Whether a lock is held by someone else just now.
     *
     * @param string $type Lock factory type
     * @param string $key Lock key
     * @return bool
     */
    private static function is_held(string $type, string $key): bool {
        $lock = \core\lock\lock_config::get_lock_factory($type)->get_lock($key, 0);
        if (!$lock) {
            return true;
        }
        $lock->release();
        return false;
    }

    /**
     * Whether a Module Assistant build is running in the course.
     *
     * @param int $courseid Course ID
     * @return bool
     */
    private static function has_running_job(int $courseid): bool {
        global $DB;
        return $DB->record_exists('aiplacement_modgen_jobs', ['courseid' => $courseid, 'status' => 'running']);
    }
}
