# Automatic Section Renumbering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One minute after the last section add or delete on a Flexible sections course, renumber the course into hierarchy order with an ad hoc task. Show site admins a status icon on the assistant bar.

**Architecture:** A core-event observer queues one ad hoc task per course, with its own "move the waiting task's run time back" logic. The task backs off while the course's `cacherev` says someone is still editing, then runs the existing `integrity_checker::fix_section_order()` and logs the outcome. The status icon reads `task_adhoc` and the standard log only, rendered server-side in the toolbar fragment and refreshed by a small polling AMD module.

**Tech Stack:** Moodle 4.5 (PHP 8.1+, PostgreSQL), `\core\task\adhoc_task`, `\core\event\base`, Mustache, AMD (ES modules built with Grunt), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-03-auto-section-reorder-design.md` (approved). The user chose not to patch flexsections' delete path; this plan relies on the ad hoc task alone.

## Global Constraints

- Plugin root: `/Users/tcadmin/Sites/moodle45/ai/placement/modgen`, component `aiplacement_modgen`, branch `feature/auto-section-reorder`.
- No new DB tables, config entries or caches for this state. Everything reads `task_adhoc`, `course.cacherev` and `logstore_standard_log`.
- Act only on courses with `format = 'flexsections'`. Only section **create** and **delete** trigger anything (`\core\event\course_section_created`, `\core\event\course_section_deleted`).
- Quiet period / delay: **60 seconds**. Poll interval: **10 seconds**. "Recent" window for renumbered/blocked: **3600 seconds**.
- Status icon visibility: `has_capability('moodle/site:config', context_system::instance())` only.
- No notifications or messages. No reload prompt.
- Moodle coding style: `vendor/bin/phpcs --standard=moodle <files>` must be clean (run from `/Users/tcadmin/Sites/moodle45`).
- AMD: edit `amd/src/`, then build with `cd ai/placement/modgen && npx grunt`. Commit `src` and `build` together (`plugin_wiring_test` checks they match).
- Every new lang string goes in `lang/en/aiplacement_modgen.php`, in alphabetical position where the file is sorted (`language_string_test` checks references and duplicates).
- PHPUnit from the Moodle root: `vendor/bin/phpunit --testsuite aiplacement_modgen_testsuite`. Single file: `vendor/bin/phpunit ai/placement/modgen/tests/<file>.php`. Run `php admin/tool/phpunit/cli/util.php --buildcomponentconfigs` once after adding test files if they aren't picked up.
- `docs/` is gitignored in this plugin. Don't commit the plan or spec unless the user says so.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **`cacherev` ahead of the clock.** `increment_revision_number()` sets `cacherev = cacherev + 1` when it's already ≥ now, so a large build (hundreds of cache rebuilds a second) can leave `cacherev` minutes, at most an hour, in the future. Expected: the task keeps deferring, writes nothing and doesn't error, then runs once the clock passes it. Test in Task 3: `test_future_cacherev_defers`.
2. **An edit arriving while the task runs.** The running row is deleted when it finishes, so moving its run time would lose the edit. Expected: a second, not-started row is queued, and later schedules move that row, never the started one. Test in Task 1: `test_schedule_ignores_started_row`.
3. **Course deleted after the task was queued.** Expected: the task returns quietly, with no exception and no events. Test in Task 3: `test_deleted_course_is_ignored`.
4. **Course switched away from flexsections while queued.** Expected: no-op. Test in Task 3: `test_non_flexsections_course_is_ignored`.
5. **A teacher (not a site admin) opening the course.** Expected: no status markup in the toolbar fragment, and the endpoint refuses. Tests in Task 4: `test_teacher_cannot_view`, and in Task 5: `test_fragment_hides_status_for_teacher`.

Also expected, with no extra test needed: existing tests that create sections now queue a task with `nextruntime = now + 60`. Moodle's `runAdhocTasks()` only runs due tasks, so these never run in existing tests. Task 1's full-suite run confirms that.

---

### Task 1: Queue helper, observer and event wiring

**Files:**
- Create: `classes/local/section_reorder_queue.php`
- Create: `classes/local/section_reorder_observer.php`
- Create: `classes/task/reorder_sections_task.php` (shell only: `for_course()`, `get_name()`, `retry_until_success()`, and an `execute()` that does nothing yet. Task 3 fills it in.)
- Create: `db/events.php`
- Modify: `lang/en/aiplacement_modgen.php` (add `taskreordersections`)
- Test: `tests/section_reorder_queue_test.php`

**Interfaces:**
- Produces:
  - `\aiplacement_modgen\local\section_reorder_queue::DELAY = 60` (public const int)
  - `section_reorder_queue::schedule(int $courseid, int $delay = self::DELAY): void`. If there is a matching row with `timestarted IS NULL`, sets its `nextruntime` to `time() + $delay`. Otherwise queues a new `reorder_sections_task::for_course($courseid)` with `set_next_run_time(time() + $delay)` through `\core\task\manager::queue_adhoc_task($task)`. Matching means `classname = '\aiplacement_modgen\task\reorder_sections_task'`, `component = 'aiplacement_modgen'`, and `customdata` equal to `json_encode((object) ['courseid' => $courseid])`, compared with `$DB->sql_compare_text()`. When there's more than one not-started row, move the lowest `id`. This deliberately replaces `reschedule_or_queue_adhoc_task()`, which can't see `timestarted`.
  - `section_reorder_queue::get_task_rows(int $courseid): array`. Matching `task_adhoc` rows (any `timestarted`), ordered by `id`.
  - `\aiplacement_modgen\task\reorder_sections_task::for_course(int $courseid): self`. Sets custom data `(object) ['courseid' => $courseid]` and component `aiplacement_modgen`, and **no user**.
  - `reorder_sections_task::retry_until_success(): bool` returns `false`. `get_name()` returns `get_string('taskreordersections', 'aiplacement_modgen')`.
  - `\aiplacement_modgen\local\section_reorder_observer::section_changed(\core\event\base $event): void`. Returns if `$event->courseid` is empty or `SITEID`, or if `get_course()` throws, or if the format isn't `flexsections`. Otherwise calls `schedule($event->courseid)`. Wraps everything in `try { } catch (\Throwable $e) { debugging(..., DEBUG_DEVELOPER); }`.
  - `db/events.php`: two observers (`\core\event\course_section_created`, `\core\event\course_section_deleted`) → `'\aiplacement_modgen\local\section_reorder_observer::section_changed'`, `'internal' => false` so they run after commit.
- Lang: `$string['taskreordersections'] = 'Renumber course sections after edits';`

- [ ] **Step 1: Write the failing tests** in `tests/section_reorder_queue_test.php` (namespace `aiplacement_modgen`, `final class section_reorder_queue_test extends \advanced_testcase`; setUp: `resetAfterTest`, `setAdminUser`, flexsections course via `create_course(['format' => 'topics'])` + `theme_builder::ensure_flexsections_format()`, then `$DB->delete_records('task_adhoc')` to clear tasks queued by setup)

```php
public function test_section_create_queues_one_delayed_task(): void {
    $before = time();
    \course_create_section($this->course->id, 0);
    $rows = section_reorder_queue::get_task_rows($this->course->id);
    $this->assertCount(1, $rows);
    $row = array_values($rows)[0];
    $this->assertNull($row->userid);
    $this->assertGreaterThanOrEqual($before + 60, (int) $row->nextruntime);
    $this->assertLessThanOrEqual(time() + 60, (int) $row->nextruntime);
}

public function test_burst_of_creates_leaves_one_task_moved_later(): void {
    \course_create_section($this->course->id, 0);
    $first = array_values(section_reorder_queue::get_task_rows($this->course->id))[0]->nextruntime;
    $this->waitForSecond();
    \course_create_section($this->course->id, 0);
    \course_create_section($this->course->id, 0);
    $rows = section_reorder_queue::get_task_rows($this->course->id);
    $this->assertCount(1, $rows);
    $this->assertGreaterThan((int) $first, (int) array_values($rows)[0]->nextruntime);
}

public function test_section_delete_queues_task(): void {
    $num = \course_create_section($this->course->id, 0)->section;
    $DB->delete_records('task_adhoc');
    \course_get_format($this->course->id)->delete_section_with_children(get_fast_modinfo($this->course->id)->get_section_info($num));
    $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
}

public function test_two_editors_share_one_task(): void {
    // Create as user A, then setUser(B) and create again: still exactly 1 row.
}

public function test_other_formats_queue_nothing(): void {
    $topics = $this->getDataGenerator()->create_course(['format' => 'topics']);
    $DB->delete_records('task_adhoc');
    \course_create_section($topics->id, 0);
    $this->assertCount(0, section_reorder_queue::get_task_rows($topics->id));
}

public function test_schedule_ignores_started_row(): void {
    section_reorder_queue::schedule($this->course->id);
    $started = array_values(section_reorder_queue::get_task_rows($this->course->id))[0];
    $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $started->id]);
    section_reorder_queue::schedule($this->course->id);
    section_reorder_queue::schedule($this->course->id, 120);
    $rows = array_values(section_reorder_queue::get_task_rows($this->course->id));
    $this->assertCount(2, $rows);
    $this->assertEquals($started->nextruntime, $DB->get_field('task_adhoc', 'nextruntime', ['id' => $started->id]));
    $this->assertGreaterThanOrEqual(time() + 119, (int) $rows[1]->nextruntime);
}
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `vendor/bin/phpunit ai/placement/modgen/tests/section_reorder_queue_test.php`
Expected: FAIL (class `section_reorder_queue` not found).

- [ ] **Step 3: Implement** the queue helper, the observer, the task shell, `db/events.php` and the lang string, with the signatures above.

- [ ] **Step 4: Run the tests to make sure they pass, then run the full suite**

Run: `php admin/cli/purge_caches.php && vendor/bin/phpunit ai/placement/modgen/tests/section_reorder_queue_test.php`, then `vendor/bin/phpunit --testsuite aiplacement_modgen_testsuite`
Expected: PASS, and the full suite stays green (no existing test counts adhoc rows that this changes).

- [ ] **Step 5: phpcs and commit**

```bash
vendor/bin/phpcs --standard=moodle ai/placement/modgen/classes/local/section_reorder_*.php ai/placement/modgen/classes/task/reorder_sections_task.php ai/placement/modgen/db/events.php ai/placement/modgen/tests/section_reorder_queue_test.php
git add classes/local/section_reorder_queue.php classes/local/section_reorder_observer.php classes/task/reorder_sections_task.php db/events.php lang/en/aiplacement_modgen.php tests/section_reorder_queue_test.php
git commit -m "Queue one delayed section renumber per course on section adds and deletes"
```

---

### Task 2: Let fix_section_order() back off just before it writes

**Files:**
- Modify: `classes/local/integrity_checker.php:824-900` (`fix_section_order`, `apply_section_order`)
- Test: `tests/section_order_test.php` (add two tests)

**Interfaces:**
- Produces: `integrity_checker::fix_section_order(int $courseid, bool $locksheld = false, ?callable $beforewrite = null): array`. The result shape gains `deferred: bool` on **every** return path (`false` unless deferred). `$beforewrite` is called with no arguments inside the locks, after `check_section_order()` has found misordered sections and before any write. If it returns `false`, the method returns `['fixed' => 0, 'checkable' => true, 'blockedby' => null, 'deferred' => true]` and writes nothing. It isn't called when there's nothing to fix or the course isn't checkable. With `null` (all existing callers), behaviour is unchanged.

- [ ] **Step 1: Write the failing tests** (reuse the A/B/C + `set_parent($c, $a)` setup from `test_fix_renumbers_and_preserves_hierarchy`)

```php
public function test_fix_defers_when_beforewrite_refuses(): void {
    // ...misordered setup...
    $before = $DB->get_records_menu('course_sections', ['course' => $this->course->id], '', 'id, section');
    $result = integrity_checker::fix_section_order($this->course->id, false, fn() => false);
    $this->assertTrue($result['deferred']);
    $this->assertSame(0, $result['fixed']);
    $this->assertEquals($before, $DB->get_records_menu('course_sections', ['course' => $this->course->id], '', 'id, section'));
}

public function test_fix_proceeds_when_beforewrite_allows(): void {
    // ...misordered setup...
    $called = 0;
    $result = integrity_checker::fix_section_order($this->course->id, false, function() use (&$called) { $called++; return true; });
    $this->assertSame(1, $called);
    $this->assertFalse($result['deferred']);
    $this->assertGreaterThan(0, $result['fixed']);
}
```

- [ ] **Step 2: Run them to make sure they fail.** Run: `vendor/bin/phpunit --filter 'beforewrite' ai/placement/modgen/tests/section_order_test.php`. Expected: FAIL (undefined index `deferred`).
- [ ] **Step 3: Implement** the parameter on both methods and `'deferred' => false` on every existing return.
- [ ] **Step 4: Run the whole file to make sure it passes.** Run: `vendor/bin/phpunit ai/placement/modgen/tests/section_order_test.php`. Expected: PASS, existing tests included.
- [ ] **Step 5: phpcs and commit.** `git commit -m "Let the section renumber back off just before writing"`

---

### Task 3: The renumber task and the "blocked" event

**Files:**
- Modify: `classes/task/reorder_sections_task.php` (`execute()`)
- Create: `classes/event/section_order_blocked.php`
- Modify: `lang/en/aiplacement_modgen.php` (add `event_sectionorderblocked`)
- Test: `tests/reorder_sections_task_test.php`

**Interfaces:**
- Consumes: `section_reorder_queue::schedule()`, `section_reorder_queue::DELAY` (Task 1). `fix_section_order(..., ?callable $beforewrite)` and `deferred` (Task 2). The existing `structure_repaired::for_course(int $courseid, string $action, int $changed, string $via)` and `section_renumber_failed::build(int $courseid, \Throwable $e): ?self`.
- Produces:
  - `\aiplacement_modgen\event\section_order_blocked::for_course(int $courseid, string $blockedby): ?self`. Model it on `section_renumber_failed`: build inside a try/catch that returns null; `objectid` and `objecttable` = course; `crud = 'r'`; `edulevel = LEVEL_OTHER`; `other = ['blockedby' => $blockedby]`; `get_url()` → `/ai/placement/modgen/check_structure.php?id=<objectid>&check=1`; `validate_data()` requires `objectid` and `other['blockedby']`.
  - The task logs `structure_repaired` with `action = 'fixsectionorder'` and `via = 'adhoctask'`.
- Lang: `$string['event_sectionorderblocked'] = 'Automatic section renumber refused';`

`execute()` order (copy exactly. Each "defer" means `section_reorder_queue::schedule($courseid); return;`):
1. `$course = $DB->get_record('course', ['id' => $courseid], 'id, format, cacherev')`. If missing or not flexsections → return.
2. `$startrev = (int) $course->cacherev;` If `$startrev > time() - section_reorder_queue::DELAY` → defer. (This also covers a `cacherev` in the future.)
3. `fix_section_order($courseid, false, fn() => (int) $DB->get_field('course', 'cacherev', ['id' => $courseid]) === $startrev)`.
4. Catch `\moodle_exception` with `errorcode === 'erroracquiringlock'` → defer. Any other `\Throwable` → `section_renumber_failed::build()` and trigger it if not null (inside its own try/catch), then return.
5. `deferred` → defer. `checkable && fixed > 0` → trigger `structure_repaired`. `!checkable && blockedby === 'brokenhierarchy'` → trigger `section_order_blocked`. Otherwise nothing.

- [ ] **Step 1: Write the failing tests.** setUp as in Task 1, plus `$this->redirectEvents()` per test where events are asserted. Helper `make_misordered(): void` (the A/B/C + `set_parent` setup) and `age_cacherev(int $seconds = 120): void` (`$DB->set_field('course', 'cacherev', time() - $seconds, ['id' => ...])`). Run the task with `reorder_sections_task::for_course($id)->execute()`.

```php
public function test_misordered_course_is_renumbered_and_logged(): void {
    $this->make_misordered(); $this->age_cacherev();
    $sink = $this->redirectEvents();
    reorder_sections_task::for_course($this->course->id)->execute();
    $this->assertSame([], integrity_checker::check_section_order($this->course->id)['misordered']);
    $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof \aiplacement_modgen\event\structure_repaired));
    $this->assertCount(1, $events);
    $this->assertSame('adhoctask', $events[0]->other['via']);
    $this->assertSame('fixsectionorder', $events[0]->other['action']);
}
public function test_ordered_course_writes_and_logs_nothing(): void { /* no structure_repaired, no section_order_blocked */ }
public function test_broken_hierarchy_logs_blocked_and_writes_nothing(): void { /* cycle setup from section_order_test; one section_order_blocked with other['blockedby'] === 'brokenhierarchy'; sections unchanged */ }
public function test_recent_edit_defers(): void {
    $this->make_misordered(); // cacherev is fresh
    $DB->delete_records('task_adhoc');
    reorder_sections_task::for_course($this->course->id)->execute();
    $this->assertNotEmpty(integrity_checker::check_section_order($this->course->id)['misordered']);
    $this->assertCount(1, section_reorder_queue::get_task_rows($this->course->id));
}
public function test_future_cacherev_defers(): void { /* cacherev = time() + 300; same assertions as test_recent_edit_defers */ }
public function test_beforewrite_guard_refuses_after_cacherev_changes(): void {
    $this->age_cacherev();
    $start = (int) $DB->get_field('course', 'cacherev', ['id' => $this->course->id]);
    $guard = (new testable_reorder_sections_task())->beforewrite_guard($this->course->id, $start); // subclass exposing it as public
    $this->assertTrue($guard());
    \rebuild_course_cache($this->course->id, true);
    $this->assertFalse($guard());
}
public function test_lock_failure_requeues(): void {
    // Anonymous subclass of reorder_sections_task whose run_fix() throws
    // new \moodle_exception('erroracquiringlock', 'aiplacement_modgen'); set its custom data via for_course's shape.
    // Assert: no exception escapes, exactly one not-started task row for the course, no section_renumber_failed event.
}
public function test_deleted_course_is_ignored(): void { /* delete_course($id, false); execute(); no exception, no events */ }
public function test_non_flexsections_course_is_ignored(): void { /* $DB->set_field('course','format','topics',...); rebuild_course_cache; age; execute(); no events */ }
```

The task gets two protected seams for these tests: `beforewrite_guard(int $courseid, int $startrev): callable`, which returns `true` while `cacherev === $startrev` and `false` after, and `run_fix(int $courseid, callable $beforewrite): array`, which just calls `fix_section_order($courseid, false, $beforewrite)`. Define `testable_reorder_sections_task` (same file, extends the task, makes `beforewrite_guard` public) for the first test.


- [ ] **Step 2: Run them to make sure they fail.** Run: `vendor/bin/phpunit ai/placement/modgen/tests/reorder_sections_task_test.php`. Expected: FAIL.
- [ ] **Step 3: Implement** `execute()`, `beforewrite_guard()`, `run_fix()`, the event class and the lang string.
- [ ] **Step 4: Run them to make sure they pass, plus `tests/events_test.php` and `tests/language_string_test.php`.** Expected: PASS.
- [ ] **Step 5: phpcs and commit.** `git commit -m "Renumber sections in an ad hoc task once the course is quiet; log refusals"`

---

### Task 4: Status lookup

**Files:**
- Create: `classes/local/section_reorder_status.php`
- Modify: `lang/en/aiplacement_modgen.php`
- Test: `tests/section_reorder_status_test.php`

**Interfaces:**
- Consumes: `section_reorder_queue::get_task_rows()` (Task 1), the `section_order_blocked` and `structure_repaired` events (Task 3).
- Produces:
  - `section_reorder_status::RECENT_WINDOW = 3600`
  - `section_reorder_status::can_view(): bool` → `has_capability('moodle/site:config', \context_system::instance())`
  - `section_reorder_status::get_for_course(int $courseid): ?array` → `null` or `['state' => 'queued'|'running'|'renumbered'|'blocked', 'time' => int|null, 'label' => string, 'url' => string|null, 'isqueued' => bool, 'isrunning' => bool, 'isrenumbered' => bool, 'isblocked' => bool]`. This is also the Mustache context in Task 5.
  - Precedence: any row with `timestarted` → running (time null). Else any row → queued (time = min `nextruntime`). Else the newest qualifying log row within `RECENT_WINDOW`: a `structure_repaired` row with `other.action === 'fixsectionorder'` and `other.changed > 0` (any `via`) → renumbered, or a `section_order_blocked` row → blocked (`url` = check_structure.php URL `->out(false)`). Else null.
  - Log read: `SELECT id, eventname, other, timecreated FROM {logstore_standard_log} WHERE courseid = ? AND eventname IN (?, ?) AND timecreated >= ? ORDER BY timecreated DESC, id DESC`, with event names `'\aiplacement_modgen\event\structure_repaired'` and `'\aiplacement_modgen\event\section_order_blocked'`. Walk the rows, `json_decode($row->other, true)`, and return the first one that qualifies. If the table is missing or the query throws → treat as no log rows.
  - Time format: `userdate($time, get_string('strftimetime', 'langconfig'))`.
- Lang (exact):
  - `$string['reorderstatus_queued'] = 'Section reorder queued · runs {$a}';`
  - `$string['reorderstatus_running'] = 'Reordering sections…';`
  - `$string['reorderstatus_renumbered'] = 'Sections renumbered at {$a}';`
  - `$string['reorderstatus_blocked'] = 'Automatic reorder refused at {$a}: needs Check Structure';`

- [ ] **Step 1: Write the failing tests.** Enable logging in setUp: `set_config('enabled_stores', 'logstore_standard', 'tool_log'); set_config('buffersize', 0, 'logstore_standard'); get_log_manager(true);`. Clear `task_adhoc`.

```php
public function test_nothing_to_report_is_null(): void
public function test_queued(): void          // schedule(); state 'queued', time == row nextruntime, label contains userdate(time)
public function test_running_beats_queued(): void // two rows, one started → 'running'
public function test_renumbered_from_task(): void // trigger structure_repaired::for_course($id,'fixsectionorder',3,'adhoctask') → 'renumbered'
public function test_renumbered_from_check_structure_counts(): void // via 'check_structure' → 'renumbered'
public function test_zero_change_repair_is_ignored(): void // changed 0 → null
public function test_other_repair_action_is_ignored(): void // action 'fixcircular' → null
public function test_renumbered_expires_after_an_hour(): void // set its log row timecreated = time() - 3601 → null
public function test_blocked(): void // section_order_blocked → 'blocked', url contains check_structure.php
public function test_newer_renumber_clears_blocked(): void // blocked at t-10 (update timecreated), renumbered at t → 'renumbered'
public function test_queued_beats_blocked(): void
public function test_admin_can_view(): void  // setAdminUser → true
public function test_teacher_cannot_view(): void // editingteacher on the course → false
```

- [ ] **Step 2: Run them to make sure they fail.** Run: `vendor/bin/phpunit ai/placement/modgen/tests/section_reorder_status_test.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the status class and the lang strings.
- [ ] **Step 4: Run them to make sure they pass.** Expected: PASS.
- [ ] **Step 5: phpcs and commit.** `git commit -m "Read the section reorder status from the task queue and the log"`

---

### Task 5: Icon on the assistant bar, poll endpoint and live updates

**Files:**
- Create: `templates/reorder_status_icon.mustache`
- Modify: `templates/course_nav.mustache` (after the `{{#showcheckstructure}}` item, before `</ul>`; also add to the documented context and example JSON)
- Modify: `classes/output/course_toolbar.php` (constructor gains trailing `bool $showreorderstatus = false, ?array $reorderstatus = null`; export `showreorderstatus`, `reorderstatus`, `courseid`)
- Modify: `lib.php:110-175` (`aiplacement_modgen_output_fragment_course_toolbar`: `$showreorderstatus = $course->format === 'flexsections' && section_reorder_status::can_view()`, and pass `section_reorder_status::get_for_course($courseid)` when shown)
- Create: `ajax/reorder_status.php`
- Create: `amd/src/reorder_status.js` (+ `amd/build/reorder_status.min.js`, `.map` via Grunt)
- Modify: `amd/src/course_toolbar.js` (after the fragment is inserted: if `[data-region="aiplacement-modgen-reorder-status"]` exists, `ReorderStatus.init(config.courseid)`)
- Modify: `styles.css`
- Test: `tests/course_toolbar_reorder_status_test.php`

**Interfaces:**
- Consumes: `section_reorder_status::can_view()`, `get_for_course()` and its array shape (Task 4).
- Produces:
  - Wrapper in `course_nav.mustache`: `{{#showreorderstatus}}<li class="nav-item ml-2 d-flex align-items-center" data-region="aiplacement-modgen-reorder-status" data-courseid="{{courseid}}" aria-live="polite">{{#reorderstatus}}{{> aiplacement_modgen/reorder_status_icon}}{{/reorderstatus}}</li>{{/showreorderstatus}}`
  - `reorder_status_icon.mustache` (context = the status array): a round `span` (an `a href="{{url}}"` when `isblocked`), `tabindex="0"`, `role="img"`, `aria-label="{{label}}"`, `title="{{label}}"`, `data-toggle="tooltip"`. Icon by state: `fa-clock`, `fa-sync fa-spin`, `fa-check`, `fa-exclamation-triangle`, with modifier classes `aiplacement-modgen-reorder-icon--queued|running|renumbered|blocked`.
  - `ajax/reorder_status.php`: copy `ajax/check_job_status.php`'s structure (`AJAX_SCRIPT`, config, `require_login()`, `require_placement_enabled()`, `require_sesskey()`, try/catch with `ajax_response`). It takes `courseid` (`required_param`, `PARAM_INT`), calls `require_login($courseid)`, throws `required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', '')` unless `can_view()`, then `ajax_response::success(['status' => section_reorder_status::get_for_course($courseid)])`.
  - `amd/src/reorder_status.js`: `export const init = (courseid) => {}`. It polls `ajax/reorder_status.php?courseid&sesskey` with `fetch` (as `checkCompletedJobs()` does) every **10000 ms** while the current state is `queued` or `running`, and stops otherwise. On each response it re-renders the region with `Templates.renderForPromise('aiplacement_modgen/reorder_status_icon', status)` (or empties it when `status` is null) and re-initialises the tooltip. It starts polling on init if the state is queued or running. It also starts polling 2000 ms after a click on any `[data-action-flexsections]` or `[data-action="addSection"]` element, or core's delete/add section controls, so an admin's own edit shows within about 10 seconds. Only one timer runs at a time.
  - CSS: 28px circle, `background: rgba(255,255,255,.16)`, `border: 1px solid rgba(255,255,255,.35)`, white icon; renumbered `background: rgba(40,167,69,.45)`; blocked `background: rgba(220,53,69,.55)`; a visible focus ring.

- [ ] **Step 1: Write the failing tests** in `tests/course_toolbar_reorder_status_test.php`:

```php
public function test_export_includes_status_when_shown(): void {
    $status = ['state' => 'queued', 'time' => time() + 60, 'label' => 'x', 'url' => null,
        'isqueued' => true, 'isrunning' => false, 'isrenumbered' => false, 'isblocked' => false];
    $toolbar = new course_toolbar($courseid, false, false, false, false, true, false, true, true, $status);
    $data = $toolbar->export_for_template($PAGE->get_renderer('core'));
    $this->assertTrue($data->showreorderstatus);
    $this->assertSame('queued', $data->reorderstatus['state']);
}
public function test_fragment_shows_status_region_for_admin(): void {
    // setAdminUser; flexsections course; schedule(); $html = aiplacement_modgen_output_fragment_course_toolbar(['courseid' => $id, 'contextid' => context_course::instance($id)->id]);
    $this->assertStringContainsString('data-region="aiplacement-modgen-reorder-status"', $html);
    $this->assertStringContainsString('fa-clock', $html);
}
public function test_fragment_hides_status_for_teacher(): void {
    // editingteacher with aiplacement/modgen:checkstructure → assertStringNotContainsString('aiplacement-modgen-reorder-status', $html)
}
public function test_fragment_hides_status_on_non_flexsections(): void
```

- [ ] **Step 2: Run them to make sure they fail.** Run: `vendor/bin/phpunit ai/placement/modgen/tests/course_toolbar_reorder_status_test.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the template, renderable, fragment and CSS.
- [ ] **Step 4: Run them to make sure they pass.** Expected: PASS.
- [ ] **Step 5: Implement the endpoint and the AMD module, then build.** Run: `cd ai/placement/modgen && npx grunt`. Expected: no ESLint errors, and `amd/build/reorder_status.min.js` exists.
- [ ] **Step 6: Run the wiring and string tests.** Run: `vendor/bin/phpunit ai/placement/modgen/tests/plugin_wiring_test.php && vendor/bin/phpunit ai/placement/modgen/tests/language_string_test.php`. Expected: PASS.
- [ ] **Step 7: Check it by hand in the browser** (http://localhost/moodle45, as admin, editing on, flexsections course): add a section and see the clock icon within about 10 seconds, with a tooltip showing a time about 60 seconds ahead. Run `php admin/cli/adhoc_task.php --execute` after the time passes, then see the tick or nothing on the next poll. As a non-admin teacher, see no icon.
- [ ] **Step 8: phpcs and commit.** `git commit -m "Show site admins the section reorder status on the assistant bar"`

---

### Task 6: Version, upgrade notes, README

**Files:**
- Modify: `version.php` (`$plugin->version = 2026110300;`, leave `$plugin->release` for the user)
- Modify: `upgrade.txt` (new top section `## Upgrading to the next version`, "Added" list: automatic renumber one minute after section adds and deletes on Flexible sections courses; the admin-only status icon; the new `section_order_blocked` event; `structure_repaired` can now have `via = 'adhoctask'`)
- Modify: `README.md` (a short paragraph in the features section)

- [ ] **Step 1: Make the edits, then run the upgrade.** Run: `php admin/cli/upgrade.php --non-interactive && php admin/cli/purge_caches.php`. Expected: the plugin upgrades to 2026110300 with no errors.
- [ ] **Step 2: Run the full suite.** Run: `vendor/bin/phpunit --testsuite aiplacement_modgen_testsuite`. Expected: PASS.
- [ ] **Step 3: Commit.** `git commit -m "Add the automatic section renumber upgrade notes and bump the version"`
