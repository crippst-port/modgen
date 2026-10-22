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
 * A structure creation job was queued.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\event;

/**
 * A structure creation job was queued.
 */
class structure_job_queued extends \core\event\base {
    /**
     * Create the event from a job record.
     *
     * @param \stdClass $job Job record (id, courseid, userid, action).
     * @param array $other Extra event data.
     * @return self
     */
    public static function from_job(\stdClass $job, array $other = []): self {
        return self::create([
            'context' => \context_course::instance($job->courseid),
            'objectid' => $job->id,
            'relateduserid' => $job->userid,
            'other' => ['action' => $job->action] + $other,
        ]);
    }

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'aiplacement_modgen_jobs';
    }

    /**
     * Get the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventstructure_job_queued', 'aiplacement_modgen');
    }

    /**
     * Get a description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' queued the '{$this->other['action']}' structure job with id " .
            "'{$this->objectid}' in the course with id '{$this->courseid}'.";
    }

    /**
     * Get the job status page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/ai/placement/modgen/job_status.php', ['jobid' => $this->objectid]);
    }

    /**
     * Check the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['action'])) {
            throw new \coding_exception('The \'action\' value must be set in other.');
        }
    }

    /**
     * The job table is not backed up, so the object id cannot be mapped on restore.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }

    /**
     * Other data holds no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
