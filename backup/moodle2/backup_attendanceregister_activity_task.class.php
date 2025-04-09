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

defined('MOODLE_INTERNAL') || die;

// Because it exists (must).
require_once($CFG->dirroot . '/mod/attendanceregister/backup/moodle2/backup_attendanceregister_stepslib.php');
// Because it exists (optional).
require_once($CFG->dirroot . '/mod/attendanceregister/backup/moodle2/backup_attendanceregister_settingslib.php');

/**
 * attendanceregister backup task that provides all the settings and steps to perform one
 * complete backup of the activity
 */
class backup_attendanceregister_activity_task extends backup_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        $this->add_step(new backup_attendanceregister_activity_structure_step('attendanceregister_structure',
            'attendanceregister.xml'));
    }

    /**
     * Code the transformations to perform in the activity in
     * order to get transportable (encoded) links
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, "/");

        // Link to the list of attendanceregisters.
        $search = "/(".$base."\/mod\/attendanceregister\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@ATTENDANCEREGISTERINDEX*$2@$', $content);

        // Link to attendanceregisters view by moduleid.
        $search = "/(".$base."\/mod\/attendanceregister\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@ATTENDANCEREGISTERVIEWBYID*$2@$', $content);

        // Link to attendanceregisters view by registerid.
        $search = "/(".$base."\/mod\/attendanceregister\/view.php\?a\=)([0-9]+)/";
        $content = preg_replace($search, '$@ATTENDANCEREGISTERVIEWBYREGISTERID*$2@$', $content);

        // Link to a User's Regiter by moduleid.
        $search = "/(".$base."\/mod\/attendanceregister\/view.php\?id\=)([0-9]+)\&userid\=([0-9]+)/";
        $content = preg_replace($search, '$@ATTENDANCEREGISTERVIEWUSERBYID*$2*$3@$', $content);

        // Link to a User's Regiter by registerid.
        $search = "/(".$base."\/mod\/attendanceregister\/view.php\?a\=)([0-9]+)\&userid\=([0-9]+)/";
        $content = preg_replace($search, '$@ATTENDANCEREGISTERVIEWUSERBYREGISTERID*$2*$3@$', $content);

        return $content;
    }

}

