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
 * Scheduled task that fails jobs whose background task was lost.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\task;

use aiplacement_modgen\local\job_status_helper;

/**
 * Marks running jobs as failed when no ad-hoc task is left to finish them.
 */
class fail_orphaned_jobs_task extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('failorphanedjobs', 'aiplacement_modgen');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        $failed = job_status_helper::fail_orphaned_running_jobs();
        if ($failed) {
            mtrace("Marked {$failed} interrupted job(s) as failed");
        }
    }
}
