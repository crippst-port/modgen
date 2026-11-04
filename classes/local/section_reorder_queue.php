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
