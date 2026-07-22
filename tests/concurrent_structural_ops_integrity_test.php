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
 * Multi-process concurrency tests for flexsections STRUCTURAL operations.
 *
 * Motivation: a user corrupted a live flexsections course by working in several
 * browser tabs at once — deleting a section in one tab while doing manual
 * copy/paste/rename (create + move) in others, letting the slow deletions run in
 * the background — and ended up with recursive / stale parent pointers.
 *
 * WHAT THESE TESTS ESTABLISH (and, just as importantly, what they DON'T):
 *
 *   1. The application-level lock is INCOMPLETE. format_flexsections has exactly
 *      one lock (upstream issue #82), and it wraps delete_section_with_children()
 *      only. move_section() / create_new_section() take no lock. So delete+delete
 *      is serialized, but delete+move and move+move are not.
 *        -> test_move_section_ignores_delete_lock proves move mutates the course
 *           while the delete lock is HELD.
 *        -> test_delete_section_respects_delete_lock proves a delete DECLINES
 *           (locktimeout) while the lock is held, and its target survives.
 *
 *   2. The DATABASE is only a PARTIAL backstop. move_section() renumbers inside one
 *      transaction and course_sections has a UNIQUE(course, section) index, so two
 *      SIMULTANEOUS structural transactions on overlapping rows deadlock and Postgres
 *      aborts one cleanly (test_delete_racing_move_is_db_protected shows this). But
 *      the UNIQUE index guards section NUMBERS only — the `parent` value in
 *      course_format_options has no constraint. If operation A commits BEFORE
 *      operation B's transaction writes (B read its snapshot early and wrote late),
 *      there is no deadlock and B's stale write lands — a lost update on parent
 *      pointers. test_stale_snapshot_write_escapes_db_backstop reproduces exactly
 *      that, deterministically, and commits an orphaned parent.
 *
 * CONCLUSION for the report: the lock gap is real AND the DB does not fully backstop
 * it. The corruption is a stale-snapshot (read-early / write-late) lost update on the
 * unconstrained `parent` field — rare because most interleavings either revert
 * cleanly or hit the UNIQUE index, which is why "delete a section per tab while
 * editing" (slow deletes widening the window) was needed to hit it.
 *
 *   3. DUPLICATE is a sharper real-world vector than plain move. Unlike
 *      moveup/movedown/movesection, flexsections' section menu does NOT strip
 *      "Duplicate" (course/format/classes/output/local/content/section/controlmenu.php),
 *      so it is a real, everyday, always-visible button. duplicate_section()
 *      (lib.php) captures its snapshot ($oldsectioninfo->parent, $createbefore) ONCE
 *      at the top, then does a sequence of unlocked section/module creation calls,
 *      and only at the very end calls move_section() using that stale-by-then
 *      snapshot. test_duplicate_section_orphans_parent_when_interleaved_with_delete
 *      proves this deterministically WITHOUT forking: it pauses the real (unmodified)
 *      duplicate_section_properties() at the exact point another request's commit
 *      would land, runs a real delete_section_with_children() inline (simulating that
 *      other request), resumes, and the new duplicate commits with an orphaned
 *      parent. Because this needs no process concurrency (the staleness is baked in
 *      by the SINGLE method's own internal ordering), it runs FAST and UNGATED — no
 *      PHPUNIT_LONGTEST, no pcntl.
 *
 * RUNNING:
 *   - Tests 1–5 (the racing/locking ones) are gated behind PHPUNIT_LONGTEST and
 *     pcntl; skipped in the normal suite.
 *   - Test 6 (the duplicate one) requires neither and runs in the normal suite.
 *   - On macOS, run with OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES to avoid the
 *     Objective-C runtime's fork() crash in child processes (tests 1–5 only).
 *   - Uses preventResetByRollback() so forked children (own DB connections) can see
 *     the committed course; cleanup falls back to Moodle's truncate-based reset.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use course_modinfo;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Forked-process concurrency tests for flexsections structural-op locking.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class concurrent_structural_ops_integrity_test extends advanced_testcase {
    /** @var string The lock factory type format_flexsections uses for delete. */
    private const LOCK_TYPE = 'format_flexsections_delete_section';

    /** @var string The lock resource key format_flexsections uses for delete. */
    private const LOCK_KEY = 'course_modification_lock';

    /**
     * Skip unless the long-test + pcntl prerequisites are met.
     */
    private function require_fork_environment(): void {
        if (!defined('PHPUNIT_LONGTEST') || !PHPUNIT_LONGTEST) {
            $this->markTestSkipped('Concurrency fork test only runs with PHPUNIT_LONGTEST=1.');
        }
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the concurrency fork test.');
        }
    }

    /**
     * Rebuild a fresh per-process DB connection and act as admin.
     *
     * A forked child must not reuse the inherited $DB (its socket is shared with the
     * parent); it builds a NEW connection from the exported config.
     *
     * @param array $dbconfig Connection config from export_dbconfig().
     * @param int $adminid Admin user id to act as.
     */
    private function reconnect(array $dbconfig, int $adminid): void {
        global $DB, $USER;
        $db = \moodle_database::get_driver_instance($dbconfig['dbtype'], $dbconfig['dblibrary']);
        $db->connect(
            $dbconfig['dbhost'],
            $dbconfig['dbuser'],
            $dbconfig['dbpass'],
            $dbconfig['dbname'],
            $dbconfig['prefix'],
            $dbconfig['dboptions']
        );
        $DB = $db;
        $USER = $db->get_record('user', ['id' => $adminid]);
    }

    /**
     * Terminate a child WITHOUT PHP shutdown, recording its outcome in a marker.
     *
     * A normal exit() runs destructors that close the shared parent DB socket;
     * SIGKILL runs none. SIGKILL carries no exit code, so the outcome
     * (0 = did its work, 3 = declined cleanly via a caught contention error) is
     * written to a per-pid marker the parent reads. Missing marker == real crash.
     *
     * @param int $exitcode Outcome code.
     * @return never
     */
    private function terminate(int $exitcode): void {
        $marker = sys_get_temp_dir() . '/modgen_structops_' . posix_getpid();
        @file_put_contents($marker, (string) $exitcode);
        posix_kill(posix_getpid(), SIGKILL);
    }

    /**
     * Child: delete one section (with children) via the flexsections format.
     *
     * @param array $dbconfig Connection config.
     * @param int $courseid Shared course id.
     * @param int $adminid Admin user id.
     * @param int $sectionid Section id to delete.
     * @return never
     */
    private function run_delete_child(array $dbconfig, int $courseid, int $adminid, int $sectionid): void {
        $exitcode = 0;
        try {
            $this->reconnect($dbconfig, $adminid);
            course_modinfo::clear_instance_cache($courseid);
            $format = course_get_format($courseid);
            $section = get_fast_modinfo($courseid)->get_section_info_by_id($sectionid, MUST_EXIST);
            $format->delete_section_with_children($section);
        } catch (\Throwable $e) {
            // locktimeout (lock held) or a deadlock abort are ACCEPTABLE declines:
            // the worker backed off rather than committing corruption.
            fwrite(STDERR, "delete child declined: " . $e->getMessage() . "\n");
            $exitcode = 3;
        }
        $this->terminate($exitcode);
    }

    /**
     * Child: move a subsection-bearing section, optionally in a loop.
     *
     * This is the "copy/paste/rename" analogue — move_section() renumbers the course
     * and rewrites parent pointers, taking NO lock. Per-iteration errors (a shifted
     * number failing can_move_section_to, or a concurrent-renumber deadlock) are
     * swallowed; the point is to keep unsynchronised writes flowing.
     *
     * @param array $dbconfig Connection config.
     * @param int $courseid Shared course id.
     * @param int $adminid Admin user id.
     * @param int $moveid Id of the (subsection-bearing) section to move.
     * @param int $anchorid Id of a low section used as an alternating "before" target.
     * @param int $iterations Number of moves to attempt.
     * @return never
     */
    private function run_move_child(
        array $dbconfig,
        int $courseid,
        int $adminid,
        int $moveid,
        int $anchorid,
        int $iterations
    ): void {
        try {
            $this->reconnect($dbconfig, $adminid);
            $format = course_get_format($courseid);
            for ($i = 0; $i < $iterations; $i++) {
                try {
                    course_modinfo::clear_instance_cache($courseid);
                    $modinfo = get_fast_modinfo($courseid);
                    $moveinfo = $modinfo->get_section_info_by_id($moveid);
                    $anchor = $modinfo->get_section_info_by_id($anchorid);
                    if (!$moveinfo || !$anchor) {
                        continue;
                    }
                    $before = ($i % 2 === 0) ? $anchor : null;
                    $format->move_section($moveinfo, 0, $before);
                } catch (\Throwable $inner) {
                    continue; // Expected under contention; keep hammering.
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "move child threw (outer): " . $e->getMessage() . "\n");
        }
        $this->terminate(0);
    }

    /**
     * Fork one worker, wait for it, and return its outcome code (-1 == crash).
     *
     * @param callable $worker Closure that runs in the child and never returns.
     * @return int Outcome: 0 (worked), 3 (declined), -1 (crashed / no marker).
     */
    private function fork_one(callable $worker): int {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        } else if ($pid === 0) {
            $worker(); // Never returns.
        }
        $status = 0;
        pcntl_waitpid($pid, $status);
        $marker = sys_get_temp_dir() . '/modgen_structops_' . $pid;
        if (!is_file($marker)) {
            return -1;
        }
        $outcome = (int) trim((string) file_get_contents($marker));
        @unlink($marker);
        return $outcome;
    }

    /**
     * Acquire the exact lock format_flexsections' delete path uses.
     *
     * @param int $timeout Seconds to wait (0 == try once).
     * @return \core\lock\lock|false
     */
    private function grab_delete_lock(int $timeout = 0) {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        return $factory->get_lock(self::LOCK_KEY, $timeout);
    }

    /**
     * Build a fresh, COMMITTED flexsections course with two subsections.
     *
     * @return array{0:int,1:array<int,int>} [courseid, sectionidbynumber]
     */
    private function build_course(): array {
        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 20, 'format' => 'flexsections'],
            ['createsections' => true]
        );
        $format = course_get_format($course->id);
        $format->create_new_section(15);
        $format->create_new_section(15);

        $bynum = [];
        foreach (get_fast_modinfo($course->id)->get_section_info_all() as $s) {
            $bynum[$s->section] = $s->id;
        }
        return [$course->id, $bynum];
    }

    /**
     * Audit a course for structural corruption (duplicate numbers, orphaned / self /
     * circular parents) using the parent's own post-reset-safe DB connection.
     *
     * @param int $courseid Course id.
     * @return string[] Problems (empty == sound).
     */
    private function audit(int $courseid): array {
        global $DB;
        $problems = [];

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');
        $nums = array_map('intval', array_column($sections, 'section'));
        if (count(array_unique($nums)) !== count($nums)) {
            foreach (array_count_values($nums) as $num => $count) {
                if ($count > 1) {
                    $problems[] = "Duplicate section number {$num} (x{$count}).";
                }
            }
        }

        $existing = array_flip($nums);
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
     * The delete lock does NOT cover move_section(): a move mutates the course even
     * while the delete lock is held by another owner. This is the lock-coverage gap.
     *
     * @covers \format_flexsections::move_section
     */
    public function test_move_section_ignores_delete_lock(): void {
        $this->require_fork_environment();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER, $DB;
        $adminid = $USER->id;
        $this->preventResetByRollback();
        $dbconfig = (array) $DB->export_dbconfig();
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        [$courseid, $bynum] = $this->build_course();

        // The parent holds the delete lock for the whole child run.
        $lock = $this->grab_delete_lock(0);
        $this->assertNotFalse($lock, 'Parent should acquire the flexsections delete lock.');

        $before = (int) $DB->get_field('course_sections', 'section', ['id' => $bynum[15]]);
        $outcome = $this->fork_one(
            fn() => $this->run_move_child($dbconfig, $courseid, $adminid, $bynum[15], $bynum[3], 1)
        );
        $lock->release();

        course_modinfo::clear_instance_cache($courseid);
        $after = (int) $DB->get_field('course_sections', 'section', ['id' => $bynum[15]]);

        $this->assertSame(0, $outcome, 'Move worker should complete despite the held delete lock.');
        $this->assertNotEquals(
            $before,
            $after,
            'move_section() mutated the course while the delete lock was HELD — the lock does not cover moves.'
        );
        $this->assertSame([], $this->audit($courseid), 'The single move should leave a sound structure.');
    }

    /**
     * delete_section_with_children() DOES respect the lock: while it is held, a
     * concurrent delete declines (locktimeout) and its target section survives.
     *
     * @covers \format_flexsections::delete_section_with_children
     */
    public function test_delete_section_respects_delete_lock(): void {
        $this->require_fork_environment();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER, $DB;
        $adminid = $USER->id;
        $this->preventResetByRollback();
        $dbconfig = (array) $DB->export_dbconfig();
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        [$courseid, $bynum] = $this->build_course();

        $lock = $this->grab_delete_lock(0);
        $this->assertNotFalse($lock, 'Parent should acquire the flexsections delete lock.');

        $outcome = $this->fork_one(
            fn() => $this->run_delete_child($dbconfig, $courseid, $adminid, $bynum[5])
        );
        $lock->release();

        $this->assertSame(
            3,
            $outcome,
            'Delete worker should DECLINE (locktimeout) while the delete lock is held.'
        );
        $this->assertTrue(
            $DB->record_exists('course_sections', ['id' => $bynum[5]]),
            'Target section must survive: the delete honoured the lock instead of proceeding.'
        );
    }

    /**
     * delete racing a stream of moves. The application lock does not serialise this,
     * but the DB does its own protecting: conflicting structural transactions
     * deadlock and one aborts cleanly. This test asserts the OBSERVED safe outcomes
     * (no unexpected crash; the losing side declines) and loudly reports to STDERR if
     * it ever manages to catch hierarchy corruption — corruption is a rare
     * interleaving, not the common case, so we do not assert it must occur.
     *
     * @covers \format_flexsections::move_section
     * @covers \format_flexsections::delete_section_with_children
     */
    public function test_delete_racing_move_is_db_protected(): void {
        $this->require_fork_environment();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER, $DB;
        $adminid = $USER->id;
        $this->preventResetByRollback();
        $dbconfig = (array) $DB->export_dbconfig();
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        $rounds = 3;
        $corruptrounds = 0;
        $crashes = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            [$courseid, $bynum] = $this->build_course();

            $pids = [];
            foreach (
                [
                fn() => $this->run_delete_child($dbconfig, $courseid, $adminid, $bynum[5]),
                fn() => $this->run_move_child($dbconfig, $courseid, $adminid, $bynum[15], $bynum[3], 40),
                ] as $worker
            ) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('pcntl_fork() failed.');
                } else if ($pid === 0) {
                    $worker();
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                $marker = sys_get_temp_dir() . '/modgen_structops_' . $pid;
                if (!is_file($marker)) {
                    $crashes++;
                } else {
                    $outcome = (int) trim((string) file_get_contents($marker));
                    @unlink($marker);
                    if ($outcome !== 0 && $outcome !== 3) {
                        $crashes++;
                    }
                }
            }

            $problems = $this->audit($courseid);
            if (!empty($problems)) {
                $corruptrounds++;
                fwrite(STDERR, "[delete+move] round {$round} CAUGHT CORRUPTION:\n  - "
                    . implode("\n  - ", $problems) . "\n");
            }
        }

        // The DB backstop means corruption is rare; a caught corruption is a real
        // finding worth surfacing, but the stable assertion is that workers never
        // crash — they either complete or deadlock-decline cleanly.
        $this->assertSame(
            0,
            $crashes,
            'Structural workers should complete or decline cleanly, never crash (see STDERR). '
            . 'On macOS, run with OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES.'
        );
        if ($corruptrounds > 0) {
            fwrite(STDERR, "[delete+move] reproduced hierarchy corruption in "
                . "{$corruptrounds}/{$rounds} rounds — the lock gap is not merely theoretical.\n");
        }
    }

    /** @var string Barrier directory for the deterministic stale-snapshot test. */
    private string $barrierdir = '';

    /** Signal a barrier flag. */
    private function barrier_signal(string $name): void {
        @file_put_contents($this->barrierdir . '/' . $name, '1');
    }

    /** Block until a barrier flag exists (bounded ~6s). */
    private function barrier_wait(string $name): void {
        $tries = 0;
        while (!is_file($this->barrierdir . '/' . $name) && $tries < 300) {
            usleep(20000);
            $tries++;
        }
    }

    /**
     * Child B for the deterministic stale-snapshot test.
     *
     * Runs move_section()'s REAL compute (via reflection on reorder_sections /
     * resolve_section_number) against the CURRENT (pre-A) snapshot, blocks at a
     * barrier while the parent commits operation A, then writes its stale-computed
     * transaction — the exact statements move_section() would run. This models a
     * move/reparent request that read the page state, then submitted just after a
     * background delete committed.
     *
     * @param array $dbconfig Connection config.
     * @param int $adminid Admin user id.
     * @param int $courseid Shared course id.
     * @param int $movenum Section number to move.
     * @param int $parentnum Destination parent section number.
     * @return never
     */
    private function run_stale_move_child(
        array $dbconfig,
        int $adminid,
        int $courseid,
        int $movenum,
        int $parentnum
    ): void {
        global $DB;
        try {
            $this->reconnect($dbconfig, $adminid);
            course_modinfo::clear_instance_cache($courseid);
            $format = course_get_format($courseid);
            $section = $format->get_section($movenum);

            // --- COMPUTE from the pre-A snapshot (mirrors move_section()). ---
            $origorder = [];
            foreach ($format->get_sections() as $s) {
                $origorder[$s->id] = $s->section;
            }
            $reorder = new \ReflectionMethod($format, 'reorder_sections');
            $reorder->setAccessible(true);
            $neworder = [];
            $args = [&$neworder, 0, $section->section, $parentnum, null];
            $reorder->invokeArgs($format, $args);

            $resolve = new \ReflectionMethod($format, 'resolve_section_number');
            $resolve->setAccessible(true);
            $changes = [];
            $newparentnum = null;
            foreach ($origorder as $id => $num) {
                if ($num != $neworder[$id]) {
                    $changes[$id] = ['old' => $num, 'new' => $neworder[$id]];
                }
                if ($resolve->invoke($format, $parentnum) === $num) {
                    $newparentnum = $neworder[$id];
                }
            }
            $changeparent = [];
            foreach ($format->get_sections() as $sub) {
                foreach ($changes as $id => $ch) {
                    if ($sub->parent == $ch['old']) {
                        $changeparent[$sub->id] = $ch['new'];
                    }
                }
            }
            $changeparent[$section->id] = $newparentnum;

            // --- BARRIER: snapshot captured; wait for A to commit. ---
            $this->barrier_signal('b_computed');
            $this->barrier_wait('a_committed');

            // --- WRITE the stale transaction (identical to move_section()'s writes). ---
            $tx = $DB->start_delegated_transaction();
            foreach ($changes as $id => $ch) {
                $DB->set_field('course_sections', 'section', -$ch['new'], ['id' => $id]);
            }
            foreach ($changes as $id => $ch) {
                $DB->set_field('course_sections', 'section', $ch['new'], ['id' => $id]);
            }
            foreach ($changeparent as $id => $newnum) {
                $format->update_section_format_options(['id' => $id, 'parent' => $newnum]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            fwrite(STDERR, "stale-move child threw: " . $e->getMessage() . "\n");
            $this->terminate(3);
        }
        $this->terminate(0);
    }

    /**
     * The DB backstop is NOT complete: a read-early / write-late lost update on the
     * unconstrained `parent` field commits a structurally-invalid hierarchy with no
     * deadlock and no unique-constraint violation.
     *
     * Deterministic interleaving (enforced by a barrier):
     *   start: ... 6, 7=child-of-6, 8, 9
     *   B computes "move section 8 under section 9" from this snapshot
     *   A commits "delete section 9"
     *   B writes its stale transaction -> section formerly-8 ends up at number 9
     *      with parent = 8, but section 8 no longer exists -> ORPHANED parent.
     *
     * This is the class of corruption seen in production (stale / recursive parents),
     * and neither the application lock nor the DB constraints prevent it.
     *
     * @covers \format_flexsections::move_section
     * @covers \format_flexsections::delete_section_with_children
     */
    public function test_stale_snapshot_write_escapes_db_backstop(): void {
        $this->require_fork_environment();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER, $DB;
        $adminid = $USER->id;
        $this->preventResetByRollback();
        $dbconfig = (array) $DB->export_dbconfig();
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        $this->barrierdir = make_request_directory();

        // 8 top-level sections; section 6 gets a subsection (so U=7, then 8 and 9).
        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 8, 'format' => 'flexsections'],
            ['createsections' => true]
        );
        $format = course_get_format($course->id);
        $format->create_new_section(6);
        rebuild_course_cache($course->id, true);
        course_modinfo::clear_instance_cache($course->id);

        // B: compute "move section 8 under section 9" from the current snapshot, then block.
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        } else if ($pid === 0) {
            $this->run_stale_move_child($dbconfig, $adminid, $course->id, 8, 9);
        }

        // A: once B has captured its snapshot, delete section 9 and commit.
        $this->barrier_wait('b_computed');
        course_modinfo::clear_instance_cache($course->id);
        $format = course_get_format($course->id);
        $s9id = (int) $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 9]);
        $s9 = get_fast_modinfo($course->id)->get_section_info_by_id($s9id, MUST_EXIST);
        $format->delete_section_with_children($s9);
        $this->barrier_signal('a_committed');

        // B now writes its stale transaction and exits.
        $status = 0;
        pcntl_waitpid($pid, $status);
        $marker = sys_get_temp_dir() . '/modgen_structops_' . $pid;
        $outcome = is_file($marker) ? (int) trim((string) file_get_contents($marker)) : -1;
        @unlink($marker);

        $this->assertNotSame(-1, $outcome, 'Stale-move child must not crash.');
        $this->assertSame(
            0,
            $outcome,
            'Stale-move child must COMMIT (no deadlock / no unique violation) — that is the whole point: '
            . 'the DB does not reject the stale write.'
        );

        course_modinfo::clear_instance_cache($course->id);
        $problems = $this->audit($course->id);
        fwrite(STDERR, "[stale-snapshot] audit: " . ($problems ? implode('; ', $problems) : 'SOUND') . "\n");

        $this->assertNotSame(
            [],
            $problems,
            'The stale-snapshot write should have produced a structurally-invalid hierarchy '
            . '(orphaned parent) that the DB accepted — proving the backstop is incomplete.'
        );
        $orphaned = array_filter($problems, fn($p) => str_contains($p, 'orphaned parent'));
        $this->assertNotEmpty(
            $orphaned,
            'Expected an orphaned parent pointer specifically (the production corruption signature).'
        );
    }

    /**
     * The RECURSIVE (self-referential / circular) parent seen in production, reproduced
     * deterministically.
     *
     * Two sibling sections, X=3 and Y=4, both top-level. A barrier forces:
     *   B computes "move section 3 under section 4" from the all-siblings snapshot.
     *   A commits "move section 4 under section 3" -> A sets section 4's PARENT = 3.
     *   B writes its stale transaction -> B sets section 4's NUMBER = 3 (its renumber),
     *     but does not touch its parent.
     *   Net: the section now at number 3 has parent 3 -> IT IS ITS OWN PARENT.
     *
     * A mixed-snapshot lost update: A wrote the parent, B wrote the number, they meet at
     * the same value. No deadlock, no unique violation — the `parent` field is
     * unconstrained. This is the recursive-parent corruption `section_has_parent()` /
     * `get_subsections()` then loop on.
     *
     * @covers \format_flexsections::move_section
     */
    public function test_stale_snapshot_write_creates_recursive_parent(): void {
        $this->require_fork_environment();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $USER, $DB;
        $adminid = $USER->id;
        $this->preventResetByRollback();
        $dbconfig = (array) $DB->export_dbconfig();
        $dbconfig += ['dblibrary' => 'native', 'dboptions' => []];

        $this->barrierdir = make_request_directory();

        // Six top-level sibling sections (1..6); X=3 and Y=4 are the pair.
        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 6, 'format' => 'flexsections'],
            ['createsections' => true]
        );
        rebuild_course_cache($course->id, true);
        course_modinfo::clear_instance_cache($course->id);

        // B: compute "move section 3 under section 4" from the all-siblings snapshot, then block.
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        } else if ($pid === 0) {
            $this->run_stale_move_child($dbconfig, $adminid, $course->id, 3, 4);
        }

        // A: once B has its snapshot, move section 4 under section 3 and commit.
        $this->barrier_wait('b_computed');
        course_modinfo::clear_instance_cache($course->id);
        $format = course_get_format($course->id);
        $format->move_section(4, 3, null);
        $this->barrier_signal('a_committed');

        // B writes its stale transaction and exits.
        $status = 0;
        pcntl_waitpid($pid, $status);
        $marker = sys_get_temp_dir() . '/modgen_structops_' . $pid;
        $outcome = is_file($marker) ? (int) trim((string) file_get_contents($marker)) : -1;
        @unlink($marker);

        $this->assertSame(
            0,
            $outcome,
            'Stale-move child must COMMIT (no deadlock / no unique violation) — the DB accepts the stale write.'
        );

        course_modinfo::clear_instance_cache($course->id);
        $problems = $this->audit($course->id);
        fwrite(STDERR, "[recursive-parent] audit: " . ($problems ? implode('; ', $problems) : 'SOUND') . "\n");

        $recursive = array_filter(
            $problems,
            fn($p) => str_contains($p, 'is its own parent') || str_contains($p, 'Circular parent chain')
        );
        $this->assertNotEmpty(
            $recursive,
            'Expected a self-referential / circular parent — the recursive-parent corruption seen in production.'
        );
    }

    /**
     * DUPLICATE, interleaved with an unrelated DELETE, orphans the new section's
     * parent — using the real, unmodified duplicate_section() / duplicate_section_properties()
     * production code, no forking required.
     *
     * duplicate_section() (format_flexsections::duplicate_section(), lib.php) reads
     * $oldsectioninfo->parent and $createbefore ONCE at the top, then performs a
     * sequence of unlocked section/module-creation writes, and only afterwards uses
     * that captured (by-then possibly stale) parent number when positioning the
     * clone. Because this staleness comes from the method's OWN internal ordering —
     * not from two processes racing — it can be demonstrated deterministically in a
     * single process: pause the real duplicate_section_properties() at the exact
     * point a concurrent request's commit would land, run a real
     * delete_section_with_children() inline (standing in for "another tab's
     * request"), resume, and observe the result.
     *
     * Course layout: Q (with 2 of its own children, so deleting it removes 3 rows —
     * enough to fully vacate P's old number, not just shift something else into it),
     * then P (top-level) with a single child X. Duplicating X:
     *   - captures oldsectioninfo->parent = P's number (say 5)
     *   - PAUSES right before the clone's parent gets written
     *   - (hook) Q + its 2 children are deleted -> P shifts from 5 to 2; that
     *     correctly cascades to X's OWN parent (X is a real row, updated correctly)
     *   - resumes: the clone is written with parent = 5 (the STALE captured value)
     *   - final move_section() step can't resolve section 5 anymore (it's vacated)
     *     and no-ops, leaving the clone's format-option parent at the stale 5
     *   -> the clone commits with an orphaned parent. No exception, no lock, no
     *      forking.
     *
     * @covers \format_flexsections::duplicate_section
     * @covers \format_flexsections::duplicate_section_properties
     */
    public function test_duplicate_section_orphans_parent_when_interleaved_with_delete(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Q = section 1, with 2 children (deleting Q removes 3 rows -> a clean,
        // full-vacate shift rather than just displacing one section into the gap).
        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 2, 'format' => 'flexsections'],
            ['createsections' => true]
        );
        $format = course_get_format($course->id);
        $format->create_new_section(1);
        $format->create_new_section(1);

        // P = a fresh top-level section; X = P's only child (the section we'll duplicate).
        $pnum = $format->create_new_section(0);
        $xnum = $format->create_new_section($pnum);
        rebuild_course_cache($course->id, true);
        course_modinfo::clear_instance_cache($course->id);

        $modinfo = get_fast_modinfo($course->id);
        $qid = $modinfo->get_section_info(1)->id;
        $xid = $modinfo->get_section_info($xnum)->id;

        // A test-only subclass of the REAL format_flexsections that pauses at the
        // exact internal point duplicate_section()'s stale snapshot gets used — no
        // logic is reimplemented, parent::duplicate_section_properties() still runs.
        $courseid = $course->id;
        $hookfired = false;
        $barrierformat = new class ($courseid) extends \format_flexsections {
            public $onhook;
            public function __construct($courseid) {
                parent::__construct('flexsections', $courseid);
            }
            protected function duplicate_section_properties(
                \section_info $originalsection,
                int $newparent,
                bool $istop = false
            ): \stdClass {
                if ($this->onhook) {
                    $hook = $this->onhook;
                    $this->onhook = null;
                    $hook();
                }
                return parent::duplicate_section_properties($originalsection, $newparent, $istop);
            }
        };

        $barrierformat->onhook = function () use ($courseid, $qid, &$hookfired) {
            $hookfired = true;
            // Stand-in for "another tab's request committing mid-duplicate": a real
            // delete, through the real (locked) delete path, using its own format
            // instance — exactly what a concurrent HTTP request would do.
            course_modinfo::clear_instance_cache($courseid);
            $otherformat = course_get_format($courseid);
            $qsection = get_fast_modinfo($courseid)->get_section_info_by_id($qid, MUST_EXIST);
            $otherformat->delete_section_with_children($qsection);
        };

        $xinfo = get_fast_modinfo($courseid)->get_section_info_by_id($xid, MUST_EXIST);
        $newsection = $barrierformat->duplicate_section($xinfo);

        $this->assertTrue($hookfired, 'The mid-duplicate hook must have fired (sanity check on the test itself).');

        course_modinfo::clear_instance_cache($courseid);
        $problems = $this->audit($courseid);
        fwrite(STDERR, "[duplicate-orphan] new section num={$newsection->section}; audit: "
            . ($problems ? implode('; ', $problems) : 'SOUND') . "\n");

        $orphaned = array_filter($problems, fn($p) => str_contains($p, 'orphaned parent'));
        $this->assertNotEmpty(
            $orphaned,
            'Expected the newly duplicated section to commit with an orphaned parent — '
            . 'duplicate_section()\'s own captured snapshot went stale mid-operation.'
        );
    }
}
