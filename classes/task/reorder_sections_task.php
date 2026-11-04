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
 * Ad hoc task that renumbers a Flexible sections course after sections are added or deleted.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\task;

/**
 * Renumbers one course's sections once the course has been quiet for a while.
 */
class reorder_sections_task extends \core\task\adhoc_task {
    /**
     * Build the task for a course. It has no user: it runs as the system.
     *
     * @param int $courseid Course to renumber
     * @return self
     */
    public static function for_course(int $courseid): self {
        $task = new self();
        $task->set_custom_data((object) ['courseid' => $courseid]);
        $task->set_component('aiplacement_modgen');
        return $task;
    }

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskreordersections', 'aiplacement_modgen');
    }

    /**
     * A failed run is not retried; the next section change queues a fresh one.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Filled in by the renumber step.
    }
}
