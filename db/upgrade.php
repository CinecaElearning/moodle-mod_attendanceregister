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
 * Upgrade file
 *
 * @package   mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_attendanceregister_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2012081004) {
        // Add attendanceregister_session.addedbyuserid column.

        // Define field addedbyuser to be added to attendanceregister_session.
        $table = new xmldb_table('attendanceregister_session');
        $field = new xmldb_field('addedbyuserid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED );

        // Launch add field addedbyuserid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add attendanceregister.pendingrecalc column.

        // Define field addedbyuser to be added to attendanceregister.
        $table = new xmldb_table('attendanceregister');
        $field = new xmldb_field('pendingrecalc', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, 1 );

        // Launch add field addedbyuserid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2012081004, 'attendanceregister');
    }

    if ( $oldversion < 2013020604 ) {
        // Issue #36 and #42.

        // Rename field attendanceregister_session.online to onlinessess.
        $table = new xmldb_table('attendanceregister_session');
        $field = new xmldb_field('online', XMLDB_TYPE_INTEGER, 1, XMLDB_UNSIGNED, XMLDB_NOTNULL, null, 1 );
        if ( $dbman->field_exists($table, $field) ) {
            // Rename field.
            $dbman->rename_field($table, $field, 'onlinesess');
        }

        // Rename field attendanceregister_aggregate.online to onlinessess.
        $table = new xmldb_table('attendanceregister_aggregate');
        $field = new xmldb_field('online', XMLDB_TYPE_INTEGER, 1, XMLDB_UNSIGNED, null, null, 1  );
        if ( $dbman->field_exists($table, $field) ) {
            // Rename field.
            $dbman->rename_field($table, $field, 'onlinesess');
        }

        upgrade_mod_savepoint(true, 2013020604, 'attendanceregister');
    }

    if ( $oldversion < 2013040605 ) {
        // Feature #7.

        // Add field attendanceregister.completiontotaldurationmins.
        $table = new xmldb_table('attendanceregister');
        $field = new xmldb_field('completiontotaldurationmins', XMLDB_TYPE_INTEGER, 10 , XMLDB_UNSIGNED, XMLDB_NOTNULL, null, 0 );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2013040605, 'attendanceregister');
    }

    if ( $oldversion < 2020071601 ) {
        $table = new xmldb_table('attendanceregister_session');
        $index = new xmldb_index('onlinesess', XMLDB_INDEX_NOTUNIQUE, ['onlinesess']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('attendanceregister_aggregate');
        $index = new xmldb_index('grandtotal', XMLDB_INDEX_NOTUNIQUE, ['grandtotal']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('attendanceregister_aggregate');
        $index = new xmldb_index('lastsessionlogout', XMLDB_INDEX_NOTUNIQUE, ['lastsessionlogout']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2020071601, 'attendanceregister');
    }

    if ( $oldversion < 2023050401 ) {
        $table = new xmldb_table('attendanceregister_log_dump');

        // Adding fields to table attendanceregister_log_dump.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('target', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('objecttable', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('objectid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('crud', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('edulevel', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('relateduserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('anonymous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('other', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('origin', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
        $table->add_field('realuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table attendanceregister_log_dump.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for attendanceregister_log_dump.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2023050401, 'attendanceregister');
    }

    if ( $oldversion < 2023050402 ) {
        // We go back 24 hours to get an one day old log (from the last session
        // which could be in the past) and start from there.
        $sql = "SELECT id FROM {logstore_standard_log} where timecreated < ((SELECT max(login) ".
            "FROM {attendanceregister_session}) - 86400) order by timecreated DESC limit 1";
        $lastcronparsedlogid = $DB->get_record_sql($sql, []);
        set_config('lastcronparsedlogid', $lastcronparsedlogid->id, 'attendanceregister');

        upgrade_mod_savepoint(true, 2023050402, 'attendanceregister');
    }

    return true;
}
