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

/**
 * Steps for the plugin's pages.
 */
class behat_aiplacement_modgen extends behat_base {
    /**
     * Open the job status page of the most recent job in a course.
     *
     * @Given /^I am on the job status page of the latest job in "(?P<course_string>(?:[^"]|\\")*)"$/
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
}
