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
 * attendanceregister_tracked_users.class.php - Class containing Attendance Register's tracked Users and their summaries
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Holds all tracked Users of an Attendance Register
 *
 * Implements method to return html_table to render it.
 *
 * @author nicus
 */
class attendanceregister_tracked_users {

    /**
     * @var array of User
     */
    public $users;

    /**
     * @var array if attendanceregister_user_aggregates_summary keyed by $userId
     */
    public $userssummaryaggregates;


    /**
     * Instance of attendanceregister_tracked_courses
     * containing all tracked Courses
     * @var type
     */
    public $trackedcourses;

    /**
     * @var Ref. to AttendanceRegister instance
     */
    private $register;

    /**
     * @var Ref to mod_attendanceregister_user_capablities instance
     */
    private $usercapabilites;


    /**
     * Constructor
     * Load all tracked User's and their summaris
     * Load list of tracked Courses
     * @param object $register
     * @param attendanceregister_user_capablities $usercapabilities
     */
    public function __construct($register, attendanceregister_user_capablities $usercapabilities) {
        $this->register = $register;
        $this->usercapabilities = $usercapabilities;
        $this->users = attendanceregister_get_tracked_users($register);
        $this->trackedcourses = new attendanceregister_tracked_courses($register);

        $trackedusersids = attendanceregister__extract_property($this->users, 'id');

        // Retrieve Aggregates summaries.
        $aggregates = attendanceregister__get_all_users_aggregate_summaries($register);
        // Remap in an array of attendanceregister_user_aggregates_summary, mapped by userId.
        $this->userssummaryaggregates = [];
        foreach ($aggregates as $aggregate) {
            // Retain only tracked users.
            if ( in_array( $aggregate->userid, $trackedusersids) ) {
                // Create User's attendanceregister_user_aggregates_summary instance if not exists.
                if ( !isset( $this->userssummaryaggregates[$aggregate->userid] )) {
                    $this->userssummaryaggregates[$aggregate->userid] = new attendanceregister_user_aggregates_summary();
                }
                // Populate attendanceregister_user_aggregates_summary fields.
                if ($aggregate->grandtotal) {
                    $this->userssummaryaggregates[$aggregate->userid]->grandtotalduration = $aggregate->duration;
                    $this->userssummaryaggregates[$aggregate->userid]->lastsassionlogout = $aggregate->lastsessionlogout;
                } else if ( $aggregate->total && $aggregate->onlinesess == 1 ) {
                    $this->userssummaryaggregates[$aggregate->userid]->onlinetotalduration = $aggregate->duration;
                } else if ( $aggregate->total && $aggregate->onlinesess == 0 ) {
                    $this->userssummaryaggregates[$aggregate->userid]->offlinetotalduration = $aggregate->duration;
                }
            }
        }
    }

    /**
     * Build the html_table object to represent details
     * @return html_table
     */
    public function html_table() {
        global $OUTPUT, $doshowprintableversion;

        $strnotavail = get_string('notavailable');

        $table = new html_table();
        $table->attributes['class'] .=
            ' attendanceregister_userlist table table-condensed table-bordered table-striped table-hover';

        // Header.

        $table->head = [
            get_string('count', 'attendanceregister'),
            get_string('fullname', 'attendanceregister'),
            get_string('total_time_online', 'attendanceregister'),
        ];
        $table->align = ['left', 'left', 'right'];

        if ( $this->register->offlinesessions ) {
            $table->head[] = get_string('total_time_offline', 'attendanceregister');
            $table->align[] = 'right';
            $table->head[] = get_string('grandtotal_time', 'attendanceregister');
            $table->align[] = 'right';
        }

        $table->head[] = get_string('last_session_logout', 'attendanceregister');
        $table->align[] = 'left';

        // Table Rows.

        if ($this->users) {
            $rowcount = 0;
            foreach ($this->users as $user) {
                $rowcount++;

                $useraggregate = null;
                if ( isset( $this->userssummaryaggregates[$user->id] ) ) {
                    $useraggregate = $this->userssummaryaggregates[$user->id];
                }

                // Basic columns.
                $linkurl = attendanceregister_makeUrl($this->register, $user->id);
                $fullnamewithlink = '<a href="' . $linkurl . '">' . fullname($user) . '</a>';
                $onlineduration = ($useraggregate) ? ( $useraggregate->onlinetotalduration ) : ( null );
                $onlinedurationstr = attendanceregister_format_duration($onlineduration );
                $tablerow = new html_table_row( [ $rowcount, $fullnamewithlink, $onlinedurationstr ] );

                // Add class for zebra stripes.
                $tablerow->attributes['class'] .= (  ($rowcount % 2) ?
                    ' attendanceregister_oddrow' : ' attendanceregister_evenrow' );

                // Optional columns.
                if ( $this->register->offlinesessions ) {
                    $offlineduration = ($useraggregate) ? ($useraggregate->offlinetotalduration) : ( null );
                    $offlinedurationstr = attendanceregister_format_duration($offlineduration);
                    $tablecell = new html_table_cell( $offlinedurationstr );
                    $tablerow->cells[] = $tablecell;

                    $grandtotalduration = ($useraggregate) ? ($useraggregate->grandtotalduration ) : ( null );
                    $grandtotaldurationstr = attendanceregister_format_duration($grandtotalduration);
                    $tablecell = new html_table_cell( $grandtotaldurationstr );
                    $tablerow->cells[] = $tablecell;
                }

                $lastsessionlogoutstr = ($useraggregate) ?
                    ( attendanceregister__formatDateTime( $useraggregate->lastsassionlogout ) ) :
                    ( get_string('no_session', 'attendanceregister') );
                $tablecell = new html_table_cell( $lastsessionlogoutstr );
                 $tablerow->cells[] = $tablecell;

                $table->data[] = $tablerow;
            }
        } else {
            // No User.
            $row = new html_table_row();
            $labelcell = new html_table_cell();
            $labelcell->colspan = count($table->head);
            $labelcell->text = get_string('no_tracked_user', 'attendanceregister');
            $row->cells[] = $labelcell;
            $table->data[] = $row;
        }

        return $table;
    }
}

