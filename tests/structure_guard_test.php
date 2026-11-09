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
 * Tests for the structure guard: who it holds back, and when a course is busy.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use aiplacement_modgen\local\course_formats;
use aiplacement_modgen\local\structure_guard;
use aiplacement_modgen\local\theme_builder;

/**
 * Tests for the structure guard.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\local\structure_guard
 */
final class structure_guard_test extends \advanced_testcase {
    /**
     * Use file locks, which one process cannot take twice.
     *
     * The database lock factories let a process take a lock it already holds again, so a lock
     * held by the test itself would never look busy to it.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        \core\plugininfo\aiplacement::enable_plugin('modgen', 1);
    }

    /**
     * Hold a lock for the length of a test.
     *
     * @param string $type Lock factory type
     * @param string $key Lock key
     * @return \core\lock\lock
     */
    private function hold(string $type, string $key): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory($type)->get_lock($key, 0);
        $this->assertNotFalse($lock, "The test should be able to take $type $key.");
        return $lock;
    }

    /**
     * An editing teacher on a Flexible sections course is held back; nobody else is.
     */
    public function test_applies_to_editors_of_flexsections_courses(): void {
        $generator = $this->getDataGenerator();
        $flex = $generator->create_course(['format' => 'flexsections']);
        $topics = $generator->create_course(['format' => 'topics']);
        $teacher = $generator->create_and_enrol($flex, 'editingteacher');
        $generator->enrol_user($teacher->id, $topics->id, 'editingteacher');
        $student = $generator->create_and_enrol($flex, 'student');
        $flexcontext = \context_course::instance($flex->id);

        $this->setUser($teacher);
        $this->assertTrue(structure_guard::applies($flex->id, $flexcontext));
        $this->assertFalse(structure_guard::applies($topics->id, \context_course::instance($topics->id)));

        $this->setUser($student);
        $this->assertFalse(structure_guard::applies($flex->id, $flexcontext));

        $this->setUser($teacher);
        set_config('enable_structureguard', 0, 'aiplacement_modgen');
        $this->assertFalse(structure_guard::applies($flex->id, $flexcontext));

        // The placement's own master switch turns it off too.
        set_config('enable_structureguard', 1, 'aiplacement_modgen');
        \core\plugininfo\aiplacement::enable_plugin('modgen', 0);
        $this->assertFalse(structure_guard::applies($flex->id, $flexcontext));
    }

    /**
     * A setting never saved counts as on.
     */
    public function test_on_until_switched_off(): void {
        unset_config('enable_structureguard', 'aiplacement_modgen');
        $this->assertTrue(local\settings_helper::is_structureguard_enabled());
        set_config('enable_structureguard', 0, 'aiplacement_modgen');
        $this->assertFalse(local\settings_helper::is_structureguard_enabled());
    }

    /**
     * A course is busy while a section delete holds Flexible sections' lock, and free after.
     */
    public function test_busy_while_a_delete_holds_the_lock(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $this->assertNull(structure_guard::busy_reason($course->id));

        $lock = $this->hold(
            course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE,
            course_formats::flexsections_delete_lock_key($course->id)
        );
        try {
            $this->assertSame(structure_guard::BUSY_SECTIONS, structure_guard::busy_reason($course->id));
        } finally {
            $lock->release();
        }
        $this->assertNull(structure_guard::busy_reason($course->id));
    }

    /**
     * Asking does not keep the lock: a delete can still take it straight after.
     */
    public function test_asking_lets_the_lock_go(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        structure_guard::busy_reason($course->id);
        $lock = $this->hold(
            course_formats::FLEXSECTIONS_DELETE_LOCK_TYPE,
            course_formats::flexsections_delete_lock_key($course->id)
        );
        $lock->release();
        $lock = $this->hold('core_course_edit', 'course_edit_' . $course->id);
        $lock->release();
    }

    /**
     * A running Module Assistant build is reported as a build.
     */
    public function test_busy_while_a_build_runs(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->insert_record('aiplacement_modgen_jobs', [
            'courseid' => $course->id,
            'userid' => get_admin()->id,
            'action' => 'create_weeks',
            'status' => 'running',
            'parameters' => '{}',
            'timecreated' => time(),
        ]);

        $locks = theme_builder::acquire_structure_locks($course->id, 0);
        try {
            $this->assertSame(structure_guard::BUSY_BUILD, structure_guard::busy_reason($course->id));
        } finally {
            theme_builder::release_structure_locks($locks);
        }
    }

    /**
     * A repair holding only the course edit lock is reported as a build.
     */
    public function test_busy_while_a_repair_holds_the_course_edit_lock(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $lock = $this->hold('core_course_edit', 'course_edit_' . $course->id);
        try {
            $this->assertSame(structure_guard::BUSY_BUILD, structure_guard::busy_reason($course->id));
        } finally {
            $lock->release();
        }
    }

    /**
     * From Flexible sections 4.1.7 a change in one course does not make another busy.
     */
    public function test_another_course_is_not_busy(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'flexsections']);
        $other = $generator->create_course(['format' => 'flexsections']);
        if (course_formats::flexsections_delete_lock_key($course->id) === 'course_modification_lock') {
            $this->markTestSkipped('This format_flexsections locks section deletes for the whole site.');
        }

        $locks = theme_builder::acquire_structure_locks($course->id, 0);
        try {
            $this->assertNotNull(structure_guard::busy_reason($course->id));
            $this->assertNull(structure_guard::busy_reason($other->id));
        } finally {
            theme_builder::release_structure_locks($locks);
        }
    }
}
