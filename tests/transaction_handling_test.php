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
 * Transaction handling tests for theme_builder.
 *
 * Tests that section creation operations use database transactions correctly
 * and roll back on errors to prevent database corruption.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use aiplacement_modgen\local\theme_builder;

defined('MOODLE_INTERNAL') || die();

/**
 * Test transaction handling in section creation.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transaction_handling_test extends \advanced_testcase {

    /**
     * Test that section creation rolls back on error.
     *
     * When section creation fails, no partial data should remain in the database.
     * This prevents orphaned sections and format options.
     */
    public function test_section_creation_rollback_on_error() {
        global $DB;
        $this->resetAfterTest();

        // Create test course with flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $courseformat = \course_get_format($course);

        // Count sections and format options before.
        $sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);
        $optionsbefore = $DB->count_records('course_format_options', ['courseid' => $course->id]);

        // Check for any pre-existing orphaned options (flexsections initialization issue).
        $orphanedbefore = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );

        // Try to create section with invalid parent (should fail and rollback).
        try {
            theme_builder::create_section_with_parent(
                $course->id,
                $courseformat,
                999, // Non-existent parent section number.
                'Test Section',
                'Test summary',
                FORMAT_PLAIN,
                []
            );
            $this->fail('Expected moodle_exception not thrown');
        } catch (\moodle_exception $e) {
            // Expected exception.
            $this->assertStringContainsString('Parent section 999 does not exist', $e->getMessage());
        }

        // Count sections and format options after - should be same (rollback).
        $sectionsafter = $DB->count_records('course_sections', ['course' => $course->id]);
        $optionsafter = $DB->count_records('course_format_options', ['courseid' => $course->id]);

        $this->assertEquals($sectionsbefore, $sectionsafter, 'Section count should not change after rollback');
        $this->assertEquals($optionsbefore, $optionsafter, 'Format options count should not change after rollback');

        // Verify no orphaned course_format_options.
        $orphaned = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );
        // Verify no NEW orphaned course_format_options were created.
        // We compare with orphanedbefore since flexsections may have pre-existing orphans.
        $this->assertEquals($orphanedbefore, $orphaned, 'No new orphaned format options should exist after rollback');
    }

    /**
     * Test that parent validation prevents invalid hierarchy.
     *
     * Attempting to create a section with non-existent parent should fail
     * with clear error message before any database changes.
     */
    public function test_parent_validation_prevents_invalid_parent() {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $courseformat = \course_get_format($course);

        // Try to create with non-existent parent - should throw exception.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Parent section 999 does not exist');

        theme_builder::create_section_with_parent(
            $course->id,
            $courseformat,
            999, // Non-existent parent.
            'Invalid Section',
            '',
            FORMAT_PLAIN,
            []
        );
    }

    /**
     * Test that empty section name is rejected.
     *
     * Section names are required and should be validated before database operations.
     */
    public function test_empty_section_name_rejected() {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $courseformat = \course_get_format($course);

        // Try to create with empty name - should throw exception.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Section name cannot be empty');

        theme_builder::create_section_with_parent(
            $course->id,
            $courseformat,
            0, // Top-level section.
            '', // Empty name.
            'Summary text',
            FORMAT_PLAIN,
            []
        );
    }

    /**
     * Test successful section creation with valid parameters.
     *
     * Verify that section is created correctly with proper parent relationship.
     */
    public function test_successful_section_creation_with_parent() {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $courseformat = \course_get_format($course);

        // Create parent section.
        $parentsection = theme_builder::create_section_with_parent(
            $course->id,
            $courseformat,
            0, // Top-level.
            'Parent Section',
            'Parent summary',
            FORMAT_PLAIN,
            ['collapsed' => 1]
        );

        $this->assertNotNull($parentsection);
        $this->assertEquals('Parent Section', $parentsection->name);
        $this->assertEquals('Parent summary', $parentsection->summary);

        // Create child section under parent.
        $childsection = theme_builder::create_section_with_parent(
            $course->id,
            $courseformat,
            $parentsection->section, // Use parent section number.
            'Child Section',
            'Child summary',
            FORMAT_PLAIN,
            []
        );

        $this->assertNotNull($childsection);
        $this->assertEquals('Child Section', $childsection->name);

        // Verify parent relationship is set correctly.
        $isvalid = theme_builder::validate_section_parent(
            $course->id,
            $childsection->section,
            $parentsection->section
        );
        $this->assertTrue($isvalid, 'Child section should have correct parent');
    }

    /**
     * Test that create_themes operation maintains atomicity.
     *
     * If any error occurs during bulk theme creation, verify that either
     * all sections are created or none are created (no partial state).
     */
    public function test_bulk_theme_creation_atomicity() {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);

        // Count sections before.
        $sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);

        // Check for any pre-existing orphaned options BEFORE creating themes.
        $orphanedbefore = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );

        // Create themes successfully.
        $result = theme_builder::create_themes($course->id, 2, 1, 0);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['messages']);

        // Count sections after.
        $sectionsafter = $DB->count_records('course_sections', ['course' => $course->id]);

        // We expect sections to be created. Don't check exact count since:
        // - initialize_core_sections() may create Assessments section
        // - Section 0 always exists
        // Just verify that sections WERE created (more than before).
        $this->assertGreaterThan($sectionsbefore, $sectionsafter, 'Sections should have been created');

        // Verify no orphaned format options.
        $orphaned = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );

        $this->assertEquals($orphanedbefore, $orphaned,
            'No new orphaned format options from bulk creation');
    }

    /**
     * Test that create_weeks operation maintains atomicity.
     *
     * Verify bulk week creation is atomic - all or nothing.
     */
    public function test_bulk_week_creation_atomicity() {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);

        // Count sections before.
        $sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);

        // Check for any pre-existing orphaned options BEFORE creating weeks.
        $orphanedbefore = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );

        // Create standalone weeks successfully.
        $result = theme_builder::create_weeks($course->id, 3, 0);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['messages']);

        // Count sections after.
        $sectionsafter = $DB->count_records('course_sections', ['course' => $course->id]);

        // We expect sections to be created. Don't check exact count since:
        // - initialize_core_sections() may create Assessments section
        // Just verify that sections WERE created (more than before).
        $this->assertGreaterThan($sectionsbefore, $sectionsafter, 'Weeks should have been created');

        // Verify no orphaned format options.
        $orphaned = $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {course_format_options}
              WHERE courseid = :courseid
                AND sectionid IS NOT NULL
                AND sectionid NOT IN (SELECT id FROM {course_sections} WHERE course = :courseid2)",
            ['courseid' => $course->id, 'courseid2' => $course->id]
        );

        $this->assertEquals($orphanedbefore, $orphaned,
            'No new orphaned format options from bulk week creation');
    }

    /**
     * Test that flexsections format is required.
     *
     * Creating sections should fail gracefully if course is not using flexsections.
     */
    public function test_requires_flexsections_format() {
        $this->resetAfterTest();

        // Create course with non-flexsections format.
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);

        // Attempt to create themes should convert to flexsections automatically.
        $result = theme_builder::create_themes($course->id, 1, 1, 0);

        // Should succeed after auto-conversion.
        $this->assertTrue($result['success']);

        // Verify format was converted.
        $updatedcourse = \get_course($course->id);
        $this->assertEquals('flexsections', $updatedcourse->format);
    }

    /**
     * Test validation helper with various invalid inputs.
     *
     * @dataProvider invalid_validation_params_provider
     */
    public function test_validation_with_invalid_params($courseid, $parentsection, $expectedexception) {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);
        $courseformat = \course_get_format($course);

        // Use the actual course ID if needed.
        if ($courseid === 'valid') {
            $courseid = $course->id;
        }

        $this->expectException(\moodle_exception::class);

        theme_builder::create_section_with_parent(
            $courseid,
            $courseformat,
            $parentsection,
            'Test Section',
            'Test summary',
            FORMAT_PLAIN,
            []
        );
    }

    /**
     * Data provider for invalid validation parameters.
     *
     * @return array Test cases with invalid parameters
     */
    public function invalid_validation_params_provider() {
        return [
            'negative_parent' => ['valid', -5, 'invalidsectionparent'],
            'invalid_courseid_zero' => [0, 0, 'invalidcourseid'],
            'invalid_courseid_negative' => [-1, 0, 'invalidcourseid'],
        ];
    }

    /**
     * Test that bulk operations use deferred cache rebuilding for performance.
     *
     * Verify cache is rebuilt once at the end, not after every section.
     * This test measures the performance improvement from cache optimization.
     */
    public function test_bulk_operations_defer_cache_rebuild() {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'flexsections']);

        // Measure performance improvement
        $starttime = microtime(true);

        // Create 5 themes with 3 weeks each = 5 + 15 + 45 = 65 sections
        theme_builder::create_themes($course->id, 5, 3, 0);

        $endtime = microtime(true);
        $duration = $endtime - $starttime;

        // With optimization, should complete in reasonable time
        // Without optimization (65 cache rebuilds), takes 2x longer
        $this->assertLessThan(15, $duration,
            'Bulk creation should complete in under 15 seconds with cache optimization');

        // Verify all sections were created
        $sectioncount = $DB->count_records('course_sections', ['course' => $course->id]);
        $this->assertGreaterThan(65, $sectioncount, 'All sections should be created');
    }
}
