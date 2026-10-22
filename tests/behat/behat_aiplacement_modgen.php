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
 * Behat steps for aiplacement_modgen.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, because this file may be loaded before Moodle itself.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');
require_once(__DIR__ . "/../fixtures/structure_snapshot.php");

/**
 * Steps for the plugin's pages.
 */
class behat_aiplacement_modgen extends behat_base {
    /**
     * Open the job status page of the most recent job in a course.
     *
     * @Given /^I am on the job status page of the latest job in "(?P<course_string>[^"]*)"$/
     * @param string $shortname Course short name.
     */
    public function i_am_on_the_job_status_page_of_the_latest_job_in(string $shortname): void {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $jobs = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $courseid], 'id DESC', 'id', 0, 1);
        if (!$jobs) {
            throw new Exception("There is no structure job in the course '$shortname'.");
        }
        $url = new moodle_url('/ai/placement/modgen/job_status.php', ['jobid' => key($jobs)]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Course id for a short name.
     *
     * @param string $shortname Course short name.
     * @return int
     */
    private function course_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Check the stored structure of a course against a table, reading the database directly.
     *
     * Columns: name and parent ("-" for top level) are required, in section order, and every section
     * above section 0 must be listed. Optional columns: collapsed (0 or 1), activities (how many
     * course modules the section holds), depth (1 = top level), visible (0 or 1), summary (text the
     * summary must contain) and activitynames (comma separated names).
     *
     * @Then /^the course "(?P<course_string>[^"]*)" structure should be:$/
     * @param string $shortname Course short name.
     * @param \Behat\Gherkin\Node\TableNode $table Expected structure.
     */
    public function the_course_structure_should_be(string $shortname, \Behat\Gherkin\Node\TableNode $table): void {
        $expected = [];
        foreach ($table->getHash() as $row) {
            if (isset($row['activitynames'])) {
                $row['activitynames'] = $row['activitynames'] === '' ? [] : array_map('trim', explode(',', $row['activitynames']));
            }
            $expected[] = $row;
        }

        $problems = aiplacement_modgen_structure_snapshot::diff($this->course_id($shortname), $expected);
        if ($problems) {
            throw new Exception("The structure of '$shortname' differs:\n" . implode("\n", $problems));
        }
    }

    /**
     * Check that a course satisfies every Flexible sections rule: numbering, parents, order, depth, modules.
     *
     * @Then /^the course "(?P<course_string>[^"]*)" structure should be sound$/
     * @param string $shortname Course short name.
     */
    public function the_course_structure_should_be_sound(string $shortname): void {
        $courseid = $this->course_id($shortname);
        $problems = aiplacement_modgen_structure_snapshot::problems($courseid);
        $check = \aiplacement_modgen\local\integrity_checker::check($courseid);
        if ($check['has_issues']) {
            $problems[] = "The plugin's integrity checker reports issues: " . json_encode($check['counts']);
        }
        if ($problems) {
            throw new Exception("The structure of '$shortname' is not sound:\n" . implode("\n", $problems));
        }
    }

    /**
     * Check how many sections a course has above section 0.
     *
     * @Then /^the course "(?P<course_string>[^"]*)" should have "(?P<count_number>\d+)" sections$/
     * @param string $shortname Course short name.
     * @param int $count Expected number.
     */
    public function the_course_should_have_sections(string $shortname, int $count): void {
        $actual = count(aiplacement_modgen_structure_snapshot::rows($this->course_id($shortname)));
        if ($actual !== $count) {
            throw new Exception("'$shortname' has $actual sections above section 0, expected $count.");
        }
    }

    /**
     * Build themes straight away, without the browser, as set-up for scenarios about something else.
     *
     * @Given /^the course "(?P<course_string>[^"]*)" has "(?P<themes_number>\d+)" themes with "(?P<weeks_number>\d+)" weeks each$/
     * @param string $shortname Course short name.
     * @param int $themes Number of themes.
     * @param int $weeks Weeks per theme.
     */
    public function the_course_has_themes_with_weeks(string $shortname, int $themes, int $weeks): void {
        \core\session\manager::set_user(get_admin());
        \aiplacement_modgen\local\theme_builder::create_themes($this->course_id($shortname), $themes, $weeks, 0, false);
    }

    /**
     * Build standalone weeks straight away, without the browser.
     *
     * @Given /^the course "(?P<course_string>[^"]*)" has "(?P<weeks_number>\d+)" standalone weeks$/
     * @param string $shortname Course short name.
     * @param int $weeks Number of weeks.
     */
    public function the_course_has_standalone_weeks(string $shortname, int $weeks): void {
        \core\session\manager::set_user(get_admin());
        \aiplacement_modgen\local\theme_builder::create_weeks($this->course_id($shortname), $weeks, 0, false);
    }

    /**
     * Check how many structure jobs a course has queued or run.
     *
     * @Then /^there should be "(?P<count_number>\d+)" structure jobs in "(?P<course_string>[^"]*)"$/
     * @param int $count Expected number.
     * @param string $shortname Course short name.
     */
    public function there_should_be_structure_jobs_in(int $count, string $shortname): void {
        global $DB;
        $actual = $DB->count_records('aiplacement_modgen_jobs', ['courseid' => $this->course_id($shortname)]);
        if ($actual !== $count) {
            throw new Exception("'$shortname' has $actual structure jobs, expected $count.");
        }
    }

    /**
     * Break a section's stored parent, to give Check Structure something to find.
     *
     * @Given /^section "(?P<section_string>[^"]*)" of "(?P<course_string>[^"]*)" has parent "(?P<parent_number>\d+)"$/
     * @param string $name Section name (the first one with that name).
     * @param string $shortname Course short name.
     * @param int $parent Section number to store as the parent.
     */
    public function the_section_has_its_parent_set_in_the_database(string $name, string $shortname, int $parent): void {
        global $DB;

        $courseid = $this->course_id($shortname);
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'name' => $name], '*', IGNORE_MULTIPLE);
        if (!$section) {
            throw new Exception("There is no section called '$name' in '$shortname'.");
        }
        $DB->set_field('course_format_options', 'value', (string) $parent, [
            'courseid' => $courseid, 'sectionid' => $section->id, 'name' => 'parent',
        ]);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Log in as a teacher and open the Structure from file form, accepting the AI policy on the way.
     *
     * @Given /^I open the Structure from file form in "(?P<course_string>[^"]*)" as "(?P<user_string>[^"]*)"$/
     * @param string $shortname Course short name.
     * @param string $username Username to log in as.
     */
    public function i_open_the_structure_from_file_form_in_as(string $shortname, string $username): void {
        $this->execute('behat_auth::i_log_in_as', [$username]);
        $courseid = $this->course_id($shortname);
        $this->getSession()->visit($this->locate_path('/course/view.php?id=' . $courseid));
        $this->execute('behat_navigation::i_turn_editing_mode_on');
        $this->execute('behat_general::i_click_on', ['Quick Add', 'link']);
        $this->execute('behat_general::i_click_on', ['Structure from file', 'link']);
        $this->execute('behat_general::i_click_on', ['I agree to the terms of AI use in this system', 'checkbox']);
        $this->execute('behat_forms::press_button', ['Accept']);
    }

    /**
     * Check the course format of a course.
     *
     * @Then /^the course "(?P<course_string>[^"]*)" should use the "(?P<format_string>[^"]*)" format$/
     * @param string $shortname Course short name.
     * @param string $format Format plugin name.
     */
    public function the_course_should_use_the_format(string $shortname, string $format): void {
        global $DB;
        $actual = $DB->get_field('course', 'format', ['id' => $this->course_id($shortname)], MUST_EXIST);
        if ($actual !== $format) {
            throw new Exception("'$shortname' uses the '$actual' format, expected '$format'.");
        }
    }

    /**
     * Check the most recent structure job in a course.
     *
     * Rows are field | value. Fields: action, status, error, warnings (how many), messages (how many),
     * and param.NAME for any queued parameter (true is 1, false is 0).
     *
     * @Then /^the latest structure job in "(?P<course_string>[^"]*)" should have:$/
     * @param string $shortname Course short name.
     * @param \Behat\Gherkin\Node\TableNode $table Expected values.
     */
    public function the_latest_structure_job_should_have(string $shortname, \Behat\Gherkin\Node\TableNode $table): void {
        global $DB;

        $jobs = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $this->course_id($shortname)], 'id DESC', '*', 0, 1);
        if (!$jobs) {
            throw new Exception("There is no structure job in '$shortname'.");
        }
        $job = reset($jobs);
        $params = json_decode($job->parameters, true) ?: [];
        $result = json_decode((string) $job->result, true) ?: [];

        foreach ($table->getRowsHash() as $field => $expected) {
            if (strpos($field, 'param.') === 0) {
                $actual = $params[substr($field, 6)] ?? '';
            } else if ($field === 'warnings' || $field === 'messages') {
                $actual = count($result[$field] ?? []);
            } else if ($field === 'error') {
                $actual = $result['error'] ?? '';
            } else {
                $actual = $job->$field ?? '';
            }
            if (is_bool($actual)) {
                $actual = $actual ? '1' : '0';
            }
            if ((string) $actual !== (string) $expected) {
                throw new Exception("Job field '$field' is '$actual', expected '$expected'.");
            }
        }
    }
}
