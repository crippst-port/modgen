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
     * Check that a course satisfies every rule for its format: numbering, parents or subsections, order,
     * depth, modules. Flexible sections courses also go through the plugin's integrity checker.
     *
     * @Then /^the course "(?P<course_string>[^"]*)" structure should be sound$/
     * @param string $shortname Course short name.
     */
    public function the_course_structure_should_be_sound(string $shortname): void {
        global $DB;
        $courseid = $this->course_id($shortname);
        $problems = aiplacement_modgen_structure_snapshot::problems($courseid);
        if ($DB->get_field('course', 'format', ['id' => $courseid]) === 'flexsections') {
            $check = \aiplacement_modgen\local\integrity_checker::check($courseid);
            if ($check['has_issues']) {
                $problems[] = "The plugin's integrity checker reports issues: " . json_encode($check['counts']);
            }
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
     * Go straight to the Structure from file page of a course by its address, as a bookmark would.
     *
     * @Given /^I visit the Structure from file page of "(?P<course_string>[^"]*)"$/
     * @param string $shortname Course short name.
     */
    public function i_visit_the_structure_from_file_page_of(string $shortname): void {
        $this->getSession()->visit($this->locate_path('/ai/placement/modgen/prompt.php?id=' . $this->course_id($shortname)));
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
     * Switch the Subsection activity on or off, which builds in Custom sections and Weekly sections need.
     *
     * @Given /^subsections are (?P<state_string>enabled|disabled)$/
     * @param string $state enabled or disabled.
     */
    public function subsections_are(string $state): void {
        \core\plugininfo\mod::enable_plugin('subsection', $state === 'enabled' ? 1 : 0);
        \core_plugin_manager::reset_caches();
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

    /**
     * Check the body returned by the last "I post ... to the modgen endpoint" step leaves something out.
     *
     * @Then /^the modgen endpoint response should not contain "(?P<text_string>[^"]*)"$/
     * @param string $text Text that must not appear.
     */
    public function the_modgen_endpoint_response_should_not_contain(string $text): void {
        $body = (string) $this->getSession()->evaluateScript('return window.modgenPostResponse;');
        if (strpos($body, $text) !== false) {
            throw new Exception("The endpoint response contains '$text'. It was: " . substr($body, 0, 300));
        }
    }

    /**
     * Queue the automatic section renumber for a course, as a section add or delete would.
     *
     * @Given /^the automatic section renumber for "(?P<course_string>[^"]*)" is queued$/
     * @param string $shortname Course short name.
     */
    public function the_automatic_section_renumber_is_queued(string $shortname): void {
        \aiplacement_modgen\local\section_reorder_queue::schedule($this->course_id($shortname));
    }

    /**
     * Mark the queued renumber for a course as started, as cron does when it picks it up.
     *
     * @Given /^the automatic section renumber for "(?P<course_string>[^"]*)" is running$/
     * @param string $shortname Course short name.
     */
    public function the_automatic_section_renumber_is_running(string $shortname): void {
        global $DB;

        $rows = \aiplacement_modgen\local\section_reorder_queue::get_task_rows($this->course_id($shortname));
        if (!$rows) {
            throw new Exception("There is no section renumber queued for '$shortname'.");
        }
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => reset($rows)->id]);
    }

    /**
     * Run the queued renumber for a course now, as if the course had been quiet for a while.
     *
     * Makes the task due and the course's last edit old enough, runs the course's renumber tasks
     * as cron would, and flushes the log so the outcome is readable straight away.
     *
     * @When /^the automatic section renumber for "(?P<course_string>[^"]*)" runs$/
     * @param string $shortname Course short name.
     */
    public function the_automatic_section_renumber_runs(string $shortname): void {
        global $DB;

        $courseid = $this->course_id($shortname);
        $rows = \aiplacement_modgen\local\section_reorder_queue::get_task_rows($courseid);
        if (!$rows) {
            throw new Exception("There is no section renumber queued for '$shortname'.");
        }
        $wait = \aiplacement_modgen\local\section_reorder_queue::DELAY * 2;
        // The task reads the last edit from the standard log: write out what set-up logged, then age it.
        // cacherev is left alone: a site never moves it backwards, and Flexible sections then reads a
        // stale copy of the course on the next add from a page that is still open.
        get_log_manager(true);
        $DB->set_field('logstore_standard_log', 'timecreated', time() - $wait, ['courseid' => $courseid]);
        foreach ($rows as $row) {
            $DB->set_field('task_adhoc', 'nextruntime', time() - 1, ['id' => $row->id]);
        }

        // Only this course's renumber tasks, through cron's own runner: running every due task would
        // also run whatever else the scenario's set-up queued, and fail on that instead.
        \core\cron::setup_user();
        foreach (array_keys($rows) as $taskid) {
            $task = \core\task\manager::get_adhoc_task($taskid);
            if (!$task) {
                throw new Exception("The section renumber task $taskid for '$shortname' is locked.");
            }
            ob_start();
            \core\cron::run_inner_adhoc_task($task);
            $output = ob_get_clean();
            if ($DB->record_exists('task_adhoc', ['id' => $taskid])) {
                throw new Exception("The section renumber task for '$shortname' failed. Its output:\n\n" . $output);
            }
        }
        get_log_manager(true);
    }

    /**
     * Log a refused renumber for a course, as the task does when the section hierarchy is broken.
     *
     * @Given /^the automatic section renumber for "(?P<course_string>[^"]*)" was refused$/
     * @param string $shortname Course short name.
     */
    public function the_automatic_section_renumber_was_refused(string $shortname): void {
        \aiplacement_modgen\event\section_order_blocked::for_course($this->course_id($shortname), 'brokenhierarchy')->trigger();
        get_log_manager(true);
    }

    /**
     * Drop any renumber tasks queued for a course, such as the one creating the course queued.
     *
     * @Given /^the section renumber queue for "(?P<course_string>[^"]*)" is empty$/
     * @param string $shortname Course short name.
     */
    public function the_section_renumber_queue_is_empty(string $shortname): void {
        global $DB;

        $rows = \aiplacement_modgen\local\section_reorder_queue::get_task_rows($this->course_id($shortname));
        if ($rows) {
            $DB->delete_records_list('task_adhoc', 'id', array_keys($rows));
        }
    }

    /**
     * Check how many renumber tasks are waiting for a course.
     *
     * @Then /^there should be "(?P<count_number>\d+)" section renumbers queued for "(?P<course_string>[^"]*)"$/
     * @param int $count Expected number of task rows.
     * @param string $shortname Course short name.
     */
    public function there_should_be_section_renumbers_queued_for(int $count, string $shortname): void {
        $actual = count(\aiplacement_modgen\local\section_reorder_queue::get_task_rows($this->course_id($shortname)));
        if ($actual !== $count) {
            throw new Exception("Expected $count section renumber(s) queued for '$shortname', found $actual.");
        }
    }

    /**
     * Check how many renumbers that changed something the standard log holds for a course.
     *
     * @Then /^the modgen log for "(?P<course_string>[^"]*)" should have "(?P<count_number>\d+)" renumbers?$/
     * @param string $shortname Course short name.
     * @param int $count Expected number of renumbers.
     */
    public function the_modgen_log_should_have_renumbers(string $shortname, int $count): void {
        global $DB;

        get_log_manager(true);
        $rows = $DB->get_records('logstore_standard_log', [
            'courseid' => $this->course_id($shortname),
            'eventname' => '\\aiplacement_modgen\\event\\structure_repaired',
        ], '', 'id, other');
        $actual = count(array_filter($rows, fn($row) => (json_decode($row->other, true)['action'] ?? '') === 'fixsectionorder'));
        if ($actual !== $count) {
            throw new Exception("Expected $count renumber(s) in the log for '$shortname', found $actual.");
        }
    }

    /**
     * Wait for the assistant bar's section reorder icon to show a state.
     *
     * The icon polls every 10 seconds while a renumber is queued or running and every 30 seconds otherwise,
     * so this waits longer than the standard timeouts.
     *
     * @Then /^the section reorder status should show "(?P<state_string>queued|running|renumbered|checked|blocked)"$/
     * @param string $state Expected state.
     */
    public function the_section_reorder_status_should_show(string $state): void {
        $selector = '[data-region="aiplacement-modgen-reorder-status"] .aiplacement-modgen-reorder-icon--' . $state;
        $this->spin(
            function () use ($selector) {
                return $this->getSession()->getPage()->find('css', $selector) !== null;
            },
            [],
            45,
            new Exception("The section reorder status did not show \"$state\" within 45 seconds.")
        );
    }

    /** @var \core\lock\lock[] Locks held by "the sections of ... are being changed elsewhere". */
    private static array $heldsectionlocks = [];

    /**
     * Hold the lock Flexible sections takes around a section delete, as another change would.
     *
     * Held by the Behat process, so to the site under test it is someone else's change.
     *
     * @Given /^the sections of "(?P<course_string>[^"]*)" are being changed elsewhere$/
     * @param string $shortname Course short name.
     */
    public function the_sections_of_are_being_changed_elsewhere(string $shortname): void {
        $courseid = $this->course_id($shortname);
        $lock = \core\lock\lock_config::get_lock_factory(
            \aiplacement_modgen\local\course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE
        )->get_lock(\aiplacement_modgen\local\course_formats::flexsections_delete_lock_key($courseid), 5);
        if (!$lock) {
            throw new Exception("Could not take the section lock of the course '$shortname'.");
        }
        self::$heldsectionlocks[$shortname] = $lock;
    }

    /**
     * Let go of the lock taken by "the sections of ... are being changed elsewhere".
     *
     * @When /^the other change to the sections of "(?P<course_string>[^"]*)" finishes$/
     * @param string $shortname Course short name.
     */
    public function the_other_change_to_the_sections_of_finishes(string $shortname): void {
        if (empty(self::$heldsectionlocks[$shortname])) {
            throw new Exception("No change to the sections of '$shortname' is being held.");
        }
        self::$heldsectionlocks[$shortname]->release();
        unset(self::$heldsectionlocks[$shortname]);
    }

    /**
     * Let go of any section lock a scenario left held.
     *
     * @AfterScenario
     */
    public function release_held_section_locks(): void {
        foreach (self::$heldsectionlocks as $lock) {
            $lock->release();
        }
        self::$heldsectionlocks = [];
    }

    /**
     * Start recording what the section change loader does on this page.
     *
     * Notes whether the loader ever became visible and every heading it showed while visible, so
     * a scenario can check what a change said after the loader has gone.
     *
     * @When /^I start watching the section change loader$/
     */
    public function i_start_watching_the_section_change_loader(): void {
        $this->getSession()->executeScript('(function() {
            const log = {appeared: false, headings: []};
            window.aiplacementModgenLoaderLog = log;
            const look = () => {
                const cover = document.querySelector("[data-region=aiplacement-modgen-structure-guard]");
                if (!cover || !cover.classList.contains("is-visible")) {
                    return;
                }
                log.appeared = true;
                const title = cover.querySelector("[data-region=title]");
                const text = title ? title.textContent.trim() : "";
                if (text && log.headings[log.headings.length - 1] !== text) {
                    log.headings.push(text);
                }
            };
            new MutationObserver(look).observe(document.body, {
                subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ["class"],
            });
        })();');
    }

    /**
     * The loader log started by "I start watching the section change loader".
     *
     * @return array{appeared: bool, headings: string[]}
     */
    private function section_change_loader_log(): array {
        $json = $this->getSession()->evaluateScript('return JSON.stringify(window.aiplacementModgenLoaderLog || null);');
        $log = json_decode((string) $json, true);
        if (!is_array($log)) {
            throw new Exception('Nothing is watching the section change loader: start watching it first.');
        }
        return $log;
    }

    /**
     * Check the loader showed these headings, in this order (others may come between).
     *
     * @Then /^the section change loader should have said, in order:$/
     * @param \Behat\Gherkin\Node\TableNode $table One heading per row.
     */
    public function the_section_change_loader_should_have_said_in_order(\Behat\Gherkin\Node\TableNode $table): void {
        $log = $this->section_change_loader_log();
        $at = 0;
        foreach ($table->getRows() as $row) {
            $found = array_search($row[0], array_slice($log['headings'], $at), true);
            if ($found === false) {
                throw new Exception("The loader did not say \"{$row[0]}\" when expected. It said: "
                    . json_encode($log['headings']));
            }
            $at += $found + 1;
        }
    }

    /**
     * Check the loader never showed a heading.
     *
     * @Then /^the section change loader should not have said "(?P<heading_string>[^"]*)"$/
     * @param string $heading The heading.
     */
    public function the_section_change_loader_should_not_have_said(string $heading): void {
        $log = $this->section_change_loader_log();
        if (in_array($heading, $log['headings'], true)) {
            throw new Exception("The loader said \"$heading\". It said: " . json_encode($log['headings']));
        }
    }

    /**
     * Check the loader never became visible.
     *
     * @Then /^the section change loader should not have appeared$/
     */
    public function the_section_change_loader_should_not_have_appeared(): void {
        $log = $this->section_change_loader_log();
        if ($log['appeared']) {
            throw new Exception('The loader appeared. It said: ' . json_encode($log['headings']));
        }
    }

    /**
     * Check the loader is not on the page.
     *
     * @Then /^the section change loader should be gone$/
     */
    public function the_section_change_loader_should_be_gone(): void {
        $this->ensure_element_does_not_exist('[data-region="aiplacement-modgen-structure-guard"]', 'css_element');
    }

    /**
     * Make the page's check of whether the course is busy fail, as a lost request would.
     *
     * @Given /^the section change check fails$/
     */
    public function the_section_change_check_fails(): void {
        $this->getSession()->executeScript('(function() {
            const fetch = window.fetch.bind(window);
            window.fetch = (resource, options) => String(resource).includes("/ai/placement/modgen/ajax/structure_busy.php")
                ? Promise.reject(new TypeError("Failed to fetch"))
                : fetch(resource, options);
        })();');
    }

    /**
     * Make the page give up waiting for another change sooner than its two minutes.
     *
     * @Given /^the section change loader gives up waiting after "(?P<seconds_number>\d+)" seconds$/
     * @param int $seconds Seconds.
     */
    public function the_section_change_loader_gives_up_waiting_after(int $seconds): void {
        $this->getSession()->executeScript('window.aiplacementModgenGuardWaitLimit = ' . ($seconds * 1000) . ';');
    }

    /** @var string|null The error a held-back section change failed with, once closed. */
    private static ?string $sectionchangeerror = null;

    /**
     * Let the other change finish, and wait for the change it held back to fail with an error.
     *
     * The error dialog is read and closed in this same step: Behat stops at any error dialog left
     * on the page, and this scenario expects one.
     *
     * @When /^the other change to the sections of "(?P<course_string>[^"]*)" finishes and the held-back change fails$/
     * @param string $shortname Course short name.
     */
    public function the_other_change_finishes_and_the_held_back_change_fails(string $shortname): void {
        self::$sectionchangeerror = null;
        if (empty(self::$heldsectionlocks[$shortname])) {
            throw new Exception("No change to the sections of '$shortname' is being held.");
        }
        self::$heldsectionlocks[$shortname]->release();
        unset(self::$heldsectionlocks[$shortname]);

        $this->wait_for_and_close_section_change_error('The held-back change did not fail with an error.');
    }

    /**
     * Wait for the error dialog a failed section change shows, note what it says, and close it.
     *
     * Closed in the step that caused it: Behat stops at any error dialog left on the page.
     *
     * @param string $none What to say if no error comes.
     */
    private function wait_for_and_close_section_change_error(string $none): void {
        $session = $this->getSession();
        $message = $this->spin(function () use ($session) {
            $text = $session->evaluateScript('return (function() {
                const error = document.querySelector("[data-rel=fatalerror]");
                return error ? error.textContent.trim() : "";
            })();');
            return $text !== '' ? $text : false;
        }, [], 30, new Exception($none), true);
        self::$sectionchangeerror = (string) $message;

        $session->executeScript('(function() {
            document.querySelectorAll("[data-rel=fatalerror]").forEach((error) => {
                (error.closest(".modal") || error).remove();
            });
            document.querySelectorAll(".modal-backdrop").forEach((backdrop) => backdrop.remove());
            document.body.classList.remove("modal-open");
        })();');
    }

    /**
     * Check the held-back change failed with an error saying this.
     *
     * @Then /^the held-back change should have failed with "(?P<text_string>[^"]*)"$/
     * @param string $text Text the error should contain.
     */
    public function the_held_back_change_should_have_failed_with(string $text): void {
        if (self::$sectionchangeerror === null || strpos(self::$sectionchangeerror, $text) === false) {
            throw new Exception("The change did not fail with '$text'. Its error was: "
                . var_export(self::$sectionchangeerror, true));
        }
    }

    /**
     * Ask the course editor to delete a section, as a click on Delete would.
     *
     * The page's own call, not a click: it reaches the course editor even while the loader covers
     * the page, to check a change asked for meanwhile is held back.
     *
     * @When /^the course editor is asked to delete section "(?P<section_number>\d+)"$/
     * @param int $section Section number.
     */
    public function the_course_editor_is_asked_to_delete_section(int $section): void {
        $this->getSession()->executeScript("(function() {
            const sectionid = document.getElementById('section-$section').dataset.id;
            require(['core_courseformat/courseeditor'], (courseeditor) => {
                courseeditor.getCurrentCourseEditor().dispatch('sectionDelete', [sectionid]);
            });
        })();");
    }

    /**
     * Ask the course editor to delete a section the server will refuse, and close the error it shows.
     *
     * @When /^the course editor is asked to delete section "(?P<section_number>\d+)", which fails$/
     * @param int $section Section number.
     */
    public function the_course_editor_is_asked_to_delete_section_which_fails(int $section): void {
        $this->the_course_editor_is_asked_to_delete_section($section);
        $this->wait_for_and_close_section_change_error('The delete did not fail with an error.');
    }

    /**
     * Start noting any promise the page rejects without catching.
     *
     * @Given /^I start watching for uncaught errors$/
     */
    public function i_start_watching_for_uncaught_errors(): void {
        $this->getSession()->executeScript('(function() {
            window.aiplacementModgenUncaught = [];
            window.addEventListener("unhandledrejection", (event) => {
                window.aiplacementModgenUncaught.push(String(event.reason && (event.reason.name || event.reason)));
            });
        })();');
    }

    /**
     * Check the page rejected no promise without catching it.
     *
     * @Then /^there should have been no uncaught errors$/
     */
    public function there_should_have_been_no_uncaught_errors(): void {
        $json = $this->getSession()->evaluateScript('return JSON.stringify(window.aiplacementModgenUncaught || null);');
        $errors = json_decode((string) $json, true);
        if (!is_array($errors)) {
            throw new Exception('Nothing is watching for uncaught errors: start watching first.');
        }
        if ($errors) {
            throw new Exception('The page had uncaught errors: ' . implode(', ', $errors));
        }
    }

    /**
     * Make the section change loader unable to render, as a failed template or string load would.
     *
     * @Given /^the section change loader cannot be drawn$/
     */
    public function the_section_change_loader_cannot_be_drawn(): void {
        $this->getSession()->executeScript('window.aiplacementModgenGuardBreakCover = true;');
    }

    /**
     * Make a section change that follows a link stay on this page, as one the user stops would.
     *
     * @Given /^a section change that loads a page is stopped, and given up on after "(?P<seconds_number>\d+)" seconds$/
     * @param int $seconds Seconds before the page is uncovered.
     */
    public function a_section_change_that_loads_a_page_is_stopped(int $seconds): void {
        $this->getSession()->executeScript('window.aiplacementModgenGuardStayOnPage = true;
            window.aiplacementModgenGuardNavigationLimit = ' . ($seconds * 1000) . ';');
    }

    /**
     * Use a link in a section from the keyboard: focus it, then follow it, noting what had focus.
     *
     * @When /^I use the "(?P<text_string>[^"]*)" link in section "(?P<section_number>\d+)" from the keyboard$/
     * @param string $text Link text.
     * @param int $section Section number.
     */
    public function i_use_the_link_in_section_from_the_keyboard(string $text, int $section): void {
        $link = $this->find('xpath', "//li[@id='section-$section']//a[normalize-space(.)="
            . behat_context_helper::escape($text) . ']');
        $this->getSession()->executeScript('(function() {
            const link = document.evaluate(' . json_encode($link->getXpath()) . ', document, null,
                XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
            link.focus();
            window.aiplacementModgenFocused = link;
            link.click();
        })();');
    }

    /**
     * Check focus is back on what had it before the change.
     *
     * @Then /^focus should be back where it was$/
     */
    public function focus_should_be_back_where_it_was(): void {
        $back = $this->getSession()->evaluateScript(
            'return !!window.aiplacementModgenFocused && document.activeElement === window.aiplacementModgenFocused;'
        );
        if (!$back) {
            $now = $this->getSession()->evaluateScript(
                'return document.activeElement ? document.activeElement.outerHTML.substring(0, 120) : "nothing";'
            );
            throw new Exception("Focus did not go back. It is on: $now");
        }
    }

    /**
     * Wait for the section change loader to leave the page.
     *
     * Its text cannot be waited on: the page carries the loader's strings in a script.
     *
     * @When /^I wait until the section change loader has gone$/
     */
    public function i_wait_until_the_section_change_loader_has_gone(): void {
        $this->ensure_element_does_not_exist('[data-region="aiplacement-modgen-structure-guard"]', 'css_element');
    }
}
