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
use aiplacement_modgen\local\ai_service;

/**
 * Tests for the generate_text availability check in ai_service.
 *
 * @covers \aiplacement_modgen\local\ai_service::generate_module
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

    /**
     * The structure handed back for preview carries only the module: not the prompt sent to the
     * provider (which includes the administrator's base prompt), nor the raw provider response.
     */
    public function test_generated_structure_does_not_carry_the_prompt_or_raw_response(): void {
        set_config('baseprompt', 'CONFIDENTIAL SITE GUIDANCE', 'aiplacement_modgen');
        $structure = ['sections' => [['title' => 'Week 1', 'summary' => 'Rivers']]];

        $response = new \core_ai\aiactions\responses\response_generate_text(true);
        $response->set_response_data(['generatedcontent' => json_encode($structure)]);
        $manager = $this->createPartialMock(\core_ai\manager::class, ['process_action']);
        $manager->expects($this->once())->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\generate_text $action) use ($response) {
                // The base prompt is sent to the provider...
                $this->assertStringContainsString('CONFIDENTIAL SITE GUIDANCE', $action->get_configuration('prompttext'));
                return $response;
            }
        );
        \core\di::set(\core_ai\manager::class, $manager);

        $result = ai_service::generate_module('A module on rivers', 'weekly');

        // ...but nothing that goes back to the browser includes it.
        $this->assertSame('Week 1', $result['sections'][0]['title']);
        foreach (['raw', 'debugprompt', 'debugresponse'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
        $this->assertStringNotContainsString('CONFIDENTIAL SITE GUIDANCE', json_encode($result));
    }

    /**
     * Regenerate is only offered when generating would actually be allowed: the plugin's AI setting,
     * the capability, and Site administration > AI must all allow it.
     *
     * @covers \aiplacement_modgen\local\settings_helper::can_generate_with_ai
     */
    public function test_ai_generation_is_offered_only_when_it_would_be_allowed(): void {
        $this->setAdminUser();
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        set_config('enable_ai', 1, 'aiplacement_modgen');
        $this->assertTrue(local\settings_helper::can_generate_with_ai($context));

        set_config('generate_text', 0, 'aiplacement_modgen');
        $this->assertFalse(local\settings_helper::can_generate_with_ai($context), 'Action off for the placement.');
        set_config('generate_text', 1, 'aiplacement_modgen');

        set_config('enable_ai', 0, 'aiplacement_modgen');
        $this->assertFalse(local\settings_helper::can_generate_with_ai($context), 'AI switched off in the plugin.');
        set_config('enable_ai', 1, 'aiplacement_modgen');

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(local\settings_helper::can_generate_with_ai($context), 'No capability.');
    }

    /**
     * When the AI answers a theme request with sections, converting them to themes keeps what the
     * build reads: each section's sessions and metadata, and outline lines as session text.
     */
    public function test_sections_converted_to_themes_keep_their_sessions_and_outline(): void {
        $method = new \ReflectionMethod(ai_service::class, 'convert_sections_to_themes');
        $method->setAccessible(true);
        $sessions = ['session' => ['description' => 'Seminar', 'learningactivity_metadata' => ['name' => 'Seminar']]];

        $result = $method->invoke(null, ['sections' => [
            ['title' => 'Theme 1', 'sessions' => $sessions, 'learningactivity_metadata' => ['name' => 'Week summary']],
            ['title' => 'Rivers', 'outline' => [['text' => 'Read chapter 1'], 'Field trip', ['nottext' => 1]]],
        ]]);

        $weeks = $result['themes'][0]['weeks'];
        $this->assertSame($sessions, $weeks[0]['sessions']);
        $this->assertSame(['name' => 'Week summary'], $weeks[0]['learningactivity_metadata']);
        $this->assertSame("Read chapter 1\nField trip", $weeks[1]['sessions']['session']['description']);
        $this->assertArrayNotHasKey('sections', $result);
    }
}
