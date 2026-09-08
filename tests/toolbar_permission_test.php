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
 * Tests that the Check Structure toolbar button and its target page are both properly
 * gated by the aiplacement/modgen:checkstructure capability.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');

/**
 * Tests for Check Structure toolbar button / page permission gating.
 */
final class toolbar_permission_test extends advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;

    /** @var \stdClass User with the checkstructure capability. */
    private $authorizeduser;

    /** @var \stdClass User with a different modgen capability, but not checkstructure. */
    private $partiallyauthorizeduser;

    /**
     * Set up a course and two differently-privileged users.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($this->course->id);

        $coursecontext = \context_course::instance($this->course->id);

        // Authorized user: editingteacher role explicitly granted checkstructure.
        $editingteacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->authorizeduser = $generator->create_user();
        $generator->role_assign($editingteacherrole, $this->authorizeduser->id, $coursecontext->id);
        assign_capability('aiplacement/modgen:checkstructure', CAP_ALLOW, $editingteacherrole, $coursecontext->id);

        // Partially-authorized user: a different role, granted a different modgen
        // capability (usesuggest) but never checkstructure. Clears the toolbar's
        // "has at least one modgen capability" gate without being allowed to see or
        // reach the structure diagnostics/fix tool specifically.
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $this->partiallyauthorizeduser = $generator->create_user();
        $generator->role_assign($teacherrole, $this->partiallyauthorizeduser->id, $coursecontext->id);
        assign_capability('aiplacement/modgen:usesuggest', CAP_ALLOW, $teacherrole, $coursecontext->id);
    }

    /**
     * has_capability() is the source of truth extend_navigation_course() uses to decide
     * whether the toolbar even loads the Check Structure button's JS/URL.
     *
     * @covers ::aiplacement_modgen_extend_navigation_course
     */
    public function test_checkstructure_capability_reflects_authorization(): void {
        $context = \context_course::instance($this->course->id);

        $this->setUser($this->authorizeduser);
        $this->assertTrue(has_capability('aiplacement/modgen:checkstructure', $context));

        $this->setUser($this->partiallyauthorizeduser);
        $this->assertFalse(has_capability('aiplacement/modgen:checkstructure', $context));
    }

    /**
     * The toolbar is rendered via a Fragment API callback, reachable directly over AJAX by
     * any user who clears the "has at least one modgen capability" gate. It must not trust
     * a client-supplied showcheckstructure flag — that would let such a user force the
     * Check Structure button/URL to render even without the capability for it.
     *
     * @covers ::aiplacement_modgen_output_fragment_course_toolbar
     */
    public function test_fragment_toolbar_ignores_forged_showcheckstructure_flag(): void {
        $this->setUser($this->partiallyauthorizeduser);
        $contextid = \context_course::instance($this->course->id)->id;

        $html = \aiplacement_modgen_output_fragment_course_toolbar([
            'courseid'               => $this->course->id,
            'contextid'              => $contextid,
            'showsuggest'            => 1,
            'showcheckstructure'     => 1, // Forged: this user has no checkstructure capability.
            'showmanagestructure'    => 1,
            'showmanagedates'        => 1,
            'showtemplatefromfile'   => 1,
            'showtemplatefromptompt' => 1,
        ]);

        $this->assertStringNotContainsString(
            'check_structure.php',
            $html,
            'Fragment callback must not trust a client-supplied showcheckstructure flag'
        );
    }

    /**
     * Conversely, a genuinely authorized user must still see the button even if the
     * client-side args omit or zero out showcheckstructure — visibility is server-computed.
     *
     * @covers ::aiplacement_modgen_output_fragment_course_toolbar
     */
    public function test_fragment_toolbar_shows_button_for_authorized_user_regardless_of_client_flag(): void {
        $this->setUser($this->authorizeduser);
        $contextid = \context_course::instance($this->course->id)->id;

        $html = \aiplacement_modgen_output_fragment_course_toolbar([
            'courseid'           => $this->course->id,
            'contextid'          => $contextid,
            'showcheckstructure' => 0,
        ]);

        $this->assertStringContainsString('check_structure.php', $html);
    }

    /**
     * A user with no modgen capabilities at all must still be refused by the fragment
     * callback outright (pre-existing behaviour, unaffected by the trust fix above).
     *
     * @covers ::aiplacement_modgen_output_fragment_course_toolbar
     */
    public function test_fragment_toolbar_refuses_user_with_no_modgen_capabilities(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $nocapuser = $generator->create_user();
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $generator->role_assign($studentrole, $nocapuser->id, \context_course::instance($this->course->id)->id);

        $this->setUser($nocapuser);
        $contextid = \context_course::instance($this->course->id)->id;

        $this->expectException(required_capability_exception::class);
        \aiplacement_modgen_output_fragment_course_toolbar([
            'courseid'  => $this->course->id,
            'contextid' => $contextid,
        ]);
    }

    /**
     * check_structure.php gates its entire body behind require_capability() for this exact
     * capability, in course context, before any output or fix action runs. This pins that
     * the security primitive it relies on actually refuses an unauthorized user.
     *
     * @covers ::aiplacement_modgen_extend_navigation_course
     */
    public function test_unauthorized_user_cannot_pass_the_check_structure_page_capability_gate(): void {
        $this->setUser($this->partiallyauthorizeduser);
        $context = \context_course::instance($this->course->id);

        $this->expectException(required_capability_exception::class);
        require_capability('aiplacement/modgen:checkstructure', $context);
    }

    /**
     * Same gate, but for the fully authorized user — must not throw.
     *
     * @covers ::aiplacement_modgen_extend_navigation_course
     */
    public function test_authorized_user_passes_the_check_structure_page_capability_gate(): void {
        $this->setUser($this->authorizeduser);
        $context = \context_course::instance($this->course->id);

        require_capability('aiplacement/modgen:checkstructure', $context);
        $this->assertTrue(true); // No exception thrown - reaching here is the assertion.
    }
}
