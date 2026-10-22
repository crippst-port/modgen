# Module Assistant (aiplacement_modgen)

An AI placement plugin for Moodle 4.5+ that helps teachers build the structure of a module in the
Flexible sections course format: themes, weeks and the pre-session / session / post-session
sections inside them.

## What it does

All of it is reached from the **Module Assistant** toolbar, which appears at the top of a course
page when editing is on (course pages using the Flexible sections format only).

- **Quick Add**
  - *New Theme(s)* creates top-level themes, each with a chosen number of weeks. It is only offered
    at the top of the course, never from inside a section.
  - *New Week(s)* creates standalone weeks, each with its three session sections.
- **Structure from file** builds a module from a CSV, either uploaded or chosen from a library of
  CSV templates kept by administrators. It does not need AI. The result is always created at the
  top level of the course.
- **Structure from prompt** asks the site's AI provider (through Moodle's core AI subsystem) to
  propose a structure from a prompt and optional CSV. The proposal is shown as a preview and nothing
  is created until the teacher approves it.
- **AI-generated activities** lists the activities the plugin created so they can be reviewed,
  hidden or opened. A marker disappears once the activity is edited.
- **Check Structure** diagnoses and repairs problems with the section hierarchy (parents, ordering,
  orphans, circular references).
- **Help and Advice** shows links an administrator configures in the plugin settings.

Creation always runs as a background (ad-hoc) task, so large builds do not time out a web request.
A job page shows progress, and a build that finishes with problems says so instead of reporting
success. A build that would take the course over the section limit is refused before it is queued.

## Requirements

- Moodle 4.5 or later (PHP 8.1 or later, as Moodle requires).
- The Flexible sections course format (`format_flexsections` 4.1.4 or later, version 2024120801).
  It is a hard dependency; the plugin only builds into courses using that format.
- An AI provider configured in Moodle's AI subsystem, for *Structure from prompt* only.
- Cron running, for background jobs.

## Installation

1. Place the plugin in `{moodle}/ai/placement/modgen`, or install the ZIP through
   Site administration > Plugins > Install plugins.
2. Run `php admin/cli/upgrade.php`, or visit Site administration > Notifications.
3. Enable the placement in Site administration > AI > AI placements.

## Permissions

Nothing is granted by default; assign these in the course (or system) context.

| Capability | Allows |
|------------|--------|
| `aiplacement/modgen:managestructure` | Quick Add: New Theme(s) and New Week(s) |
| `aiplacement/modgen:generatefromtemplate` | Structure from file, and the AI-generated activities list |
| `aiplacement/modgen:generatewithprompt` | Structure from prompt (needs AI enabled), and the AI-generated activities list |
| `aiplacement/modgen:checkstructure` | Check Structure and its repair actions |
| `aiplacement/modgen:managetemplates` (system) | Managing the CSV template library |

Toolbar entries only appear for users who hold the matching capability. Anyone who can start a build can follow their own build on the job page.

## Configuration

Site administration > AI > AI placements > Module Assistant (Settings):

- **Feature switches**: AI generation, Quick Add and Structure from file can each be turned off.
- **AI generation**: base prompt, timeout (default 300 seconds) and a per-user hourly request limit
  (default 10).
- **Section limits**: maximum themes or weeks per Quick Add (30), weeks per theme (5), sections in a
  CSV, and a total section cap per course (default 400) that protects courses from builds that
  would make Flexible sections very slow.
- **CSV template library** and **Help links**: manage the shared templates and up to five links
  shown in the toolbar.
- **Admin tools**: a link to the site-wide structure diagnostics.

## Logging and background tasks

The plugin writes standard Moodle events, so actions appear in the logs report:

- structure job queued, completed and failed (with the action and the outcome);
- structure repaired (Check Structure and the admin tools);
- template added, updated, deleted and moved.

Scheduled tasks:

- *Clean up old job records* removes finished job records after 30 days and stale
  AI-generated markers.
- *Fail interrupted background jobs* (every five minutes) marks a job as failed when its task has
  been lost, so a job cannot stay "running" for ever.

## Privacy

The plugin implements the Privacy API. It stores job records (user, course, the action, a summary of
its parameters, and the result). Uploaded CSV files stay in the user's Moodle draft area, which Moodle
cleans up, and are not kept by the plugin; only files an administrator adds to the template library
are stored. Prompts sent to an AI provider are handled by Moodle's AI subsystem. Course content
created by the plugin belongs to the course and is not removed when a user is deleted.

## Development

### Running tests

```bash
# Initialise PHPUnit, then run the plugin's tests
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite aiplacement_modgen_testsuite

# Behat (browser) tests, once the Behat site is initialised
php admin/tool/behat/cli/init.php
vendor/bin/behat --config /path/to/behatrun/behat/behat.yml --tags @aiplacement_modgen
```

### Code quality

```bash
# Uses phpcs.xml.dist, which applies the moodle-extra standard
cd ai/placement/modgen && phpcs

# Auto-fix what can be fixed mechanically
phpcbf
```

### Building JavaScript

The plugin uses Moodle's own Grunt setup, so it has no Gruntfile or package.json of its own.
From the Moodle root, use the Node version in `.nvmrc` and install the dependencies once:

```bash
nvm install && nvm use
npm install
```

After changing anything in `amd/src`, lint and rebuild `amd/build` (commit the result):

```bash
cd ai/placement/modgen/amd
npx grunt amd
```

## Troubleshooting

**"Another user is currently creating content in this course"**
- A build is running on that course. Wait for it to finish and try again.

**A job stays queued**
- Check that cron is running; builds run as ad-hoc tasks.

**A job finished "with problems"**
- The job page lists each item that could not be created. The rest of the structure was built.

**"AI generation failed"**
- Verify the AI provider is configured in Site administration > AI, and check its credentials and
  quota.

**Sections do not appear**
- Make sure the course uses the Flexible sections format, then purge caches.

## Changes

See `upgrade.txt` for the notes for each version.

## Support

- Email: tom.cripps@port.ac.uk

## License

Copyright 2025 Tom Cripps <tom.cripps@port.ac.uk>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.

## Credits

Developed by Tom Cripps for the University of Portsmouth.

Built with:
- Moodle AI Subsystem
- format_flexsections by Marina Glancy
