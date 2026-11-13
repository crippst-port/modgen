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
 * Tests for how ai_service turns a provider's reply into a module structure.
 *
 * The provider is replaced through the DI container, so each test controls exactly what the
 * "AI" sends back: clean JSON, JSON wrapped in commentary or code fences, structures nested in
 * a wrapper key or stringified inside a summary, the wrong shape for the requested structure,
 * malformed structures, and plain text.
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
 * Tests for ai_service response parsing, validation and prompt building.
 *
 * @covers \aiplacement_modgen\local\ai_service
 */
final class ai_response_parsing_test extends advanced_testcase {
    /** @var string|null The prompt text the stand-in provider was last sent. */
    private ?string $sentprompt = null;

    /**
     * A user who has accepted the AI policy, with an enabled provider and no rate limit.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        \core\di::get(\core_ai\manager::class)->user_policy_accepted($user->id, \context_system::instance()->id);

        set_config('enabled', 1, 'aiprovider_openai');
        set_config('apikey', '123', 'aiprovider_openai');
        set_config('ai_rate_limit', 0, 'aiplacement_modgen');
    }

    /**
     * Stand in for the provider: process_action() returns a response whose data is $data.
     *
     * @param array|\Throwable $data Response data, or an exception to throw instead.
     */
    private function provider_returns($data): void {
        $response = $this->createMock(\core_ai\aiactions\responses\response_generate_text::class);
        $response->method('get_response_data')->willReturn(is_array($data) ? $data : []);

        $manager = $this->createPartialMock(\core_ai\manager::class, ['process_action']);
        $manager->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\generate_text $action) use ($response, $data) {
                $this->sentprompt = $action->get_configuration('prompttext');
                if ($data instanceof \Throwable) {
                    throw $data;
                }
                return $response;
            }
        );
        \core\di::set(\core_ai\manager::class, $manager);
    }

    /**
     * Stand in for a provider whose generated text is $text.
     *
     * @param string $text Generated text.
     */
    private function ai_says(string $text): void {
        $this->provider_returns(['generatedcontent' => $text]);
    }

    /**
     * A valid weekly structure.
     *
     * @return array
     */
    private function weekly(): array {
        return ['sections' => [
            ['title' => 'Week 1: Rivers', 'summary' => 'Where rivers start'],
            ['title' => 'Week 2: Deltas', 'summary' => 'Where rivers end'],
        ]];
    }

    /**
     * A valid themed structure.
     *
     * @return array
     */
    private function themed(): array {
        return ['themes' => [
            ['title' => 'Water', 'summary' => 'About water', 'weeks' => [['title' => 'Oct 1 - 7: Rivers', 'summary' => 'S']]],
            ['title' => 'Land', 'summary' => 'About land', 'weeks' => [['title' => 'Oct 8 - 14: Hills', 'summary' => 'S']]],
        ]];
    }

    /**
     * Assert the result is an error whose message names $stringid.
     *
     * @param array $result generate_module() result.
     * @param string $stringid Expected inner error string.
     * @param mixed $a Placeholder for the inner string.
     */
    private function assert_ai_error(array $result, string $stringid, $a = null): void {
        $this->assertTrue($result['error'] ?? false, 'Expected an error result.');
        $this->assertSame(
            get_string('aierror', 'aiplacement_modgen', get_string($stringid, 'aiplacement_modgen', $a)),
            $result['template']
        );
    }

    /**
     * Call a private static method of ai_service.
     *
     * @param string $name Method name.
     * @param mixed ...$args Arguments.
     * @return mixed
     */
    private function call(string $name, ...$args) {
        $method = new \ReflectionMethod(ai_service::class, $name);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    // Clean and wrapped JSON.

    public function test_clean_weekly_json_is_returned_as_it_is(): void {
        $this->ai_says(json_encode($this->weekly()));

        $this->assertSame($this->weekly(), ai_service::generate_module('Rivers', 'weekly'));
    }

    public function test_json_in_a_code_fence_with_commentary_is_read(): void {
        $fence = str_repeat(chr(96), 3); // A markdown code fence.
        $this->ai_says("Here is your module:\n{$fence}json\n" . json_encode($this->weekly()) . "\n{$fence}\nEnjoy!");

        $this->assertSame($this->weekly()['sections'], ai_service::generate_module('Rivers', 'weekly')['sections']);
    }

    public function test_json_inside_plain_commentary_is_read(): void {
        $this->ai_says('Sure! ' . json_encode($this->weekly()) . ' Let me know if you need changes.');

        $this->assertSame($this->weekly()['sections'], ai_service::generate_module('Rivers', 'weekly')['sections']);
    }

    public function test_structure_under_a_single_wrapper_key_is_unwrapped(): void {
        $this->ai_says(json_encode(['module' => $this->weekly()]));

        $this->assertSame($this->weekly(), ai_service::generate_module('Rivers', 'weekly'));
    }

    public function test_alternative_response_keys_are_read(): void {
        foreach (['generatedtext', 'text', 'content'] as $key) {
            $this->provider_returns([$key => json_encode($this->weekly())]);
            $this->assertSame($this->weekly(), ai_service::generate_module('Rivers', 'weekly'), $key);
        }
    }

    public function test_stringified_json_in_a_field_is_decoded(): void {
        $this->ai_says(json_encode(['sections' => [
            ['title' => 'Week 1', 'summary' => 'S', 'outline' => json_encode(['Read', 'Write'])],
        ]]));

        $result = ai_service::generate_module('Rivers', 'weekly');

        $this->assertSame(['Read', 'Write'], $result['sections'][0]['outline']);
    }

    public function test_whole_structure_stringified_in_a_theme_summary_is_used(): void {
        $inner = $this->themed();
        $this->ai_says(json_encode(['themes' => [
            ['title' => 'Wrapper', 'summary' => json_encode($inner), 'weeks' => []],
        ]]));

        $result = ai_service::generate_module('Rivers', 'theme');

        $this->assertSame(['Water', 'Land'], array_column($result['themes'], 'title'));
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_weekly_structure_stringified_in_a_section_summary_is_used(): void {
        $this->ai_says(json_encode(['sections' => [['title' => 'Wrapper', 'summary' => json_encode($this->weekly())]]]));

        $result = ai_service::generate_module('Rivers', 'weekly');

        $this->assertSame($this->weekly()['sections'], $result['sections']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_weekly_structure_escaped_in_a_lone_section_summary_is_used(): void {
        $this->ai_says(json_encode(['sections' => [
            ['title' => 'Wrapper', 'summary' => addslashes(json_encode($this->weekly()))],
        ]]));

        $this->assertSame($this->weekly()['sections'], ai_service::generate_module('Rivers', 'weekly')['sections']);
    }

    /**
     * A structure in one summary among other sections (an example, say) does not replace them all.
     */
    public function test_a_structure_in_one_of_several_summaries_does_not_replace_the_others(): void {
        $sections = [
            ['title' => 'Week 1', 'summary' => 'Plain text'],
            ['title' => 'Week 2', 'summary' => json_encode(['sections' => [['title' => 'Example']]])],
            ['title' => 'Week 3', 'summary' => 'More text'],
        ];
        $this->ai_says(json_encode(['sections' => $sections]));

        $result = ai_service::generate_module('Rivers', 'weekly');

        $this->assertSame(['Week 1', 'Week 2', 'Week 3'], array_column($result['sections'], 'title'));
    }

    /**
     * Ordinary text with brackets that are valid JSON on their own, such as citations, is kept as text
     * everywhere the build reads it.
     */
    public function test_citations_and_brackets_in_text_are_kept_as_text(): void {
        $this->ai_says(json_encode(['sections' => [[
            'title' => 'Part [2]: Deltas',
            'summary' => 'As Smith argues [1], rivers flood. See pages [10, 12] and {braces}.',
            'outline' => ['Read [3]', ['text' => 'Discuss [4]']],
        ]]]));

        $result = ai_service::generate_module('Rivers', 'weekly');

        $section = $result['sections'][0];
        $this->assertSame('Part [2]: Deltas', $section['title']);
        $this->assertSame('As Smith argues [1], rivers flood. See pages [10, 12] and {braces}.', $section['summary']);
        $this->assertSame(['Read [3]', ['text' => 'Discuss [4]']], $section['outline']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_a_theme_title_with_a_citation_does_not_fail_the_generation(): void {
        $themed = $this->themed();
        $themed['themes'][0]['title'] = 'Water [1]';
        $themed['themes'][0]['weeks'][0]['title'] = 'Oct 1 - 7: Rivers [2]';
        $this->ai_says(json_encode($themed));

        $result = ai_service::generate_module('Rivers', 'theme');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Water [1]', $result['themes'][0]['title']);
        $this->assertSame('Oct 1 - 7: Rivers [2]', $result['themes'][0]['weeks'][0]['title']);
    }

    // Theme requests.

    public function test_generated_theme_summaries_are_emptied(): void {
        $this->ai_says(json_encode($this->themed()));

        $result = ai_service::generate_module('Rivers', 'theme');

        $this->assertSame(['', ''], array_column($result['themes'], 'summary'));
        $this->assertSame('Oct 1 - 7: Rivers', $result['themes'][0]['weeks'][0]['title']);
    }

    public function test_sections_returned_for_a_theme_request_are_grouped_into_themes(): void {
        $this->ai_says(json_encode(['sections' => [
            ['title' => 'Unit 1 Basics', 'summary' => 'A'],
            ['title' => 'More basics', 'summary' => 'B'],
            ['title' => 'Unit 2 Advanced', 'summary' => 'C'],
        ]]));

        $result = ai_service::generate_module('Rivers', 'connected_theme');

        $this->assertArrayNotHasKey('sections', $result);
        $this->assertSame(['Unit 1', 'Unit 2'], array_column($result['themes'], 'title'));
        $this->assertSame(['Unit 1 Basics', 'More basics'], array_column($result['themes'][0]['weeks'], 'title'));
        $this->assertSame(['', ''], array_column($result['themes'], 'summary'));
    }

    public function test_section_titles_decide_theme_names(): void {
        $result = $this->call('convert_sections_to_themes', ['sections' => [
            ['title' => 'Introduction'],
            ['title' => 'Part 2 overview'],
            ['title' => 'Week 3 field work'],
            'not a section',
            ['title' => 'Module 4 review'],
        ]]);

        $this->assertSame(
            ['Introduction', 'Part: 2', 'Week 3 field work', 'Module 4'],
            array_column($result['themes'], 'title')
        );
    }

    public function test_activities_alone_become_a_session(): void {
        $activities = [['type' => 'quiz', 'name' => 'Check']];
        $result = $this->call('convert_sections_to_themes', ['sections' => [
            ['title' => 'Rivers', 'activities' => $activities],
            ['title' => 'Hills'],
        ]]);

        $weeks = $result['themes'][0]['weeks'];
        $this->assertSame(['description' => '', 'activities' => $activities], $weeks[0]['sessions']['session']);
        $this->assertArrayNotHasKey('sessions', $weeks[1], 'A week with nothing to put in a session gets none.');
    }

    public function test_nothing_to_convert_is_left_alone(): void {
        $this->assertSame(['themes' => []], $this->call('convert_sections_to_themes', ['themes' => []]));
        $this->assertSame(['sections' => []], $this->call('convert_sections_to_themes', ['sections' => []]));
    }

    // Validation.

    /**
     * Malformed structures and the validation message each one gets.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'weekly without sections' => ['weekly', ['activities' => []], 'aivalidation_missingsections'],
            'no sections' => ['weekly', ['sections' => []], 'aivalidation_noitems'],
            'section not an object' => ['weekly', ['sections' => ['Week 1']], 'aivalidation_itemnotarray'],
            'section without title' => ['weekly', ['sections' => [['summary' => 'S']]], 'aivalidation_missingtitle'],
            'blank title' => ['weekly', ['sections' => [['title' => '   ']]], 'aivalidation_missingtitle'],
            'theme without weeks' => ['theme', ['themes' => [['title' => 'T']]], 'aivalidation_missingweeks'],
            'weeks not a list' => ['theme', ['themes' => [['title' => 'T', 'weeks' => 'none']]], 'aivalidation_weeksnotarray'],
            'week not an object' => ['theme', ['themes' => [['title' => 'T', 'weeks' => ['W1']]]], 'aivalidation_weeknotarray'],
            'theme without title' => ['theme', ['themes' => [['weeks' => []]]], 'aivalidation_missingtitle'],
            'theme request with activities only' => ['theme', ['activities' => []], 'aivalidation_missingthemes'],
        ];
    }

    /**
     * A malformed structure is refused with a message that says what is wrong.
     *
     * @dataProvider malformed_provider
     * @param string $structure Requested structure.
     * @param array $reply What the AI sends back.
     * @param string $stringid Expected validation message.
     */
    public function test_malformed_structures_are_refused(string $structure, array $reply, string $stringid): void {
        $this->ai_says(json_encode($reply));

        $result = ai_service::generate_module('Rivers', $structure);

        $message = get_string($stringid, 'aiplacement_modgen');
        $this->assertSame($message, $result['validation_error']);
        $this->assertTrue($result['error']);
        $this->assertSame([], $result[$structure === 'theme' ? 'themes' : 'sections']);
    }

    // Replies that are not a structure.

    public function test_plain_text_is_wrapped_as_a_single_section(): void {
        $this->ai_says('I cannot produce JSON today.');

        $result = ai_service::generate_module('Rivers', 'weekly');

        $this->assertSame(
            [['title' => get_string('aigensummary', 'aiplacement_modgen'), 'summary' => 'I cannot produce JSON today.']],
            $result['sections']
        );
        $this->assertSame(get_string('aigeneratedvia_nonjson', 'aiplacement_modgen'), $result['template']);
    }

    public function test_plain_text_for_a_theme_request_is_wrapped_as_a_theme(): void {
        $this->provider_returns(['generatedcontent' => 'No JSON', 'revisedprompt' => 'Revised']);

        $result = ai_service::generate_module('Rivers', 'theme');

        $this->assertSame('No JSON', $result['themes'][0]['summary']);
        $this->assertSame('Revised', $result['template']);
    }

    public function test_json_without_a_structure_is_wrapped_as_text(): void {
        $this->ai_says('{"answer": 42}');

        $result = ai_service::generate_module('Rivers', 'weekly');

        $this->assertSame('{"answer": 42}', $result['sections'][0]['summary']);
    }

    public function test_an_empty_reply_is_an_error(): void {
        $this->provider_returns([]);

        $this->assert_ai_error(ai_service::generate_module('Rivers'), 'aierror_empty');
    }

    public function test_a_reply_without_text_names_the_keys_it_had(): void {
        $this->provider_returns(['id' => 'x', 'generatedcontent' => null, 'finishreason' => 'length']);

        $this->assert_ai_error(
            ai_service::generate_module('Rivers'),
            'aierror_notext',
            'id, generatedcontent, finishreason'
        );
    }

    public function test_a_provider_exception_is_reported_not_thrown(): void {
        $this->provider_returns(new \RuntimeException('Provider timed out'));

        $result = ai_service::generate_module('Rivers');

        $this->assertTrue($result['error']);
        $this->assertSame(get_string('aierror', 'aiplacement_modgen', 'Provider timed out'), $result['template']);
    }

    public function test_without_the_ai_policy_nothing_is_sent(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->ai_says(json_encode($this->weekly()));

        $this->assert_ai_error(ai_service::generate_module('Rivers'), 'aierror_policy');
        $this->assertNull($this->sentprompt);
    }

    // The prompt sent to the provider.

    public function test_a_weekly_prompt_asks_for_sections(): void {
        $this->ai_says(json_encode($this->weekly()));

        ai_service::generate_module('A module on rivers', 'connected_weekly');

        $this->assertStringContainsString('WEEKLY GENERATION', $this->sentprompt);
        $this->assertStringContainsString('User request: A module on rivers', $this->sentprompt);
        $this->assertStringNotContainsString('THEME GENERATION', $this->sentprompt);
    }

    public function test_the_default_guidance_is_used_without_a_base_prompt(): void {
        set_config('baseprompt', '', 'aiplacement_modgen');
        $this->ai_says(json_encode($this->weekly()));

        ai_service::generate_module('Rivers');

        $this->assertStringContainsString('expert Moodle learning content designer', $this->sentprompt);
        $this->assertStringContainsString('UK English', $this->sentprompt);
    }

    public function test_a_requested_theme_count_is_demanded(): void {
        $this->ai_says(json_encode($this->themed()));

        ai_service::generate_module('Split this into 5 themes please', 'theme');

        $this->assertStringContainsString('Generate EXACTLY 5 themes', $this->sentprompt);
        $this->assertStringContainsString('REQUIRED THEME COUNT: 5', $this->sentprompt);
    }

    public function test_without_a_theme_count_a_range_is_asked_for(): void {
        $this->ai_says(json_encode($this->themed()));

        ai_service::generate_module('A module on rivers', 'theme');

        $this->assertStringContainsString('Generate 4-7 themes', $this->sentprompt);
        $this->assertStringNotContainsString('REQUIRED THEME COUNT', $this->sentprompt);
    }

    /**
     * Theme counts read from the prompt, and the ones ignored.
     *
     * @return array
     */
    public static function theme_count_provider(): array {
        return [
            'plain' => ['Make 5 themes', 'theme', 5],
            'singular' => ['Use 3 theme groups', 'theme', 3],
            'themed sections' => ['12 themed sections', 'theme', 12],
            'upper limit' => ['20 themes', 'theme', 20],
            'too few' => ['1 theme', 'theme', null],
            'too many' => ['21 themes', 'theme', null],
            'no number' => ['Some themes', 'theme', null],
            'weekly ignores it' => ['5 themes', 'weekly', null],
        ];
    }

    /**
     * Reading the requested number of themes from the prompt.
     *
     * @dataProvider theme_count_provider
     * @param string $prompt Prompt.
     * @param string $structure Structure.
     * @param int|null $expected Expected count.
     */
    public function test_requested_theme_count(string $prompt, string $structure, ?int $expected): void {
        $this->assertSame($expected, $this->call('extract_requested_theme_count', $prompt, $structure));
    }

    public function test_week_dates_follow_the_course_start(): void {
        $course = $this->getDataGenerator()->create_course(['startdate' => make_timestamp(2026, 9, 28, 12)]);
        $this->ai_says(json_encode($this->themed()));

        ai_service::generate_module('Rivers', 'theme', $course->id);

        $this->assertStringContainsString('Include week dates in titles: "Sep 28 - 4"', $this->sentprompt);
        $this->assertSame('Oct 12 - 18', $this->call('get_week_date_range', 3, $course->id));
    }

    // The helpers on their own.

    /**
     * The helper undoes one extra level of escaping: a doubled backslash before n, t, r or a quote.
     */
    public function test_doubly_escaped_text_is_unescaped(): void {
        $this->assertSame("a\nb\tc\rd", $this->call('unescape_json_string', 'a\\\\nb\\\\tc\\\\rd'));
        $this->assertSame('say "hi"', $this->call('unescape_json_string', 'say \\\\"hi\\\\"'));
        $this->assertSame('a\\b', $this->call('unescape_json_string', 'a\\\\b'));
        $this->assertSame('a/b', $this->call('unescape_json_string', 'a\\/b'));
        $this->assertSame('plain', $this->call('unescape_json_string', 'plain'));
    }

    public function test_normalising_leaves_ordinary_values_alone(): void {
        $this->assertSame('', $this->call('normalize_ai_response', ''));
        $this->assertSame('Just text', $this->call('normalize_ai_response', 'Just text'));
        $this->assertSame('{not json', $this->call('normalize_ai_response', '{not json'));
        $this->assertSame(7, $this->call('normalize_ai_response', 7));
    }

    public function test_normalising_decodes_json_in_strings(): void {
        $this->assertSame(['a' => 1], $this->call('normalize_ai_response', '{"a": 1}'));
        $this->assertSame(['a' => 1], $this->call('normalize_ai_response', '{\\"a\\": 1}'), 'Escaped quotes.');
    }

    public function test_only_a_structure_is_taken_out_of_larger_text(): void {
        $this->assertSame(
            $this->weekly(),
            $this->call('normalize_ai_response', 'Here it is: ' . json_encode($this->weekly()) . ' Enjoy.')
        );
        foreach (['Result: {"a": 1} done', 'As Smith argues [1], rivers flood.', 'Pages [10, 12]'] as $text) {
            $this->assertSame($text, $this->call('normalize_ai_response', $text), $text);
        }
    }

    public function test_deep_unescaping_stops_at_its_depth_limit(): void {
        $data = ['x' => '{"y": 1}'];
        $this->assertSame($data, $this->call('deep_unescape_stringified_json', $data, 11));
        $this->assertSame(['x' => ['y' => 1]], $this->call('deep_unescape_stringified_json', $data));
        $this->assertSame('scalar', $this->call('deep_unescape_stringified_json', 'scalar'));
    }

    public function test_deep_unescaping_decodes_escaped_json(): void {
        $result = $this->call('deep_unescape_stringified_json', ['x' => '{\\\\"y\\\\": [1]}', 'z' => ['w' => '[2]']]);

        $this->assertSame(['x' => ['y' => [1]], 'z' => ['w' => [2]]], $result);
    }

    public function test_a_structure_in_a_lone_wrappers_summary_replaces_the_data(): void {
        $data = ['template' => 'Kept', 'themes' => [['title' => 'Wrapper', 'summary' => $this->themed()]]];

        $result = $this->call('extract_misplaced_content_from_summaries', $data);

        $this->assertSame(['Water', 'Land'], array_column($result['themes'], 'title'));
        $this->assertSame('Kept', $result['template']);

        $default = $this->call('extract_misplaced_content_from_summaries', ['sections' => [['summary' => $this->weekly()]]]);
        $this->assertSame(get_string('aigeneratedvia', 'aiplacement_modgen'), $default['template']);
    }

    public function test_a_structure_beside_other_themes_is_left_where_it_is(): void {
        $data = ['themes' => [
            ['title' => 'Real', 'summary' => 'Text'],
            ['title' => 'Wrapper', 'summary' => $this->themed()],
        ]];

        $this->assertSame($data, $this->call('extract_misplaced_content_from_summaries', $data));
    }

    public function test_a_lone_wrapper_without_a_structure_is_left_alone(): void {
        foreach (
            [
            ['sections' => ['not a section']],
            ['sections' => [['title' => 'One', 'summary' => ['other' => 1]]]],
            ['sections' => [['title' => 'One', 'summary' => '{"sections": []}']]],
            ] as $data
        ) {
            $this->assertSame($data, $this->call('extract_misplaced_content_from_summaries', $data));
        }
    }

    public function test_ordinary_summaries_are_not_mistaken_for_misplaced_content(): void {
        $data = $this->themed();
        $this->assertSame($data, $this->call('extract_misplaced_content_from_summaries', $data));
        $this->assertSame($this->weekly(), $this->call('extract_misplaced_content_from_summaries', $this->weekly()));
        $this->assertSame('x', $this->call('extract_misplaced_content_from_summaries', 'x'));
    }
}
