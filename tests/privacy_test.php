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
 * Privacy API tests for aiplacement_modgen.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use aiplacement_modgen\privacy\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy API test class.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2025 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\privacy\provider
 */
final class privacy_test extends provider_testcase
{
    /**
     * Test getting metadata.
     */
    public function test_get_metadata(): void {
        $collection = new collection('aiplacement_modgen');
        $metadata = provider::get_metadata($collection);

        $this->assertInstanceOf(collection::class, $metadata);
        $this->assertNotEmpty($metadata->get_collection());
    }

    /**
     * Test that contexts are retrieved for a user.
     */
    public function test_get_contexts_for_userid(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        // Create job for user1 in course1.
        $job1id = $this->create_test_job($user1->id, $course1->id, 'create_themes');

        // Create job for user2 in course2.
        $job2id = $this->create_test_job($user2->id, $course2->id, 'create_weeks');

        // Verify jobs were created
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job1id]), 'Job 1 should exist');
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job2id]), 'Job 2 should exist');

        // Get contexts for user1.
        $contextlist = provider::get_contexts_for_userid($user1->id);

        $this->assertInstanceOf(contextlist::class, $contextlist);
        $contexts = $contextlist->get_contextids();
        $this->assertCount(1, $contexts);

        $coursecontext1 = \context_course::instance($course1->id);

        // Verify it's the correct course context.
        // Note: get_contextids() returns strings, so we need to convert for comparison
        $this->assertContains((string)$coursecontext1->id, $contexts, 'Should contain the expected course context');

        // Verify user2's context is different.
        $contextlist2 = provider::get_contexts_for_userid($user2->id);
        $contexts2 = $contextlist2->get_contextids();
        $coursecontext2 = \context_course::instance($course2->id);
        $this->assertContains((string)$coursecontext2->id, $contexts2);
    }

    /**
     * Test exporting user data.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        // Create test job for user.
        $jobid = $this->create_test_job($user->id, $course->id, 'create_themes', [
            'parameters' => json_encode(['themes' => 3, 'weeks' => 5]),
            'status' => 'completed',
        ]);

        // Get contexts for export.
        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'aiplacement_modgen', $contextlist->get_contextids());

        // Export data.
        provider::export_user_data($approvedcontextlist);

        // Verify data was exported.
        $writer = writer::with_context($coursecontext);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('privacy:path:jobs', 'aiplacement_modgen')]);
        $this->assertNotEmpty($data);
        $this->assertObjectHasProperty('jobs', $data);
        $this->assertCount(1, $data->jobs);

        $exportedjob = $data->jobs[0];
        $this->assertEquals('create_themes', $exportedjob['action']);
        $this->assertEquals('completed', $exportedjob['status']);
    }

    /**
     * Test deleting data for a user in a specific context.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        // Create jobs for both users in same course.
        $job1 = $this->create_test_job($user1->id, $course->id, 'create_themes');
        $job2 = $this->create_test_job($user2->id, $course->id, 'create_weeks');

        // Verify both jobs exist.
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job1]));
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job2]));

        // Delete data for user1.
        $contextlist = provider::get_contexts_for_userid($user1->id);
        $approvedcontextlist = new approved_contextlist($user1, 'aiplacement_modgen', $contextlist->get_contextids());
        provider::delete_data_for_user($approvedcontextlist);

        // Verify user1's job is deleted.
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job1]));

        // Verify user2's job still exists.
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job2]));
    }

    /**
     * Test deleting all data in a context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        // Create jobs in course1.
        $job1 = $this->create_test_job($user1->id, $course1->id, 'create_themes');
        $job2 = $this->create_test_job($user2->id, $course1->id, 'create_weeks');

        // Create job in course2.
        $job3 = $this->create_test_job($user1->id, $course2->id, 'create_themes');

        // Verify all jobs exist.
        $this->assertEquals(3, $DB->count_records('aiplacement_modgen_jobs'));

        // Delete all data in course1 context.
        $coursecontext1 = \context_course::instance($course1->id);
        provider::delete_data_for_all_users_in_context($coursecontext1);

        // Verify course1 jobs are deleted.
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job1]));
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job2]));

        // Verify course2 job still exists.
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job3]));
    }

    /**
     * Test getting users in a context.
     */
    public function test_get_users_in_context(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        // Create jobs for user1 and user2 in course.
        $this->create_test_job($user1->id, $course->id, 'create_themes');
        $this->create_test_job($user2->id, $course->id, 'create_weeks');
        // user3 has no jobs.

        // Get users in context.
        $userlist = new userlist($coursecontext, 'aiplacement_modgen');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();

        $this->assertCount(2, $userids);
        // Note: get_userids() returns integers, user->id is string, so we need to convert for comparison
        $this->assertContains((int)$user1->id, $userids);
        $this->assertContains((int)$user2->id, $userids);
        $this->assertNotContains((int)$user3->id, $userids);
    }

    /**
     * Test deleting data for specific users in a context.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        // Create jobs for all three users.
        $job1 = $this->create_test_job($user1->id, $course->id, 'create_themes');
        $job2 = $this->create_test_job($user2->id, $course->id, 'create_weeks');
        $job3 = $this->create_test_job($user3->id, $course->id, 'create_from_json');

        // Delete data for user1 and user2 only.
        $approveduserlist = new approved_userlist($coursecontext, 'aiplacement_modgen', [$user1->id, $user2->id]);
        provider::delete_data_for_users($approveduserlist);

        // Verify user1 and user2 jobs deleted.
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job1]));
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job2]));

        // Verify user3 job still exists.
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job3]));
    }

    /**
     * Helper method to create a test job record.
     *
     * @param int $userid User ID
     * @param int $courseid Course ID
     * @param string $action Job action
     * @param array $overrides Optional field overrides
     * @return int Job ID
     */
    private function create_test_job(int $userid, int $courseid, string $action, array $overrides = []): int {
        global $DB;

        $job = (object) array_merge([
            'userid' => $userid,
            'courseid' => $courseid,
            'action' => $action,
            'parameters' => json_encode([]),
            'result' => null,
            'status' => 'queued',
            'timecreated' => time(),
            'timestarted' => null,
            'timecompleted' => null,
        ], $overrides);

        return $DB->insert_record('aiplacement_modgen_jobs', $job);
    }
}
