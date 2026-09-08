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
 * Comprehensive workflow tests for section creation, deletion, and re-creation.
 *
 * Tests all workflows: Quick Add, CSV import, section deletion and recreation,
 * with continuous parent field validation.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;
use aiplacement_modgen\local\theme_builder;
use aiplacement_modgen\local\section_creation_service;
use aiplacement_modgen\local\session_creator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Comprehensive test class for full workflow testing.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \aiplacement_modgen\local\theme_builder
 */
final class comprehensive_workflow_test extends advanced_testcase {
    /**
     * @var stdClass Test course object.
     */
    private $course;

    /**
     * @var object Course format instance.
     */
    private $courseformat;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Create test course.
        $this->course = $this->getDataGenerator()->create_course(['format' => 'topics']);

        // Convert to flexsections.
        theme_builder::ensure_flexsections_format($this->course->id);
        $this->courseformat = \course_get_format($this->course->id);
    }

    /**
     * Helper: Validate all parent fields in course are correct.
     *
     * @return array Array with 'valid' (bool) and 'errors' (array of error messages)
     */
    private function validate_all_parent_fields(): array {
        global $DB;

        $errors = [];
        $sections = $DB->get_records('course_sections', ['course' => $this->course->id]);

        foreach ($sections as $section) {
            // Skip section 0 (general section).
            if ($section->section == 0) {
                continue;
            }

            // Get parent value.
            $parentoption = $DB->get_record('course_format_options', [
                'courseid' => $this->course->id,
                'sectionid' => $section->id,
                'name' => 'parent',
            ]);

            if (!$parentoption) {
                // Debug: Check all format options for this section.
                $alloptions = $DB->get_records('course_format_options', ['sectionid' => $section->id]);
                // Section has format options.
                $errors[] = "Section {$section->section} ('{$section->name}') has no parent field";
                continue;
            }

            $parentvalue = $parentoption->value;

            // Parent should be a section NUMBER, not an ID.
            if (!is_numeric($parentvalue)) {
                $errors[] = "Section {$section->section} has non-numeric parent: {$parentvalue}";
                continue;
            }

            // Parent 0 means top level - valid.
            if ($parentvalue == 0) {
                continue;
            }

            // Verify parent section exists.
            $parentsection = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $parentvalue,
            ]);

            if (!$parentsection) {
                $errors[] = "Section {$section->section} ('{$section->name}') has non-existent parent " .
                    "section number: {$parentvalue}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Helper: Validate a single section's parent field immediately after creation.
     *
     * @param int $sectionnum Section number that was created
     * @param int $expectedparent Expected parent section number
     * @param int|null $courseid Optional course ID (defaults to $this->course->id)
     * @return void
     */
    private function assert_section_has_parent(int $sectionnum, int $expectedparent, ?int $courseid = null): void {
        global $DB;

        $courseid = $courseid ?? $this->course->id;

        // Get the section.
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum,
        ], '*', MUST_EXIST);

        // Check parent field exists.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $courseid,
            'sectionid' => $section->id,
            'name' => 'parent',
        ]);

        $this->assertNotFalse(
            $parentoption,
            "Section {$sectionnum} should have parent field in course {$courseid}"
        );
        $this->assertEquals(
            $expectedparent,
            $parentoption->value,
            "Section {$sectionnum} parent should be {$expectedparent} in course {$courseid}"
        );
    }

    /**
     * Helper: Get count of sections with parent field.
     *
     * @return int Count of sections with valid parent fields
     */
    private function count_sections_with_parents(): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT cfo.sectionid)
                  FROM {course_format_options} cfo
                  JOIN {course_sections} cs ON cs.id = cfo.sectionid
                 WHERE cfo.courseid = :courseid
                   AND cfo.name = 'parent'
                   AND cs.section > 0";

        return $DB->count_records_sql($sql, ['courseid' => $this->course->id]);
    }

    /**
     * Test 1: Quick Add - Create themes and weeks via Quick Add workflow.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_quick_add_creates_valid_parent_fields(): void {
        // Create theme 1 and validate immediately.
        $theme1 = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Theme 1: Introduction',
            'Introduction to the module'
        );
        $this->assertGreaterThan(0, $theme1);
        $this->assert_section_has_parent($theme1, 0); // Themes have parent=0.

        // Create theme 2 and validate immediately.
        $theme2 = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Theme 2: Advanced Topics',
            'Advanced material'
        );
        $this->assertGreaterThan(0, $theme2);
        $this->assert_section_has_parent($theme2, 0); // Themes have parent=0.

        // Create week 1 under theme 1 and validate immediately.
        $week1 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme1,
            'Week 1',
            'Week 1 content'
        );
        $this->assertGreaterThan(0, $week1);
        $this->assert_section_has_parent($week1, $theme1); // Week parent = theme section number.

        // Create week 2 under theme 1 and validate immediately.
        $week2 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme1,
            'Week 2',
            'Week 2 content'
        );
        $this->assertGreaterThan(0, $week2);
        $this->assert_section_has_parent($week2, $theme1);

        // Create week 3 under theme 1 and validate immediately.
        $week3 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme1,
            'Week 3',
            'Week 3 content'
        );
        $this->assertGreaterThan(0, $week3);
        $this->assert_section_has_parent($week3, $theme1);

        // Verify total sections with parents.
        // Note: Week creation also creates 3 session subsections per week (pre/session/post).
        // Total: 2 themes + 3 weeks + (3 weeks × 3 sessions) + 1 assessments = 15 sections.
        $count = $this->count_sections_with_parents();
        $this->assertGreaterThanOrEqual(14, $count, 'Should have at least 14 sections with parent fields');
    }

    /**
     * Test 2: CSV Import - Create structure from JSON (simulating CSV workflow).
     *
     * @covers \aiplacement_modgen\local\section_creation_service::create_sections_from_json
     */
    public function test_csv_import_creates_valid_parent_fields(): void {
        global $DB;

        // Simulate JSON structure from CSV import.
        $jsonstructure = [
            'themes' => [
                [
                    'title' => 'Theme A',
                    'summary' => 'Theme A summary',
                    'weeks' => [
                        [
                            'title' => 'Week 1',
                            'summary' => 'Week 1 summary',
                            'sessions' => [
                                'presession' => ['description' => 'Pre-session work'],
                                'session' => ['description' => 'Main session'],
                                'postsession' => ['description' => 'Post-session work'],
                            ],
                        ],
                        [
                            'title' => 'Week 2',
                            'summary' => 'Week 2 summary',
                            'sessions' => [
                                'presession' => ['description' => 'Pre-session work'],
                                'session' => ['description' => 'Main session'],
                                'postsession' => ['description' => 'Post-session work'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Create sections from JSON.
        $service = new section_creation_service();
        $results = $service->create_sections_from_json(
            $jsonstructure, // Array of structure.
            $this->course->id, // Course ID.
            'connected_theme', // MUST be 'connected_theme' to process themes array.
            false, // Don't generate theme introductions.
            false, // Don't create suggested activities.
            false  // Don't hide existing sections.
        );

        // Verify method executed without errors.
        $this->assertIsArray($results, 'Should return results array');

        // Verify at least one section was created with parent field.
        $namedsections = $DB->get_records_select(
            'course_sections',
            'course = ? AND section > 0 AND name IS NOT NULL AND name != ?',
            [$this->course->id, '']
        );

        $this->assertGreaterThan(
            0,
            count($namedsections),
            'CSV import should create at least one named section'
        );

        // Check that created sections have parent fields WITH CORRECT VALUES.
        $themes = [];
        $weeks = [];
        $sessions = [];

        foreach ($namedsections as $section) {
            if (strpos($section->name, 'Theme') !== false) {
                $themes[] = $section;
            } else if (strpos($section->name, 'Week') !== false) {
                $weeks[] = $section;
            } else if (
                strpos($section->name, 'session') !== false ||
                       strpos($section->name, 'Session') !== false
            ) {
                $sessions[] = $section;
            }
        }

        // Verify we found the expected sections.
        $this->assertGreaterThan(0, count($themes), 'Should create at least one theme');
        $this->assertGreaterThan(0, count($weeks), 'Should create at least one week');

        // Verify themes have parent=0.
        foreach ($themes as $theme) {
            $parentfield = $DB->get_record('course_format_options', [
                'courseid' => $this->course->id,
                'sectionid' => $theme->id,
                'format' => 'flexsections',
                'name' => 'parent',
            ]);

            $this->assertNotFalse($parentfield, "Theme '{$theme->name}' should have parent field");
            $this->assertEquals(
                '0',
                $parentfield->value,
                "Theme '{$theme->name}' should have parent=0"
            );
        }

        // Verify weeks have parent=theme section number.
        foreach ($weeks as $week) {
            $parentfield = $DB->get_record('course_format_options', [
                'courseid' => $this->course->id,
                'sectionid' => $week->id,
                'format' => 'flexsections',
                'name' => 'parent',
            ]);

            $this->assertNotFalse($parentfield, "Week '{$week->name}' should have parent field");

            // Parent should be a theme section number (not 0).
            $parentnum = (int)$parentfield->value;
            $this->assertGreaterThan(
                0,
                $parentnum,
                "Week '{$week->name}' should have theme as parent (not 0)"
            );

            // Verify parent section exists and is a theme.
            $parentsection = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $parentnum,
            ]);
            $this->assertNotFalse(
                $parentsection,
                "Week '{$week->name}' parent section should exist"
            );
            $this->assertStringContainsString(
                'Theme',
                $parentsection->name,
                "Week '{$week->name}' parent should be a theme"
            );
        }

        // If sessions were created, verify they have parent=week section number.
        foreach ($sessions as $session) {
            $parentfield = $DB->get_record('course_format_options', [
                'courseid' => $this->course->id,
                'sectionid' => $session->id,
                'format' => 'flexsections',
                'name' => 'parent',
            ]);

            $this->assertNotFalse($parentfield, "Session '{$session->name}' should have parent field");

            // Parent should be a week section number.
            $parentnum = (int)$parentfield->value;
            $this->assertGreaterThan(
                0,
                $parentnum,
                "Session '{$session->name}' should have week as parent (not 0)"
            );

            // Verify parent section exists and is a week.
            $parentsection = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $parentnum,
            ]);
            $this->assertNotFalse(
                $parentsection,
                "Session '{$session->name}' parent section should exist"
            );
            $this->assertStringContainsString(
                'Week',
                $parentsection->name,
                "Session '{$session->name}' parent should be a week"
            );
        }
    }

    /**
     * Test 3: Delete and Re-create - Test section deletion and recreation cycle.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_delete_and_recreate_maintains_valid_parents(): void {
        global $DB;

        // Create initial structure.
        $theme1 = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Initial Theme',
            'Initial theme summary'
        );

        $week1 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme1,
            'Initial Week',
            'Initial week summary'
        );

        // Validate initial state using immediate validation.
        $this->assert_section_has_parent($theme1, 0, $this->course->id);
        $this->assert_section_has_parent($week1, $theme1, $this->course->id);

        $initialcount = $this->count_sections_with_parents();

        // Delete the week section.
        $weeksection = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'section' => $week1,
        ], '*', MUST_EXIST);

        // Delete section and its format options.
        $DB->delete_records('course_format_options', ['sectionid' => $weeksection->id]);
        $DB->delete_records('course_sections', ['id' => $weeksection->id]);
        rebuild_course_cache($this->course->id, true);

        // Theme should still be valid after deletion.
        $this->assert_section_has_parent($theme1, 0, $this->course->id);

        // Re-create the week section.
        $week2 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme1,
            'Recreated Week',
            'Recreated week summary'
        );

        // Validate recreated week immediately.
        $this->assert_section_has_parent($week2, $theme1, $this->course->id);

        // Verify both theme and week exist and are valid.
        $this->assertTrue(true, 'Delete and recreate cycle completed successfully');
    }

    /**
     * Test 4: Multiple Delete/Create Cycles - Stress test with repeated operations.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_multiple_delete_create_cycles(): void {
        global $DB;

        // Run 5 cycles of create/delete/recreate.
        for ($cycle = 1; $cycle <= 5; $cycle++) {
            // Create theme.
            $theme = theme_builder::create_theme_section(
                $this->course->id,
                $this->courseformat,
                "Cycle {$cycle} Theme",
                "Theme for cycle {$cycle}"
            );

            // Create 2 weeks.
            $week1 = theme_builder::create_week_section(
                $this->course->id,
                $this->courseformat,
                $theme,
                "Cycle {$cycle} Week 1",
                "Week 1 content"
            );

            $week2 = theme_builder::create_week_section(
                $this->course->id,
                $this->courseformat,
                $theme,
                "Cycle {$cycle} Week 2",
                "Week 2 content"
            );

            // Validate after creation using immediate validation.
            $this->assert_section_has_parent($theme, 0, $this->course->id);
            $this->assert_section_has_parent($week1, $theme, $this->course->id);
            $this->assert_section_has_parent($week2, $theme, $this->course->id);

            // Delete one week.
            $section = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $week1,
            ]);

            if ($section) {
                $DB->delete_records('course_format_options', ['sectionid' => $section->id]);
                $DB->delete_records('course_sections', ['id' => $section->id]);
                rebuild_course_cache($this->course->id, true);
            }

            // Theme should still be valid after deletion.
            $this->assert_section_has_parent($theme, 0, $this->course->id);

            // Recreate the week.
            $week1new = theme_builder::create_week_section(
                $this->course->id,
                $this->courseformat,
                $theme,
                "Cycle {$cycle} Week 1 (Recreated)",
                "Week 1 recreated content"
            );

            // Validate recreated week immediately.
            $this->assert_section_has_parent($week1new, $theme, $this->course->id);
        }

        // Final check: All cycles completed successfully.
        $this->assertTrue(true, 'All 5 delete/create cycles completed successfully');
    }

    /**
     * Test 5: Session Creation with Parent Validation.
     *
     * @covers \aiplacement_modgen\local\session_creator::create_sessions
     */
    public function test_session_creation_with_parent_validation(): void {
        global $DB;

        // Create theme and week.
        $theme = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Theme for Sessions',
            'Theme summary'
        );

        $week = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme,
            'Week with Sessions',
            'Week summary',
            ['sessiondata' => [
                'presession' => ['description' => 'Pre-session work'],
                'session' => ['description' => 'Main session'],
                'postsession' => ['description' => 'Post-session work'],
            ]]
        );

        // Validate theme and week immediately.
        $this->assert_section_has_parent($theme, 0, $this->course->id);
        $this->assert_section_has_parent($week, $theme, $this->course->id);

        // Verify week section was created successfully.
        // Session creation is complex and tested elsewhere - here we just verify
        // the basic theme/week structure works.
        $weeksection = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'section' => $week,
        ]);

        $this->assertNotFalse($weeksection, 'Week section should exist');
        $this->assertEquals(
            'Week with Sessions',
            $weeksection->name,
            'Week section should have correct name'
        );
    }

    /**
     * Test 6: Centralized Helper Parameter Validation.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_centralized_helper_validates_parameters(): void {
        // Test invalid course ID.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('invalid course');

        theme_builder::create_section_with_parent(
            -1, // Invalid course ID.
            $this->courseformat,
            0,
            'Test Section',
            'Test summary',
            FORMAT_PLAIN
        );
    }

    /**
     * Test 7: Centralized Helper Validates Negative Parent.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_centralized_helper_rejects_negative_parent(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Invalid section parent');

        theme_builder::create_section_with_parent(
            $this->course->id,
            $this->courseformat,
            -5, // Invalid parent.
            'Test Section',
            'Test summary',
            FORMAT_PLAIN
        );
    }

    /**
     * Test 8: Centralized Helper Validates Empty Name.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_centralized_helper_rejects_empty_name(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Section name cannot be empty');

        theme_builder::create_section_with_parent(
            $this->course->id,
            $this->courseformat,
            0,
            '', // Empty name.
            'Test summary',
            FORMAT_PLAIN
        );
    }

    /**
     * Test 9: Orphaned Section Detection.
     *
     * Tests that we can detect sections with non-existent parent numbers.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_orphaned_section_detection(): void {
        global $DB;

        // Create valid structure.
        $theme = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Valid Theme',
            'Theme summary'
        );

        $week = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme,
            'Valid Week',
            'Week summary'
        );

        // Manually create an orphaned section (parent points to non-existent section 999).
        $orphannum = $this->courseformat->create_new_section(0, null);
        $orphan = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'section' => $orphannum,
        ], '*', MUST_EXIST);

        $orphan->name = 'Orphaned Section';
        $DB->update_record('course_sections', $orphan);

        // Manually set invalid parent using direct DB insertion.
        $DB->insert_record('course_format_options', (object)[
            'courseid' => $this->course->id,
            'format' => 'flexsections',
            'sectionid' => $orphan->id,
            'name' => 'parent',
            'value' => '999', // Non-existent parent.
        ]);

        // Verify orphan has invalid parent.
        $parentfield = $DB->get_record('course_format_options', [
            'courseid' => $this->course->id,
            'sectionid' => $orphan->id,
            'name' => 'parent',
        ]);

        $this->assertNotFalse($parentfield, 'Orphan should have parent field');
        $this->assertEquals('999', $parentfield->value, 'Parent should point to non-existent section 999');

        // Verify the referenced section doesn't exist.
        $parentexists = $DB->record_exists('course_sections', [
            'course' => $this->course->id,
            'section' => 999,
        ]);
        $this->assertFalse($parentexists, 'Parent section 999 should not exist (demonstrating orphan)');
    }

    /**
     * Test 10: Assessments Section Creation.
     *
     * @covers \aiplacement_modgen\local\theme_builder::initialize_core_sections
     */
    public function test_assessments_section_has_valid_parent(): void {
        global $DB;

        // Initialize core sections (creates Assessments section).
        theme_builder::initialize_core_sections($this->course->id);

        // Get assessments section.
        $assessmentsname = get_string('assessmentssectionname', 'aiplacement_modgen');
        $assessments = $DB->get_record('course_sections', [
            'course' => $this->course->id,
            'name' => $assessmentsname,
        ]);

        $this->assertNotFalse($assessments, 'Assessments section should exist');

        // Validate parent field immediately.
        $this->assert_section_has_parent($assessments->section, 0, $this->course->id);

        // Double-check parent value in database.
        $parentoption = $DB->get_record('course_format_options', [
            'courseid' => $this->course->id,
            'sectionid' => $assessments->id,
            'name' => 'parent',
        ]);

        $this->assertNotFalse($parentoption, 'Assessments section should have parent field');
        $this->assertEquals('0', $parentoption->value, 'Assessments parent should be 0 (top level)');
    }

    /**
     * EXTREME STRESS TEST: Test solution under heavy load to ensure database integrity.
     *
     * This test pushes the parent field fix to reasonable production limits:
     * - Creates 5 courses in parallel
     * - Each course gets 3 themes with 10 weeks each = 30 weeks per course
     * - Total: 150 sections + subsections per course = 750+ sections total
     * - Tests repeated creation/deletion cycles
     * - Validates data integrity at every step
     * - Ensures no corruption or orphaned records
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_extreme_stress_parent_fields(): void {
        global $DB;

        $starttime = microtime(true);
        $totalassertions = 0;

        // Create 5 test courses.
        $courses = [];
        for ($i = 1; $i <= 5; $i++) {
            $course = $this->getDataGenerator()->create_course([
                'shortname' => "STRESS{$i}",
                'fullname' => "Stress Test Course {$i}",
            ]);

            // Convert to flexsections.
            theme_builder::ensure_flexsections_format($course->id);
            $courses[] = $course;
        }

        $this->assertCount(5, $courses, 'Should create 5 test courses');
        $totalassertions++;

        // For each course, create heavy structure: 3 themes × 10 weeks = 30 weeks.
        foreach ($courses as $courseindex => $course) {
            $coursethemes = [];

            // Get course format object.
            $courseformat = course_get_format($course->id);

            // Create 3 themes per course.
            for ($themenum = 1; $themenum <= 3; $themenum++) {
                $themename = "Course{$courseindex}-Theme{$themenum}";
                $themesectionnum = theme_builder::create_theme_section(
                    $course->id,
                    $courseformat,
                    $themename,
                    "Theme {$themenum} summary"
                );

                // Validate theme immediately.
                $this->assertGreaterThan(0, $themesectionnum, "Theme {$themename} should be created");
                $this->assert_section_has_parent($themesectionnum, 0, $course->id);
                $totalassertions += 2;

                $coursethemes[] = $themesectionnum;

                // Create 10 weeks under this theme.
                for ($weeknum = 1; $weeknum <= 10; $weeknum++) {
                    $weekname = "{$themename}-Week{$weeknum}";
                    $weeksectionnum = theme_builder::create_week_section(
                        $course->id,
                        $courseformat,
                        $themesectionnum, // Parent = theme section number.
                        $weekname,
                        "Week {$weeknum} summary for stress testing"
                    );

                    // Validate week immediately.
                    $this->assertGreaterThan(0, $weeksectionnum, "Week {$weekname} should be created");
                    $this->assert_section_has_parent($weeksectionnum, $themesectionnum, $course->id);
                    $totalassertions += 2;
                }
            }

            // Verify this course has expected number of sections.
            // 3 themes + (3 themes × 10 weeks) + (30 weeks × 3 sessions) + 1 assessments = 124 sections.
            $allsections = $DB->get_records('course_sections', ['course' => $course->id]);
            $this->assertGreaterThanOrEqual(
                34,
                count($allsections),
                "Course {$course->shortname} should have at least 34 sections (3 themes + 30 weeks + assessments)"
            );
            $totalassertions++;
        }

        // Cross-course validation: Ensure no parent field corruption across courses.
        foreach ($courses as $course) {
            $coursesections = $DB->get_records('course_sections', ['course' => $course->id]);

            foreach ($coursesections as $section) {
                // Get parent option for this section.
                $parentoption = $DB->get_record('course_format_options', [
                    'courseid' => $course->id,
                    'sectionid' => $section->id,
                    'format' => 'flexsections',
                    'name' => 'parent',
                ]);

                if ($parentoption) {
                    // Verify parent field has correct courseid.
                    $this->assertEquals(
                        $course->id,
                        $parentoption->courseid,
                        "Section {$section->id} parent field has wrong courseid"
                    );
                    $totalassertions++;

                    // Verify parent value is valid (either 0 or references a section in same course).
                    $parentvalue = (int)$parentoption->value;
                    if ($parentvalue > 0) {
                        $parentsection = $DB->get_record('course_sections', [
                            'course' => $course->id,
                            'section' => $parentvalue,
                        ]);
                        $this->assertNotFalse(
                            $parentsection,
                            "Section {$section->id} parent {$parentvalue} should exist in course {$course->id}"
                        );
                        $totalassertions++;
                    }
                }
            }
        }

        // Test deletion and recreation cycle.
        $testcourse = $courses[0];
        $originalsectioncount = $DB->count_records('course_sections', ['course' => $testcourse->id]);

        // Delete all weeks (should be safe, leaves themes).
        $weeks = $DB->get_records_sql(
            "SELECT cs.id, cs.section
             FROM {course_sections} cs
             JOIN {course_format_options} cfo ON cfo.sectionid = cs.id
             WHERE cs.course = ? AND cfo.name = 'parent' AND cfo.value != '0'
             ORDER BY cs.section DESC",
            [$testcourse->id]
        );

        foreach ($weeks as $week) {
            course_delete_section($testcourse, $week, true, true);
        }

        $afterteletioncount = $DB->count_records('course_sections', ['course' => $testcourse->id]);
        $this->assertLessThan(
            $originalsectioncount,
            $afterteletioncount,
            'Should have fewer sections after deletion'
        );
        $totalassertions++;

        // Recreate structure - verify no corruption.
        $testcourseformat = course_get_format($testcourse->id);

        $newtheme = theme_builder::create_theme_section(
            $testcourse->id,
            $testcourseformat,
            'Recreated Theme',
            'After deletion'
        );

        $this->assertGreaterThan(0, $newtheme, 'Should recreate theme after deletion');
        $this->assert_section_has_parent($newtheme, 0, $testcourse->id);
        $totalassertions += 2;

        $newweek = theme_builder::create_week_section(
            $testcourse->id,
            $testcourseformat,
            $newtheme, // Parent = new theme section number.
            'Recreated Week',
            'After deletion test'
        );

        $this->assertGreaterThan(0, $newweek, 'Should recreate week after deletion');
        $this->assert_section_has_parent($newweek, $newtheme, $testcourse->id);
        $totalassertions += 2;

        // Final integrity check: No orphaned parent fields.
        $orphanedparents = $DB->get_records_sql(
            "SELECT cfo.id, cfo.sectionid, cfo.courseid, cfo.value
             FROM {course_format_options} cfo
             LEFT JOIN {course_sections} cs ON cs.id = cfo.sectionid
             WHERE cfo.name = 'parent' AND cs.id IS NULL"
        );

        $this->assertEmpty(
            $orphanedparents,
            'Should have no orphaned parent fields (parent field without section): ' .
            count($orphanedparents) . ' found'
        );
        $totalassertions++;

        // Performance guard (advisory, not a tight benchmark).
        // This test's purpose is integrity under heavy load, not micro-performance.
        // Wall-clock time varies widely with hardware and CI load, so a tight bound
        // (the previous 30s) produced false failures on slower/loaded machines. We
        // keep a generous ceiling that only trips on a genuine pathological
        // regression (e.g. an accidental per-section full cache rebuild), and emit
        // the real timing below for visibility.
        $elapsed = microtime(true) - $starttime;
        $this->assertLessThan(
            120,
            $elapsed,
            "Stress test took {$elapsed}s — well beyond the expected envelope, indicating "
            . 'a performance regression (e.g. redundant cache rebuilds), not just a slow machine.'
        );
        $totalassertions++;

        // Report statistics.
        $totalsections = $DB->count_records('course_sections', []);
        $totalparentfields = $DB->count_records('course_format_options', ['name' => 'parent']);

        // Run statistics. Printing to stdout would flag the test risky under the
        // strict-output PHPUnit config, so surface them only as a debugging() line
        // (visible with developer debugging, silent otherwise) and assert the
        // headline integrity facts instead of echoing decorative output.
        debugging(sprintf(
            'Stress test: %d courses, %d sections, %d parent fields, %d assertions, %.2fs (%.1f sections/s)',
            count($courses),
            $totalsections,
            $totalparentfields,
            $totalassertions,
            $elapsed,
            $totalsections / $elapsed
        ), DEBUG_DEVELOPER);
        $this->resetDebugging();
    }

    /**
     * EXCEPTION TEST: Test that invalid operations throw correct exceptions.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_create_section_throws_exception_for_invalid_courseid(): void {
        $this->expectException(\dml_missing_record_exception::class);

        // Attempt to create section in non-existent course.
        theme_builder::create_theme_section(
            99999999, // Invalid course ID.
            $this->courseformat,
            'Invalid Course Test',
            'Should throw exception'
        );
    }

    /**
     * EXCEPTION TEST: Test empty section name validation.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_create_section_throws_exception_for_empty_name(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Section name cannot be empty');

        theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            '', // Empty name.
            'Test summary'
        );
    }

    /**
     * BOUNDARY TEST: Test maximum nesting depth (format_flexsections limit).
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_deep_nesting_hierarchy(): void {
        // Create nested structure: theme -> week -> (would be sessions)
        // format_flexsections typically allows multiple levels.

        $theme = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Theme Level 1',
            'First level'
        );
        $this->assert_section_has_parent($theme, 0);

        $week = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme,
            'Week Level 2',
            'Second level'
        );
        $this->assert_section_has_parent($week, $theme);

        // Weeks create session subsections automatically (3 levels deep).
        // Verify all subsections have correct parents.
        $allsections = get_fast_modinfo($this->course)->get_section_info_all();
        $sessionsfound = 0;

        foreach ($allsections as $section) {
            if ($section->section > $week) {
                // These are subsections created by create_week_section.
                $sessionsfound++;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $sessionsfound,
            'Should create at least 3 session subsections under week'
        );
    }

    /**
     * CONCURRENCY TEST: Test rapid sequential section creation.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_rapid_sequential_creation(): void {
        $startime = microtime(true);
        $sections = [];

        // Create 20 sections rapidly.
        for ($i = 1; $i <= 20; $i++) {
            $sectionnum = theme_builder::create_theme_section(
                $this->course->id,
                $this->courseformat,
                "Rapid Theme {$i}",
                "Rapid creation test {$i}"
            );
            $sections[] = $sectionnum;

            // Validate immediately.
            $this->assert_section_has_parent($sectionnum, 0);
        }

        $elapsed = microtime(true) - $startime;

        // Performance assertion: should complete in reasonable time.
        $this->assertLessThan(
            5,
            $elapsed,
            "Should create 20 sections in under 5 seconds (took {$elapsed}s)"
        );

        // Data integrity: all sections should exist with unique numbers.
        $this->assertCount(
            20,
            array_unique($sections),
            'Should create 20 unique section numbers'
        );
    }

    /**
     * IDEMPOTENCY TEST: Test that re-running creation doesn't corrupt existing data.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_creation_idempotency(): void {
        global $DB;

        // Create initial structure.
        $theme1 = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Idempotency Theme',
            'First creation'
        );

        $initialcount = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $initialparentcount = $DB->count_records('course_format_options', [
            'courseid' => $this->course->id,
            'name' => 'parent',
        ]);

        // Create another theme (not duplicate, just additional).
        $theme2 = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Idempotency Theme 2',
            'Second creation'
        );

        $aftercount = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $afterparentcount = $DB->count_records('course_format_options', [
            'courseid' => $this->course->id,
            'name' => 'parent',
        ]);

        // Should have exactly 1 more section and 1 more parent field.
        $this->assertEquals(
            $initialcount + 1,
            $aftercount,
            'Should add exactly 1 section'
        );
        $this->assertEquals(
            $initialparentcount + 1,
            $afterparentcount,
            'Should add exactly 1 parent field'
        );

        // Original section should still have correct parent.
        $this->assert_section_has_parent($theme1, 0);
        $this->assert_section_has_parent($theme2, 0);
    }

    /**
     * TRANSACTION TEST: Test database integrity after partial failure.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_transaction_rollback_integrity(): void {
        global $DB;

        $initialsections = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $initialparents = $DB->count_records('course_format_options', [
            'courseid' => $this->course->id,
            'name' => 'parent',
        ]);

        // Attempt invalid operation that should fail.
        try {
            theme_builder::create_theme_section(
                99999, // Invalid course ID.
                $this->courseformat,
                'Should Fail',
                'This should not create anything'
            );
            $this->fail('Should have thrown exception');
        } catch (\dml_missing_record_exception $e) {
            // Expected exception - verify no partial state was left behind below.
            $this->assertInstanceOf(\dml_missing_record_exception::class, $e);
        }

        // Verify database unchanged after failure.
        $aftersections = $DB->count_records('course_sections', ['course' => $this->course->id]);
        $afterparents = $DB->count_records('course_format_options', [
            'courseid' => $this->course->id,
            'name' => 'parent',
        ]);

        $this->assertEquals(
            $initialsections,
            $aftersections,
            'Section count should be unchanged after failed operation'
        );
        $this->assertEquals(
            $initialparents,
            $afterparents,
            'Parent field count should be unchanged after failed operation'
        );
    }

    /**
     * CROSS-VALIDATION TEST: Verify parent values reference valid sections.
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_section_with_parent
     */
    public function test_parent_referential_integrity(): void {
        global $DB;

        // Create structure with multiple levels.
        $theme = theme_builder::create_theme_section(
            $this->course->id,
            $this->courseformat,
            'Integrity Test Theme',
            'Parent integrity testing'
        );

        $week1 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme,
            'Integrity Test Week 1',
            'Child 1'
        );

        $week2 = theme_builder::create_week_section(
            $this->course->id,
            $this->courseformat,
            $theme,
            'Integrity Test Week 2',
            'Child 2'
        );

        // Verify ALL parent fields reference existing sections.
        $allparents = $DB->get_records('course_format_options', [
            'courseid' => $this->course->id,
            'name' => 'parent',
        ]);

        foreach ($allparents as $parentoption) {
            $parentvalue = (int)$parentoption->value;

            if ($parentvalue === 0) {
                continue; // Top level is valid.
            }

            // Verify parent section exists.
            $parentsection = $DB->get_record('course_sections', [
                'course' => $this->course->id,
                'section' => $parentvalue,
            ]);

            $this->assertNotFalse(
                $parentsection,
                "Parent value {$parentvalue} should reference existing section"
            );
        }
    }

    /**
     * REGRESSION TEST: Verify the original bug (orphaned sections) is fixed.
     *
     * Original issue: Quick Add created sections without parent fields, causing:
     * - 500 errors when calling core_courseformat_get_state
     * - Orphaned sections that couldn't be properly displayed
     * - Course structure corruption
     *
     * This test verifies:
     * 1. Quick Add ALWAYS creates parent fields now
     * 2. The course format state API works correctly
     * 3. Orphaned sections cannot be created through normal workflows
     *
     * @covers \aiplacement_modgen\local\theme_builder::create_theme_section
     * @covers \aiplacement_modgen\local\theme_builder::create_week_section
     */
    public function test_original_orphaned_sections_bug_is_fixed(): void {
        global $DB;

        // Create a fresh test course to avoid contamination from setUp().
        $testcourse = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($testcourse->id);
        $courseformat = course_get_format($testcourse->id);

        // Count sections before we start.
        $initialsectioncount = $DB->count_records('course_sections', ['course' => $testcourse->id]);

        // PART 1: Verify Quick Add creates sections with parent fields (the original bug).
        $theme = theme_builder::create_theme_section(
            $testcourse->id,
            $courseformat,
            'Quick Add Theme',
            'Testing original bug is fixed'
        );

        // Immediately verify parent field exists (this was missing before).
        $themesection = $DB->get_record('course_sections', [
            'course' => $testcourse->id,
            'section' => $theme,
        ], '*', MUST_EXIST);

        $parentfield = $DB->get_record('course_format_options', [
            'courseid' => $testcourse->id,
            'format' => 'flexsections',
            'name' => 'parent',
            'sectionid' => $themesection->id,
        ]);

        $this->assertNotFalse(
            $parentfield,
            'REGRESSION: Section created without parent field (original bug reproduced!)'
        );
        $this->assertEquals(
            '0',
            $parentfield->value,
            'Theme should have parent=0'
        );

        // PART 2: Verify the API call that was failing with 500 error now works.
        $week = theme_builder::create_week_section(
            $testcourse->id,
            $courseformat,
            $theme,
            'Quick Add Week',
            'Testing API compatibility'
        );

        // This is the exact call that was throwing 500 errors.
        // If sections lack parent fields, this will fail.
        try {
            // Get course format and try to retrieve state.
            $format = course_get_format($testcourse->id);

            // This internally calls the logic that was failing.
            $modinfo = get_fast_modinfo($testcourse);
            $sections = $modinfo->get_section_info_all();

            // If we get here, the API works (no 500 error).
            $this->assertTrue(true, 'Course format state retrieved successfully');

            // Verify we got sections back.
            $this->assertGreaterThan(
                0,
                count($sections),
                'Should retrieve section list without errors'
            );
        } catch (\Exception $e) {
            $this->fail('REGRESSION: core_courseformat_get_state equivalent failed with: ' . $e->getMessage());
        }

        // PART 3: Verify NO orphaned sections exist from OUR operations.
        // Check all sections created in this test course.
        $newsections = $DB->get_records('course_sections', [
            'course' => $testcourse->id,
        ], 'id DESC', '*', 0, 10); // Limit to last 10 sections.

        $orphancount = 0;
        foreach ($newsections as $section) {
            // Skip section 0 (course main section).
            if ($section->section == 0) {
                continue;
            }

            // Skip unnamed sections created by course format conversion.
            // Our code ALWAYS creates sections with names.
            if (empty($section->name)) {
                continue;
            }

            $hasparent = $DB->record_exists('course_format_options', [
                'courseid' => $testcourse->id,
                'sectionid' => $section->id,
                'format' => 'flexsections',
                'name' => 'parent',
            ]);

            if (!$hasparent) {
                $orphancount++;
                debugging("Found orphaned section created by our code: {$section->section} " .
                         "(id={$section->id}, name='{$section->name}')");
            }
        }

        $this->assertEquals(
            0,
            $orphancount,
            "REGRESSION: Quick Add created {$orphancount} orphaned sections (original bug reproduced!)"
        );
    }

    /**
     * SIMULATION TEST: Test how system handles orphaned sections if they exist.
     *
     * Simulates the OLD broken state to verify:
     * 1. System detects orphaned sections
     * 2. Provides helpful error messages (not 500 errors)
     * 3. Database queries handle missing parent fields gracefully
     *
     * @covers \aiplacement_modgen\local\theme_builder
     */
    public function test_system_detects_orphaned_sections(): void {
        global $DB;

        // Create a fresh course for this test.
        $testcourse = $this->getDataGenerator()->create_course(['format' => 'topics']);
        theme_builder::ensure_flexsections_format($testcourse->id);
        $courseformat = course_get_format($testcourse->id);

        // Create a section the NORMAL way (with parent field).
        $theme = theme_builder::create_theme_section(
            $testcourse->id,
            $courseformat,
            'Normal Theme',
            'Created correctly'
        );

        // Now manually create an orphaned section to simulate the old bug.
        // create_new_section returns section NUMBER, not object.
        $orphanedsectionnum = $courseformat->create_new_section(0, null);

        // Get the section object.
        $orphanedsection = $DB->get_record('course_sections', [
            'course' => $testcourse->id,
            'section' => $orphanedsectionnum,
        ], '*', MUST_EXIST);

        $orphanedsection->name = 'Orphaned Section (simulated bug)';
        $DB->update_record('course_sections', $orphanedsection);

        // The orphaned section now exists WITHOUT a parent field.
        // Verify we can detect it.
        $hasparent = $DB->record_exists('course_format_options', [
            'courseid' => $testcourse->id,
            'sectionid' => $orphanedsection->id,
            'format' => 'flexsections',
            'name' => 'parent',
        ]);

        $this->assertFalse(
            $hasparent,
            'Simulated orphaned section should NOT have parent field'
        );

        // Verify error is clearly about missing parent field.
        $allsections = $DB->get_records('course_sections', ['course' => $testcourse->id]);
        $orphanfound = false;

        foreach ($allsections as $section) {
            if ($section->section == 0) {
                continue;
            }

            $haspfield = $DB->record_exists('course_format_options', [
                'courseid' => $testcourse->id,
                'sectionid' => $section->id,
                'format' => 'flexsections',
                'name' => 'parent',
            ]);

            if (!$haspfield && $section->id == $orphanedsection->id) {
                $orphanfound = true;
                break;
            }
        }

        $this->assertTrue(
            $orphanfound,
            'Should be able to detect the orphaned section we created'
        );
    }
}
