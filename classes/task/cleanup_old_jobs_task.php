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
 * Scheduled task to clean up old completed/failed jobs.
 *
 * @package     aiplacement_modgen
 * @category    task
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup task for old job records.
 *
 * Deletes job records older than 30 days to prevent database bloat.
 */
class cleanup_old_jobs_task extends \core\task\scheduled_task
{

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name()
    {
        return get_string('cleanupoldjobs', 'aiplacement_modgen');
    }

    /**
     * Execute the task - delete jobs older than 30 days.
     */
    public function execute()
    {
        global $DB;

        $retentiondays = 30;
        $cutofftime = time() - ($retentiondays * DAYSECS);

        // Delete completed/failed jobs older than cutoff.
        // Keep running/queued jobs regardless of age (they might be stuck).
        $deleted = $DB->delete_records_select(
            'aiplacement_modgen_jobs',
            'timecompleted < :cutoff AND timecompleted IS NOT NULL',
            ['cutoff' => $cutofftime]
        );

        if ($deleted) {
            mtrace("Deleted {$deleted} old job record(s) from aiplacement_modgen_jobs table");
        } else {
            mtrace("No old job records to delete");
        }
    }
}
