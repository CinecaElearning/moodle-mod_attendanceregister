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
 * @package   mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define all the backup steps that will be used by the backup_attendanceregister_activity_task
 */

/**
 * Define the complete attendanceregister structure for backup, with file and id annotations
 */
class backup_attendanceregister_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element.
        $attendanceregister = new backup_nested_element('attendanceregister', ['id'],
            ['name', 'intro', 'introformat' , 'type', 'offlinesessions',
                'sessiontimeout', 'dayscertificable',
                'offlinecomments', 'mandatoryofflinecomm', 'offlinespecifycourse', 'mandofflspeccourse',
                'timemodified', 'completiontotaldurationmins']);

        $sessions = new backup_nested_element('sessions');

        $session = new backup_nested_element('session', ['id'],
                ['userid', 'login', 'logout', 'duration', 'onlinesess', 'refcourseshortname', 'comments', 'addedbyuserid']);

        // Builds the tree.
        $attendanceregister->add_child($sessions);
        $sessions->add_child($session);

        // Define sources.
        $attendanceregister->set_source_table('attendanceregister', ['id' => backup::VAR_ACTIVITYID]);

        if ( $userinfo ) {
            $session->set_source_sql('
                SELECT s.id, s.register, s.userid, s.login, s.logout, s.duration, s.onlinesess, s.comments,
                    c.shortname AS refcourseshortname,
                    s.addedbyuserid
                  FROM {attendanceregister_session} s LEFT JOIN {course} c ON c.id = s.refcourse
                  WHERE s.register = ? AND s.onlinesess = 0
                ', [backup::VAR_PARENTID]);
        }

        // Define ID annotations.
        $session->annotate_ids('user', 'userid');
        $session->annotate_ids('user', 'addedbyuserid');

        // Define file annotations.
        $attendanceregister->annotate_files('mod_attendanceregister', 'intro', null); // This file area hasn't itemid.

        // Return the root element (attendanceregister), wrapped into standard activity structure.
        return $this->prepare_activity_structure($attendanceregister);
    }
}
