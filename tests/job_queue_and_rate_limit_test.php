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

use aiplacement_modgen\local\job_status_helper;

defined('MOODLE_INTERNAL') || die();

// The ai_service class sits under classes/local but in the plugin's root namespace, so it is not autoloadable.
require_once(__DIR__ . '/../classes/local/ai_service.php');

/**
 * Tests for queuing a creation job and for the AI request allowance.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\job_status_helper::queue_creation_job
 * @covers \aiplacement_modgen\ai_service
 */
final class job_queue_and_rate_limit_test extends \advanced_testcase {
    /**
     * The job row and its task are created together and agree on the job id.
     */
    public function test_queue_creation_job_creates_job_and_task(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $jobid = job_status_helper::queue_creation_job($course->id, $user->id, 'create_weeks', ['weekcount' => 3]);

        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('queued', $job->status);
        $this->assertSame(['weekcount' => 3], json_decode($job->parameters, true));

        $task = job_status_helper::find_section_creation_task($jobid);
        $this->assertNotNull($task);
        $this->assertSame(3, $task->get_custom_data()->weekcount);
        $this->assertSame((int) $user->id, (int) $task->get_userid());
    }

    /**
     * A large structure travels in the task data only; the job row keeps just a summary.
     */
    public function test_queue_creation_job_can_store_a_summary_instead_of_the_payload(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $json = ['themes' => [['title' => 'A']]];

        $jobid = job_status_helper::queue_creation_job(
            $course->id,
            $user->id,
            'create_from_json',
            ['json' => $json, 'moduletype' => 'connected_theme'],
            ['moduletype' => 'connected_theme', 'sectioncount' => 1]
        );

        $stored = json_decode($DB->get_field('aiplacement_modgen_jobs', 'parameters', ['id' => $jobid]), true);
        $this->assertSame(['moduletype' => 'connected_theme', 'sectioncount' => 1], $stored);

        $taskdata = job_status_helper::find_section_creation_task($jobid)->get_custom_data();
        $this->assertEquals(json_decode(json_encode($json)), $taskdata->json);
    }

    /**
     * If queuing fails the job row is rolled back rather than left queued for ever.
     */
    public function test_queue_creation_job_rolls_back_when_queuing_fails(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $before = $DB->count_records('aiplacement_modgen_jobs');

        // An action longer than the 50-character column makes the insert fail.
        try {
            job_status_helper::queue_creation_job($course->id, 2, str_repeat('a', 100), ['weekcount' => 1]);
            $this->fail('Queuing should have failed.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame($before, $DB->count_records('aiplacement_modgen_jobs'));
    }

    /**
     * The allowance runs out after the configured number of requests and is a fixed window.
     */
    public function test_rate_limit_is_a_fixed_window(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        \cache::make('aiplacement_modgen', 'ai_requests')->purge();

        $consume = new \ReflectionMethod(ai_service::class, 'consume_rate_limit');
        $consume->setAccessible(true);

        $this->assertTrue($consume->invoke(null, 2));
        $this->assertTrue($consume->invoke(null, 2));
        $this->assertFalse($consume->invoke(null, 2));
        // Refusals do not move the window or the count.
        $this->assertFalse($consume->invoke(null, 2));
    }
}
