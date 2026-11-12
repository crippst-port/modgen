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
 * Data generator for aiplacement_modgen tests.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates the plugin's records for PHPUnit and Behat tests.
 */
class aiplacement_modgen_generator extends component_generator_base {
    /**
     * Create a structure job record.
     *
     * Required: courseid and userid. Everything else has a sensible default.
     *
     * @param array|stdClass $record Job fields (courseid, userid, action, status, parameters, result, ...).
     * @return stdClass The job record.
     */
    public function create_job($record): stdClass {
        global $DB;

        $record = (array) $record;
        foreach (['courseid', 'userid'] as $required) {
            if (empty($record[$required])) {
                throw new coding_exception("The job '$required' is required.");
            }
        }

        $now = time();
        $job = (object) ($record + [
            'action' => 'create_weeks',
            'status' => 'queued',
            'parameters' => json_encode(['weekcount' => 1, 'parentsection' => 0]),
            'result' => null,
            'timecreated' => $now,
            'timestarted' => null,
            'timecompleted' => null,
        ]);
        // Arrays (for example a result with warnings) are stored as JSON.
        foreach (['parameters', 'result'] as $json) {
            if (is_array($job->$json)) {
                $job->$json = json_encode($job->$json);
            }
        }
        // A finished job is complete now unless a time was given. A failed job waiting for a retry
        // is not finished, as when the task records it.
        $willretry = $job->status === 'failed' && !empty(json_decode((string) $job->result, true)['will_retry']);
        if (in_array($job->status, ['completed', 'failed']) && empty($job->timecompleted) && !$willretry) {
            $job->timecompleted = $now;
        }

        $job->id = $DB->insert_record('aiplacement_modgen_jobs', $job);
        return $job;
    }

    /**
     * Add a CSV structure to the template library.
     *
     * @param array|stdClass $record name (required), description, filename and content (the CSV text).
     * @return stdClass The template record.
     */
    public function create_template($record): stdClass {
        global $DB;

        $record = (array) $record;
        if (empty($record['name'])) {
            throw new coding_exception("The template 'name' is required.");
        }
        $file = get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'aiplacement_modgen',
            'filearea' => 'generator_upload',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $record['filename'] ?? 'structure.csv',
        ], $record['content'] ?? "Theme 1,Foundations\nWeek 1,Introduction\n");

        $id = \aiplacement_modgen\local\template_manager::create($record['name'], $record['description'] ?? '', $file);
        $file->delete();
        return $DB->get_record('aiplacement_modgen_templates', ['id' => $id], '*', MUST_EXIST);
    }
}
