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
 * Assertions and builders shared by the structure tests.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/structure_snapshot.php');

/**
 * For advanced_testcase classes that build course structure and check what is stored.
 */
trait aiplacement_modgen_structure_assertions {
    /**
     * A course already in the Flexible sections format.
     *
     * @return stdClass
     */
    protected function flexsections_course(): stdClass {
        return $this->getDataGenerator()->create_course(['format' => 'flexsections', 'numsections' => 0]);
    }

    /**
     * Switch the placement and its Quick Add and file routes on: queued jobs recheck them when they run.
     */
    protected function enable_placement(): void {
        set_config('enabled', 1, 'aiplacement_modgen');
        set_config('enable_quickadd', 1, 'aiplacement_modgen');
        set_config('enable_structurefromfile', 1, 'aiplacement_modgen');
        \aiplacement_modgen\local\settings_helper::clear_cache();
    }

    /**
     * Run the queued background jobs, hiding the task output.
     */
    protected function run_jobs(): void {
        ob_start();
        try {
            $this->runAdhocTasks();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Assert the stored structure equals the expected one, with a readable difference on failure.
     *
     * @param int $courseid Course id.
     * @param array[] $expected Expected rows (see aiplacement_modgen_structure_snapshot::diff()).
     * @param string $message Context for the failure message.
     */
    protected function assert_structure(int $courseid, array $expected, string $message = ''): void {
        $this->assertSame(
            [],
            aiplacement_modgen_structure_snapshot::diff($courseid, $expected),
            $message ?: 'The stored structure differs from the expected one.'
        );
    }

    /**
     * Assert the course satisfies every Flexible sections rule, and the plugin's and Moodle's own checks.
     *
     * @param int $courseid Course id.
     */
    protected function assert_sound(int $courseid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->assertSame([], aiplacement_modgen_structure_snapshot::problems($courseid), 'Structural rules are broken.');
        $check = \aiplacement_modgen\local\integrity_checker::check($courseid);
        $this->assertFalse($check['has_issues'], "The plugin's integrity checker reports issues: " . json_encode($check['issues']));
        $this->assertSame(
            [],
            course_integrity_check($courseid, null, null, true, true),
            "Moodle's course integrity check reports problems."
        );
    }

    /**
     * Put a file in the current user's draft area, as the file manager would.
     *
     * @param string $filename File name.
     * @param string $content File content.
     * @return array With 'itemid' (the draft item id) and 'file' (the stored file).
     */
    protected function draft_file(string $filename, string $content): array {
        global $USER;

        $itemid = file_get_unused_draft_itemid();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return ['itemid' => $itemid, 'file' => $file];
    }

    /**
     * Build a structure the way the approve step does: clean it, queue the job and run it.
     *
     * @param int $courseid Course id.
     * @param array $json Structure (as the generator returns it).
     * @param string $moduletype connected_theme or connected_weekly.
     * @param bool $activities Whether to create the section summary activities.
     * @return stdClass The job record after it has run.
     */
    protected function build_structure(int $courseid, array $json, string $moduletype, bool $activities = false): stdClass {
        global $DB, $USER;

        $jobid = \aiplacement_modgen\local\job_status_helper::queue_creation_job($courseid, $USER->id, 'create_from_json', [
            'json' => \aiplacement_modgen\local\json_sanitiser::clean($json),
            'moduletype' => $moduletype,
            'createsummaryactivities' => $activities,
        ]);
        $this->run_jobs();
        return $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
    }

    /**
     * Row for the Assessments section that Quick Add's Assessments entry adds at the top level.
     *
     * @return array
     */
    protected function assessments_row(): array {
        return [
            'name' => get_string('assessmentssectionname', 'aiplacement_modgen'),
            'parent' => '-',
            'collapsed' => '0',
            'activities' => 0,
        ];
    }

    /**
     * Rows for a week and its three session sections.
     *
     * @param string $name Week name.
     * @param string $parent Parent section name, or '-' for top level.
     * @param int $activities Learning activities in each of the four sections (1 or 0).
     * @param string $summary Text the week summary must contain.
     * @return array[]
     */
    protected function week_rows(string $name, string $parent, int $activities, string $summary = ''): array {
        $depth = $parent === '-' ? 1 : 2;
        $rows = [[
            'name' => $name, 'parent' => $parent, 'collapsed' => '1', 'activities' => $activities,
            'depth' => $depth, 'summary' => $summary,
            'activitynames' => $activities ? [$name] : [],
        ]];
        foreach (['presession', 'session', 'postsession'] as $session) {
            $label = get_string($session, 'aiplacement_modgen');
            $rows[] = [
                'name' => $label, 'parent' => $name, 'collapsed' => '0',
                'activities' => $activities, 'depth' => $depth + 1,
                'activitynames' => $activities ? [get_string('sessionactivityname', 'aiplacement_modgen', $label)] : [],
            ];
        }
        return $rows;
    }

    /**
     * Rows for a top-level theme followed by its weeks (each with sessions).
     *
     * @param string $name Theme name.
     * @param string[] $weeks Week names.
     * @param int $activities Learning activities in each week and session (1 or 0).
     * @param string $themesummary Text the theme summary must contain.
     * @param string $weeksummary Text each week summary must contain.
     * @return array[]
     */
    protected function theme_rows(
        string $name,
        array $weeks,
        int $activities,
        string $themesummary = '',
        string $weeksummary = ''
    ): array {
        $rows = [[
            'name' => $name, 'parent' => '-', 'collapsed' => '1', 'activities' => 0, 'depth' => 1, 'summary' => $themesummary,
        ]];
        foreach ($weeks as $week) {
            $rows = array_merge($rows, $this->week_rows($week, $name, $activities, $weeksummary));
        }
        return $rows;
    }
}
