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

/**
 * Tests for the upgrade steps that change data rather than only the schema.
 *
 * Each test rewinds the recorded plugin version to just before the step, so the savepoint
 * behaves as it does in a real upgrade.
 *
 * @package     aiplacement_modgen
 * @category    test
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_aiplacement_modgen_upgrade
 */
final class upgrade_steps_test extends \advanced_testcase {
    /**
     * Load the upgrade function.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/ai/placement/modgen/db/upgrade.php');
    }

    /**
     * Run the upgrade from $from, as a site recorded at that version would.
     *
     * @param int $from Version the site was at.
     */
    private function upgrade_from(int $from): void {
        set_config('version', $from, 'aiplacement_modgen');
        $this->assertTrue(xmldb_aiplacement_modgen_upgrade($from));
        $this->assertGreaterThan($from, (int) get_config('aiplacement_modgen', 'version'), 'The savepoints move the version on.');
    }

    public function test_jobs_left_by_deleted_courses_are_removed(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('aiplacement_modgen');
        $user = $this->getDataGenerator()->create_user();
        $kept = $this->getDataGenerator()->create_course();
        $keptjob = $generator->create_job(['courseid' => $kept->id, 'userid' => $user->id]);
        $orphan = $DB->insert_record('aiplacement_modgen_jobs', (object) [
            'courseid' => $kept->id + 1000, 'userid' => $user->id, 'action' => 'create_weeks', 'status' => 'completed',
            'parameters' => '{}', 'timecreated' => time(),
        ]);

        $this->upgrade_from(2026102699);

        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $keptjob->id]));
        $this->assertFalse($DB->record_exists('aiplacement_modgen_jobs', ['id' => $orphan]));
    }

    /**
     * The Quick Add switch a site had, and the Assessments switch it starts with.
     *
     * @return array
     */
    public static function assessments_provider(): array {
        return [
            'Quick Add on' => ['1', '1'],
            'Quick Add off' => ['0', '0'],
            'Quick Add never set (on by default)' => [null, '1'],
        ];
    }

    /**
     * The new Assessments switch starts as the Quick Add switch it used to follow.
     *
     * @dataProvider assessments_provider
     * @param string|null $quickadd enable_quickadd before the upgrade, null for unset.
     * @param string $expected enable_assessments after.
     */
    public function test_assessments_switch_starts_as_quick_add(?string $quickadd, string $expected): void {
        unset_config('enable_assessments', 'aiplacement_modgen');
        if ($quickadd === null) {
            unset_config('enable_quickadd', 'aiplacement_modgen');
        } else {
            set_config('enable_quickadd', $quickadd, 'aiplacement_modgen');
        }

        $this->upgrade_from(2026103001);

        $this->assertSame($expected, get_config('aiplacement_modgen', 'enable_assessments'));
    }

    public function test_an_assessments_switch_already_set_is_kept(): void {
        set_config('enable_quickadd', '1', 'aiplacement_modgen');
        set_config('enable_assessments', '0', 'aiplacement_modgen');

        $this->upgrade_from(2026103001);

        $this->assertSame('0', get_config('aiplacement_modgen', 'enable_assessments'));
    }

    public function test_the_holiday_setting_is_removed(): void {
        set_config('holiday_dates', '2026-12-25', 'aiplacement_modgen');

        $this->upgrade_from(2026102200);

        $this->assertFalse(get_config('aiplacement_modgen', 'holiday_dates'));
    }

    /**
     * The whole chain runs on an up-to-date schema without errors, leaves the current tables in
     * place, and does not bring back the tables later steps removed.
     */
    public function test_the_whole_upgrade_runs_on_a_current_schema(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $generator = $this->getDataGenerator()->get_plugin_generator('aiplacement_modgen');
        $course = $this->getDataGenerator()->create_course();
        $job = $generator->create_job(['courseid' => $course->id, 'userid' => get_admin()->id]);

        $this->upgrade_from(2025201500);

        foreach (['aiplacement_modgen_jobs', 'aiplacement_modgen_templates'] as $table) {
            $this->assertTrue($dbman->table_exists($table), "$table is kept.");
        }
        foreach (['aiplacement_modgen_cache', 'aiplacement_modgen_aigen'] as $table) {
            $this->assertFalse($dbman->table_exists($table), "$table stays removed.");
        }
        $index = new \xmldb_index('courseid_userid_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid', 'status']);
        $this->assertTrue($dbman->index_exists(new \xmldb_table('aiplacement_modgen_jobs'), $index));
        $this->assertTrue($DB->record_exists('aiplacement_modgen_jobs', ['id' => $job->id]), 'Live jobs are kept.');
    }
}
