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
 * attendanceregister_user_sessions.class.php - Class containing User's Sessions in an AttendanceRegister
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Holds all attendanceregister_session record of a User's Register
 *
 * Implements method to return html_table to render it.
 */
class attendanceregister_user_sessions {

    /**
     * @var attendanceregister_session records
     */
    public $usersessions;

    /**
     * @var Instance of attendanceregister_user_aggregates
     */
    public $useraggregates;

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
     * Load User's Sessions
     * Load User's Aggregates
     *
     * @param object $register
     * @param int $userId
     * @param attendanceregister_user_capablities $usercapabilities
     */
    public function __construct($register, $userid, attendanceregister_user_capablities $usercapabilities) {
        $this->register = $register;
        $this->usersessions = attendanceregister_get_user_sessions($register, $userid);
        $this->useraggregates = new attendanceregister_user_aggregates($register, $userid, $this);
        $this->trackedcourses = new attendanceregister_tracked_courses($register);
        $this->usercapabilites = $usercapabilities;
    }

    /**
     * Build the html_table object to represent details
     * @return html_table
     */
    public function html_table() {
        global $OUTPUT, $doshowprintableversion;

        $table = new html_table();
        $table->attributes['class'] .=
            ' attendanceregister_sessionlist table table-condensed table-bordered table-striped table-hover';

        // Header.

        $table->head = [
            get_string('count', 'attendanceregister'),
            get_string('start', 'attendanceregister'),
            get_string('end', 'attendanceregister'),
            get_string('online_offline', 'attendanceregister'),
        ];
        $table->align = ['left', 'left', 'left', 'right'];

        if ($this->register->offlinesessions) {
            $table->head[] = get_string('online_offline', 'attendanceregister');
            $table->align[] = 'center';
            if ($this->register->offlinespecifycourse) {
                $table->head[] = get_string('ref_course', 'attendanceregister');
                $table->align[] = 'left';
            }
            if ($this->register->offlinecomments) {
                $table->head[] = get_string('comments', 'attendanceregister');
                $table->align[] = 'left';
            }
        }

        // Table rows.

        if ( $this->usersessions ) {
            $stronline = get_string('online', 'attendanceregister');
            $stroffline = get_string('offline', 'attendanceregister');

            // Iterate sessions.
            $rowcount = 0;
            foreach ($this->usersessions as $session) {
                $rowcount++;

                // Rowcount column.
                $rowcountstr = (string)$rowcount;
                // Offline Delete button (if Session is offline and the current user may delete this user's offline sessions).
                if ( !$session->onlinesess && $this->usercapabilites->canDeleteThisUserOfflineSession($session->userid) ) {
                    $deleteurl = attendanceregister_makeUrl($this->register,
                        $session->userid, null, ATTENDANCEREGISTER_ACTION_DELETE_OFFLINE_SESSION,
                        ['session' => $session->id ]);
                    $confirmaction = new confirm_action(get_string('are_you_sure_to_delete_offline_session', 'attendanceregister'));
                    $rowcountstr .= ' ' . $OUTPUT->action_icon($deleteurl,
                        new pix_icon('t/delete', get_string('delete') ), $confirmaction);
                }

                // Duration.
                $duration = attendanceregister_format_duration($session->duration);

                // Basic columns.
                $tablerow = new html_table_row( [$rowcountstr,
                    attendanceregister__formatDateTime($session->login),
                    attendanceregister__formatDateTime($session->logout), $duration] );

                // Add class for zebra stripes.
                $tablerow->attributes['class'] .=
                    (  ($rowcount % 2) ? ' attendanceregister_oddrow' : ' attendanceregister_evenrow' );

                // Optional columns.
                if ($this->register->offlinesessions) {

                    // Offline/Online.
                    $onlineofflinestr = (($session->onlinesess) ? $stronline : $stroffline);

                    // If saved by other.
                    if ( $session->addedbyuserid ) {
                        // Retrieve the other user, if any, or unknown.
                        $a = attendanceregister__otherUserFullnameOrUnknown($session->addedbyuserid);
                        $addedbystr = get_string('session_added_by_another_user', 'attendanceregister', $a);
                        $onlineofflinestr = html_writer::tag('a', $onlineofflinestr . '*',
                            ['title' => $addedbystr, 'class' => 'addedbyother'] );
                    }
                    $tablecell = new html_table_cell($onlineofflinestr);
                    $tablecell->attributes['class'] .= ( ($session->onlinesess) ? ' online_label' : ' offline_label' );
                    $tablerow->attributes['class'] .= ( ($session->onlinesess) ? ' success' : '' );
                    $tablerow->cells[] = $tablecell;

                    // Ref.Course.
                    if ( $this->register->offlinespecifycourse  ) {
                        if ( $session->onlinesess ) {
                            $refcoursename = '';
                        } else {
                            if ( $session->refcourse ) {
                                $refcourse = $this->trackedcourses->courses[$session->refcourse];

                                // In Printable Version show fullname (shortname), otherwise only shortname.
                                if ($doshowprintableversion) {
                                    $refcoursename = $refcourse->fullname . ' ('. $refcourse->shortname .')';
                                } else {
                                    $refcoursename = $refcourse->shortname;
                                }
                            } else {
                                $refcoursename = get_string('not_specified', 'attendanceregister');
                            }
                        }
                        $tablecell = new html_table_cell($refcoursename);
                        $tablerow->cells[] = $tablecell;
                    }

                    // Offline Comments.
                    if ($this->register->offlinecomments  ) {
                        if ( !$session->onlinesess && $session->comments ) {
                            // Shorten the comments (if !printable).
                            if ( !$doshowprintableversion ) {
                                $comment = attendanceregister__shorten_comment($session->comments);
                            } else {
                                $comment = $session->comments;
                            }
                        } else {
                            $comment = '';
                        }
                        $tablecell = new html_table_cell($comment);
                        $tablerow->cells[] = $tablecell;
                    }
                }
                $table->data[] = $tablerow;
            }
        } else {
            // No Session.

            $row = new html_table_row();
            $labelcell = new html_table_cell();
            $labelcell->colspan = count($table->head);
            $labelcell->text = get_string('no_session_for_this_user', 'attendanceregister');
            $row->cells[] = $labelcell;
            $table->data[] = $row;
        }

        return $table;
    }

}
