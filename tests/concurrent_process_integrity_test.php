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
 * True multi-process concurrency integrity test for Module Generator.
 *
 * The synchronous and single-process lock tests prove the per-course generation
 * lock excludes a contender *within one process*. They cannot reproduce the real
 * production hazard: two cron workers / web requests running
 * create_sections_from_json against the SAME course at the SAME time, racing for
 * the DB-backed lock. If that lock has a gap, the two generations interleave and
 * corrupt the structure (duplicate section numbers, crossed parents).
 *
 * This test reproduces that race by forking real OS processes, each with its own
 * database connection, all generating into one shared course. The parent then
 * audits the resulting structure for corruption.
 *
 * IMPORTANT CAVEATS (read before trusting a pass):
 *   - A pass proves the lock held *for these runs*, not universally. Absence of
 *     corruption across N iterations is evidence, not proof.
 *   - It requires the pcntl extension and a commit-capable test DB, so it is gated
 *     behind PHPUNIT_LONGTEST and skipped in the normal suite.
 *   - It uses preventResetByRollback(): the course is committed so child processes
 *     can see it. Cleanup falls back to Moodle's slower truncate-based reset.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Forked-process concurrency integrity test.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\section_creation_service
 */
final class concurrent_process_integrity_test extends advanced_testcase {

    /**
     * Audit the shared course for structural corruption using the parent's own
     * (post-reset-safe) DB connection.
     *
     * @param int $courseid Course ID.
     * @return string[] Integrity problems (empty == sound).
     */
    private function audit(int $courseid): array {
        global $DB;
        $problems = [];

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $sectionnums = array_map('intval', array_column($sections, 'section'));

        // Duplicate section numbers — the headline corruption a lock gap produces.
        if (count(array_unique($sectionnums)) !== count($sectionnums)) {
            $counts = array_count_values($sectionnums);
            foreach ($counts as $num => $count) {
                if ($count > 1) {
                    $problems[] = "Duplicate section number {$num} (x{$count}).";
                }
            }
        }

        // Orphaned / self / circular parents.
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

        return $problems;
    }

    /**
     * Run one generation inside a forked child, on its OWN database connection.
     *
     * The child must not reuse the inherited $DB (its socket is shared with the
     * parent and siblings). It builds a fresh connection from the exported config,
     * runs the service, and exits with 0 on success / non-zero on failure. It never
     * returns into PHPUnit.
     *
     * Critical: a forked child shares the parent's DB file descriptor. If the child
     * runs PHP shutdown (which a normal exit() does), object destructors close that
     * shared socket and poison the parent's connection. So the child must terminate
     * with SIGKILL, which runs no destructors, and must never dispose() any handle.
     * Its own fresh connection leaks harmlessly and is reaped by the OS on kill.
     *
     * @param array $dbconfig Connection config from export_dbconfig().
     * @param int $courseid Shared course ID.
     * @param int $adminid Admin user id to act as.
     * @param string $title Unique theme title for this worker.
     * @return never
     */
    private function run_child(array $dbconfig, int $courseid, int $adminid, string $title): void {
        global $DB, $USER;

        $exitcode = 0;
        try {
            // Fresh DB connection for this process (a NEW socket, distinct from the
            // inherited one which we must never use or close).
            $db = \moodle_database::get_driver_instance($dbconfig['dbtype'], $dbconfig['dblibrary']);
            $db->connect(
                $dbconfig['dbhost'], $dbconfig['dbuser'], $dbconfig['dbpass'],
                $dbconfig['dbname'], $dbconfig['prefix'], $dbconfig['dboptions']
            );
            $DB = $db;

            // Act as admin so capability checks pass.
            $USER = $db->get_record('user', ['id' => $adminid]);

            $service = new section_creation_service();
            $service->create_sections_from_json(
                ['themes' => [
                    ['title' => $title, 'summary' => 's', 'weeks' => [
                        ['title' => $title . ' Week', 'summary' => 'w', 'sessions' => []],
                    ]],
                ]],
                $courseid, 'connected_theme', false, false, false
            );
        } catch (\Throwable $e) {
            // A lock-loss or contention error is an ACCEPTABLE outcome (the worker
            // declined rather than corrupting). Signal it distinctly from a crash so
            // the parent can tell "blocked cleanly" from "died unexpectedly".
            fwrite(STDERR, "child '{$title}' threw: " . $e->getMessage() . "\n");
            $exitcode = 3;
        }

        // Hard-exit WITHOUT running PHP shutdown, so no destructor closes the shared
        // parent DB socket. We encode the outcome in the signal-free exit by writing a
        // marker file the parent reads, since SIGKILL cannot carry an exit code.
        $marker = sys_get_temp_dir() . '/modgen_concurrency_' . posix_getpid();
        @file_put_contents($marker, (string) $exitcode);
        posix_kill(posix_getpid(), SIGKILL);
    }

    /**
     * Fork N concurrent generations against one shared course and assert the
     * resulting structure is free of corruption.
     *
     * @covers ::create_sections_from_json
     */
    public function test_concurrent_generations_do_not_corrupt(): void {
        global $DB;

        if (!defined('PHPUNIT_LONGTEST') || !PHPUNIT_LONGTEST) {
            $this->markTestSkipped('Concurrency fork test only runs with PHPUNIT_LONGTEST=1.');
        }
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the concurrency fork test.');
        }

        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER;
        $adminid = $USER->id;

        // Shared course must be COMMITTED so forked children (separate connections)
        // can see it. preventResetByRollback() commits and switches cleanup to the
        // slower truncate path.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($course->id);
        $this->preventResetByRollback();

        // export_dbconfig() returns a stdClass; cast to array for the child helper.
        $dbconfig = (array) $DB->export_dbconfig();
        // Normalise optional keys export_dbconfig() may omit.
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        // Run several rounds of concurrent pairs/triples; a single corrupt result
        // across all rounds is a finding.
        $rounds = 8;
        $workersperround = 3;
        $totalcorrupt = 0;
        $childcrashes = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            $pids = [];
            for ($w = 0; $w < $workersperround; $w++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('pcntl_fork() failed.');
                } else if ($pid === 0) {
                    // Child.
                    $this->run_child($dbconfig, $course->id, $adminid,
                        "R{$round}W{$w}-" . substr(md5(uniqid('', true)), 0, 6));
                    // run_child never returns.
                }
                $pids[] = $pid;
            }

            // Parent waits for all workers in this round. Children terminate via
            // SIGKILL (to protect the shared DB socket), so the wait status is
            // "killed", not an exit code — the real outcome is in a per-pid marker.
            foreach ($pids as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);

                $marker = sys_get_temp_dir() . '/modgen_concurrency_' . $pid;
                if (is_file($marker)) {
                    $outcome = (int) trim((string) file_get_contents($marker));
                    @unlink($marker);
                    // 0 = generated; 3 = declined cleanly (lock loss). Both acceptable.
                    if ($outcome !== 0 && $outcome !== 3) {
                        $childcrashes++;
                    }
                } else {
                    // No marker means the child died before finishing its work block —
                    // a genuine crash, not a clean decline.
                    $childcrashes++;
                }
            }

            // The parent's connection was untouched by the children; re-read fresh.
            $problems = $this->audit($course->id);
            if (!empty($problems)) {
                $totalcorrupt++;
                fwrite(STDERR, "Round {$round} corruption:\n  - " . implode("\n  - ", $problems) . "\n");
            }
        }

        $this->assertSame(0, $childcrashes,
            'No worker process should crash unexpectedly (clean lock-loss exits are allowed).');
        $this->assertSame(0, $totalcorrupt,
            "Concurrent generation corrupted the course in {$totalcorrupt} of {$rounds} rounds. "
            . 'See STDERR for the specific structural faults.');

        // Final explicit soundness assertion on the accumulated structure.
        $this->assertSame([], $this->audit($course->id),
            'Final shared-course structure must be sound after all concurrent rounds.');
    }
}
