# Module Assistant (aiplacement_modgen)

An AI placement plugin for Moodle 4.5+ that helps teachers build the structure of a module: weeks
with pre-session / session / post-session sections inside them, and, in the Flexible sections
format, themes that hold those weeks.

## Course formats

The plugin never changes a course's format; a course in any other format is refused.

| Format | What is built |
|--------|---------------|
| Flexible sections | Themes, weeks and sessions, nested as sections inside sections. |
| Custom sections, Weekly sections | Weeks as sections, each with its three sessions as core subsections. No themes: anything with themes (New Theme(s), a theme CSV, a themed AI structure) is refused, as is a week inside another section. |

Custom sections and Weekly sections are off until an administrator turns on **Enable on core Moodle course formats:
Weekly & Custom sections** in the plugin settings; until then only Flexible sections courses are
built in, as before 0.9.0.

Builds in Custom sections and Weekly sections need the Subsection activity enabled (Site
administration > Plugins > Activity modules > Manage activities); while it is off, the toolbar says
so in place of the Quick Add menu. Core counts subsections towards the site's "Maximum number of
sections" (Course default settings, 52 by default), and each week is four sections, so a build that
would take a course past it is refused.

## What it does

All of it is reached from the **Module Assistant** toolbar, which appears at the top of a course
page when editing is on, in the formats above.

- **Quick Add**
  - *New Theme(s)* creates top-level themes, each with a chosen number of weeks. It is only offered
    at the top of the course, never from inside a section, and only in Flexible sections.
  - *New Week(s)* creates standalone weeks, each with its three session sections.
- **Structure from file** builds a module from a CSV, either uploaded or chosen from a library of
  CSV templates kept by administrators. It does not need AI. The result is always created at the
  top level of the course.
- **Structure from prompt** asks the site's AI provider (through Moodle's core AI subsystem) to
  propose a structure from a prompt and optional CSV. The proposal is shown as a preview and nothing
  is created until the teacher approves it.
- **Check Structure** diagnoses and repairs problems with the section hierarchy (parents, ordering,
  orphans, circular references) in Flexible sections courses. It is not offered in other formats,
  which keep their own hierarchy.
- **Help and Advice** shows links an administrator configures in the plugin settings.

Creation always runs as a background (ad-hoc) task, so large builds do not time out a web request.
A job page shows progress, and a build that finishes with problems says so instead of reporting
success. A build that would take the course over the section limit is refused before it is queued.

## Requirements

- Moodle 4.5 or later (PHP 8.1 or later, as Moodle requires).
- For Custom sections and Weekly sections courses: the Subsection activity (`mod_subsection`, part of
  Moodle 4.5), enabled.
- Optional: the Flexible sections course format (`format_flexsections` 4.1.4 or later, version
  2024120801), for themes and for Check Structure. Flexible sections courses are refused on an
  older version, which takes no lock around deleting a section.
- An AI provider configured in Moodle's AI subsystem, offering *Generate text* and allowed for this
  placement, for *Structure from prompt* only.
- Optional: the Learning Activity module (`mod_learningactivity`). When it is installed, builds can add
  a learning design summary activity to each section; without it that option is not offered.
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
| `aiplacement/modgen:managestructure` | Quick Add: New Theme(s), New Week(s) and Assessments section |
| `aiplacement/modgen:generatefromtemplate` | Structure from file (needs Structure from file enabled) |
| `aiplacement/modgen:generatewithprompt` | Structure from prompt (needs AI enabled) |
| `aiplacement/modgen:checkstructure` | Check Structure and its repair actions |
| `aiplacement/modgen:managetemplates` (system) | Managing the CSV template library |

Toolbar entries only appear for users who hold the matching capability, while the matching feature
switch is on and the course's format can hold what the entry builds. A user with no entry to use
gets no toolbar at all; the help links alone do not show it. Anyone who can start a build can follow
their own build on the job page.

## Configuration

Site administration > AI > AI placements > Module Assistant (Settings):

- **Features**: New Theme(s) and New Week(s), the Assessments section, Structure from file and
  Structure from prompt can each be switched off. Building in Custom sections and Weekly sections is
  off by default and can be switched on.
- **Structure from prompt**: guidance sent to the AI, the time a request may run (default 300
  seconds) and a per-user hourly request limit (default 10).
- **Size limits**: most themes or weeks per Quick Add (30), weeks per theme (5), themes and weeks in
  a CSV file (50), and most sections in a course (400).
- **CSV structure library**: the shared CSV structures teachers can pick in Structure from file.
- **Help and Advice menu**: up to five links shown in the toolbar.
- **Diagnostics**: a link to the site-wide structure diagnostics for Flexible sections courses.

## Logging and background tasks

The plugin writes standard Moodle events, so actions appear in the logs report:

- structure job queued, completed and failed (with the action and the outcome);
- structure repaired (Check Structure and the admin tools);
- template added, updated, deleted and moved.

Scheduled tasks:

- *Clean up old job records* removes finished job records after 30 days.
- *Fail interrupted background jobs* (every five minutes) marks a job as failed when its task has
  been lost or Moodle has stopped retrying it, so a job cannot stay "running" or "will retry" for ever.

## Privacy

The plugin implements the Privacy API. It stores job records (user, course, the action, a summary of
its parameters, and the result), which are deleted with their course. Uploaded CSV files stay in the user's Moodle draft area, which Moodle
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

**The toolbar does not appear**
- Make sure the course uses the Custom sections, Weekly sections or Flexible sections format, and
  that editing is on.

**The toolbar says subsections are switched off**
- Enable the Subsection activity in Site administration > Plugins > Activity modules > Manage
  activities.

**Sections do not appear**
- Make sure cron is running, then purge caches.

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
