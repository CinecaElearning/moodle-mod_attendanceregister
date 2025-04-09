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
 * locallib.php - Library functions and constants for module Attendance Register
 * not included in public library.
 * These functions are called only by other functions defined in lib.php
 * or in classes defined in attendanceregister_*.class.php
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");
require_once("$CFG->libdir/completionlib.php");

/**
 * Retrieve the Course object instance of the Course where the Register is
 *
 * @param object $register
 * @return object Course
 */
function attendanceregister__get_register_course($register) {
    global $DB;

    $course = $DB->get_record('course', ['id' => $register->course], '*', MUST_EXIST);
    return $course;
}

/**
 * Calculate the the end of the last online Session already calculated
 * for a given user, retrieving the User's Sessions (i.e. do not use cached timestamp in aggregate)
 * If no Session exists, returns 0
 * @param object $register
 * @param int $userid
 * @return int
 */
function attendanceregister__calculate_last_user_online_session_logout($register, $userid) {
    global $DB;

    $queryparams = ['register' => $register->id, 'userid' => $userid];
    $lastsessionend = $DB->get_field_sql('SELECT MAX(logout) FROM {attendanceregister_session} '.
        'WHERE register = ? AND userid = ? AND onlinesess = 1', $queryparams);
    if ($lastsessionend === false) {
        $lastsessionend = 0;
    }
    return $lastsessionend;
}


/**
 * Sets all log entries unused from previous elaborations in the dump table.
 *
 * @param array $dumpentriestodb
 */
function attendanceregister__set_dump_entries($dumpentriestodb) {
    global $DB;

    try {
        $transaction = $DB->start_delegated_transaction();
        // $deletetable = $DB->execute('TRUNCATE TABLE {attendanceregister_log_dump}', []);

        $chuncks = array_chunk($dumpentriestodb, 1000);
        foreach ($chuncks as $chunk) {
            $insert = "INSERT INTO {attendanceregister_log_dump} (id, eventname, component, action, target, objecttable, ".
                "objectid, crud, edulevel, contextid, contextlevel, contextinstanceid, userid, courseid, relateduserid, ".
                "anonymous, other, timecreated, origin, ip, realuserid) VALUES ";
            $valuesplaceholders = [];
            for ($i = 1; $i <= count($chunk); $i++) {
                $valuesplaceholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            }
            $values = [];
            foreach ($chunk as $item) {
                $values = array_merge($values, array_values((array)$item));
            }
            $valuesplaceholderssql = implode(',', $valuesplaceholders);
            $insert .= $valuesplaceholderssql;
            $dumpentries = $DB->execute($insert, $values);
        }

        $transaction->allow_commit();
    } catch (Exception $e) {
        mtrace('DB problem, attendanceregister__set_dump_entries rollback');
        $transaction->rollback($e);
    }
}


/**
 * This is the function that actually process log entries and calculate sessions
 *
 * Calculate and Save all new Sessions of a given User
 * starting from a given timestamp.
 * Optionally updates a progress_bar
 *
 * Also Updates User's Aggregates
 *
 * @param Attendanceregister $register
 * @param int $userid
 * @param int $fromTime (default 0)
 * @param progress_bar optional instance of progress_bar to update
 * @return int number of new sessions found
 */
function attendanceregister__build_new_user_sessions($register, $userid, $fromtime = 0, ?progress_bar $progressbar = null) {
    global $DB;

    // Retrieve ID of Course containing Register.
    $course = attendanceregister__get_register_course($register);
    $user = attendanceregister__getUser($userid);

    // All Courses where User's activities are tracked (Always contains current Course).
    $trackedcoursesids = attendanceregister__get_tracked_courses_ids($register, $course);

    // Retrieve logs entries for all tracked courses, after fromTime.
    $totallogentriescount = 0;

    $lastcronparsedlogid = get_config('attendanceregister', 'lastcronparsedlogid');
    if (!$lastcronparsedlogid) {
        // No previous elaboration so no lastcronparsedlogid.
        $toid = 0;      // Needed during switchoff to version 2023050401, in case lastcronparsedlogid doesn't exists.
        // We need to load all the user's or register's logs until the last one.
        // Since we have no upper limit, we fallback on comparing with the actual elaboration time.
        $timestamptoconfrontto = time();
    } else {
        // Previous elaboration present.
        // We are going to limit the logs loading to the previous cron last parsed id,
        // otherwise we could take only a part of the still not parsed data (like just one user's logs.
        $toid = $lastcronparsedlogid;
        // We have an upper limit, we need to compare to the limit's log's timecreated.
        $querysql = "SELECT * FROM {logstore_standard_log} WHERE id = :id";
        $lastcronparsedlogentry = $DB->get_record_sql($querysql, ['id' => $lastcronparsedlogid]);
        $timestamptoconfrontto = $lastcronparsedlogentry->timecreated;
    }

    $logentries = attendanceregister__get_user_log_entries_in_courses($userid,
        $fromtime, $toid, $trackedcoursesids, $totallogentriescount);

    $sessiontimeoutseconds = $register->sessiontimeout * 60;
    $prevlogentry = null;
    $sessionstarttimestamp = null;
    $logentriescount = 0;
    $newsessionscount = 0;
    $sessionlastentrytimestamp = 0;
    $dumpentriestmp = [];

    // Loop new entries if any.
    if (is_array($logentries) && count($logentries) > 0) {


        $dumpentriestodelete = [];
        if ($lastcronparsedlogid) {
            foreach ($logentries as $logentry) {
                $dumpentriestodelete[] = $logentry->id;
            }
        }
        attendanceregister__delete_dump_entries($dumpentriestodelete);

        // Scroll all log entries.
        foreach ($logentries as $logentry) {
            $logentriescount++;

            // On first element, get prev entry and session start, than loop.
            if (!$prevlogentry) {
                $prevlogentry = $logentry;
                $sessionstarttimestamp = $logentry->timecreated;
                continue;
            }

            // Check if between prev and current log, last more than Session Timeout
            // if so, the Session ends on the _prev_ log entry.
            if (($logentry->timecreated - $prevlogentry->timecreated) > $sessiontimeoutseconds) {
                // Remove possible log entries from the dump variable because I transfer them to a session.
                $dumpentriestmp = [];
                $newsessionscount++;

                // Estimate Session ended half the Session Timeout after the prev log entry
                // (prev log entry is the last entry of the Session).
                $sessionlastentrytimestamp = $prevlogentry->timecreated;
                $estimatedsessionend = $sessionlastentrytimestamp + $sessiontimeoutseconds / 2;

                // Save a new session to the prev entry.
                attendanceregister__save_session($register, $userid, $sessionstarttimestamp, $estimatedsessionend);

                // Update the progress bar, if any.
                if ($progressbar) {
                    $msg = get_string('updating_online_sessions_of', 'attendanceregister', fullname($user));

                    $progressbar->update($logentriescount, $totallogentriescount, $msg);
                }

                // Session has ended: session start on current log entry.
                $sessionstarttimestamp = $logentry->timecreated;
            } else {
                // Log entries that don't go in a session go in the dump variable and eventually in the dump table.
                $dumpentriestmp[$prevlogentry->id] = $prevlogentry;
            }
            $prevlogentry = $logentry;
        }

        // If the last log entry is not the end of the last calculated session and is older than SessionTimeout
        // create a last session.
        if ( $logentry->timecreated > $sessionlastentrytimestamp &&
            ( $timestamptoconfrontto - $logentry->timecreated ) > $sessiontimeoutseconds  ) {
            // Remove possible log entries from the dump variable because I transfer them to a session.
            $dumpentriestmp = [];
            $newsessionscount++;

            // In this case logEntry (and not prevLogEntry is the last entry of the Session).
            $sessionlastentrytimestamp = $logentry->timecreated;
            $estimatedsessionend = $sessionlastentrytimestamp + $sessiontimeoutseconds / 2;

            // Save a new session to the prev entry.
            attendanceregister__save_session($register, $userid, $sessionstarttimestamp, $estimatedsessionend);

            // Update the progress bar, if any.
            if ($progressbar) {
                $msg = get_string('updating_online_sessions_of', 'attendanceregister', fullname($user));

                $progressbar->update($logentriescount, $totallogentriescount, $msg);
            }
        } else {
            // Log entries that don't go in a session go in the dump variable and eventually in the dump table.
            $dumpentriestmp[$logentry->id] = $logentry;
        }
    }

    $dumpentriestodb = [];
    foreach ($dumpentriestmp as $tmp) {
        $dumpentriestodb[$tmp->id] = $tmp;
    }

    $dumpentriestmp = [];

    // Updates Aggregates, only on new session creation.
    if ($newsessionscount) {
        attendanceregister__update_user_aggregates($register, $userid);
    }

    mtrace('dumping logs rows: '. count($dumpentriestodb));
    attendanceregister__set_dump_entries($dumpentriestodb);

    // Finalize Progress Bar.
    if ($progressbar) {
        $a = new stdClass();
        $a->fullname = fullname($user);
        $a->numnewsessions = $newsessionscount;
        $msg = get_string('online_session_updated_report', 'attendanceregister', $a );
        attendanceregister__finalize_progress_bar($progressbar, $msg);
    }

    return $newsessionscount;
}

/**
 * Updates Aggregates for a given user
 * and notify completion, if needed [feature #7]
 *
 * @param object $regiser
 * @param int $userid
 */
function attendanceregister__update_user_aggregates($register, $userid) {
    global $DB;

    // Delete old aggregates.
    $DB->delete_records('attendanceregister_aggregate', ['userid' => $userid, 'register' => $register->id]);

    $aggregates = [];
    $queryparams = ['registerid' => $register->id, 'userid' => $userid];

    // Calculate aggregates of offline Sessions.
    if ( $register->offlinesessions ) {
        // Note that refcourse has passed as first column to avoid warning of duplicate values in first column by get_records().
        $sql = 'SELECT sess.refcourse, sess.register, sess.userid, 0 AS onlinesess, '
            .'SUM(sess.duration) AS duration, 0 AS total, 0 as grandtotal'
            .' FROM {attendanceregister_session} sess'
            .' WHERE sess.onlinesess = 0 AND sess.register = :registerid AND sess.userid = :userid'
            .' GROUP BY sess.register, sess.userid, sess.refcourse';
        $offlinepercourseaggregates = $DB->get_records_sql($sql, $queryparams);
        // Append records.
        if ( $offlinepercourseaggregates ) {
            $aggregates = array_merge($aggregates, $offlinepercourseaggregates);
        }

        // Calculates total offline, regardless of RefCourse.
        $sql = 'SELECT sess.register, sess.userid, 0 AS onlinesess, null AS refcourse, '
            .'SUM(sess.duration) AS duration, 1 AS total, 0 as grandtotal'
            .' FROM {attendanceregister_session} sess'
            .' WHERE sess.onlinesess = 0 AND sess.register = :registerid AND sess.userid = :userid'
            .' GROUP BY sess.register, sess.userid';
        $totalofflineaggregate = $DB->get_record_sql($sql, $queryparams);
        // Append record.
        if ( $totalofflineaggregate ) {
            $aggregates[] = $totalofflineaggregate;
        }
    }

    // Calculates aggregates of online Sessions (this is a total as no RefCourse may exist).
    $sql = 'SELECT sess.register, sess.userid, 1 AS onlinesess, null AS refcourse, '
        .'SUM(sess.duration) AS duration, 1 AS total, 0 as grandtotal'
        .' FROM {attendanceregister_session} sess'
        .' WHERE sess.onlinesess = 1 AND sess.register = :registerid AND sess.userid = :userid'
        .' GROUP BY sess.register, sess.userid';
    $onlineaggregate = $DB->get_record_sql($sql, $queryparams);

    // If User has no Session, generate an online Total record.
    if ( !$onlineaggregate ) {
        $onlineaggregate = new stdClass();
        $onlineaggregate->register = $register->id;
        $onlineaggregate->userid = $userid;
        $onlineaggregate->onlinesess = 1;
        $onlineaggregate->refcourse = null;
        $onlineaggregate->duration = 0;
        $onlineaggregate->total = 1;
        $onlineaggregate->grandtotal = 0;
    }
    // Append record.
    $aggregates[] = $onlineaggregate;

    // Calculates grand total.

    $sql = 'SELECT sess.register, sess.userid, null AS onlinesess, null AS refcourse, '
        .'SUM(sess.duration) AS duration, 0 AS total, 1 as grandtotal'
        .' FROM {attendanceregister_session} sess'
        .' WHERE sess.register = :registerid AND sess.userid = :userid'
        .' GROUP BY sess.register, sess.userid';
    $grandtotalaggregate = $DB->get_record_sql($sql, $queryparams);

    // If User has no Session, generate a grandTotal record.
    if ( !$grandtotalaggregate ) {
        $grandtotalaggregate = new stdClass();
        $grandtotalaggregate->register = $register->id;
        $grandtotalaggregate->userid = $userid;
        $grandtotalaggregate->onlinesess = null;
        $grandtotalaggregate->refcourse = null;
        $grandtotalaggregate->duration = 0;
        $grandtotalaggregate->total = 0;
        $grandtotalaggregate->grandtotal = 1;
    }
    // Add lastSessionLogout to GrandTotal.
    $grandtotalaggregate->lastsessionlogout = attendanceregister__calculate_last_user_online_session_logout($register, $userid);
    // Append record.
    $aggregates[] = $grandtotalaggregate;

    // Save all as Aggregates.
    foreach ($aggregates as $aggregate) {
        $DB->insert_record('attendanceregister_aggregate', $aggregate );
    }

    // Notify completion if needed
    // (only if any completion condition is enabled).
    if (attendanceregister__isAnyCompletionConditionSpecified($register)) {
        // Retrieve Course-Module an Course instances.
        $cm = get_coursemodule_from_instance('attendanceregister', $register->id, $register->course, null, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            // Check completion values.
            $completiontrackedvalues = [
                'totaldurationsecs' => $grandtotalaggregate->duration,
            ];
            $iscomplete = attendanceregister__areCompletionConditionsMet($register, $completiontrackedvalues);

            // Notify complete or incomplete.
            if ( $iscomplete ) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            } else {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid);
            }
        }
    }

}

/**
 * Retrieve all Users tracked by a given Register.
 * User are sorted by fullname
 *
 * All Users that in the Register's Course have any Role with "mod/attendanceregister:tracked" Capability assigned.
 * (NOT Users having this Capability in all tracked Courses!)
 *
 * @param object $register
 * @return array of users
 */
function attendanceregister__get_tracked_users($register) {
    global $DB;
    $trackedusers = [];

    // Get Context of each Tracked Course.
    $thiscourse = attendanceregister__get_register_course($register);
    $trackedcoursedids = attendanceregister__get_tracked_courses_ids($register, $thiscourse);
    foreach ($trackedcoursedids as $courseid) {
        $context = context_course::instance($courseid);
        // Retrieve all tracked users.
        $trackedusersincourse = get_users_by_capability($context,
            ATTENDANCEREGISTER_CAPABILITY_TRACKED, '', '', '', '', '', '', false);
        $trackedusers = array_merge($trackedusers, $trackedusersincourse);
    }

    // Users must be unique [issue #15].
    $uniquetrackedusers = attendanceregister__unique_object_array_by_id($trackedusers);

    // Sort Users by fullname [issue #13].
    usort($uniquetrackedusers, function($a, $b) {
        return strcmp( fullname($a), fullname($b) );
    });

    return $uniquetrackedusers;
}


/**
 * Retrieve all User's Aggregates of a given User
 * @param object $register
 * @param int $userid
 * @return array of attendanceregister_aggregate
 */
function attendanceregister__get_user_aggregates($register, $userid) {
    global $DB;
    $params = ['register' => $register->id, 'userid' => $userid];
    return $DB->get_records('attendanceregister_aggregate', $params );
}

/**
 * Retrieve User's Aggregates summary-only (only total & grandtotal records)
 * for all Users tracked by the Register.
 * @param object $register
 * @return array of attendanceregister_aggregate
 */
function attendanceregister__get_all_users_aggregate_summaries($register) {
    global $DB;
    $params = ['register' => $register->id];
    $select = "register = :register AND (total = 1 OR grandtotal = 1)";
    return $DB->get_records_select('attendanceregister_aggregate', $select, $params);
}

/**
 * Retrieve cached value of Aggregate GrandTotal row
 * (containing grandTotal duration and lastSessionLogout)
 * If no aggregate, return false
 *
 * @param object $register
 * @param int $userid
 * @return an object with grandtotal and lastsessionlogout or FALSE if missing
 */
function attendanceregister__get_cached_user_grandtotal($register, $userid) {
    global $DB;
    $params = ['register' => $register->id, 'userid' => $userid, 'grandtotal' => 1];
    return $DB->get_record('attendanceregister_aggregate', $params, '*', IGNORE_MISSING );
}


/**
 * Returns an array of Course ID with all Courses tracked by this Register
 * depending on type
 *
 * @param object $register
 * @param object $course
 * @return array
 */
function attendanceregister__get_tracked_courses_ids($register, $course) {
    $trackedcoursesids = [];
    switch ($register->type) {
        case ATTENDANCEREGISTER_TYPE_METAENROL:
            // This course.
            $trackedcoursesids[] = $course->id;
            // Add all courses linked to the current Course.
            $trackedcoursesids = array_merge($trackedcoursesids, attendanceregister__get_coursed_ids_meta_linked($course));
            break;
        case ATTENDANCEREGISTER_TYPE_CATEGORY:
            // Add all Courses in the same Category (include this Course).
            $trackedcoursesids = array_merge($trackedcoursesids, attendanceregister__get_courses_ids_in_category($course));
            break;
        default:
            // This course only.
            $trackedcoursesids[] = $course->id;
    }

    return $trackedcoursesids;
}

/**
 * Get all IDs of Courses in the same Category of the given Course
 * @param object $course a Course
 * @return array of int
 */
function attendanceregister__get_courses_ids_in_category($course) {
    global $DB;
    $coursesidsincategory = $DB->get_fieldset_select('course', 'id', 'category = :categoryid ',
        ['categoryid' => $course->category]);
    return $coursesidsincategory;
}

/**
 * Get IDs of all Courses meta-linked to a give Course
 * @param object $course  a Course
 * @return array of int
 */
function attendanceregister__get_coursed_ids_meta_linked($course) {
    global $DB;
    // All Courses that have a enrol record pointing to them from the given Course.
    $linkedcoursesids = $DB->get_fieldset_select('enrol', 'customint1', "courseid = :courseid AND enrol = 'meta'",
        ['courseid' => $course->id]);
    return $linkedcoursesids;
}

/**
 * Retrieves all log entries of a given user, after a given time,
 * for all activities in a given list of courses.
 * Log entries are sorted from oldest to newest
 *
 * @param int $userid
 * @param int $fromTime
 * @param array $courseIds
 * @param int $logCount count of records, passed by ref.
 */
function attendanceregister__get_user_log_entries_in_courses($userid, $fromtime, $toid, $courseids, &$logcount) {
    global $DB;

    $courseidlist = implode(',', $courseids);
    if (!$fromtime) {
        $fromtime = 0;
    }

    // Prepare Queries for counting and selecting.
    $selectlistsql = " *";
    $fromwheresql = " FROM {logstore_standard_log} l WHERE l.userid = :userid "
        ."AND l.timecreated > :fromtime AND l.courseid IN ($courseidlist)";
    if ($toid) {
        $fromwheresql .= " AND id < :toid";
    }
    $orderbysql = " ORDER BY l.timecreated ASC";
    $querysql = "SELECT" . $selectlistsql . $fromwheresql . $orderbysql;

    // Execute queries.
    $params = ['userid' => $userid, 'fromtime' => $fromtime];
    if ($toid) {
        $params['toid'] = $toid;
    }
    $logentries = $DB->get_records_sql($querysql, $params);
    $logcount = count($logentries); // Optimization suggested by MorrisR2 [https://github.com/MorrisR2].
    return $logentries;
}

/**
 * Checks if a given login-logout overlap with a User's Session already saved
 * in the Register
 *
 * @param object $register
 * @param object $user
 * @param int $login
 * @param int $logout
 * @return boolean true if overlapping
 */
function attendanceregister__check_overlapping_old_sessions($register, $userid, $login, $logout) {
    global $DB;

    $select = 'userid = :userid AND register = :registerid AND ((:login BETWEEN login AND logout) '
        .'OR (:logout BETWEEN login AND logout))';
    $params = ['userid' => $userid, 'registerid' => $register->id, 'login' => $login, 'logout' => $logout];

    return $DB->record_exists_select('attendanceregister_session', $select, $params);
}

/**
 * Checks if a given login-logout overlap overlap the current User's session
 * If the user is the current user, just checks if logout is after User's Last Login
 * If is another user, if user's lastaccess is older then sessiontimeout he is supposed to be logged out
 *
 *
 * @param object $register
 * @param object $user
 * @param int $login
 * @param int $logout
 * @return boolean true if overlapping
 */
function attendanceregister__check_overlapping_current_session($register, $userid, $login, $logout) {
    global $USER, $DB;
    if ( $USER->id == $userid ) {
        $user = $USER;
    } else {
        $user = attendanceregister__getUser($userid);
        // If user never logged in, no overlapping could happens.
        if ( !$user->lastaccess ) {
            return false;
        }

        // If user lastaccess is older than sessiontimeout, the user is supposed to be logged out and no check is done.
        $sessiontimeoutseconds = $register->sessiontimeout * 60;
        if ( !$user->lastaccess < (time() - $sessiontimeoutseconds)) {
            return false;
        }
    }
    return ( $user->currentlogin < $logout );

}

/**
 * Deletes user's register dumped logs
 * @param int $register
 * @param int $userid
 */
function attendanceregister__delete_dump_entries($idarray) {
    global $DB;
    $idlist = implode(',', $idarray);
    $querysql = "DELETE FROM {attendanceregister_log_dump} WHERE id in ($idlist)";
    $result = $DB->execute($querysql);
    return $result;
}

/**
 * Save a new Session
 * @param object $register
 * @param int $userid
 * @param int $loginTimestamp
 * @param int $logoutTimestamp
 * @param boolean $isOnline
 * @param int $refCourseId
 * @param string $comments
 */
function attendanceregister__save_session($register, $userid, $logintimestamp,
    $logouttimestamp, $isonline = true, $refcourseid = null, $comments = null) {
    global $DB;

    $session = new stdClass();
    $session->register = $register->id;
    $session->userid = $userid;
    $session->login = $logintimestamp;
    $session->logout = $logouttimestamp;
    $session->duration = ($logouttimestamp - $logintimestamp);
    $session->onlinesess = $isonline;
    $session->refcourse = $refcourseid;
    $session->comments = $comments;

    $DB->insert_record('attendanceregister_session', $session);
}

/**
 * Delete all online Sessions of a given User
 * If $onlyDeleteAfter is specified, deletes only Sessions with login >= $onlyDeleteAfter
 * (this is used not to delete calculated sessions older than the first available
 * User's log entry)
 *
 * @param object $register
 * @param int $userid
 * @param int $onlyDeleteAfter default ) null (=ignored)
 */
function attendanceregister__delete_user_online_sessions($register, $userid, $onlydeleteafter = null) {
    global $DB;
    $params = ['userid' => $userid, 'register' => $register->id, 'onlinesess' => 1];
    if ( $onlydeleteafter ) {
        $where = 'userid = :userid AND register = :register AND onlinesess = :onlinesess '
        .'AND login >= :lowerlimit';
        $params['lowerlimit'] = $onlydeleteafter;
        $DB->delete_records_select('attendanceregister_session', $where, $params);
    } else {
        // If no lower delete limit has been specified, deletes all User's Sessions.
        $DB->delete_records('attendanceregister_session', $params);
    }
}

/**
 * Delete all User's Aggrgates of a given User
 * @param object $register
 * @param int $userid
 */
function attendanceregister__delete_user_aggregates($register, $userid) {
    global $DB;
    $DB->delete_records('attendanceregister_aggregate', ['userid' => $userid, 'register' => $register->id]);
}


/**
 * Retrieve the timestamp of the oldest Log Entry of a User
 * Please not that this is the oldest log entry in the site, not only in tracked courses.
 * @param int $userid
 * @return int or null if no log entry found
 */
function attendanceregister__get_user_oldest_log_entry_timestamp($userid) {
    global $DB;
    $obj = $DB->get_record_sql('SELECT MIN(timecreated) as oldestlogtime FROM {logstore_standard_log} '
        .'WHERE userid = :userid', ['userid' => $userid], IGNORE_MISSING );
    if ( $obj ) {
        return $obj->oldestlogtime;
    }
    return null;
}

/**
 * Check if a Lock exists on a given User's Register
 * @param object $register
 * @param int $userid
 * @param boolean true if lock exists
 */
function attendanceregister__check_lock_exists($register, $userid) {
    global $DB;
    mtrace('attendanceregister__filter_logs_by_users');
    return $DB->record_exists('attendanceregister_lock', ['register' => $register->id, 'userid' => $userid]);
}

/**
 * Attain a Lock on a User's Register
 * @param object $register
 * @param int $userid
 */
function attendanceregister__attain_lock($register, $userid) {
    global $DB;
    $lock = new stdClass();
    $lock->register = $register->id;
    $lock->userid = $userid;
    $lock->takenon = time();
    $DB->insert_record('attendanceregister_lock', $lock);
}

/**
 * Release (all) Lock(s) on a User's Register.
 * @param object $register
 * @param int $userid
 */
function attendanceregister__release_lock($register, $userid) {
    global $DB;
    $DB->delete_records('attendanceregister_lock', ['register' => $register->id, 'userid' => $userid]);
}

/**
 * Finalyze (push to 100%) the progressbar, if any, showing a message.
 * @param progress_bar $progressbar Progress Bar instance to update; if null do nothing
 * @param string $msg
 */
function attendanceregister__finalize_progress_bar($progressbar, $msg = '') {
    if ($progressbar) {
        $progressbar->update_full(100, $msg);
    }
}

/**
 * Extract an array containing values of a property from an array of objets
 * @param array $arrayOfObjects
 * @param string $propertyName
 * @return array containing only the values of the property
 */
function attendanceregister__extract_property($arrayofobjects, $propertyname) {
    $arrayofvalue = [];
    foreach ($arrayofobjects as $obj) {
        if ( ($objectproperties = get_object_vars($obj) ) ) {
            if ( isset($objectproperties[$propertyname])) {
                $arrayofvalue[] = $objectproperties[$propertyname];
            }
        }
    }
    return $arrayofvalue;
}

/**
 * Shorten a Comment to a given length, w/o truncating words
 * @param string $text
 * @param int $maxLen
 */
function attendanceregister__shorten_comment($text, $maxlen = ATTENDANCEREGISTER_COMMENTS_SHORTEN_LENGTH) {
    if (strlen($text) > $maxlen ) {
        $text = $text . " ";
        $text = substr($text, 0, $maxlen);
        $text = substr($text, 0, strrpos($text, ' '));
        $text = $text . "...";
    }
    return $text;
}

/**
 * Returns an array with unique objects in a given array
 * comparing by id property
 * @param array $objArray of object
 * @return array of object
 */
function attendanceregister__unique_object_array_by_id($objarray) {
    $uniqueobjects = [];
    $uniquobjids = [];
    foreach ($objarray as $obj) {
        if ( !in_array($obj->id, $uniquobjids)) {
            $uniquobjids[] = $obj->id;
            $uniqueobjects[] = $obj;
        }
    }
    return $uniqueobjects;
}

/**
 * Format a dateTime using userdate()
 * If Debug configuration is active and at ALL or DEVELOPER level,
 * adds extra informations on UnixTimestamp
 * and return "Never" if timestamp is 0
 * @param int $dateTime
 * @return string
 */
function attendanceregister__formatdatetime($datetime) {
    global $CFG;

    // If Timestamp is 0 or null return "Never".
    if ( !$datetime ) {
        return get_string('never', 'attendanceregister');
    }

    if ( $CFG->debugdisplay && $CFG->debug >= DEBUG_DEVELOPER ) {
        return userdate($datetime) . ' ['. $datetime . ']';
    } else if ( $CFG->debugdisplay && $CFG->debug >= DEBUG_ALL ) {
        return '<a title="' . $datetime . '">'. userdate($datetime) .'</a>';
    }
    return userdate($datetime);

}

/**
 * A shortcut for loading a User
 * It the User does not exist, an error is thrown
 * @param int $userid
 */
function attendanceregister__getuser($userid) {
    global $DB;
    return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
}

/**
 * Check if a given User ID is of the currently logged user
 * @param int $userid (consider null as current user)
 * @return boolean
 */
function attendanceregister__iscurrentuser($userid) {
    global $USER;
    return (!$userid || $USER->id == $userid);
}

/**
 * Return user's full name or unknown
 * @param type $otherUserId
 */
function attendanceregister__otheruserfullnameorunknown($otheruserid) {
    global $DB;
    $otheruser = attendanceregister__getUser($otheruserid);
    if ( $otheruser ) {
        return fullname($otheruser);
    } else {
        return get_string('unknown', 'attendanceregister');
    }
}

/**
 * Check if any completion condition is enabled in a given Register instance.
 * ANY CHECK FOR ENABLED COMPLETION CONDITION must use this function
 *
 * @param object $register Register instance
 * @return boolean TRUE if any completion condition is enabled
 */
function attendanceregister__isanycompletionconditionspecified($register) {
    return (boolean)( $register->completiontotaldurationmins );
}

/**
 * Check completion of the activity by a user.
 * Note that this method performs aggregation SQL queries for caculating tracked values
 * useful for completion check.
 * Actual completion condition check is delegated
 * to attendanceregister__areCompletionConditionsMet(...)
 *
 * @param object $register AttendanceRegister
 * @param int $userid User ID
 * @return boolean TRUE if the Activity is complete, FALSE if not complete,
 * NULL if no activity completion condition has been specified
 */
function attendanceregister__calculateusercompletion($register, $userid) {
    global $DB;

    // If not completion condition is set, returns immediately.
    if ( !attendanceregister__isAnyCompletionConditionSpecified($register)) {
        return null;
    }

    // Retrieve all tracked values (useful for completion) for the user.

    // Calculate total tracked time by an instance for a user.
    $sqltotaldurationsecs = "select sum(sess.duration) from {attendanceregister_session} sess "
        ."where sess.register=:registerid and userid=:userid";
    $params = ['registerid' => $register->id, 'userid' => $userid];
    $totaldurationsecs = $DB->get_field_sql($sqltotaldurationsecs, $params);

    // When more tracked values will be supported, put calculation here.

    // Evaluate all tracked parameters for completion.
    return attendanceregister__areCompletionConditionsMet($register, ['totaldurationsecs' => $totaldurationsecs] );
}

/**
 * Check if a set of tracked values meets the completion condition for the instance
 *
 * This method implements evaluation of (pre-calculated) tracked values
 * against completion conditions.
 * ANY COMPLETION CHECK (for a user) must be delegated to this method.
 *
 * Values are passed as an associative array.
 * i.e.
 * [ 'totaldurationsecs' => xxxxx,  ]
 *
 * @param object $register Register instance
 * @param array $trackedValues array of tracked values, by parameter name
 * @param int $totaldurationsecs total calculated duration, in seconds
 * @return boolean TRUE if this values match comletion condition, otherwise FALSE
 */
function attendanceregister__arecompletionconditionsmet($register, $trackedvalues ) {
    // By now only totaldurationsecs is considered.
    // When more parameters will be added to completion condition set, this function will implement them.

    if ( isset($trackedvalues['totaldurationsecs'])) {
        $totaldurationsecs = $trackedvalues['totaldurationsecs'];
        if ( !$totaldurationsecs ) {
            return false;
        }
        return ( ($totaldurationsecs / 60) >= $register->completiontotaldurationmins );
    } else {
        return false;
    }
}

/**
 * Check if the Cron form this module ran after the creation of an instance
 * @param object $cm Course-Module instance
 * @return boolean TRUE if the Cron run on this module after instance creation
 */
function attendanceregister__didcronranafterinstancecreation($cm) {
    global $DB;
    $module = $DB->get_record('task_scheduled', ['component' => 'mod_attendanceregister'], '*', MUST_EXIST);
    return ( $cm->added < $module->lastruntime );
}

/**
 * Class form Offline Session Self-Certification form
 * (Note that the User is always the CURRENT user ($USER) )
 */
class mod_attendanceregister_selfcertification_edit_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        global $CFG, $USER, $OUTPUT;

        $mform =& $this->_form;

        $register = $this->_customdata['register'];
        $courses = $this->_customdata['courses'];
        if ( isset(  $this->_customdata['userid'] )) {
            $userid = $this->_customdata['userid'];
        } else {
            $userid = null;
        }

        // Login/Logout defaults
        // based on User's LastLogin:
        // logout = User's current login time, truncate to hour
        // login = 1h before logout.
        $refdate = usergetdate( $USER->currentlogin );
        $refts = make_timestamp($refdate['year'], $refdate['mon'], $refdate['mday'], $refdate['hours'] );
        $deflogout = $refts;
        $deflogin = $refts - 3600;

        // Title.
        if ( attendanceregister__isCurrentUser($userid) ) {
            $titlestr = get_string('insert_new_offline_session', 'attendanceregister');
        } else {
            $otheruser = attendanceregister__getUser($userid);
            $a->fullname = fullname($otheruser);
            $titlestr = get_string('insert_new_offline_session_for_another_user', 'attendanceregister', $a);
        }
        $mform->addElement('html', '<h3>' . $titlestr . '</h3>');

        // Self certification fields.
        $mform->addElement('date_time_selector', 'login', get_string('offline_session_start', 'attendanceregister'),
            ['defaulttime' => $deflogin, 'optional' => false ]);
        $mform->addRule('login', get_string('required'), 'required');
        $mform->addHelpButton('login', 'offline_session_start', 'attendanceregister');

        $mform->addElement('date_time_selector', 'logout', get_string('offline_session_end', 'attendanceregister'),
            ['defaulttime' => $deflogout, 'optional' => false] );
        $mform->addRule('logout', get_string('required'), 'required');

        // Comments (if needed).
        if ( $register->offlinecomments ) {
            $mform->addElement('textarea', 'comments', get_string('comments', 'attendanceregister'));
            $mform->setType('comments', PARAM_TEXT);
            $mform->addRule('comments', get_string('maximumchars', '', 255), 'maxlength', 255, 'client' );
            if ( $register->mandatoryofflinecomm ) {
                $mform->addRule('comments', get_string('required'), 'required', null, 'client');
            }
            $mform->addHelpButton('comments', 'offline_session_comments', 'attendanceregister');
        }

        // Ref.Courses.
        if ( $register->offlinespecifycourse ) {
            $coursesselect = [];

            if ( $register->mandofflspeccourse ) {
                $coursesselect[] = get_string('select_a_course', 'attendanceregister');
            } else {
                $coursesselect[] = get_string('select_a_course_if_any', 'attendanceregister');
            }

            foreach ($courses as $course) {
                $coursesselect[$course->id] = $course->fullname;
            }
            $mform->addElement('select', 'refcourse', get_string('offline_session_ref_course', 'attendanceregister'),
                $coursesselect );
            if ( $register->mandofflspeccourse ) {
                $mform->addRule('refcourse', get_string('required'), 'required', null, 'client');
            }
            $mform->addHelpButton('refcourse', 'offline_session_ref_course', 'attendanceregister');
        }

        // Hidden params.
        $mform->addElement('hidden', 'a');
        $mform->setType('a', PARAM_INT);
        $mform->setDefault('a', $register->id);

        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ACTION);
        $mform->setDefault('action',  ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION);

        // Add userid hidden param if needed.
        if ($userid) {
            $mform->addElement('hidden', 'userid');
            $mform->setType('userid', PARAM_INT);
            $mform->setDefault('userid', $userid);
        }

        // Buttons.
        $this->add_action_buttons();
    }

    /**
     * Form validation.
     */
    public function validation($data, $files) {
        global $USER, $DB;

        $errors = parent::validation($data, $files);

        // Retrieve Register and User passed through the form.
        $register = $DB->get_record('attendanceregister', ['id' => $data['a']], '*', MUST_EXIST);

        $login = $data['login'];
        $logout = $data['logout'];
        if ( isset($data['userid']) ) {
            $userid = $data['userid'];
        } else {
            $userid = $USER->id;
        }

        // Check if login is before logout.
        if ( ($logout - $login ) <= 0  ) {
            $errors['login'] = get_string('login_must_be_before_logout', 'attendanceregister');
        }

        // Check if session is unreasonably long.
        if ( ($logout - $login) > ATTENDANCEREGISTER_MAX_REASONEABLE_OFFLINE_SESSION_SECONDS  ) {
            $hours = floor(($logout - $login) / 3600);
            $errors['login'] = get_string('unreasoneable_session', 'attendanceregister', $hours);
        }

        // Checks if login is more than 'dayscertificable' days ago.
        if ( ( time() - $login ) > ($register->dayscertificable * 3600 * 24)  ) {
            $errors['login'] = get_string('dayscertificable_exceeded', 'attendanceregister', $register->dayscertificable);
        }

        // Check if logout is future.
        if ( $logout > time() ) {
            $errors['login'] = get_string('logout_is_future', 'attendanceregister');
        }

        // Check if login-logout overlap any saved session.
        if (attendanceregister__check_overlapping_old_sessions($register, $userid, $login, $logout) ) {
            $errors['login'] = get_string('overlaps_old_sessions', 'attendanceregister');
        }

        // Check if login-logout overlap current User session.
        if (attendanceregister__check_overlapping_current_session($register, $userid, $login, $logout)) {
            $errors['login'] = get_string('overlaps_current_session', 'attendanceregister');
        }

        return $errors;
    }
}

/**
 * This class collects al current User's Capabilities
 * regarding the current instance of Attendance Register
 */
class attendanceregister_user_capablities {

    /**
     * @var bool traking capability.
     */
    public $istracked = false;
    /**
     * @var bool canviewownregister capability.
     */
    public $canviewownregister = false;
    /**
     * @var bool canviewotherregisters capability.
     */
    public $canviewotherregisters = false;
    /**
     * @var bool canaddownofflinesessions capability.
     */
    public $canaddownofflinesessions = false;
    /**
     * @var bool canaddotherofflinesessions capability.
     */
    public $canaddotherofflinesessions = false;
    /**
     * @var bool candeleteownofflinesessions capability.
     */
    public $candeleteownofflinesessions = false;
    /**
     * @var bool candeleteotherofflinesessions capability.
     */
    public $candeleteotherofflinesessions = false;
    /**
     * @var bool canrecalcsessions capability.
     */
    public $canrecalcsessions = false;

    /**
     * Create an instance for the CURRENT User and Context
     * @param object $context
     */
    public function __construct($context) {
        $this->canviewownregister = has_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OWN_REGISTERS, $context, null, true);
        $this->canviewotherregisters = has_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OTHER_REGISTERS, $context, null, true);
        $this->canrecalcsessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS, $context, null, true);
        $this->istracked = has_capability(ATTENDANCEREGISTER_CAPABILITY_TRACKED, $context, null, false); // Ignore doAnything.
        $this->canaddownofflinesessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_ADD_OWN_OFFLINE_SESSIONS,
            $context, null, false);  // Ignore doAnything.
        $this->canaddotherofflinesessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_ADD_OTHER_OFFLINE_SESSIONS,
            $context, null, false);  // Ignore doAnything.
        $this->candeleteownofflinesessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OWN_OFFLINE_SESSIONS,
            $context, null, false);  // Ignore doAnything.
        $this->candeleteotherofflinesessions = has_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OTHER_OFFLINE_SESSIONS,
            $context, null, false);  // Ignore doAnything.
    }

    /**
     * Checks if the current user can view a given User's Register.
     *
     * @param int $userid (null means current user's register)
     * @return boolean
     */
    public function canviewthisuserregister($userid) {
        return ( ( (attendanceregister__isCurrentUser($userid)) && $this->canviewownregister  )
                || ($this->canviewotherregisters) );
    }

    /**
     * Checks if the current user can delete a given User's Offline Sessions
     * @param int $userid (null means current user's register)
     * @return boolean
     */
    public function candeletethisuserofflinesession($userid) {
        return ( ( (attendanceregister__isCurrentUser($userid))  &&  $this->candeleteownofflinesessions )
                || ($this->candeleteotherofflinesessions) );
    }

    /**
     * Check if the current USER can add Offline Sessions for a specified User
     * @param int $userid (null means current user's register)
     * @return boolean
     */
    public function canaddthisuserofflinesession($register, $userid) {
        global $DB;

        if (attendanceregister__isCurrentUser($userid) ) {
            return  $this->canaddownofflinesessions;
        } else if ( $this->canaddotherofflinesessions ) {
            // If adding Session for another user also check it is tracked by the register instance.
            $user = attendanceregister__getUser($userid);
            return attendanceregister_is_tracked_user($register, $user);
        }
        return false;
    }
}
