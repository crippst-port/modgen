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
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, array('courseid'), 'course', array('id'));

        // Note: Foreign keys automatically create indexes, so we don't need a separate index

        // Create table if it doesn't exist
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached
        upgrade_plugin_savepoint(true, 2025201600, 'aiplacement', 'modgen');
    }

    return true;
}
