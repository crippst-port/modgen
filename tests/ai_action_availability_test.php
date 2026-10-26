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
 * Tests that AI generation honours the admin's AI action configuration.
 *
 * Core's placements check that a provider offers generate_text and that the action is
 * allowed for the placement before calling a provider. These tests pin that modgen does
 * the same, and that a refused request does not use up the user's rate limit.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/ai/placement/modgen/classes/local/ai_service.php');

/**
 * Tests for the generate_text availability check in ai_service.
 *
 * @covers \aiplacement_modgen\ai_service::generate_module
 */
final class ai_action_availability_test extends advanced_testcase {
    /**
     * Set up a user who has accepted the AI policy, with a configured, enabled provider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $manager = \core\di::get(\core_ai\manager::class);
        $manager->user_policy_accepted($user->id, \context_system::instance()->id);

        set_config('enabled', 1, 'aiprovider_openai');
        set_config('apikey', '123', 'aiprovider_openai');
        set_config('ai_rate_limit', 1, 'aiplacement_modgen');
    }

    /**
     * Assert the result is the "action unavailable" refusal.
     *
     * @param array $result Result of generate_module().
     */
    private function assert_refused(array $result): void {
        $this->assertTrue($result['error']);
        $this->assertSame(
            get_string('aierror', 'aiplacement_modgen', get_string('aierror_actionunavailable', 'aiplacement_modgen')),
            $result['template']
        );
    }

    /**
     * Assert the user still has their one request of rate limit left.
     */
    private function assert_rate_limit_untouched(): void {
        global $USER;
        $window = \cache::make('aiplacement_modgen', 'ai_requests')->get('user_' . $USER->id);
        $this->assertFalse($window, 'A refused request must not count against the rate limit.');
    }

    public function test_refused_when_action_disabled_for_placement(): void {
        set_config('generate_text', 0, 'aiplacement_modgen');

        $this->assert_refused(ai_service::generate_module('A module on rivers'));
        $this->assert_rate_limit_untouched();
    }

    public function test_refused_when_no_provider_offers_the_action(): void {
        set_config('generate_text', 0, 'aiprovider_openai');

        $this->assert_refused(ai_service::generate_module('A module on rivers'));
        $this->assert_rate_limit_untouched();
    }

    public function test_refused_when_provider_disabled(): void {
        set_config('enabled', 0, 'aiprovider_openai');

        $this->assert_refused(ai_service::generate_module('A module on rivers'));
        $this->assert_rate_limit_untouched();
    }
}
