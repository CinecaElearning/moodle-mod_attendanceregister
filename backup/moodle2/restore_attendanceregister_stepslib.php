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
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Define all the restore steps that will be used by the restore_attendanceregister_activity_task
 */

/**
 * Structure step to restore one choice activity
 */
class restore_attendanceregister_activity_structure_step extends restore_activity_structure_step {

    /**
     * Define the structure of the restore workflow.
     *
     * @return restore_path_element $structure
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('attendanceregister', '/activity/attendanceregister');
        if ($userinfo) {
            $paths[] = new restore_path_element('attendanceregister_session', '/activity/attendanceregister/sessions/session');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process an attendanceregister restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_attendanceregister($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Feature #7 - Set completiontotaldurationmins = 0 if not defined in backup.
        if ( !isset($data->completiontotaldurationmins)) {
            $data->completiontotaldurationmins = 0;
        }

        // Insert the attendanceregister record.
        $newitemid = $DB->insert_record('attendanceregister', $data);

        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process a session restore
     * @param object $data The data in object form
     * @return void
     */
    protected function process_attendanceregister_session($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->register = $this->get_new_parentid('attendanceregister');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->addedbyuserid = $this->get_mappingid('user', $data->addedbyuserid);

        // Issue #36 and #41
        // If 'online' field is defined (i.e. the backup is of an older version), rename it to 'onlinesess'.
        if ( isset($data->online) ) {
            $data->onlinesess = $data->online;
            unset($data->online);
        }

        // Lookup RefCourse by ShortName, if exists on destination.
        if ($data->refcourseshortname) {
            $refcourse = $DB->get_record('course', ['shortname' => $data->refcourseshortname], '*', IGNORE_MISSING);
            if ($refcourse) {
                $data->refcourse = $refcourse->id;
            }
        }

        $newitemid = $DB->insert_record('attendanceregister_session', $data);
        // No need to save this mapping as far as nothing depend on it
        // (child paths, file areas nor links decoder).
    }

    /**
     * Once the database tables have been fully restored, restore the files
     */
    protected function after_execute() {
        global $DB;
        // Add attendanceregister related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_attendanceregister', 'intro', null);

        // Execute recalculate all.
        $register = $DB->get_record('attendanceregister', ['id' => $this->task->get_activityid()], '*', MUST_EXIST);
        attendanceregister_set_pending_recalc($register, true);
    }

}
