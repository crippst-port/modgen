# Automatic section renumbering after section adds and deletes

Date: 2026-11-03
Plugin: `aiplacement_modgen`
Branch: `feature/auto-section-reorder`

## Why

On Flexible sections (`format_flexsections`) courses, tutors add or delete sections faster than each request completes. The editing UI does not block while a request is in flight, so overlapping requests leave section numbers out of step with the parent/child hierarchy. That matters because flexsections deletes a section by removing everything numbered after it: on a misordered course a later delete can silently remove unrelated content (see `integrity_checker::check_section_order()` and the docblock on `theme_builder::renumber_into_hierarchy_order()`).

Today this is repaired by hand. A report finds courses whose numbering is not consecutive with the hierarchy, and an admin moves a section up and back down, so that flexsections' own `move_section()` renumbers the course. This feature automates that repair.

## Goals

- After a section is **added** or **deleted** on a flexsections course, renumber the course into hierarchy order automatically, **one minute after the last such edit** (a quiet period, so a burst of clicks gets one repair at the end, not one per click).
- Exactly **one pending task per course** at a time, however many edits or editors there are.
- Use the repair modgen already has: `\aiplacement_modgen\local\integrity_checker::fix_section_order()`.
- Give **site admins** a small status icon on the assistant bar showing whether a renumber is queued, running, or was done recently, with times, and whether an automatic repair was refused because the hierarchy is broken.
- Store **no new data**: no new tables, and no new config or cache entries for this state. Everything shown comes from data Moodle already keeps.

## Non-goals

- Section **moves** do not trigger anything. flexsections already renumbers on every move.
- Repairing **broken hierarchies** (duplicate numbers, cycles, orphans, unreachable sections). `fix_section_order()` refuses these (`checkable = false`), and so does the task. They still need a person, using Check Structure.
- Any indicator for **non-admins**. Tutors see no change.
- Notifications, emails, or messages of any kind.
- Fixing the underlying race in flexsections or in the course editor UI.

## Anti-goals (would make this a failure even if it works)

- Running the renumber **while a burst of edits is still going on**, or once per edit.
- Slowing down section add/delete requests noticeably. The observer must be cheap.
- Prompting anyone to reload the page. A renumber only brings the stored numbers back in line with the tree the page already shows, so there's nothing visible to refresh.
- Writing to a broken hierarchy. This must never shuffle sections on a course `check_section_order()` can't vouch for.

## Constraints

- Moodle 4.5, PHP 8.1+, PostgreSQL. Follow Moodle coding style (`phpcs --standard=moodle`), and include PHPUnit tests.
- Edit AMD sources in `amd/src/` and rebuild with `npx grunt` from the plugin root.
- No new DB tables (user requirement). The version bump covers the new event, observer, string and AMD changes only.
- Only act on courses whose format is `flexsections`.

## Design

### 1. Trigger: event observer

Add `db/events.php` with observers for:

- `\core\event\course_section_created`, fired by `course_create_section()` in `course/format/classes/local/sectionactions.php`, which flexsections' `create_new_section()` uses.
- `\core\event\course_section_deleted`, fired by flexsections' `delete_section_with_children()` and by core's section delete.

The observer (a new class, for example `\aiplacement_modgen\local\section_reorder_observer`):

1. Returns at once unless the course's format is `flexsections`. The section events carry no course snapshot, so use `get_course($event->courseid)`, which is cached for the current course. Restores and course copies also fire `course_section_created`. That's fine: they queue one task like any other burst.
2. Builds the ad hoc task (below) with custom data `{"courseid": <id>}`, **no user** set, and a next run time of `time() + 60`.
3. Calls `\core\task\manager::reschedule_or_queue_adhoc_task($task)`. That matches on classname + component + customdata (+ userid, which is why no user is set). If a matching task is already queued, it moves that task's `nextruntime` to the new value. That reschedule is the "one minute after the **last** edit" behaviour, with no extra code.
4. **Edits that land while the task is running.** `reschedule_or_queue_adhoc_task()` doesn't check `timestarted`. If the matching row has already started, rescheduling it is lost when the running task finishes and its row is deleted. So the observer first looks for a matching `task_adhoc` row with `timestarted` set (or uses an equivalent check). If one exists, it calls `queue_adhoc_task()` to add a second task rather than rescheduling. The cost is at most one spare run, which does nothing on an already-ordered course. *(Builder: this case can produce two rows that share customdata. The next reschedule matches whichever `get_record_select` returns, so make sure the not-started row is the one that gets moved. Matching on `timestarted IS NULL` yourself, rather than through the core helper, is fine.)*
5. Must never throw into the request that triggered it: catch `\Throwable` and send it to `debugging()`.

modgen's own builds also create sections through `course_create_section()`, so they'll start the countdown as well. **This is deliberate (designer's call):** the cost is one small query per section, and the run a minute later is a free second check. If the build is still holding the structure locks when the task runs, the task waits on them (see §2).

### 2. The ad hoc task

New class `\aiplacement_modgen\task\reorder_sections_task` extending `\core\task\adhoc_task`.

`execute()`:

1. Reads `courseid` from the custom data. If the course no longer exists, or its format is no longer `flexsections`, it returns quietly.
2. **Quiet-period check (user's decision).** The structure locks don't protect against tutor edits: in flexsections only `delete_section_with_children()` takes a lock. `create_new_section()`, `move_section()` and core's `course_create_section()` take none, and a move fires no event, so moves can't restart the countdown through the observer. What every edit does do is call `rebuild_course_cache()`, which bumps `course.cacherev` to about `time()` (`increment_revision_number()`). Viewing, completion, submissions and grading don't bump it. So:
   - If `course.cacherev > time() - 60`, the course was edited in the last minute. **Skip, and queue a fresh task for `time() + 60`** using the observer's reschedule-or-queue logic, then return.
   - `fix_section_order()` reads the numbers before it writes. To narrow the window between check and write, run the same `cacherev` check again inside the locks, right before the write, and back off the same way if it fails. This needs a small hook in `integrity_checker` (for example an optional callable or `$maxcacherev` parameter checked after the locks are taken and before `apply_section_order()` writes). The builder can choose how to do this, but the existing manual callers must behave exactly as they do now.
   - The writes themselves bump `cacherev` via the cache rebuild. That's harmless, because the task doesn't queue itself afterwards.
   - Site-wide bumps ("Purge all caches", plugin upgrades) just delay waiting renumbers by a minute. That's acceptable.
3. Calls `integrity_checker::fix_section_order($courseid)`, which takes the structure locks itself (60s wait per lock).
4. Then, based on the result:
   - `checkable && fixed > 0`: trigger `structure_repaired::for_course($courseid, 'fixsectionorder', $fixed, 'adhoctask')`. `structure_repaired` already takes a `$via` argument, so `'adhoctask'` is a new value, not a new event.
   - `checkable && fixed == 0`: do nothing (the common case).
   - `!checkable && blockedby == 'brokenhierarchy'`: trigger the **new** event `\aiplacement_modgen\event\section_order_blocked` for the course, with `other.blockedby`. Model it on `section_renumber_failed`: course context, `crud = 'r'`, `edulevel = LEVEL_OTHER`, `get_url()` pointing at check_structure.php, and new lang strings. The user explicitly asked for log-only handling: no notifications.
   - `!checkable && blockedby == 'notflexsections'`: do nothing.
5. **Lock failure.** `acquire_structure_locks()` throws `moodle_exception('erroracquiringlock')` if it can't get a lock within 60s, for example during a long modgen build. Catch that specific failure and queue a fresh task for `time() + 60`, using the same reschedule-or-queue logic as the observer, so the repair isn't lost. Then return normally.
6. Any other `\Throwable` is logged through the existing `section_renumber_failed` event (reuse `section_renumber_failed::build($courseid, $e)`), and the task returns normally. **No retries (designer's call):** the next edit queues a fresh task anyway, and a repeatedly failing renumber shouldn't sit in the ad hoc queue. `retry_until_success()` returns `false`.

### 3. Admin status icon on the assistant bar

**Who sees it.** Only users with `moodle/site:config` in the system context, i.e. site admins *(designer's call: "admins" read as site admins)*. Only on flexsections courses, and only where the toolbar already shows.

**Placement and look** (chosen from mockups: *icon only*). A small round icon in the navbar, **next to Check Structure** (after it, at the right-hand end). It has no visible text. The details appear in a tooltip on hover **and** keyboard focus, using Bootstrap's tooltip as the rest of Moodle does, and the same text is the icon's accessible name (`aria-label`). An `aria-live="polite"` region announces state changes. Nothing renders when there's no state to show.

| State | Icon | Tooltip / accessible text |
|---|---|---|
| Queued | clock (`fa-clock`) | "Section reorder queued · runs 14:32" |
| Running | spinning sync (`fa-sync fa-spin`) | "Reordering sections…" |
| Renumbered | green check (`fa-check`) | "Sections renumbered at 14:32" |
| Blocked (user's decision) | red/amber warning (`fa-exclamation-triangle`) | "Automatic reorder refused at 14:32: needs Check Structure". Clicking the icon goes to check_structure.php for the course. |

Times use `userdate()` with `strftimetime`, rendered on the server. Final wording goes in lang strings.

**Where each state comes from (no new storage):**

- **Queued:** a `task_adhoc` row with `classname = '\aiplacement_modgen\task\reorder_sections_task'`, matching course customdata, and `timestarted IS NULL`. Show its `nextruntime`.
- **Running:** the same row with `timestarted` set. Running beats queued if both exist (see §1.4).
- **Renumbered:** the most recent `structure_repaired` event for this course whose `other.action = 'fixsectionorder'` and `other.changed > 0`, logged within the last **hour**. **This includes repairs from any `via`** (the Check Structure button, admin tools and the ad hoc task), as the user confirmed. Read it from the standard log store (`logstore_standard_log`, filtered by `eventname`, `courseid` and `timecreated`; `other` holds the action). If that store is disabled or the row has been purged, the state doesn't show, which is acceptable. When a renumber is queued or running as well, show queued or running instead.
- **Blocked:** the most recent `section_order_blocked` event for this course within the last **hour**, read the same way, and **only if it's newer than the latest renumbered event**. A later successful repair, from any source, clears it.
- **Precedence:** running > queued > whichever of blocked or renumbered is newer > none.
- Reading `other` from the log store: filter by `eventname`, `courseid` and `timecreated` in SQL, then decode `other` and filter on `action`/`changed` in PHP. Order by `timecreated DESC` and stop at the first match.

Put the state lookup in one helper (for example `\aiplacement_modgen\local\section_reorder_status::get_for_course(int $courseid): ?array` returning `state`, `time` and `label`). Both the server render and the poll endpoint use it.

**Initial render.** The toolbar is rendered as a fragment (`aiplacement_modgen_output_fragment_course_toolbar()` in `lib.php` → `course_toolbar` renderable → `course_nav.mustache`). Add a flag for showing the reorder status and the initial status to the renderable and the template, including the template's documented context and example JSON.

**Live updates.**

- New endpoint `ajax/reorder_status.php`, following the existing `ajax/check_job_status.php` pattern: `AJAX_SCRIPT`, `require_login()`, `require_placement_enabled()`, `require_sesskey()`, `ajax_response`. It also checks `moodle/site:config`, then returns `section_reorder_status::get_for_course()`. *(Designer's call: the plugin's existing ajax/ pattern rather than a new `db/services.php` external function, since modgen has none.)*
- New AMD module (for example `amd/src/reorder_status.js`), initialised from the toolbar's JS only when the icon flag is set. It polls every **10 seconds** while the state is queued or running, and stops when it reaches renumbered, blocked or none.
- So the icon appears straight after an admin's own add or delete without a page reload, the module also starts polling when the course editor reports a section was created or deleted. Use the `core_courseformat` reactive state events if they're reachable from the toolbar. Otherwise, start a short poll after any click on the editor's add-section or delete-section controls. The builder can choose how to do this as long as an admin's own edit makes the icon appear within about 10 seconds. Polling must never run for non-admins.

### 4. Strings, version, docs

- Lang strings: the four tooltip states, the icon's accessible name prefix, the `section_order_blocked` event name, and the task name.
- Bump `version.php` (date-based `$plugin->version`), and add an `upgrade.txt` entry.
- README: a short paragraph on automatic renumbering and the admin status icon.

## Tests (PHPUnit)

- Observer: creating a section on a flexsections course queues exactly one task with `nextruntime ≈ now+60`. Creating three in a row still leaves one task, with `nextruntime` moved later. Other formats queue nothing. Deletes behave the same. Two different users editing the same course give one task.
- Observer while running: with a matching row whose `timestarted` is set, a new edit adds a second, not-started row.
- Task: on a deliberately misordered course (reuse the setups in the existing `tests/` integrity tests), the course ends up in order and a `structure_repaired` event with `via = 'adhoctask'` is logged. On an ordered course nothing is written and no event is logged. On a broken hierarchy `section_order_blocked` is logged and nothing is written. A deleted course or a non-flexsections course returns quietly. A lock failure queues a fresh task. A course with `cacherev` under 60 seconds old is not written to and gets a fresh task. The same goes when `cacherev` is bumped between the start of the task and the write. The manual Check Structure renumber behaves exactly as it did before.
- Status helper: returns queued, running, renumbered (within an hour; not after), blocked (within an hour; cleared by a newer renumber) or null, following the precedence above. A manual Check Structure repair counts as renumbered.
- Endpoint: refuses non-admins.

## Left to the builder

- Class and file names (the ones above are suggestions).
- How exactly the observer detects a started task (§1.4), within the constraint stated there.
- How the JS learns that a local section add or delete happened (§3, live updates).
- Exact icon classes, CSS (match the bar's existing translucent-white look; green for renumbered) and string wording.
