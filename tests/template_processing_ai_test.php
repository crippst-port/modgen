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

use aiplacement_modgen\local\template_processing_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/structure_assertions.php');

/**
 * Choosing between the file-only and AI routes, and what the AI is told about an uploaded file.
 *
 * The provider is replaced through the DI container; each test records the prompt it was sent.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \aiplacement_modgen\local\template_processing_service
 */
final class template_processing_ai_test extends \advanced_testcase {
    use \aiplacement_modgen_structure_assertions;

    /** @var string A file with two themes and three weeks. */
    private const THEME_CSV = "Theme 1,Foundations\nWeek 1,Introduction\nWeek 2,Methods\nTheme 2,Application\nWeek 3,Practice\n";

    /** @var \stdClass Course. */
    private $course;

    /** @var string[] Prompts sent to the stand-in provider. */
    private array $prompts = [];

    /**
     * AI switched on with a stand-in provider that answers with a themed structure.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_placement();
        $this->course = $this->flexsections_course();

        set_config('enable_ai', 1, 'aiplacement_modgen');
        set_config('ai_rate_limit', 0, 'aiplacement_modgen');
        set_config('enabled', 1, 'aiprovider_openai');
        set_config('apikey', '123', 'aiprovider_openai');
        \core\di::get(\core_ai\manager::class)->user_policy_accepted(get_admin()->id, \context_system::instance()->id);

        $reply = json_encode(['themes' => [
            ['title' => 'From the AI', 'summary' => '', 'weeks' => [['title' => 'AI week', 'summary' => '']]],
        ]]);
        $response = $this->createMock(\core_ai\aiactions\responses\response_generate_text::class);
        $response->method('get_response_data')->willReturn(['generatedcontent' => $reply]);
        $manager = $this->createPartialMock(\core_ai\manager::class, ['process_action']);
        $manager->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\generate_text $action) use ($response) {
                $this->prompts[] = $action->get_configuration('prompttext');
                return $response;
            }
        );
        \core\di::set(\core_ai\manager::class, $manager);
    }

    /**
     * Run the service as the generator page does.
     *
     * @param array $fields Form fields to set.
     * @param string $compositeprompt The prompt the page has put together.
     * @return array
     */
    private function generate(array $fields, string $compositeprompt = 'Make it engaging'): array {
        $pdata = (object) array_merge([
            'supportingfiles' => 0,
            'prompt' => '',
            'moduletype' => 'connected_weekly',
            'selected_template_id' => 0,
        ], $fields);
        return (new template_processing_service())->process_and_generate($pdata, $this->course->id, $compositeprompt);
    }

    public function test_a_file_with_a_prompt_is_sent_to_the_ai_as_the_base_structure(): void {
        $itemid = $this->draft_file('plan.csv', self::THEME_CSV)['itemid'];

        $result = $this->generate(['supportingfiles' => $itemid, 'prompt' => 'Add readings']);

        $this->assertCount(1, $this->prompts);
        $prompt = $this->prompts[0];
        $this->assertStringContainsString('User request: Make it engaging', $prompt);
        $this->assertStringContainsString('*** BASE STRUCTURE FROM CSV ***', $prompt);
        $this->assertStringContainsString('"title": "Foundations"', $prompt);
        $this->assertStringContainsString('Create EXACTLY 2 themes with 3 weeks total', $prompt);
        $this->assertStringContainsString('*** NO TITLE CHANGES ***', $prompt);

        // The file decided the type, and the AI's answer is what comes back.
        $this->assertSame('connected_theme', $result['_detected_moduletype']);
        $this->assertSame('From the AI', $result['themes'][0]['title']);
    }

    public function test_a_weekly_file_counts_no_themes(): void {
        $itemid = $this->draft_file('plan.csv', "Week 1,Rivers\nWeek 2,Deltas\n")['itemid'];

        $this->generate(['supportingfiles' => $itemid, 'prompt' => 'Add readings']);

        $this->assertStringContainsString('Create EXACTLY 0 themes with 0 weeks total', $this->prompts[0]);
        $this->assertStringContainsString('"title": "Rivers"', $this->prompts[0]);
    }

    public function test_a_chosen_type_is_not_overridden_by_the_file(): void {
        $itemid = $this->draft_file('plan.csv', self::THEME_CSV)['itemid'];

        $result = $this->generate(['supportingfiles' => $itemid, 'prompt' => 'Add readings', 'moduletype' => 'connected_theme']);

        $this->assertSame('connected_theme', $result['_detected_moduletype']);
    }

    public function test_a_prompt_without_a_file_sends_no_base_structure(): void {
        $result = $this->generate(['prompt' => 'A module on rivers', 'moduletype' => 'connected_theme']);

        $this->assertCount(1, $this->prompts);
        $this->assertStringNotContainsString('BASE STRUCTURE FROM CSV', $this->prompts[0]);
        $this->assertSame('connected_theme', $result['_detected_moduletype']);
    }

    public function test_a_file_without_a_prompt_does_not_use_the_ai(): void {
        $itemid = $this->draft_file('plan.csv', self::THEME_CSV)['itemid'];

        $result = $this->generate(['supportingfiles' => $itemid]);

        $this->assertSame([], $this->prompts);
        $this->assertSame('connected_theme', $result['_detected_moduletype']);
        $this->assertSame('Foundations', $result['themes'][0]['title']);
    }

    public function test_a_library_template_with_a_prompt_is_sent_to_the_ai(): void {
        $templateid = local\template_manager::create('Library', '', $this->draft_file('lib.csv', self::THEME_CSV)['file']);

        $this->generate(['selected_template_id' => $templateid, 'prompt' => 'Add readings']);

        $this->assertStringContainsString('"title": "Foundations"', $this->prompts[0]);
    }

    public function test_with_ai_off_and_no_file_nothing_can_be_read(): void {
        set_config('enable_ai', 0, 'aiplacement_modgen');

        $this->expectExceptionObject(new \moodle_exception('csvnodraft', 'aiplacement_modgen'));
        $this->generate(['prompt' => 'A module on rivers']);
    }

    public function test_an_unknown_library_template_is_reported(): void {
        $this->expectException(\moodle_exception::class);
        $message = get_string('templateloaderror', 'aiplacement_modgen', '');
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');

        $this->generate(['selected_template_id' => 999999]);
    }

    public function test_a_library_template_whose_file_is_gone_is_reported(): void {
        global $DB;
        $templateid = local\template_manager::create('Library', '', $this->draft_file('lib.csv', self::THEME_CSV)['file']);
        $DB->set_field('aiplacement_modgen_templates', 'fileid', 999999, ['id' => $templateid]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('csvfilenotfound', 'aiplacement_modgen'), '/') . '/');

        $this->generate(['selected_template_id' => $templateid]);
    }
}
