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
 * Ad-hoc task for creating course sections in the background.
 *
 * This task handles large section creation operations that may exceed
 * HTTP timeout limits. Runs via cron without timeout restrictions.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task for background section creation.
 */
class create_sections_task extends \core\task\adhoc_task {

    /**
     * Execute the task.
     *
     * Retrieves job data, creates sections, and updates job status.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $jobid = $data->jobid;

        // Update job status to running.
        $DB->update_record('aiplacement_modgen_jobs', [
            'id' => $jobid,
            'status' => 'running',
            'timestarted' => time()
        ]);

        try {
            require_once(__DIR__ . '/../../classes/local/theme_builder.php');

            $result = null;

            // Execute the appropriate creation method based on action.
            if ($data->action === 'create_themes') {
                $result = \aiplacement_modgen\local\theme_builder::create_themes(
                    $data->courseid,
                    $data->themecount,
                    $data->weeksperTheme,
                    $data->parentsection
                );
            } else if ($data->action === 'create_weeks') {
                $result = \aiplacement_modgen\local\theme_builder::create_weeks(
                    $data->courseid,
                    $data->weekcount,
                    $data->parentsection
                );
            } else if ($data->action === 'create_from_json') {
                // Template upload workflow - decode parameters and create sections.
                require_once(__DIR__ . '/../../classes/local/section_creation_service.php');
                $section_service = new \aiplacement_modgen\local\section_creation_service();
                $creation_result = $section_service->create_sections_from_json(
                    $data->json,
                    $data->courseid,
                    $data->moduletype,
                    $data->generatethemeintroductions ?? false,
                    $data->createsuggestedactivities ?? false,
                    $data->hideexistingsections ?? false
                );
                $result = [
                    'success' => true,
                    'messages' => array_column($creation_result['results'], 'message')
                ];
            } else {
                throw new \moodle_exception('invalidaction', 'aiplacement_modgen');
            }

            // Ensure all course modules have contexts (subsections create course modules)
            // Only check if we actually created sections with modules
            if (!empty($result['success'])) {
                $sql = "SELECT cm.id
                        FROM {course_modules} cm
                        LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                        WHERE cm.course = :courseid AND ctx.id IS NULL";
                $orphaned = $DB->get_records_sql($sql, [
                    'courseid' => $data->courseid,
                    'contextlevel' => CONTEXT_MODULE
                ]);
                
                if (!empty($orphaned)) {
                    foreach ($orphaned as $cm) {
                        \context_coursemodule::instance($cm->id);
                    }
                }
            }

            // Update job status to completed with results.
            $DB->update_record('aiplacement_modgen_jobs', [
                'id' => $jobid,
                'status' => 'completed',
                'result' => json_encode([
                    'success' => true,
                    'messages' => $result['messages'] ?? []
                ]),
                'timecompleted' => time()
            ]);

        } catch (\Exception $e) {
            // Update job status to failed with error message.
            $DB->update_record('aiplacement_modgen_jobs', [
                'id' => $jobid,
                'status' => 'failed',
                'result' => json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]),
                'timecompleted' => time()
            ]);

            // Log the error for debugging.
            debugging('Section creation task failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
