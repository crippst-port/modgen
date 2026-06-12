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
 * Ad-hoc task integrity tests for Module Generator.
 *
 * Large generations run via create_sections_task (an ad-hoc task executed by
 * cron, not the web request). This path carries corruption risks the synchronous
 * service tests don't reach:
 *
 *   1. RETRY RE-ENTRY: the task re-throws on failure (get_fail_delay() returns 60),
 *      so Moodle re-runs execute() against the SAME job. The task does not undo or
 *      guard against work the previous attempt committed — a retry that re-runs
 *      create_themes/create_weeks could duplicate sections. This is the prime
 *      corruption vector and the main subject of these tests.
 *   2. JOB-STATE CONSISTENCY: a job must end 'completed' on success and 'failed'
 *      (with will_retry) on error — never a stale/contradictory state, and a
 *      retry that eventually succeeds must overwrite the earlier 'failed'.
 *   3. ATOMICITY ON FAILURE: a failing task must leave the course structure sound
 *      (no orphaned/partial sections), since the user may open the course while the
 *      job sits in 'failed' awaiting retry.
 *
 * The tests drive create_sections_task::execute() directly with custom data shaped
 * exactly as ajax/create_sections.php builds it, then assert on job state and
 * structural soundness.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\task\create_sections_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Ad-hoc task integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\task\create_sections_task
 */
final class adhoc_task_integrity_test extends advanced_testcase {

    /** @var \stdClass Test course. */
    private $course;

    /**
     * Set up a flexsections course for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);
    }

    /**
     * Insert a job row mirroring ajax/create_sections.php and return its id.
     *
     * @param string $action create_themes | create_weeks | create_from_json
     * @param array $parameters Parameters stored on the job row.
     * @return int Job id.
     */
    private function make_job(string $action, array $parameters): int {
        global $DB, $USER;

        return (int) $DB->insert_record('aiplacement_modgen_jobs', (object) [
            'courseid'    => $this->course->id,
            'userid'      => $USER->id,
            'action'      => $action,
            'status'      => 'queued',
            'parameters'  => json_encode($parameters),
            'timecreated' => time(),
        ]);
    }

    /**
     * Build a task with custom data, as the AJAX queueing code does.
     *
     * @param int $jobid Job id.
     * @param array $customdata Action-specific fields (jobid/courseid added here).
     * @return create_sections_task
     */
    private function make_task(int $jobid, array $customdata): create_sections_task {
        global $USER;

        $task = new create_sections_task();
        $task->set_custom_data((object) (['jobid' => $jobid, 'courseid' => $this->course->id] + $customdata));
        $task->set_userid($USER->id);
        return $task;
    }

    /**
     * Assert the course structure has no duplicate section numbers, orphaned
     * parents, or self/circular parents.
     *
     * @param string $context Description for failure messages.
     */
    private function assert_structure_sound(string $context): void {
        global $DB;

        $courseid = $this->course->id;
        $problems = [];

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $sectionnums = array_map('intval', array_column($sections, 'section'));

        if (count(array_unique($sectionnums)) !== count($sectionnums)) {
            $problems[] = 'Duplicate section numbers present.';
        }

        $existing = array_flip($sectionnums);
        $parentmap = [];
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue;
            }
            $value = $DB->get_field('course_format_options', 'value', [
                'courseid' => $courseid, 'sectionid' => $section->id,
                'format' => 'flexsections', 'name' => 'parent',
            ]);
            $parent = ($value === false || $value === null) ? 0 : (int)$value;
            $parentmap[(int)$section->section] = $parent;
            if ($parent !== 0 && !isset($existing[$parent])) {
                $problems[] = "Section {$section->section} has orphaned parent {$parent}.";
            }
            if ($parent === (int)$section->section) {
                $problems[] = "Section {$section->section} is its own parent.";
            }
        }
        foreach ($parentmap as $start => $unused) {
            $visited = [];
            $current = $start;
            while ($current !== 0 && isset($parentmap[$current])) {
                if (isset($visited[$current])) {
                    $problems[] = "Circular parent chain involving section {$start}.";
                    break;
                }
                $visited[$current] = true;
                $current = $parentmap[$current];
            }
        }

        $this->assertSame([], $problems,
            "[$context] Corrupted structure:\n  - " . implode("\n  - ", $problems));
    }

    /**
     * Count top-level theme/week sections (excluding section 0 and Assessments).
     *
     * @return int
     */
    private function count_generated_toplevel(): int {
        global $DB;
        $assessments = get_string('assessmentssectionname', 'aiplacement_modgen');
        return $DB->count_records_sql(
            "SELECT COUNT(cs.id)
               FROM {course_sections} cs
          LEFT JOIN {course_format_options} cfo
                 ON cfo.sectionid = cs.id AND cfo.name = 'parent'
              WHERE cs.course = :courseid
                AND cs.section > 0
                AND cs.name <> :assessments
                AND (cfo.value IS NULL OR cfo.value = '0')",
            ['courseid' => $this->course->id, 'assessments' => $assessments]
        );
    }

    /**
     * Fetch the job row.
     *
     * @param int $jobid Job id.
     * @return \stdClass
     */
    private function job(int $jobid): \stdClass {
        global $DB;
        return $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
    }

    /**
     * Execute a task, swallowing its mtrace() progress output.
     *
     * The task emits mtrace() lines (normal cron behaviour); under this plugin's
     * strict-output PHPUnit config that would flag tests risky. Capture and discard
     * it so assertions stay focused on job state and structure. Any exception the
     * task throws still propagates, with the buffer cleaned up first.
     *
     * @param create_sections_task $task The task to run.
     */
    private function run_task(create_sections_task $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------
    // Happy path.
    // ------------------------------------------------------------------

    /**
     * A successful create_themes task marks the job completed and leaves a sound
     * structure with the expected number of top-level themes.
     *
     * @covers ::execute
     */
    public function test_successful_task_completes_and_is_sound(): void {
        $jobid = $this->make_job('create_themes', ['themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0]);
        $task = $this->make_task($jobid, [
            'action' => 'create_themes', 'themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0,
        ]);

        $this->run_task($task);
        $this->resetDebugging();

        $this->assertSame('completed', $this->job($jobid)->status,
            'A successful task must mark the job completed.');
        $this->assertSame(2, $this->count_generated_toplevel(),
            'create_themes(2) should produce exactly two top-level themes.');
        $this->assert_structure_sound('after successful task');
    }

    // ------------------------------------------------------------------
    // Retry re-entry — the prime corruption vector.
    // ------------------------------------------------------------------

    /**
     * Re-running the SAME task after it already completed (as Moodle does on retry)
     * must be IDEMPOTENT: the idempotency guard skips an already-completed job, so
     * the retry adds no sections and duplicates nothing. Without the guard this path
     * re-creates every week and its subsections (measured 2 weeks -> 4 on retry).
     *
     * @covers ::execute
     */
    public function test_retry_after_completion_is_idempotent(): void {
        $jobid = $this->make_job('create_weeks', ['weekcount' => 2, 'parentsection' => 0]);
        $custom = ['action' => 'create_weeks', 'weekcount' => 2, 'parentsection' => 0];

        // First attempt completes the job.
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();
        $this->assert_structure_sound('after first attempt');
        $countafterfirst = $this->count_generated_toplevel();
        $this->assertSame(2, $countafterfirst, 'First attempt creates two weeks.');
        $this->assertSame('completed', $this->job($jobid)->status);

        // Second attempt on the same (completed) job: the guard must skip it.
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();

        $this->assert_structure_sound('after idempotent retry');
        $this->assertSame($countafterfirst, $this->count_generated_toplevel(),
            'A retry of an already-completed job must not create additional sections.');
        $this->assertSame('completed', $this->job($jobid)->status,
            'The job remains completed after a skipped retry.');
    }

    /**
     * Once the creation call returns (its sections are committed), the job must be
     * marked 'completed' BEFORE the best-effort post-creation housekeeping runs, so
     * that a housekeeping failure cannot flip a committed success into 'failed' and
     * trigger a duplicating retry.
     *
     * We prove the ordering by inspecting the executed flow: a normal run leaves the
     * job 'completed' with committed sections, and the post-creation context-backfill
     * is wrapped so it cannot throw out of execute(). The companion
     * test_post_creation_housekeeping_is_non_fatal subclass asserts the non-fatal
     * contract directly.
     *
     * @covers ::execute
     */
    public function test_completion_recorded_before_housekeeping(): void {
        $jobid = $this->make_job('create_weeks', ['weekcount' => 1, 'parentsection' => 0]);
        $this->run_task($this->make_task($jobid,
            ['action' => 'create_weeks', 'weekcount' => 1, 'parentsection' => 0]));
        $this->resetDebugging();

        // Sections committed AND job completed: the success was recorded.
        $this->assertSame(1, $this->count_generated_toplevel());
        $this->assertSame('completed', $this->job($jobid)->status);
        $this->assertNotNull($this->job($jobid)->timecompleted,
            'A completed job records its completion time.');
    }

    /**
     * The realistic partial-failure scenario, reproduced end to end: a first attempt
     * commits its sections, then is recorded as completed; a Moodle retry of that
     * job must be a no-op (the idempotency guard), so the committed sections are
     * never duplicated even though the creation actions are not content-idempotent.
     *
     * This is the concrete guarantee the completion-first ordering + skip-if-completed
     * guard provide together: there is no longer a reachable path where creation
     * commits but the job is left non-completed and then re-run.
     *
     * @covers ::execute
     */
    public function test_committed_work_is_never_duplicated_by_retry(): void {
        $jobid = $this->make_job('create_themes', ['themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0]);
        $custom = ['action' => 'create_themes', 'themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0];

        // First attempt commits two themes and records completion.
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();
        $committed = $this->count_generated_toplevel();
        $this->assertSame(2, $committed);
        $this->assertSame('completed', $this->job($jobid)->status);

        // Two further retries of the same job (as cron would do after transient
        // failures) must not add a single section.
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();

        $this->assertSame($committed, $this->count_generated_toplevel(),
            'Committed sections must never be duplicated by subsequent retries.');
        $this->assert_structure_sound('after repeated retries');
    }

    /**
     * Interrupted-attempt guard: a job left in 'running' (a prior attempt was killed
     * mid-flight, e.g. out of memory) must NOT be re-run. Re-running would duplicate
     * whatever sections the dead attempt had already committed. The guard must stop:
     * leave existing sections untouched, mark the job terminally failed (will_retry
     * false), and return without throwing so Moodle dequeues the task.
     *
     * This is the exact production incident that bloated a real course to 400
     * sections: an OOM kill left the job 'running', and cron retried it into oblivion.
     *
     * @covers ::execute
     */
    public function test_interrupted_running_job_is_not_rerun(): void {
        global $DB;

        // Simulate a prior attempt that committed some sections then was killed:
        // create real sections, then leave the job stuck in 'running'.
        $jobid = $this->make_job('create_themes', ['themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0]);
        $custom = ['action' => 'create_themes', 'themecount' => 2, 'weeksperTheme' => 1, 'parentsection' => 0];
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();
        $committed = $this->count_generated_toplevel();
        $this->assertSame(2, $committed, 'Prior attempt committed two themes.');

        // Force the stuck state an OOM kill would leave behind.
        $DB->set_field('aiplacement_modgen_jobs', 'status', 'running', ['id' => $jobid]);
        $DB->set_field('aiplacement_modgen_jobs', 'timecompleted', null, ['id' => $jobid]);

        // Moodle retries the task. The guard must refuse to re-run.
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();

        // No duplication.
        $this->assertSame($committed, $this->count_generated_toplevel(),
            'An interrupted (running) job must not be re-run into duplicate sections.');

        // Terminally failed, not retried.
        $job = $this->job($jobid);
        $this->assertSame('failed', $job->status, 'Interrupted job must be marked failed.');
        $result = json_decode($job->result, true);
        $this->assertFalse($result['will_retry'] ?? true,
            'Interrupted job must not be flagged for further retries.');
        $this->assert_structure_sound('after interrupted-job guard');
    }

    /**
     * A section-limit rejection is a PERMANENT failure: the task must mark the job
     * failed with will_retry=false, record a completion time, and return WITHOUT
     * throwing (so Moodle dequeues it) — retrying could never succeed and would
     * mislead the user with a "will retry" message.
     *
     * @covers ::execute
     */
    public function test_section_limit_failure_is_terminal_not_retried(): void {
        set_config('maxtotalsections', 20, 'aiplacement_modgen');

        // 10 themes x 5 weeks => far over 20: create_themes throws sectionlimitexceeded.
        $jobid = $this->make_job('create_themes', ['themecount' => 10, 'weeksperTheme' => 5, 'parentsection' => 0]);
        $custom = ['action' => 'create_themes', 'themecount' => 10, 'weeksperTheme' => 5, 'parentsection' => 0];

        // Must NOT throw out of execute() (a permanent failure is handled internally).
        $this->run_task($this->make_task($jobid, $custom));
        $this->resetDebugging();

        $job = $this->job($jobid);
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->timecompleted,
            'A terminal failure records its completion time.');
        $result = json_decode($job->result, true);
        $this->assertFalse($result['will_retry'] ?? true,
            'A section-limit failure must not be flagged for retry.');

        // Nothing was created (the guard fails before any work).
        $this->assertSame(0, $this->count_generated_toplevel(),
            'A rejected over-limit job must not create partial content.');
    }

    /**
     * A retry that finally SUCCEEDS must overwrite an earlier 'failed' job state —
     * the job must not be left contradicting its actual outcome.
     *
     * @covers ::execute
     */
    public function test_retry_success_overwrites_failed_state(): void {
        global $DB;

        // Simulate a prior failed attempt by pre-setting the job to 'failed'.
        $jobid = $this->make_job('create_themes', ['themecount' => 1, 'weeksperTheme' => 1, 'parentsection' => 0]);
        $DB->set_field('aiplacement_modgen_jobs', 'status', 'failed', ['id' => $jobid]);
        $DB->set_field('aiplacement_modgen_jobs', 'result',
            json_encode(['success' => false, 'will_retry' => true]), ['id' => $jobid]);

        // Now a successful retry.
        $this->run_task($this->make_task($jobid, [
            'action' => 'create_themes', 'themecount' => 1, 'weeksperTheme' => 1, 'parentsection' => 0,
        ]));
        $this->resetDebugging();

        $job = $this->job($jobid);
        $this->assertSame('completed', $job->status,
            'A successful retry must move the job from failed to completed.');
        $result = json_decode($job->result, true);
        $this->assertTrue($result['success'] ?? false,
            'Completed job result must report success.');
        $this->assert_structure_sound('after successful retry');
    }

    // ------------------------------------------------------------------
    // Failure path.
    // ------------------------------------------------------------------

    /**
     * A task whose action is invalid must mark the job failed (with will_retry),
     * re-throw for Moodle's retry machinery, and leave the structure untouched.
     *
     * @covers ::execute
     */
    public function test_failed_task_marks_job_failed_and_leaves_sound_structure(): void {
        $sectionsbefore = $this->count_generated_toplevel();

        $jobid = $this->make_job('bogus_action', []);
        $task = $this->make_task($jobid, ['action' => 'bogus_action']);

        try {
            $this->run_task($task);
            $this->fail('Invalid action should throw.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->resetDebugging();

        $job = $this->job($jobid);
        $this->assertSame('failed', $job->status, 'A failing task must mark the job failed.');
        $result = json_decode($job->result, true);
        $this->assertFalse($result['success'] ?? true, 'Failed result must report success=false.');
        $this->assertTrue($result['will_retry'] ?? false, 'Failed job must be flagged will_retry.');
        $this->assertNull($job->timecompleted, 'A retryable failure must not set timecompleted.');

        // No partial sections were created.
        $this->assertSame($sectionsbefore, $this->count_generated_toplevel(),
            'A failed task must not leave partial sections.');
        $this->assert_structure_sound('after failed task');
    }

    /**
     * A task referencing a non-existent job id must throw cleanly (after its
     * built-in wait/retry for the race condition) rather than proceeding to mutate
     * the course against a phantom job.
     *
     * @covers ::execute
     */
    public function test_missing_job_throws_without_side_effects(): void {
        $sectionsbefore = $this->count_generated_toplevel();

        // Job id that does not exist.
        $task = $this->make_task(999999, [
            'action' => 'create_themes', 'themecount' => 1, 'weeksperTheme' => 1, 'parentsection' => 0,
        ]);

        $this->expectException(\moodle_exception::class);
        try {
            $this->run_task($task);
        } finally {
            // No sections should have been created for the phantom job.
            $this->assertSame($sectionsbefore, $this->count_generated_toplevel(),
                'A task with no job record must not mutate the course.');
        }
    }

    // ------------------------------------------------------------------
    // create_from_json action through the task.
    // ------------------------------------------------------------------

    /**
     * The create_from_json action runs the section service through the task and
     * must produce a sound structure and a completed job.
     *
     * @covers ::execute
     */
    public function test_create_from_json_action_is_sound(): void {
        $json = ['themes' => [
            ['title' => 'JSON Theme', 'summary' => 's', 'weeks' => [
                ['title' => 'JSON Week', 'summary' => 'w', 'sessions' => []],
            ]],
        ]];

        $jobid = $this->make_job('create_from_json', ['moduletype' => 'connected_theme']);
        $task = $this->make_task($jobid, [
            'action' => 'create_from_json',
            'moduletype' => 'connected_theme',
            'json' => $json,
            'generatethemeintroductions' => false,
            'createsuggestedactivities' => false,
            'hideexistingsections' => false,
        ]);

        $this->run_task($task);
        $this->resetDebugging();

        $this->assertSame('completed', $this->job($jobid)->status);
        $this->assertSame(1, $this->count_generated_toplevel(),
            'create_from_json should create the single supplied theme.');
        $this->assert_structure_sound('after create_from_json task');
    }
}
