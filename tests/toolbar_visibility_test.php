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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/ai/placement/modgen/lib.php');

/**
 * The toolbar shows exactly the entries a user may use, and does not appear at all when there are none.
 *
 * Every combination of the four capabilities, the four feature switches, the course format and
 * where in the course the toolbar is shown. A help link is configured throughout: help links are
 * not entries of their own, so they never keep an otherwise empty toolbar on the page.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::aiplacement_modgen_extend_navigation_course
 * @covers ::aiplacement_modgen_output_fragment_course_toolbar
 */
final class toolbar_visibility_test extends \advanced_testcase {
    /** @var string[] The capabilities that can show a toolbar entry. */
    private const CAPABILITIES = [
        'managestructure' => 'aiplacement/modgen:managestructure',
        'generatefromtemplate' => 'aiplacement/modgen:generatefromtemplate',
        'generatewithprompt' => 'aiplacement/modgen:generatewithprompt',
        'checkstructure' => 'aiplacement/modgen:checkstructure',
    ];

    /** @var string[] What marks each entry in the toolbar's HTML. */
    private const MARKERS = [
        'newtheme' => 'data-form="add_theme"',
        'newweek' => 'data-form="add_week"',
        'assessments' => 'data-form="add_assessments"',
        'fromfile' => 'data-action="open-generator"',
        'fromprompt' => 'data-form="template_from_prompt"',
        'checkstructure' => 'check_structure.php',
    ];

    /**
     * Data provider: every set of capabilities, in each format.
     *
     * @return array
     */
    public static function capability_sets_provider(): array {
        $cases = [];
        $names = array_keys(self::CAPABILITIES);
        for ($mask = 0; $mask < 2 ** count($names); $mask++) {
            $held = [];
            foreach ($names as $bit => $name) {
                if ($mask & (1 << $bit)) {
                    $held[] = $name;
                }
            }
            foreach (['flexsections', 'topics'] as $format) {
                $cases[$format . ': ' . ($held ? implode(', ', $held) : 'none')] = [$held, $format];
            }
        }
        return $cases;
    }

    /**
     * For one set of capabilities in one format, try every switch setting at the top of the course and inside a section.
     *
     * @dataProvider capability_sets_provider
     * @param string[] $held Capabilities the user holds (keys of CAPABILITIES).
     * @param string $format Course format.
     */
    public function test_toolbar_shows_only_usable_entries_and_nothing_without_any(array $held, string $format): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'aiplacement_modgen');
        set_config('enable_coreformats', 1, 'aiplacement_modgen');
        set_config('helplink1_text', 'Module design guide', 'aiplacement_modgen');
        set_config('helplink1_url', 'https://example.com/guide', 'aiplacement_modgen');
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        \core_plugin_manager::reset_caches();

        $course = $this->getDataGenerator()->create_course(['format' => $format, 'numsections' => 1]);
        $context = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (self::CAPABILITIES as $name => $capability) {
            // Nobody holds these by default; an explicit prohibition makes sure no other role grants them.
            assign_capability($capability, in_array($name, $held) ? CAP_ALLOW : CAP_PROHIBIT, $role, $context->id, true);
        }
        $this->setUser($user);

        // Every setting of the four switches, at the top of the course and inside a section.
        $switches = ['enable_quickadd', 'enable_assessments', 'enable_structurefromfile', 'enable_ai'];
        for ($mask = 0; $mask < 2 ** count($switches); $mask++) {
            $on = [];
            foreach ($switches as $bit => $switch) {
                $on[$switch] = (bool) ($mask & (1 << $bit));
                set_config($switch, (int) $on[$switch], 'aiplacement_modgen');
            }

            foreach ([0, 1] as $section) {
                $case = sprintf(
                    '%s, holding [%s], switched on [%s], %s',
                    $format,
                    implode(', ', $held),
                    implode(', ', array_keys(array_filter($on))),
                    $section ? 'inside section 1' : 'at the top'
                );
                $expected = self::expected_entries(
                    $held,
                    $format,
                    $on['enable_quickadd'],
                    $on['enable_assessments'],
                    $on['enable_structurefromfile'],
                    $on['enable_ai'],
                    $section
                );

                $this->assertSame(
                    $expected !== [],
                    $this->toolbar_added($course, $context, $section),
                    "The toolbar must be on the page exactly when it has an entry: $case."
                );
                $this->assertSame($expected, $this->rendered_entries($course->id, $section), "Entries shown: $case.");
            }
        }
    }

    /**
     * The entries a user should see: the rules the toolbar is meant to follow, written out.
     *
     * @param string[] $held Capabilities held.
     * @param string $format Course format.
     * @param bool $quickadd New Theme(s) and New Week(s) switched on.
     * @param bool $assessments Assessments section switched on.
     * @param bool $fromfile Structure from file switched on.
     * @param bool $ai AI generation switched on.
     * @param int $section Section the toolbar is shown in, 0 at the top.
     * @return string[] Entry names, in MARKERS order.
     */
    private static function expected_entries(
        array $held,
        string $format,
        bool $quickadd,
        bool $assessments,
        bool $fromfile,
        bool $ai,
        int $section
    ): array {
        $attop = $section === 0;
        // Themes, and weeks inside another section, need Flexible sections.
        $flex = $format === 'flexsections';
        $structure = $quickadd && in_array('managestructure', $held);
        return array_keys(array_filter([
            'newtheme' => $structure && $attop && $flex,
            'newweek' => $structure && ($attop || $flex),
            'assessments' => $assessments && in_array('managestructure', $held) && $attop,
            'fromfile' => $fromfile && in_array('generatefromtemplate', $held),
            'fromprompt' => $ai && in_array('generatewithprompt', $held),
            // Check Structure repairs Flexible sections hierarchies only.
            'checkstructure' => $flex && in_array('checkstructure', $held),
        ]));
    }

    /**
     * Whether the course page adds the toolbar, as it does while editing.
     *
     * @param \stdClass $course Course.
     * @param \context_course $context Its context.
     * @param int $section Section being viewed, 0 for the whole course.
     * @return bool
     */
    private function toolbar_added(\stdClass $course, \context_course $context, int $section): bool {
        global $PAGE, $USER;

        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-' . $course->format);
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $USER->editing = 1;
        $_GET['section'] = $section;
        try {
            aiplacement_modgen_extend_navigation_course(new \navigation_node('test'), $course, $context);
        } finally {
            unset($_GET['section']);
        }

        $amd = new \ReflectionProperty($PAGE->requires, 'amdjscode');
        return str_contains(implode("\n", $amd->getValue($PAGE->requires)), 'aiplacement_modgen/course_toolbar');
    }

    /**
     * The entries in the toolbar as the fragment renders it; none when it renders nothing or is refused.
     *
     * @param int $courseid Course ID.
     * @param int $section Section the toolbar is shown in.
     * @return string[] Entry names, in MARKERS order.
     */
    private function rendered_entries(int $courseid, int $section): array {
        try {
            $html = aiplacement_modgen_output_fragment_course_toolbar([
                'courseid' => $courseid,
                'currentsection' => $section,
            ]);
        } catch (\required_capability_exception $e) {
            return [];
        }
        return array_keys(array_filter(self::MARKERS, fn($marker) => str_contains($html, $marker)));
    }
}
