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
 * Tests for the routes changed when Suggest, base-on-existing-module, example content and
 * Hide existing sections were removed, and for the authorization and transaction fixes made
 * alongside that.
 *
 * The AJAX scripts end in exit(), so the logic worth testing lives in classes and fragment
 * callbacks and is driven from here.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\ai_service;
use aiplacement_modgen\local\csv_processing_service;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\task\create_sections_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/upgradelib.php');
/**
 * Behaviour tests for the changed routes.
 *
 * @covers \aiplacement_modgen\local\theme_builder::rollback_quietly
 * @covers \aiplacement_modgen\local\csv_processing_service
 * @covers \aiplacement_modgen\task\create_sections_task
 * @covers ::aiplacement_modgen_fragment_context
 */
final class removed_features_routes_test extends advanced_testcase {
    /** @var \stdClass Course the user works in. */
    private $course;

    /** @var \stdClass A second course the user must not be able to reach through the first. */
    private $other;

    /**
     * Two flexsections courses and the placement switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('enabled', 1, 'aiplacement_modgen');
        set_config('enable_quickadd', 1, 'aiplacement_modgen');
        set_config('enable_structurefromfile', 1, 'aiplacement_modgen');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'flexsections', 'numsections' => 0]);
        $this->other = $generator->create_course(['format' => 'flexsections', 'numsections' => 0]);
        theme_builder::prepare_course_for_build($this->course->id);
        theme_builder::prepare_course_for_build($this->other->id);
    }

    /**
     * The dates-to-sections feature is gone: no endpoints, no fragment, no capability.
     */
    public function test_dates_to_sections_is_removed(): void {
        foreach (['apply', 'preview', 'remove'] as $name) {
            $this->assertFileDoesNotExist(__DIR__ . '/../ajax/' . $name . '_section_dates.php');
        }
        $this->assertFalse(function_exists('aiplacement_modgen_output_fragment_form_dates_for_sections'));
        $this->assertNull(get_capability_info('aiplacement/modgen:managedates'));
    }

    // ------------------------------------------------------------------
    // Fragment callbacks.

    /**
     * A caller-chosen context id must be the course's own context.
     */
    public function test_fragment_context_must_match_course(): void {
        $own = \context_course::instance($this->course->id);
        $foreign = \context_course::instance($this->other->id);

        $this->assertSame($own->id, aiplacement_modgen_fragment_context($this->course->id, 0)->id);
        $this->assertSame($own->id, aiplacement_modgen_fragment_context($this->course->id, $own->id)->id);

        $this->expectException(\moodle_exception::class);
        aiplacement_modgen_fragment_context($this->course->id, $foreign->id);
    }

    /**
     * The forms that take a context id reject a context that is not the course's.
     *
     * @dataProvider contextid_fragments
     * @param string $function Fragment callback.
     */
    public function test_fragments_reject_foreign_context(string $function): void {
        $foreign = \context_course::instance($this->other->id);

        $this->expectException(\moodle_exception::class);
        $function(['courseid' => $this->course->id, 'contextid' => $foreign->id]);
    }

    /**
     * Fragments that accept a caller-supplied context id.
     *
     * @return array
     */
    public static function contextid_fragments(): array {
        return [
            'add theme' => ['aiplacement_modgen_output_fragment_form_add_theme'],
            'add week' => ['aiplacement_modgen_output_fragment_form_add_week'],
            'prompt' => ['aiplacement_modgen_output_fragment_form_template_from_prompt'],
        ];
    }

    /**
     * With the course's own context the forms still render.
     *
     * @dataProvider contextid_fragments
     * @param string $function Fragment callback.
     */
    public function test_fragments_render_with_own_context(string $function): void {
        $html = $function([
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);

        $this->assertStringContainsString('<form', $html);
    }

    /**
     * The Suggest fragment is gone, along with its capability.
     */
    public function test_suggest_fragment_and_capability_are_gone(): void {
        $this->assertFalse(function_exists('aiplacement_modgen_output_fragment_form_suggest'));
        $this->assertNull(get_capability_info('aiplacement/modgen:usesuggest'));
    }

    /**
     * The toolbar has no Suggest entry or AI-suggestions link, and ignores a stale showsuggest argument.
     */
    public function test_toolbar_has_no_suggest_or_ai_suggestions_link(): void {
        $args = [
            'courseid' => $this->course->id,
            'contextid' => \context_course::instance($this->course->id)->id,
            'showsuggest' => 1,
        ];

        $html = aiplacement_modgen_output_fragment_course_toolbar($args);
        $this->assertStringNotContainsString('data-form="suggest"', $html);
        $this->assertStringNotContainsString('aigen_list.php', $html);
    }

    /**
     * The AI-suggestion markers are gone: their code, pages, observers and table.
     */
    public function test_ai_suggestion_markers_are_removed(): void {
        global $CFG, $DB;
        $this->assertFalse(class_exists(\aiplacement_modgen\aigen_tracker::class));
        $this->assertFalse(class_exists(\aiplacement_modgen\event\observer::class));
        foreach (['aigen_list.php', 'ajax/get_aigen.php', 'ajax/toggle_visibility.php', 'db/events.php'] as $path) {
            $this->assertFileDoesNotExist($CFG->dirroot . '/ai/placement/modgen/' . $path);
        }
        $this->assertFalse($DB->get_manager()->table_exists('aiplacement_modgen_aigen'));
    }

    // ------------------------------------------------------------------
    // Forms.

    /**
     * Field names that belonged to the removed features.
     *
     * @return string[]
     */
    private function removed_field_names(): array {
        return ['existing_modules', 'expandonthemes', 'generateexamplecontent', 'hideexistingsections', 'suggestedcontentheader'];
    }

    /**
     * The generator forms render without the removed fields.
     */
    public function test_generator_forms_render_without_removed_fields(): void {
        global $CFG;
        set_config('enable_ai', 1, 'aiplacement_modgen');
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/generator_form.php');
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/prompt_form.php');

        $forms = [
            new \aiplacement_modgen_generator_form(null, ['courseid' => $this->course->id]),
            new \aiplacement_modgen_prompt_form(null, ['courseid' => $this->course->id]),
        ];

        foreach ($forms as $form) {
            $html = $form->render();
            foreach ($this->removed_field_names() as $name) {
                $this->assertStringNotContainsString($name, $html, get_class($form) . " still renders {$name}");
            }
        }
    }

    /**
     * The approve form no longer needs the removed hidden fields in its custom data.
     */
    public function test_approve_form_needs_no_removed_custom_data(): void {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/approve_form.php');

        $form = new \aiplacement_modgen_approve_form(null, [
            'courseid' => $this->course->id,
            'approvedjson' => '{"themes":[]}',
            'moduletype' => 'connected_theme',
            'generatedsummary' => '',
        ]);
        $html = $form->render();

        $this->assertStringContainsString('approvedjson', $html);
        foreach (['generatethemeintroductions', 'createsuggestedactivities', 'hideexistingsections'] as $name) {
            $this->assertStringNotContainsString($name, $html);
        }
    }

    /**
     * Input is required (shown against the file field), and an existing module no longer satisfies it.
     */
    public function test_generator_form_validation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/generator_form.php');
        $form = new \aiplacement_modgen_generator_form(null, ['courseid' => $this->course->id]);

        $this->assertArrayHasKey('supportingfiles', $form->validation([], []));
        $this->assertArrayHasKey('supportingfiles', $form->validation(['existing_modules' => [5]], []));
        $this->assertSame([], $form->validation(['prompt' => 'A module on soil science'], []));
    }

    /**
     * A prompt of only whitespace is refused on the server, not just by the browser.
     *
     * @covers \aiplacement_modgen_prompt_form::validation
     */
    public function test_prompt_form_refuses_a_blank_prompt(): void {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/classes/form/prompt_form.php');
        $form = new \aiplacement_modgen_prompt_form(null, ['courseid' => $this->course->id]);

        $this->assertArrayHasKey('prompt', $form->validation(['prompt' => "  \n\t "], []));
        $this->assertArrayHasKey('prompt', $form->validation([], []));
        $this->assertSame([], $form->validation(['prompt' => 'A module on soil science'], []));
    }

    // ------------------------------------------------------------------
    // Services.

    /**
     * Pure CSV mode now depends only on AI being off, or a CSV with no prompt.
     */
    public function test_pure_csv_mode_rules(): void {
        $service = new csv_processing_service();

        $this->assertTrue($service->should_use_pure_csv_mode(false, false, true), 'AI off: always CSV.');
        $this->assertTrue($service->should_use_pure_csv_mode(true, true, false), 'CSV and no prompt.');
        $this->assertFalse($service->should_use_pure_csv_mode(true, true, true), 'A prompt hands over to the AI.');
        $this->assertFalse($service->should_use_pure_csv_mode(true, false, false), 'No CSV: AI.');
        $this->assertFalse(method_exists($service, 'build_csv_enhancement_instructions'));
    }

    /**
     * Removed service methods and the shortened signatures stay removed.
     */
    public function test_removed_api_stays_removed(): void {
        $this->assertFalse(method_exists(ai_service::class, 'generate_suggestions_from_map'));
        $this->assertFalse(method_exists(ai_service::class, 'analyze_module'));
        $this->assertFalse(method_exists(ai_service::class, 'generate_module_with_template'));
        $this->assertFalse(class_exists(\aiplacement_modgen\local\template_reader::class));
        $this->assertFalse(class_exists(\aiplacement_modgen\local\learning_type_colors::class));
        $this->assertFalse(method_exists(\aiplacement_modgen\local\settings_helper::class, 'is_suggest_enabled'));
        $this->assertFalse(method_exists(\aiplacement_modgen\local\settings_helper::class, 'is_hideexistingsections_enabled'));

        $this->assertSame(
            ['prompt', 'structure', 'courseid'],
            array_map(fn($p) => $p->getName(), (new \ReflectionMethod(ai_service::class, 'generate_module'))->getParameters())
        );
        $this->assertSame(
            ['json', 'courseid', 'moduletype', 'createsummaryactivities'],
            array_map(
                fn($p) => $p->getName(),
                (new \ReflectionMethod(section_creation_service::class, 'create_sections_from_json'))->getParameters()
            )
        );
    }

    /**
     * Without AI, a CSV in the draft area builds a structure through the whole service.
     */
    public function test_template_processing_service_builds_structure_from_csv(): void {
        global $USER;
        set_config('enable_ai', 0, 'aiplacement_modgen');

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'structure.csv',
        ], "Theme 1,Foundations\nWeek 1,Introduction\nWeek 2,Methods\n");

        $result = (new local\template_processing_service())->process_and_generate(
            (object) ['supportingfiles' => $draftid, 'moduletype' => 'connected_weekly', 'prompt' => ''],
            $this->course->id,
            ''
        );

        $this->assertIsArray($result);
        $this->assertSame('connected_theme', $result['_detected_moduletype']);
    }

    // ------------------------------------------------------------------
    // Transactions and the task.

    /**
     * Number of open delegated transactions.
     *
     * The PHPUnit harness itself keeps one open, so tests compare depth against a baseline
     * rather than asking whether any transaction is open.
     *
     * @return int
     */
    private function transaction_depth(): int {
        global $DB;
        $property = new \ReflectionProperty(get_class($DB), 'transactions');
        $property->setAccessible(true);
        return count($property->getValue($DB));
    }

    /**
     * Rolling back closes the transaction, and a second call on it is harmless.
     */
    public function test_rollback_quietly_closes_transaction_and_is_idempotent(): void {
        global $DB;
        $baseline = $this->transaction_depth();

        $transaction = $DB->start_delegated_transaction();
        $this->assertSame($baseline + 1, $this->transaction_depth());

        theme_builder::rollback_quietly($transaction, new \Exception('boom'));
        $this->assertTrue($transaction->is_disposed());
        $this->assertSame($baseline, $this->transaction_depth());

        theme_builder::rollback_quietly($transaction, new \Exception('again'));
        $this->assertSame($baseline, $this->transaction_depth());
    }

    /**
     * A TypeError (an Error, not an Exception) inside a build still rolls back: no transaction
     * is left open and nothing is created.
     */
    public function test_failed_build_leaves_no_open_transaction(): void {
        global $DB;
        $baseline = $this->transaction_depth();
        $before = $DB->count_records('course_sections', ['course' => $this->course->id]);

        try {
            (new section_creation_service())->create_sections_from_json(
                ['themes' => [['title' => ['not', 'a', 'string'], 'summary' => 's', 'weeks' => []]]],
                $this->course->id,
                'connected_theme'
            );
            $this->fail('A malformed structure should fail the build.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $this->assertSame($baseline, $this->transaction_depth(), 'The failed build must not leave a transaction open.');
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * Queue a job and build the matching task.
     *
     * @param int $userid Job owner.
     * @param array $customdata Task fields.
     * @return array [jobid, task]
     */
    private function queue(int $userid, array $customdata): array {
        global $DB;
        $jobid = (int) $DB->insert_record('aiplacement_modgen_jobs', (object) [
            'courseid' => $this->course->id,
            'userid' => $userid,
            'action' => $customdata['action'],
            'status' => 'queued',
            'parameters' => json_encode($customdata),
            'timecreated' => time(),
        ]);
        $task = new create_sections_task();
        $task->set_custom_data((object) (['jobid' => $jobid, 'courseid' => $this->course->id] + $customdata));
        $task->set_userid($userid);
        return [$jobid, $task];
    }

    /**
     * Run a task with its output swallowed.
     *
     * @param create_sections_task $task Task.
     */
    private function run_task(create_sections_task $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A job whose owner cannot be loaded fails terminally instead of running as the cron user.
     */
    public function test_task_fails_terminally_when_owner_is_missing(): void {
        global $DB;
        [$jobid, $task] = $this->queue(999999, [
            'action' => 'create_themes', 'themecount' => 1, 'weeksperTheme' => 1, 'parentsection' => 0,
        ]);
        $before = $DB->count_records('course_sections', ['course' => $this->course->id]);

        $this->run_task($task);

        $job = $DB->get_record('aiplacement_modgen_jobs', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('failed', $job->status);
        $this->assertNotEmpty($job->timecompleted);
        $this->assertFalse(json_decode($job->result, true)['will_retry']);
        $this->assertSame($before, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * Jobs queued before the upgrade carry the removed fields in their custom data; they must
     * still run to completion.
     */
    public function test_queued_task_with_legacy_fields_still_completes(): void {
        global $DB, $USER;
        [$jobid, $task] = $this->queue((int) $USER->id, [
            'action' => 'create_from_json',
            'moduletype' => 'connected_theme',
            'json' => ['themes' => [['title' => 'Legacy theme', 'summary' => 's', 'weeks' => [
                ['title' => 'Legacy week', 'summary' => 'w', 'sessions' => []],
            ]]]],
            'generatethemeintroductions' => true,
            'createsuggestedactivities' => true,
            'hideexistingsections' => true,
        ]);

        $this->run_task($task);
        $this->resetDebugging();

        $this->assertSame('completed', $DB->get_field('aiplacement_modgen_jobs', 'status', ['id' => $jobid]));
        $this->assertTrue($DB->record_exists('course_sections', ['course' => $this->course->id, 'name' => 'Legacy theme']));
    }

    // ------------------------------------------------------------------
    // Settings and upgrade.

    /**
     * The admin page no longer offers the removed settings and still offers the kept ones.
     */
    public function test_settings_page_offers_only_current_settings(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $names = [];
        $root = admin_get_root(true, true);
        $page = $root->locate('aiplacement_modgen');
        $this->assertNotNull($page, 'The plugin settings page should exist.');
        foreach ($page->settings as $setting) {
            $names[] = $setting->name;
        }

        foreach (
            ['enable_suggest', 'suggest_pedagogical_guidance', 'enable_existing_modules', 'enable_hideexistingsections'] as $gone
        ) {
            $this->assertNotContains($gone, $names);
        }
        foreach (['enable_quickadd', 'enable_structurefromfile', 'enable_ai'] as $kept) {
            $this->assertContains($kept, $names);
        }
    }

    /**
     * The upgrade step deletes the stale settings and leaves the others.
     */
    public function test_upgrade_removes_stale_settings(): void {
        global $CFG;
        require_once($CFG->dirroot . '/ai/placement/modgen/db/upgrade.php');

        foreach (
            ['enable_suggest', 'suggest_pedagogical_guidance', 'enable_existing_modules', 'enable_hideexistingsections'] as $name
        ) {
            set_config($name, '1', 'aiplacement_modgen');
        }
        set_config('enable_quickadd', '1', 'aiplacement_modgen');

        // Rewind the recorded version so the savepoint behaves as in a real upgrade.
        set_config('version', 2026091002, 'aiplacement_modgen');

        $this->assertTrue(xmldb_aiplacement_modgen_upgrade(2026091002));

        foreach (
            ['enable_suggest', 'suggest_pedagogical_guidance', 'enable_existing_modules', 'enable_hideexistingsections'] as $name
        ) {
            $this->assertFalse(get_config('aiplacement_modgen', $name), "{$name} should be deleted");
        }
        $this->assertSame('1', get_config('aiplacement_modgen', 'enable_quickadd'));
    }
}
