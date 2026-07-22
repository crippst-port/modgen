<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Database upgrade script for aiplacement_modgen.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade function for aiplacement_modgen plugin.
 *
 * @param int $oldversion Old plugin version
 * @return bool True on success
 */
function xmldb_aiplacement_modgen_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Upgrade path for version 2025201600
    if ($oldversion < 2025201600) {
        // Define table aiplacement_modgen_cache to be created
        $table = new xmldb_table('aiplacement_modgen_cache');

        // Adding fields to table aiplacement_modgen_cache
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('data', XMLDB_TYPE_TEXT, 'medium', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        // Adding keys to table aiplacement_modgen_cache
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // Note: Foreign keys automatically create indexes, so we don't need a separate index

        // Create table if it doesn't exist
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached
        upgrade_plugin_savepoint(true, 2025201600, 'aiplacement', 'modgen');
    }

    // Upgrade path for version 2025201603 - add AI-generated tracking table.
    if ($oldversion < 2025201603) {
        // Define table aiplacement_modgen_aigen to be created.
        $table = new xmldb_table('aiplacement_modgen_aigen');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cmid_uk', XMLDB_KEY_UNIQUE, ['cmid']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // Create table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2025201603, 'aiplacement', 'modgen');
    }

    // Upgrade path for version 2025201604 - remove explore cache table.
    if ($oldversion < 2025201604) {
        // Drop the aiplacement_modgen_cache table (explore insights cache).
        $table = new xmldb_table('aiplacement_modgen_cache');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2025201604, 'aiplacement', 'modgen');
    }

    // Upgrade path for version 2026010900 - add CSV templates table.
    if ($oldversion < 2026010900) {
        // Define table aiplacement_modgen_templates to be created.
        $table = new xmldb_table('aiplacement_modgen_templates');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table.
        $table->add_index('sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);

        // Create table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2026010900, 'aiplacement', 'modgen');
    }

    // Upgrade path for version 2026011700 - add index on courseid for performance.
    if ($oldversion < 2026011700) {
        // Add index on courseid in aiplacement_modgen_aigen table for better query performance.
        $table = new xmldb_table('aiplacement_modgen_aigen');
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2026011700, 'aiplacement', 'modgen');
    }

    // Upgrade path for version 2026020901 - add jobs table for background section creation.
    if ($oldversion < 2026020901) {
        // Define table aiplacement_modgen_jobs to be created.
        $table = new xmldb_table('aiplacement_modgen_jobs');

        // Adding fields to table.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('action', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('parameters', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null);

        // Adding keys to table.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table.
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        // Create table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2026020901, 'aiplacement', 'modgen');
    }

    return true;
}
