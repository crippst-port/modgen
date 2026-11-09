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

use aiplacement_modgen\local\section_reorder_queue;
use aiplacement_modgen\local\section_reorder_status;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');

/**
 * Only site administrators see the section reorder status, whatever role anyone else has.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_modgen\local\section_reorder_status::can_view
 * @covers     ::aiplacement_modgen_output_fragment_course_toolbar
 */
final class reorder_status_visibility_test extends \advanced_testcase {
    /** @var string[] The plugin's toolbar capabilities, so a non-admin gets the toolbar at all. */
    private const TOOLBAR_CAPABILITIES = [
        'aiplacement/modgen:managestructure',
        'aiplacement/modgen:generatewithprompt',
        'aiplacement/modgen:generatefromtemplate',
        'aiplacement/modgen:checkstructure',
    ];

    /** @var \stdClass Flexible sections course with a renumber queued, so there is a status to show. */
    private $course;

    /**
     * Create the course, queue a renumber and grant every role the toolbar.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('enabled', 1, 'aiplacement_modgen');

        $this->course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $DB->delete_records('task_adhoc');
        section_reorder_queue::schedule($this->course->id);

        // Every role can use the toolbar here, so a missing icon is the icon's own check and not
        // the toolbar being absent.
        $context = \context_course::instance($this->course->id);
        foreach (get_all_roles() as $role) {
            foreach (self::TOOLBAR_CAPABILITIES as $capability) {
                assign_capability($capability, CAP_ALLOW, $role->id, $context->id, true);
            }
        }
    }

    /**
     * Render the toolbar fragment for the test course as the current user.
     *
     * @return string
     */
    private function toolbar_html(): string {
        global $PAGE;
        $PAGE->set_url('/course/view.php', ['id' => $this->course->id]);
        $PAGE->set_context(\context_course::instance($this->course->id));
        return aiplacement_modgen_output_fragment_course_toolbar([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * Every role that can be given in a course.
     *
     * @return array<string, array{string}>
     */
    public static function course_role_provider(): array {
        return [
            'manager' => ['manager'],
            'course creator' => ['coursecreator'],
            'editing teacher' => ['editingteacher'],
            'non-editing teacher' => ['teacher'],
            'student' => ['student'],
        ];
    }

    public function test_site_administrator_sees_the_status(): void {
        $this->setAdminUser();
        $this->assertTrue(section_reorder_status::can_view($this->course->id));
        $this->assertStringContainsString('data-region="aiplacement-modgen-reorder-status"', $this->toolbar_html());
    }

    /**
     * A course role never sees the renumber status, nor does its toolbar show it.
     *
     * @dataProvider course_role_provider
     * @param string $shortname Role short name
     */
    public function test_course_role_does_not_see_the_status(string $shortname): void {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, $shortname);
        $this->setUser($user);

        $this->assertFalse(section_reorder_status::can_view($this->course->id));
        $html = $this->toolbar_html();
        $this->assertStringContainsString('aiplacement-modgen-navbar', $html, 'The toolbar itself should show.');
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $html);
    }

    public function test_site_manager_does_not_see_the_status(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertFalse(section_reorder_status::can_view($this->course->id));
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $this->toolbar_html());
    }

    /**
     * An administrator switched to a course role does not see the renumber status.
     *
     * @dataProvider course_role_provider
     * @param string $shortname Role short name
     */
    public function test_administrator_switched_to_a_course_role_does_not_see_the_status(string $shortname): void {
        global $DB;
        $this->setAdminUser();
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_switch($roleid, \context_course::instance($this->course->id));

        $this->assertFalse(section_reorder_status::can_view($this->course->id));
        $this->assertStringNotContainsString('aiplacement-modgen-reorder-status', $this->toolbar_html());
    }

    public function test_administrator_sees_the_status_again_after_switching_back(): void {
        global $DB;
        $this->setAdminUser();
        $context = \context_course::instance($this->course->id);
        role_switch($DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST), $context);
        role_switch(0, $context);

        $this->assertTrue(section_reorder_status::can_view($this->course->id));
    }

    public function test_switching_role_in_one_course_leaves_the_status_on_another(): void {
        global $DB;
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        role_switch(
            $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST),
            \context_course::instance($this->course->id)
        );

        $this->assertTrue(section_reorder_status::can_view($other->id));
    }
}
