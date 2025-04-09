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
 * attendanceregister_user_aggregates.class.php - Class containing User's Aggregate  in an AttendanceRegister
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Represents a User's Aggregate for a Register
 * Holds in a single Object all attendanceregister_aggregate records
 * for a User and a Register instance.
 *
 * Implements method to return html_table to render it.
 *
 * Note that class constructor execute a db query for retrieving User's aggregates
 *
 */
class attendanceregister_user_aggregates {

    /**
     * Grandtotal of all sessions
     */
    public $grandtotalduration = 0;

    /**
     * Total of all Online Sessions
     */
    public $onlinetotalduration = 0;

    /**
     * Total of all Offline Sessions
     */
    public $offlinetotalduration = 0;

    /**
     * Offline sessions, per RefCourseId
     */
    public $percourseofflinesessions = [];

    /**
     * Offline Sessions w/o any RefCourse
     */
    public $nocourseofflinesessions = 0;

    /**
     * Last calculated Session Logout
     */
    public $lastsassionlogout = 0;

    /**
     * Ref to attendanceregister_user_sessions instance
     */
    private $usersessions;

    /**
     * User instance
     */
    public $user;

    /**
     * Create an instance for a given register and user
     * @param object $register
     * @param int $userId
     * @param attendanceregister_user_sessions $usersessions
     */
    public function __construct($register, $userid, attendanceregister_user_sessions $usersessions) {
        global $DB;

        $this->usersessions = $usersessions;

        // Retrieve User instance.
        $this->user = attendanceregister__getUser($userid);

        // Retrieve attendanceregister_aggregate records.
        $aggregates = attendanceregister__get_user_aggregates($register, $userid);

        foreach ($aggregates as $aggregate) {
            if ($aggregate->grandtotal) {
                $this->grandtotalduration = $aggregate->duration;
                $this->lastsassionlogout = $aggregate->lastsessionlogout;
            } else if ( $aggregate->total && $aggregate->onlinesess == 1 ) {
                $this->onlinetotalduration = $aggregate->duration;
            } else if ( $aggregate->total && $aggregate->onlinesess == 0 ) {
                $this->offlinetotalduration = $aggregate->duration;
            } else if (!$aggregate->total && $aggregate->onlinesess == 0 && $aggregate->refcourse != null ) {
                $this->percourseofflinesessions[$aggregate->refcourse] = $aggregate->duration;
            } else if (!$aggregate->total && $aggregate->onlinesess == 0 && $aggregate->refcourse == null ) {
                $this->nocourseofflinesessions = $aggregate->duration;
            } else {
                // Should not happen!
                debugging('Unconsistent Aggregate: '. var_export($aggregate, true), DEBUG_DEVELOPER);
            }
        }
    }


    /**
     * Build the html_table object to represent summary
     * @return html_table
     */
    public function html_table() {
        global $OUTPUT, $doshowprintableversion;

        $table = new html_table();
        $table->attributes['class'] .=
        ' attendanceregister_usersummary table table-condensed table-bordered table-striped table-hover';

        // Header.
        $table->head[] = get_string('user_sessions_summary', 'attendanceregister');
        $table->headspan = [3];

        // Previous Site-wise Login (is Moodle's _last_ login).
        $row = new html_table_row();
        $labelcell = new html_table_cell();
        $labelcell->colspan = 2;
        $labelcell->text = get_string('prev_site_login', 'attendanceregister');
        $row->cells[] = $labelcell;
        $valuecell = new html_table_cell();
        $valuecell->text = attendanceregister__formatDateTime($this->user->lastlogin);
        $row->cells[] = $valuecell;
        $table->data[] = $row;

        // Last Site-wise Login (is Moodle's _current_ login).
        $row = new html_table_row();
        $labelcell = new html_table_cell();
        $labelcell->colspan = 2;
        $labelcell->text = get_string('last_site_login', 'attendanceregister');
        $row->cells[] = $labelcell;
        $valuecell = new html_table_cell();
        $valuecell->text = attendanceregister__formatDateTime($this->user->currentlogin);
        $row->cells[] = $valuecell;
        $table->data[] = $row;

        // Last Site-wise access.
        $row = new html_table_row();
        $labelcell = new html_table_cell();
        $labelcell->colspan = 2;
        $labelcell->text = get_string('last_site_access', 'attendanceregister');
        $row->cells[] = $labelcell;
        $valuecell = new html_table_cell();
        $valuecell->text = attendanceregister__formatDateTime($this->user->lastaccess);
        $row->cells[] = $valuecell;
        $table->data[] = $row;

        // Last Calculated Session Logout.
        $row = new html_table_row();
        $labelcell = new html_table_cell();
        $labelcell->colspan = 2;
        $labelcell->text = get_string('last_calc_online_session_logout', 'attendanceregister');
        $row->cells[] = $labelcell;
        $valuecell = new html_table_cell();
        $valuecell->text = attendanceregister__formatDateTime($this->lastsassionlogout);
        $row->cells[] = $valuecell;
        $table->data[] = $row;

        // Separator.
        $table->data[] = 'hr';

        // Online Total.
        $row = new html_table_row();
        $row->attributes['class'] .= ' attendanceregister_onlinesubtotal success';
        $labelcell = new html_table_cell();
        $labelcell->colspan = 2;
        $labelcell->text = get_string('online_sessions_total_duration', 'attendanceregister');
        $row->cells[] = $labelcell;

        $valuecell = new html_table_cell();
        $valuecell->text = attendanceregister_format_duration( $this->onlinetotalduration );
        $row->cells[] = $valuecell;

        $table->data[] = $row;

        // Offline.
        if ( $this->offlinetotalduration ) {
            // Separator.
            $table->data[] = 'hr';

            // Offline per RefCourse (if any).
            foreach ($this->percourseofflinesessions as $refcourseid => $courseofflinesessions) {
                $row = new html_table_row();
                $row->attributes['class'] .= '';
                $labelcell = new html_table_cell();
                $labelcell->text = get_string('offline_refcourse_duration', 'attendanceregister');
                $row->cells[] = $labelcell;

                $coursecell = new html_table_cell();
                if ( $refcourseid ) {
                    $coursecell->text = $this->usersessions->trackedcourses->courses[$refcourseid]->fullname;
                } else {
                    $coursecell->text = get_string('not_specified', 'attendanceregister');
                }
                $row->cells[] = $coursecell;

                $valuecell = new html_table_cell();
                $valuecell->text = attendanceregister_format_duration( $courseofflinesessions );
                $row->cells[] = $valuecell;

                $table->data[] = $row;
            }

            // Offline no-RefCourse (if any).
            if ( $this->nocourseofflinesessions ) {
                $row = new html_table_row();
                 $row->attributes['class'] .= '';
                $labelcell = new html_table_cell();
                $labelcell->text = get_string('offline_refcourse_duration', 'attendanceregister');
                $row->cells[] = $labelcell;

                $coursecell = new html_table_cell();
                $coursecell->text = get_string('no_refcourse', 'attendanceregister');
                $row->cells[] = $coursecell;

                $valuecell = new html_table_cell();
                $valuecell->text = attendanceregister_format_duration( $this->nocourseofflinesessions );
                $row->cells[] = $valuecell;

                $table->data[] = $row;
            }

            // Offline Total (if any).
            $row = new html_table_row();
            $row->attributes['class'] .= ' attendanceregister_offlinesubtotal';
            $labelcell = new html_table_cell();
            $labelcell->colspan = 2;
            $labelcell->text = get_string('offline_sessions_total_duration', 'attendanceregister');
            $row->cells[] = $labelcell;

            $valuecell = new html_table_cell();
            $valuecell->text = attendanceregister_format_duration( $this->offlinetotalduration );
            $row->cells[] = $valuecell;

            $table->data[] = $row;

            // GrandTotal.
            $row = new html_table_row();
            $row->attributes['class'] .= ' attendanceregister_grandtotal active';
            $labelcell = new html_table_cell();
            $labelcell->colspan = 2;
            $labelcell->text = get_string('sessions_grandtotal_duration', 'attendanceregister');
            $row->cells[] = $labelcell;

            $valuecell = new html_table_cell();
            $valuecell->text = attendanceregister_format_duration( $this->grandtotalduration );
            $row->cells[] = $valuecell;

            $table->data[] = $row;
        }

        return $table;
    }
}
