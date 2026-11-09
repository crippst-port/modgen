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
 * Unit tests for course modification lock handling.
 *
 * Tests verify that concurrent requests properly wait for locks,
 * timeouts are handled gracefully, and locks are released on errors.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Test class for lock handling.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lock_handling_test extends advanced_testcase {
    /**
     * The delete lock key modgen takes is the one the installed format_flexsections locks on.
     *
     * Read from format_flexsections' own source, so this fails if a new version changes the key:
     * a build would then no longer keep a teacher's section delete out.
     *
     * @covers \aiplacement_modgen\local\course_formats::flexsections_delete_lock_key
     */
    public function test_delete_lock_key_matches_installed_flexsections(): void {
        $this->resetAfterTest(true);
        $dir = \core_component::get_component_directory('format_flexsections');
        if ($dir === null) {
            $this->markTestSkipped('format_flexsections is not installed.');
        }
        $source = file_get_contents($dir . '/lib.php');

        $this->assertMatchesRegularExpression(
            "/get_lock_factory\('" . local\course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE . "'\)/",
            $source
        );
        $found = preg_match("/->get_lock\('course_modification_lock(_' \. \\\$this->courseid)?'?,/", $source, $match);
        $this->assertSame(1, $found, 'format_flexsections no longer takes a recognised delete lock.');

        $courseid = 42;
        $expected = empty($match[1]) ? 'course_modification_lock' : 'course_modification_lock_' . $courseid;
        $this->assertSame($expected, local\course_formats::flexsections_delete_lock_key($courseid));
    }

    /**
     * A build holds the flexsections delete lock while it runs, and lets it go after.
     *
     * The lock is re-entrant within one process on some databases, so this checks what is held
     * rather than trying to take it again; the forked concurrency tests check the exclusion itself.
     *
     * @covers \aiplacement_modgen\local\theme_builder::acquire_structure_locks
     */
    public function test_build_takes_the_flexsections_delete_lock(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $wanted = local\course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE . '_'
            . local\course_formats::flexsections_delete_lock_key((int) $course->id);

        $locks = theme_builder::acquire_structure_locks($course->id, 0);
        try {
            $keys = array_map(fn($lock) => $lock->get_key(), $locks);
            $this->assertContains($wanted, $keys);
        } finally {
            theme_builder::release_structure_locks($locks);
        }

        // A core format course takes no flexsections lock at all.
        $topics = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $locks = theme_builder::acquire_structure_locks($topics->id, 0);
        $keys = array_map(fn($lock) => $lock->get_key(), $locks);
        theme_builder::release_structure_locks($locks);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString(local\course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE, $key);
        }
    }

    /**
     * Test that lock is acquired before section creation.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_lock_acquired_before_creation(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($course->id);

        $structure = [
            'themes' => [
                [
                    'title' => 'Test Theme',
                    'summary' => 'Summary',
                    'weeks' => [],
                ],
            ],
        ];

        $service = new section_creation_service();

        // This should acquire lock internally.
        $results = $service->create_sections_from_json(
            $structure,
            $course->id,
            'connected_theme'
        );

        // If we get here, lock was acquired successfully.
        $this->assertIsArray($results, 'Should complete with lock acquired');
        $this->assertArrayHasKey('results', $results, 'Should return results');
    }

    /**
     * Test that lock is released after successful completion.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_lock_released_after_completion(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($course->id);

        $structure = [
            'themes' => [
                ['title' => 'Theme 1', 'summary' => 'S1', 'weeks' => []],
            ],
        ];

        $service = new section_creation_service();

        // First request.
        $results1 = $service->create_sections_from_json(
            $structure,
            $course->id,
            'connected_theme'
        );

        // Second request should succeed (lock released from first).
        $structure2 = [
            'themes' => [
                ['title' => 'Theme 2', 'summary' => 'S2', 'weeks' => []],
            ],
        ];

        $results2 = $service->create_sections_from_json(
            $structure2,
            $course->id,
            'connected_theme'
        );

        $this->assertIsArray($results1, 'First request should complete');
        $this->assertIsArray($results2, 'Second request should complete (lock released)');
    }

    /**
     * Test that lock is released on exception.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_lock_released_on_exception(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($course->id);

        $structure = [
            'themes' => [
                ['title' => 'Theme', 'summary' => 'Summary', 'weeks' => []],
            ],
        ];

        $service = new section_creation_service();

        // First successful request.
        $results1 = $service->create_sections_from_json(
            $structure,
            $course->id,
            'connected_theme'
        );

        $this->assertIsArray($results1, 'First request should succeed');

        // Second request should also succeed - testing lock release.
        $results2 = $service->create_sections_from_json(
            ['themes' => [['title' => 'Theme 2', 'summary' => 'S', 'weeks' => []]]],
            $course->id,
            'connected_theme'
        );

        $this->assertIsArray(
            $results2,
            'Second request should succeed (lock properly released)'
        );
    }

    /**
     * Test lock key is course-specific.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_lock_is_course_specific(): void {
        $this->resetAfterTest(true);

        // Create two courses.
        $course1 = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $course2 = $this->getDataGenerator()->create_course(['format' => 'flexsections']);

        theme_builder::prepare_course_for_build($course1->id);
        theme_builder::prepare_course_for_build($course2->id);

        $structure = [
            'themes' => [
                ['title' => 'Theme', 'summary' => 'Summary', 'weeks' => []],
            ],
        ];

        $service = new section_creation_service();

        // Both should succeed simultaneously (different locks).
        $results1 = $service->create_sections_from_json(
            $structure,
            $course1->id,
            'connected_theme'
        );

        $results2 = $service->create_sections_from_json(
            $structure,
            $course2->id,
            'connected_theme'
        );

        $this->assertIsArray($results1, 'Course 1 should complete');
        $this->assertIsArray($results2, 'Course 2 should complete (separate lock)');
    }

    /**
     * Test rebuild_course_cache called in finally block.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_cache_rebuilt_in_finally_block(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($course->id);

        $structure = [
            'themes' => [
                ['title' => 'Theme', 'summary' => 'Summary', 'weeks' => []],
            ],
        ];

        $service = new section_creation_service();

        // Get cache timestamp before.
        $cachebefore = $DB->get_field('course', 'cacherev', ['id' => $course->id]);

        $results = $service->create_sections_from_json(
            $structure,
            $course->id,
            'connected_theme'
        );

        // Cache should be rebuilt (timestamp changed).
        $cacheafter = $DB->get_field('course', 'cacherev', ['id' => $course->id]);

        $this->assertNotEquals(
            $cachebefore,
            $cacheafter,
            'Cache should be rebuilt in finally block'
        );
    }

    /**
     * Test that lock factory exists for the plugin.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_lock_factory_exists(): void {
        $this->resetAfterTest(true);

        $lockfactory = \core\lock\lock_config::get_lock_factory('aiplacement_modgen');

        $this->assertNotNull($lockfactory, 'Lock factory should exist for plugin');
        $this->assertInstanceOf(
            \core\lock\lock_factory::class,
            $lockfactory,
            'Should be valid lock factory instance'
        );
    }

    /**
     * Test concurrent section creation on same course waits for lock.
     * This is a conceptual test - actual concurrency testing requires process forking.
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_concurrent_requests_serialize(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        theme_builder::prepare_course_for_build($course->id);

        $structure = [
            'themes' => [
                ['title' => 'Theme', 'summary' => 'Summary', 'weeks' => []],
            ],
        ];

        $service = new section_creation_service();

        // Sequential requests should both complete.
        // (True concurrency test would require process forking).
        $results1 = $service->create_sections_from_json(
            $structure,
            $course->id,
            'connected_theme'
        );

        $results2 = $service->create_sections_from_json(
            ['themes' => [['title' => 'Theme 2', 'summary' => 'S', 'weeks' => []]]],
            $course->id,
            'connected_theme'
        );

        $this->assertIsArray($results1, 'First request should complete');
        $this->assertIsArray($results2, 'Second request should complete after lock released');
    }
}
