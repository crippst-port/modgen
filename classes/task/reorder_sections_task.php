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

use aiplacement_modgen\event\section_renumber_failed;
use aiplacement_modgen\local\integrity_checker;
use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\settings_helper;

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
     *
     * Waits for the course to go quiet, renumbers it if it is out of order, and logs the outcome.
     * Switching the setting off makes a task that was queued earlier do nothing.
     */
    public function execute() {
        global $DB;

        if (!settings_helper::is_autoreorder_enabled()) {
            return;
        }

        $courseid = (int) ($this->get_custom_data()->courseid ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid], 'id, format, cacherev');
        if (!$course || $course->format !== 'flexsections') {
            return;
        }

        $startrev = (int) $course->cacherev;
        if ($this->edited_recently($courseid, $startrev)) {
            section_reorder_queue::schedule($courseid);
            return;
        }

        try {
            $result = $this->run_fix($courseid, $this->beforewrite_guard($courseid, $startrev));
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'erroracquiringlock') {
                section_reorder_queue::schedule($courseid);
                return;
            }
            $this->log_failure($courseid, $e);
            return;
        } catch (\Throwable $e) {
            $this->log_failure($courseid, $e);
            return;
        }

        if ($result['deferred']) {
            section_reorder_queue::schedule($courseid);
        } else {
            section_reorder_queue::record_outcome($courseid, $result, 'adhoctask');
        }
    }

    /**
     * Whether someone has edited the course within the configured renumber delay.
     *
     * Read from the standard log, which has the real time of every edit. cacherev cannot be used as a
     * clock: once it is ahead of the time, each cache purge adds a second to it, so a busy editing
     * session can leave it many minutes in the future and the renumber would wait all that time. It is
     * only the fallback for a site without the standard log.
     *
     * @param int $courseid Course ID
     * @param int $cacherev The course's cacherev
     * @return bool
     */
    protected function edited_recently(int $courseid, int $cacherev): bool {
        global $DB;

        $since = time() - section_reorder_queue::delay();
        $stores = explode(',', (string) get_config('tool_log', 'enabled_stores'));
        if (!in_array('logstore_standard', $stores, true)) {
            return $cacherev > $since;
        }

        // Any create, update or delete in the course, by anyone.
        return $DB->record_exists_select(
            'logstore_standard_log',
            'courseid = :courseid AND timecreated > :since AND crud <> :read',
            ['courseid' => $courseid, 'since' => $since, 'read' => 'r']
        );
    }

    /**
     * Build the last-chance check: true while the course cache has not been rebuilt since we looked.
     *
     * @param int $courseid Course ID
     * @param int $startrev The cacherev the quiet-period check saw
     * @return callable
     */
    protected function beforewrite_guard(int $courseid, int $startrev): callable {
        return function () use ($courseid, $startrev): bool {
            global $DB;
            return (int) $DB->get_field('course', 'cacherev', ['id' => $courseid]) === $startrev;
        };
    }

    /**
     * Run the renumber. A seam so tests can simulate a lock failure.
     *
     * @param int $courseid Course ID
     * @param callable $beforewrite Guard passed to the renumber
     * @return array Result of integrity_checker::fix_section_order()
     */
    protected function run_fix(int $courseid, callable $beforewrite): array {
        return integrity_checker::fix_section_order($courseid, false, $beforewrite);
    }

    /**
     * Log a failed run. Never throws.
     *
     * @param int $courseid Course ID
     * @param \Throwable $e What went wrong
     */
    private function log_failure(int $courseid, \Throwable $e): void {
        try {
            $event = section_renumber_failed::build($courseid, $e);
            if ($event) {
                $event->trigger();
            }
        } catch (\Throwable $ignored) {
            debugging('Could not log the failed section renumber: ' . $ignored->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
