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
 * Status of the automatic section renumber for a course.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Works out what the assistant bar icon should say, from the task queue and the standard log.
 * Nothing is stored for this: it reads task_adhoc and logstore_standard_log only.
 */
class section_reorder_status {
    /** @var int Seconds a finished outcome stays on the icon. */
    public const RECENT_WINDOW = 3600;

    /**
     * Whether the current user should see the status icon at all.
     *
     * Site administrators only, and only while the automatic renumber is switched on.
     *
     * @return bool
     */
    public static function can_view(): bool {
        return settings_helper::is_autoreorder_enabled()
            && has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Get the status for a course, shaped as the icon template's context.
     *
     * @param int $courseid Course ID
     * @return array|null Null when there is nothing to show
     */
    public static function get_for_course(int $courseid): ?array {
        $rows = section_reorder_queue::get_task_rows($courseid);

        foreach ($rows as $row) {
            if ($row->timestarted !== null) {
                return self::build('running', null, null);
            }
        }
        if ($rows) {
            $time = min(array_map(fn($row) => (int) $row->nextruntime, $rows));
            return self::build('queued', $time, null);
        }

        foreach (self::get_recent_log_rows($courseid) as $row) {
            // Carries no other data, so it is matched before the decode below.
            if ($row->eventname === '\\aiplacement_modgen\\event\\section_order_checked') {
                return self::build('checked', (int) $row->timecreated, null);
            }
            $other = json_decode($row->other ?? '', true);
            if (!is_array($other)) {
                continue;
            }
            if ($row->eventname === '\\aiplacement_modgen\\event\\section_order_blocked') {
                $url = new \moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $courseid, 'check' => 1]);
                return self::build('blocked', (int) $row->timecreated, $url->out(false));
            }
            if (
                ($other['action'] ?? '') === 'fixsectionorder'
                && (int) ($other['changed'] ?? 0) > 0
            ) {
                return self::build('renumbered', (int) $row->timecreated, null);
            }
        }

        return null;
    }

    /**
     * Read the recent renumber outcomes from the standard log, newest first.
     *
     * @param int $courseid Course ID
     * @return array
     */
    private static function get_recent_log_rows(int $courseid): array {
        global $DB;

        try {
            return $DB->get_records_sql(
                'SELECT id, eventname, other, timecreated
                   FROM {logstore_standard_log}
                  WHERE courseid = :courseid AND eventname IN (:repaired, :blocked, :checked) AND timecreated >= :since
               ORDER BY timecreated DESC, id DESC',
                [
                    'courseid' => $courseid,
                    'repaired' => '\\aiplacement_modgen\\event\\structure_repaired',
                    'blocked' => '\\aiplacement_modgen\\event\\section_order_blocked',
                    'checked' => '\\aiplacement_modgen\\event\\section_order_checked',
                    'since' => time() - self::RECENT_WINDOW,
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build the template context for one state.
     *
     * @param string $state queued, running, renumbered, checked or blocked
     * @param int|null $time When it runs or happened
     * @param string|null $url Where to go for the blocked state
     * @return array
     */
    private static function build(string $state, ?int $time, ?string $url): array {
        $formatted = $time === null ? '' : userdate($time, get_string('strftimetime', 'langconfig'));
        return [
            'state' => $state,
            'time' => $time,
            'label' => get_string('reorderstatus_' . $state, 'aiplacement_modgen', $formatted),
            'url' => $url,
            'isqueued' => $state === 'queued',
            'isrunning' => $state === 'running',
            'isrenumbered' => $state === 'renumbered',
            'isblocked' => $state === 'blocked',
            'ischecked' => $state === 'checked',
            // Both finished states show the same tick; the label says which.
            'isdone' => $state === 'renumbered' || $state === 'checked',
        ];
    }
}
