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

namespace aiplacement_modgen;

use aiplacement_modgen\local\quick_add_service;
use aiplacement_modgen\local\theme_builder;

/**
 * Tests for the rules behind the Quick Add endpoint.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\quick_add_service
 */
final class quick_add_service_test extends \advanced_testcase {
    /** @var \stdClass Course the requests are made in. */
    private $course;

    /** @var \stdClass User queuing the requests. */
    private $user;

    /**
     * A Flexible sections course and a user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 0]);
        theme_builder::ensure_flexsections_format($this->course->id);
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * Assert that a call is refused with a given error code and queues nothing.
     *
     * @param string $errorcode Expected code.
     * @param callable $call The request.
     */
    private function assert_refused(string $errorcode, callable $call): void {
        global $DB;
        $before = $DB->count_records('aiplacement_modgen_jobs');
        try {
            $call();
            $this->fail("Expected '$errorcode'.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('aiplacement_modgen_jobs'), 'A refused request must not queue a job.');
    }

    /**
     * A valid theme request queues one job and reports how many sections it will make.
     */
    public function test_valid_theme_request_is_queued(): void {
        global $DB;

        $result = quick_add_service::queue_themes($this->course->id, $this->user->id, 2, 3, 0, false);

        // 2 themes + 2 x 3 weeks x (a week and its 3 sessions).
        $this->assertSame(2 + (2 * 3 * 4), $result['total']);
        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $result['jobid']], '*', MUST_EXIST);
        $this->assertSame('create_themes', $job->action);
        $this->assertSame('queued', $job->status);
        $this->assertEquals($this->user->id, $job->userid);
        $params = json_decode($job->parameters, true);
        $this->assertSame(2, $params['themecount']);
        $this->assertSame(3, $params['weeksperTheme']);
    }

    /**
     * Themes are top-level containers and cannot be requested from inside a section.
     */
    public function test_themes_are_refused_from_inside_a_section(): void {
        $this->assert_refused('themetoplevelonly', fn() => quick_add_service::queue_themes(
            $this->course->id,
            $this->user->id,
            1,
            1,
            3,
            false
        ));
    }

    /**
     * Counts outside the configured range are refused.
     */
    public function test_counts_outside_the_limits_are_refused(): void {
        set_config('maxquicksections', 5, 'aiplacement_modgen');
        set_config('maxweeksperTheme', 2, 'aiplacement_modgen');
        $id = $this->course->id;
        $uid = $this->user->id;

        $this->assert_refused('invalidthemecount', fn() => quick_add_service::queue_themes($id, $uid, 0, 1, 0, false));
        $this->assert_refused('invalidthemecount', fn() => quick_add_service::queue_themes($id, $uid, 6, 1, 0, false));
        $this->assert_refused('invalidweeksperTheme', fn() => quick_add_service::queue_themes($id, $uid, 1, 0, 0, false));
        $this->assert_refused('invalidweeksperTheme', fn() => quick_add_service::queue_themes($id, $uid, 1, 3, 0, false));
        $this->assert_refused('invalidweekcount', fn() => quick_add_service::queue_weeks($id, $uid, 0, 0, false));
        $this->assert_refused('invalidweekcount', fn() => quick_add_service::queue_weeks($id, $uid, 6, 0, false));
    }

    /**
     * A request that would take the course over the total section cap is refused up front.
     */
    public function test_request_over_the_total_section_cap_is_refused(): void {
        set_config('maxtotalsections', 10, 'aiplacement_modgen');

        $this->assert_refused('sectionlimitexceeded', fn() => quick_add_service::queue_weeks(
            $this->course->id,
            $this->user->id,
            5,
            0,
            false
        ));
    }

    /**
     * Weeks queued from inside a section pin the section by id, because numbers shift.
     */
    public function test_weeks_from_a_section_record_the_section_id(): void {
        global $DB;
        $section = $this->getDataGenerator()->create_course_section(['course' => $this->course->id, 'section' => 1]);

        $result = quick_add_service::queue_weeks($this->course->id, $this->user->id, 2, 1, true);

        $this->assertSame(2 + (2 * 3), $result['total']);
        $params = json_decode($DB->get_field('aiplacement_modgen_jobs', 'parameters', ['id' => $result['jobid']]), true);
        $this->assertSame(1, $params['parentsection']);
        $this->assertEquals($section->id, $params['parentsectionid']);
        $this->assertTrue($params['createsummaryactivities']);
    }
}
