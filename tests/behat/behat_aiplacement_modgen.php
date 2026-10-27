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
     * Add a top-level section with a given name straight away, without the browser.
     *
     * @Given /^the course "(?P<course_string>[^"]*)" has a top-level section called "(?P<name_string>[^"]*)"$/
     * @param string $shortname Course short name.
     * @param string $name Section name.
     */
    public function the_course_has_a_top_level_section_called(string $shortname, string $name): void {
        \core\session\manager::set_user(get_admin());
        $courseid = $this->course_id($shortname);
        \aiplacement_modgen\local\theme_builder::create_section_with_parent(
            $courseid,
            course_get_format($courseid),
            0,
            $name,
            '',
            FORMAT_PLAIN
        );
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

    /**
     * Open the Check Structure page of a course, with the check already run.
     *
     * Used when the course page itself cannot be shown, for example while the hierarchy is broken.
     *
     * @Given /^I am on the Check Structure page of "(?P<course_string>[^"]*)"$/
     * @param string $shortname Course short name.
     */
    public function i_am_on_the_check_structure_page_of(string $shortname): void {
        $url = new moodle_url('/ai/placement/modgen/check_structure.php', ['id' => $this->course_id($shortname), 'check' => 1]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Add a section under another without renumbering, as a build that is still running would leave it.
     *
     * @Given /^section "(?P<name_string>[^"]*)" was added under "(?P<p_string>[^"]*)" in "(?P<course_string>[^"]*)" unnumbered$/
     * @param string $name New section name.
     * @param string $parentname Name of the section to add it under (the first one with that name).
     * @param string $shortname Course short name.
     */
    public function a_section_was_added_without_renumbering(string $name, string $parentname, string $shortname): void {
        global $DB;

        $courseid = $this->course_id($shortname);
        $parent = $DB->get_record('course_sections', ['course' => $courseid, 'name' => $parentname], '*', IGNORE_MULTIPLE);
        if (!$parent) {
            throw new Exception("There is no section called '$parentname' in '$shortname'.");
        }
        \core\session\manager::set_user(get_admin());
        $course = get_course($courseid);
        \aiplacement_modgen\local\theme_builder::create_section_with_parent(
            $courseid,
            course_get_format($course),
            (int) $parent->section,
            $name,
            '',
            FORMAT_PLAIN,
            ['collapsed' => 0],
            true
        );
    }

    /**
     * Leave format options behind for a section that no longer exists.
     *
     * @Given /^leftover format options exist in "(?P<course_string>[^"]*)" for a section that no longer exists$/
     * @param string $shortname Course short name.
     */
    public function leftover_format_options_exist(string $shortname): void {
        global $DB;
        $DB->insert_record('course_format_options', (object) [
            'courseid' => $this->course_id($shortname), 'format' => 'flexsections', 'sectionid' => 987654,
            'name' => 'parent', 'value' => '0',
        ]);
    }

    /**
     * Fill a field with a course's id, for pages that ask for one.
     *
     * @When /^I set the field "(?P<field_string>[^"]*)" to the id of course "(?P<course_string>[^"]*)"$/
     * @param string $field Field label.
     * @param string $shortname Course short name.
     */
    public function i_set_the_field_to_the_id_of_course(string $field, string $shortname): void {
        $this->execute('behat_forms::i_set_the_field_to', [$field, (string) $this->course_id($shortname)]);
    }

    /**
     * Fill a field found by XPath with a course's id (for pages with several fields of the same name).
     *
     * @When /^I set the field with xpath "(?P<xpath_string>[^"]*)" to the id of course "(?P<course_string>[^"]*)"$/
     * @param string $xpath XPath of the field.
     * @param string $shortname Course short name.
     */
    public function i_set_the_field_with_xpath_to_the_id_of_course(string $xpath, string $shortname): void {
        $this->execute('behat_forms::i_set_the_field_with_xpath_to', [$xpath, (string) $this->course_id($shortname)]);
    }

    /**
     * Hide a section in the database, without going through the course page.
     *
     * @Given /^section "(?P<section_string>[^"]*)" of "(?P<course_string>[^"]*)" is hidden in the database$/
     * @param string $name Section name (the first one with that name).
     * @param string $shortname Course short name.
     */
    public function section_is_hidden_in_the_database(string $name, string $shortname): void {
        global $DB;
        $courseid = $this->course_id($shortname);
        $section = $DB->get_record('course_sections', ['course' => $courseid, 'name' => $name], '*', IGNORE_MULTIPLE);
        if (!$section) {
            throw new Exception("There is no section called '$name' in '$shortname'.");
        }
        $DB->set_field('course_sections', 'visible', 0, ['id' => $section->id]);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Check the raw body of the last response, which matters for downloads that are not shown as pages.
     *
     * @Then /^the response body should contain "(?P<text_string>[^"]*)"$/
     * @param string $text Expected text.
     */
    public function the_response_body_should_contain(string $text): void {
        $body = $this->getSession()->getDriver()->getContent();
        if (strpos($body, $text) === false) {
            throw new Exception("The response does not contain '$text'. It starts: " . substr(strip_tags($body), 0, 200));
        }
    }

    /**
     * Finish the most recent job in a course successfully, as its task would on a later attempt.
     *
     * @Given /^the latest structure job in "(?P<course_string>[^"]*)" completes successfully$/
     * @param string $shortname Course short name.
     */
    public function the_latest_structure_job_completes_successfully(string $shortname): void {
        global $DB;

        $jobs = $DB->get_records('aiplacement_modgen_jobs', ['courseid' => $this->course_id($shortname)], 'id DESC', '*', 0, 1);
        if (!$jobs) {
            throw new Exception("There is no structure job in the course '$shortname'.");
        }
        $job = reset($jobs);
        $job->status = 'completed';
        $job->result = json_encode(['success' => true, 'messages' => ['Section created: Week 1'], 'warnings' => []]);
        $job->timecompleted = time();
        $DB->update_record('aiplacement_modgen_jobs', $job);
    }

    /**
     * POST straight to one of the plugin's endpoints from the current page, as a crafted request
     * would, bypassing whatever the UI offers. The course id and the user's sesskey are added.
     *
     * @When /^I post "(?P<params_string>[^"]*)" to the modgen endpoint "(?P<path_string>[^"]*)" for "(?P<course_string>[^"]*)"$/
     * @param string $params Query-string encoded fields, e.g. "action=create_weeks&weekcount=2".
     * @param string $path Path under /ai/placement/modgen/, e.g. "ajax/create_sections.php".
     * @param string $shortname Course short name.
     */
    public function i_post_to_the_modgen_endpoint(string $params, string $path, string $shortname): void {
        $courseid = $this->course_id($shortname);
        $session = $this->getSession();
        $session->executeScript('window.modgenPostResponse = null;
            const body = new URLSearchParams(' . json_encode($params) . ');
            body.set("courseid", ' . $courseid . ');
            body.set("sesskey", M.cfg.sesskey);
            fetch(M.cfg.wwwroot + "/ai/placement/modgen/" + ' . json_encode($path) . ', {method: "POST", body: body})
                .then(r => r.text())
                .then(t => { window.modgenPostResponse = t; })
                .catch(e => { window.modgenPostResponse = "FETCH FAILED: " + e; });');
        $this->spin(
            fn() => $session->evaluateScript('return window.modgenPostResponse !== null;'),
            [],
            behat_base::get_extended_timeout()
        );
    }

    /**
     * Check the body returned by the last "I post ... to the modgen endpoint" step.
     *
     * @Then /^the modgen endpoint response should contain "(?P<text_string>[^"]*)"$/
     * @param string $text Expected text.
     */
    public function the_modgen_endpoint_response_should_contain(string $text): void {
        $body = (string) $this->getSession()->evaluateScript('return window.modgenPostResponse;');
        if (strpos($body, $text) === false) {
            throw new Exception("The endpoint response does not contain '$text'. It was: " . substr($body, 0, 300));
        }
    }
}
