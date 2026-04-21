# Cleanup and Polish — Design Spec
Date: 2026-04-21
Branch: work from `assistantbar`

## Overview

Two sequential passes to clean up the plugin before wider rollout:

- **Pass 1** — Dead code and explore feature removal (low risk, no user-facing changes)
- **Pass 2** — CSV upload and Quick Add UX polish (reliability + user feedback)

Do Pass 1 first. It reduces noise in the Pass 2 diff and removes files with no place in the rollout codebase.

---

## Pass 1: Dead Code Removal

### Files to delete entirely

| File | Reason |
|------|--------|
| `amd/src/json_handler.js` | No callers anywhere in the codebase |
| `amd/src/modal_generator_reactive.js.backup` | Backup file with no purpose in the repo |
| `amd/src/modal_generator_reactive_es6.js.bak` | Old backup, superseded |
| `explore.php` | Explore feature removed |
| `ajax/explore_ajax.php` | Explore feature removed |
| `amd/src/explore.js` | Explore feature removed |
| `amd/build/explore.min.js` | Build artifact for removed explore feature |
| `classes/local/explore_cache.php` | Explore feature removed |
| `templates/modal_tabbed.mustache` | Never rendered; leftover from earlier development |

Also check whether these exist before deleting — they were found in the worktree but may not be in `assistantbar`:
- `templates/improvements.mustache`
- `templates/insights_summary.mustache`
- `classes/form/upload_form.php`

### Code to remove from existing files

**`lib.php`** — Remove the explore toolbar button block:
```php
if (get_config('aiplacement_modgen', 'enable_exploration')) {
    $exploreurl = new moodle_url('/ai/placement/modgen/explore.php', ['id' => $course->id]);
    $navigation->add(
        get_string('exploremenuitem', 'aiplacement_modgen'),
        $exploreurl,
        navigation_node::TYPE_SETTING,
        null,
        'aiplacement_modgen_explore'
    );
}
```

**`settings.php`** — Remove the `enable_exploration` admin setting (search for `aiplacement_modgen/enable_exploration`).

**`lang/en/aiplacement_modgen.php`** — Remove all `$string['explore*']` entries (around lines 246–260).

### Verification after Pass 1

- No PHP errors on course pages (toolbar still renders correctly)
- No broken navigation links
- Search confirms no remaining references:
  ```
  grep -r "explore\|json_handler\|modal_tabbed" . --include="*.php" --include="*.js" --include="*.mustache" --exclude-dir=".git" --exclude-dir="node_modules"
  ```

---

## Pass 2: CSV Upload and Quick Add Polish

### 2a. CSV Upload — validation and error handling

**Problem:** Malformed CSVs and exceeded section limits produce either silent failures or raw PHP exceptions shown to users.

**Changes:**

1. **Validate before parsing** — in `csv_parser::parse_csv_to_structure()`, improve the upfront checks:
   - If no valid data rows are found after skipping blank/empty lines, throw a translated exception: _"No valid rows were found in the CSV file. Please check the format and try again."_
   - If the section count limit would be exceeded, throw a translated exception: _"Your CSV contains X sections, which exceeds the limit of Y. Please reduce the number of weeks or themes and try again."_ (the limit check already exists but the message may be unclear — improve the wording).

2. **Catch CSV exceptions in the form handler** — in `prompt.php`, wrap the call to `csv_parser::parse_csv_to_structure()` in a try/catch that renders the error back into the form as a Moodle error notification, not a blank page or stack trace.

3. **Surface the limit in the UI** — in `generator_form.php`, add a small hint below the CSV file upload field: _"Maximum X themes/sections per file."_ Read the limit from `get_config('aiplacement_modgen', 'maxcsvsections')`.

### 2b. Quick Add — remaining rough edges

**Note:** The core flow already works well — modal closes on submission, user is redirected to `job_status.php`, which polls for completion and auto-redirects back to the course. No changes needed there.

**Remaining issues:**

1. **`checkCompletedJobs` uses sessionStorage** — `course_toolbar.js` tracks notified job IDs in `sessionStorage`. If the user closes and reopens the browser, previously failed job notifications are lost. Since the `job_status.php` page already handles the primary feedback loop, this secondary mechanism on the course page is low priority, but the fix is: store a `notified_at` timestamp in the job DB record (add a nullable `INT` column `notifiedtime` to `aiplacement_modgen_jobs`). Mark it on display. On course page load, fetch only unnotified failed jobs rather than relying on sessionStorage.

2. **Auto-reload commented out on course page** — `course_toolbar.js` line 81 says _"Auto-reload removed - user can manually refresh to see new sections."_ If a user returns to the course page after a job completes (e.g. via browser back), they won't see the new sections until they manually refresh. Reinstate `window.location.reload()` when a job transitions from `running`/`queued` to `completed` in the `checkCompletedJobs` poll — but only if the page is visible (use `document.visibilityState === 'visible'` guard to avoid reloading a background tab).

3. **Failed job error shown as dismissible toast** — currently a failed job notification is a small toast that can be scrolled past. For a failed Quick Add, show a Moodle-style persistent error alert at the top of the page using `\core\notification::error()` server-side, or the JS equivalent `Notification.addNotification({type: 'error', ...})`.

---

## What is NOT in scope

- Rollback / transaction handling for partial section creation
- AI generation error handling improvements
- New CSV features (template download, intermediate preview step)
- Parent section selection in Quick Add
- Any new admin settings beyond the `notifiedtime` column

---

## Order of implementation

1. Pass 1 (delete files + remove references) → rebuild JS (`grunt`) → verify no errors
2. Pass 2a (CSV validation) → commit
3. Pass 2b (Quick Add feedback) → commit

Each step is independently deployable.

---

## Files touched summary

| File | Pass | Change |
|------|------|--------|
| `amd/src/json_handler.js` | 1 | Delete |
| `amd/src/modal_generator_reactive.js.backup` | 1 | Delete |
| `amd/src/modal_generator_reactive_es6.js.bak` | 1 | Delete |
| `explore.php` | 1 | Delete |
| `ajax/explore_ajax.php` | 1 | Delete |
| `amd/src/explore.js` | 1 | Delete |
| `amd/build/explore.min.js` | 1 | Delete |
| `classes/local/explore_cache.php` | 1 | Delete |
| `templates/modal_tabbed.mustache` | 1 | Delete |
| `lib.php` | 1 | Remove explore block |
| `settings.php` | 1 | Remove enable_exploration setting |
| `lang/en/aiplacement_modgen.php` | 1 | Remove explore strings |
| `classes/local/csv_parser.php` | 2a | Improve validation error messages |
| `prompt.php` | 2a | Catch CSV exceptions, render as user error |
| `classes/form/generator_form.php` | 2a | Add section limit hint to CSV upload field |
| `db/upgrade.php` + `db/install.xml` | 2b | Add `notifiedtime` column to jobs table |
| `amd/src/course_toolbar.js` | 2b | Use DB-backed notified state; reinstate reload with visibility guard |
| `amd/build/course_toolbar.min.js` | 2b | Rebuild via grunt |
