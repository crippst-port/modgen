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
 * CLI script to add dummy learning outcomes for testing.
 *
 * @package    aiplacement_modgen
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

global $DB;

// Module details
$module_code = 'M11111';
$module_academic_year = '2024/25';
$module_period = 'Y';
$module_occurrence = 'SMYEAR';
$fullcode = $module_code . '-' . $module_academic_year . '-' . $module_occurrence;

// Dummy learning outcomes for testing
$learning_outcomes = [
    'Demonstrate critical understanding of key theoretical concepts and frameworks in the subject area',
    'Apply analytical and problem-solving skills to complex scenarios and case studies',
    'Evaluate and synthesize information from multiple sources to develop informed arguments',
    'Communicate ideas effectively through written and oral presentations',
    'Work collaboratively in teams to achieve shared objectives and outcomes',
    'Reflect critically on personal learning and professional development'
];

echo "Adding dummy learning outcomes for {$fullcode}...\n\n";

// Check if records already exist
$sql = "SELECT * FROM {sits_module_learning_outcomes}
        WHERE module_code = ? AND module_academic_year = ? AND module_occurrence = ?";
$existing = $DB->get_records_sql($sql, [$module_code, $module_academic_year, $module_occurrence]);

if (!empty($existing)) {
    echo "WARNING: Found " . count($existing) . " existing records. Deleting them first...\n";
    foreach ($existing as $record) {
        $DB->delete_records('sits_module_learning_outcomes', ['id' => $record->id]);
    }
    echo "Deleted existing records.\n\n";
}

// Insert new records
$count = 0;
foreach ($learning_outcomes as $outcome) {
    $record = new stdClass();
    $record->module_code = $module_code;
    $record->module_academic_year = $module_academic_year;
    $record->module_period = $module_period;
    $record->module_occurrence = $module_occurrence;
    $record->learning_outcome = $outcome;
    $record->fullcode = $fullcode;

    $id = $DB->insert_record('sits_module_learning_outcomes', $record);
    $count++;
    echo "{$count}. Added: {$outcome}\n";
}

echo "\n✓ Successfully added {$count} learning outcomes for {$fullcode}\n";

// Verify the insertion
echo "\nVerifying insertion...\n";
$sql = "SELECT * FROM {sits_module_learning_outcomes}
        WHERE module_code = ? AND module_academic_year = ? AND module_occurrence = ?";
$verify = $DB->get_records_sql($sql, [$module_code, $module_academic_year, $module_occurrence]);

echo "Found " . count($verify) . " records in database.\n";
echo "\nLearning outcomes stored in database:\n";
foreach ($verify as $record) {
    echo "- {$record->learning_outcome}\n";
}
