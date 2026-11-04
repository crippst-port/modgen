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
 * Queue for the automatic section renumber ad hoc task.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

use aiplacement_modgen\task\reorder_sections_task;

/**
 * Keeps at most one waiting renumber task per course and moves its run time back on each edit.
 */
class section_reorder_queue {
    /** @var int Seconds to wait after the last section change before renumbering. */
    public const DELAY = 60;

    /**
     * Queue a renumber for the course, or push back the one already waiting.
     *
     * Moodle's reschedule_or_queue_adhoc_task() cannot see timestarted, so a running task would
     * be pushed back instead of a new one being queued behind it. Only rows that have not
     * started are moved here.
     *
     * @param int $courseid Course to renumber
     * @param int $delay Seconds from now until the task is due
     */
    public static function schedule(int $courseid, int $delay = self::DELAY): void {
        global $DB;

        $waiting = array_filter(self::get_task_rows($courseid), fn($row) => $row->timestarted === null);
        if ($waiting) {
            $row = reset($waiting);
            $DB->set_field('task_adhoc', 'nextruntime', time() + $delay, ['id' => $row->id]);
            return;
        }

        $task = reorder_sections_task::for_course($courseid);
        $task->set_next_run_time(time() + $delay);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Renumber a course now, as the task would, for a repair that has just changed parents.
     *
     * A reparent on Check Structure is one deliberate change, not a burst of edits, so there is no
     * quiet period to wait for. When the lock cannot be had the renumber is queued instead.
     *
     * @param int $courseid Flexible sections course
     * @param string $via Where it was run from, for the log
     * @return array<int,int> Old section number => new, for every section that moved (empty if none)
     */
    public static function renumber_now(int $courseid, string $via = 'check_structure'): array {
        global $DB;

        if (!settings_helper::is_autoreorder_enabled()) {
            return [];
        }

        $before = $DB->get_records_menu('course_sections', ['course' => $courseid], '', 'id, section');
        try {
            $result = integrity_checker::fix_section_order($courseid);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'erroracquiringlock') {
                throw $e;
            }
            self::schedule($courseid);
            return [];
        }
        self::record_outcome($courseid, $result, $via);
        if (empty($result['fixed'])) {
            return [];
        }

        $moved = [];
        foreach ($DB->get_records_menu('course_sections', ['course' => $courseid], '', 'id, section') as $id => $num) {
            if (isset($before[$id]) && (int) $before[$id] !== (int) $num) {
                $moved[(int) $before[$id]] = (int) $num;
            }
        }
        return $moved;
    }

    /**
     * Log what a renumber did, so the status icon can show it.
     *
     * @param int $courseid Course ID
     * @param array $result What integrity_checker::fix_section_order() returned
     * @param string $via Where it was run from: adhoctask or check_structure
     */
    public static function record_outcome(int $courseid, array $result, string $via): void {
        if (!empty($result['deferred'])) {
            return;
        }
        if ($result['checkable'] && $result['fixed'] > 0) {
            \aiplacement_modgen\event\structure_repaired::for_course($courseid, 'fixsectionorder', (int) $result['fixed'], $via)
                ->trigger();
        } else if ($result['checkable']) {
            // Already in order. Logged anyway, so the status icon can say the run finished.
            $event = \aiplacement_modgen\event\section_order_checked::for_course($courseid);
            if ($event) {
                $event->trigger();
            }
        } else if ($result['blockedby'] === 'brokenhierarchy') {
            $event = \aiplacement_modgen\event\section_order_blocked::for_course($courseid, $result['blockedby']);
            if ($event) {
                $event->trigger();
            }
        }
    }

    /**
     * Get the renumber task rows for a course, started or not.
     *
     * @param int $courseid Course ID
     * @return array task_adhoc rows keyed by id, oldest first
     */
    public static function get_task_rows(int $courseid): array {
        global $DB;

        $customdata = json_encode((object) ['courseid' => $courseid]);
        return $DB->get_records_select(
            'task_adhoc',
            'classname = :classname AND component = :component AND ' . $DB->sql_compare_text('customdata') . ' = :customdata',
            [
                'classname' => '\\aiplacement_modgen\\task\\reorder_sections_task',
                'component' => 'aiplacement_modgen',
                'customdata' => $customdata,
            ],
            'id ASC'
        );
    }
}
