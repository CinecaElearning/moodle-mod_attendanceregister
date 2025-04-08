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
 * cronlib.php - Class containing Attendance Register's cron functions
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Takes N log rows from the last execution, parses them and organizes sessions.
 *
 * @param array $registers
 * @param int $fromid (default 0)
 */
function attendanceregister_update_sessions_from_id($registers, $fromid) {
    global $DB;

    ini_set('memory_limit', '2G');

    $trackedcoursesids = [];
    foreach ($registers as $register) {
        // If there's a single lock on any register, exit.
        // Otherwise I should move every log entry in the dump table and it could become very large.
        if (attendanceregister__check_lock_exists_cron($register)) {
            return false;
        }
        $course = attendanceregister__get_register_course($register);

        // All Courses where User's activities are tracked (Always contains current Course).
        $registertrackedcoursesids = attendanceregister__get_tracked_courses_ids($register, $course);
        $trackedcoursesids = array_merge($trackedcoursesids, $registertrackedcoursesids);
        foreach ($registertrackedcoursesids as $trackedcoursesid) {
            $courseidtoregistermap[$trackedcoursesid][] = $register;
        }
        $registeridtocoursesmap[$register->id] = $registertrackedcoursesids;
        $trackedusersinregisters[$register->id] = attendanceregister__get_tracked_users_accociative($register,
            $registeridtocoursesmap);
    }

    // Loads in a separate variable the previously elaborated log entries that weren't put in a session.
    $dumpentries = attendanceregister__get_dump_entries();
    mtrace(get_string('dump_entries_count', 'attendanceregister', count($dumpentries)));
    $maxusersregisterslogouts = get_max_users_registers_logouts();
    mtrace(get_string('starting_query_id', 'attendanceregister', $fromid));

    $totallogentriescount = 0;
    // We used to call attendanceregister__get_user_log_entries_in_courses for every user,
    // now we call attendanceregister__get_log_entries_in_courses.
    $logentriesarray = attendanceregister__get_log_entries_in_courses($fromid, $trackedcoursesids, $totallogentriescount);
    $logentries = $logentriesarray[0];
    $lastcronparsedlogid = $logentriesarray[1];

    if ($lastcronparsedlogid == $fromid && count($dumpentries) == 0) {
        // No ids at all. No elaboration nor lastcronparsedlogid update.
        mtrace(get_string('no_entries', 'attendanceregister'));
        return;
    } else if (count($logentries) || count($dumpentries)) {
        // Some logentries are present, elaborate.
        mtrace(get_string('logentries_before_filter', 'attendanceregister', count($logentries)));

        // We filter logs via PHP to make it faster on huge logs tables.
        $logentries = attendanceregister__filter_logs_by_users($logentries, $trackedusersinregisters,
            $courseidtoregistermap, $maxusersregisterslogouts);

        mtrace(get_string('logentries_after_filter', 'attendanceregister', count($logentries)));

        $totallogentries = attendanceregister__order_logs_by_user_and_register($logentries, $dumpentries,
            $courseidtoregistermap, $registeridtocoursesmap);
        $dumpentriestmp = [];

        // Loop all entries if any.
        if (is_array($totallogentries) && count($totallogentries) > 0) {
            if ($logentries) {
                // If new log entries are present, the last one becomes our reference.
                $lastcronparsedlogtimestamp = $logentries[array_key_last($logentries)]->timecreated;
            } else {
                // Otherwise we will use the last elaboration log id and now as time.
                $lastcronparsedlogtimestamp = time();
            }

            mtrace(get_string('starting_data_elaboration', 'attendanceregister'));
            $dumpentriestodb = [];
            $newsessionscount = 0;
            foreach ($totallogentries as $userid => $userlogentries) {
                foreach ($userlogentries as $registerid => $userregisterlogentries) {
                    $prevlogentry = null;
                    $sessionstarttimestamp = null;
                    $logentriescount = 0;
                    $sessionlastentrytimestamp = 0;
                    $courseid = null;

                    // Scroll all log entries.
                    foreach ($userregisterlogentries as $logentry) {
                        $sessiontimeoutseconds = $registers[$registerid]->sessiontimeout * 60;
                        $logentriescount++;

                        // On first element, get prev entry and session start, than loop.
                        if (!$prevlogentry) {
                            $prevlogentry = $logentry;
                            $sessionstarttimestamp = $logentry->timecreated;
                            $courseid = $logentry->courseid;
                            continue;
                        }

                        // Check if between prev and current log, last more than Session Timeout
                        // if so, the Session ends on the _prev_ log entry.
                        if (($logentry->timecreated - $prevlogentry->timecreated) > $sessiontimeoutseconds) {
                            // Remove possible log entries from the dump variable because I transfer them to a session.
                            $dumpentriestmp = [];

                            // Estimate Session ended half the Session Timeout after the prev log entry
                            // (prev log entry is the last entry of the Session).
                            $sessionlastentrytimestamp = $prevlogentry->timecreated;
                            $estimatedsessionend = $sessionlastentrytimestamp + $sessiontimeoutseconds / 2;

                            // Save a new session to the prev entry.
                            $newsessionscount++;
                            attendanceregister__save_session($registers[$registerid], $userid,
                                $sessionstarttimestamp, $estimatedsessionend);

                            // Session has ended: session start on current log entry.
                            $sessionstarttimestamp = $logentry->timecreated;
                        } else {
                            // Log entries that don't go in a session go in the dump variable and eventually in the dump table.
                            $dumpentriestmp[$prevlogentry->id] = $prevlogentry;
                        }
                        $prevlogentry = $logentry;
                    }

                    // If le last log entry is not the end of the last calculated session and is older than SessionTimeout
                    // create a last session.
                    if ( $logentry->timecreated > $sessionlastentrytimestamp &&
                        ( $lastcronparsedlogtimestamp - $logentry->timecreated ) > $sessiontimeoutseconds  ) {
                        // Remove possible log entries from the dump variable because I transfer them to a session.
                        $dumpentriestmp = [];

                        // In this case logEntry (and not prevlogentry is the last entry of the Session).
                        $sessionlastentrytimestamp = $logentry->timecreated;
                        $estimatedsessionend = $sessionlastentrytimestamp + $sessiontimeoutseconds / 2;

                        // Save a new session to the prev entry.
                        $newsessionscount++;
                        attendanceregister__save_session($registers[$registerid], $userid,
                            $sessionstarttimestamp, $estimatedsessionend);
                    } else {
                        // Log entries that don't go in a session go in the dump variable and eventually in the dump table.
                        $dumpentriestmp[$logentry->id] = $logentry;
                    }

                    foreach ($dumpentriestmp as $tmp) {
                        $dumpentriestodb[$tmp->id] = $tmp;
                    }

                    $dumpentriestmp = [];
                    // Updates Aggregates, only on new session creation.
                    if ($newsessionscount) {
                        attendanceregister__update_user_aggregates($registers[$registerid], $userid);
                    }
                }
            }

            mtrace(get_string('dumping_logs_rows', 'attendanceregister', count($dumpentriestodb)));
            attendanceregister__set_dump_entries($dumpentriestodb);

            // Once done everything, set the last parsed log entry.
            mtrace(get_string('sessions_added', 'attendanceregister', $newsessionscount));
        }
    }

    // At the end, update lastcronparsedlogid anyway.
    mtrace(get_string('last_parsed_log_id', 'attendanceregister', $lastcronparsedlogid));
    set_config('lastcronparsedlogid', $lastcronparsedlogid, 'attendanceregister');
}

/**
 *
 */
function get_max_users_registers_logouts() {
    global $DB;

    $sql = "select CONCAT(register, '_', userid) as fakeid, max(logout) logout, "
        ."register, userid from {attendanceregister_session} group by register, userid";
    $maxusersregisterslogouts = $DB->get_records_sql($sql, []);

    $structure = [];
    foreach ($maxusersregisterslogouts as $tmp) {
        $structure[$tmp->register][$tmp->userid] = $tmp->logout;
    }
    return $structure;
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
function attendanceregister__get_tracked_users_accociative($register, $registeridtocoursesmap) {
    global $DB;
    $trackedusers = [];

    $registertrackedcoursesids = $registeridtocoursesmap[$register->id];
    foreach ($registertrackedcoursesids as $courseid) {
        $context = context_course::instance($courseid);
        // Retrieve all tracked users.
        $trackedusersincourse = get_users_by_capability($context,
            ATTENDANCEREGISTER_CAPABILITY_TRACKED, '', '', '', '', '', '', false);
        foreach ($trackedusersincourse as $tmpuser) {
            $trackedusers[$tmpuser->id] = $tmpuser;
        }
    }

    return $trackedusers;
}


/**
 * Log version of the check log.
 *
 * @param object $register
 */
function attendanceregister__check_lock_exists_cron($register) {
    global $DB;
    return $DB->record_exists('attendanceregister_lock', ['register' => $register->id]);
}


/**
 * Get users tracked in a course.
 *
 * @param array $trackedcoursesids
 */
function attendanceregister__get_courses_users($trackedcoursesids) {
    global $DB;

    $trackedusersincourse = [];
    $params = [];
    foreach ($trackedcoursesids as $courseid) {
        $context = context_course::instance($courseid);
        list($esql, $params) = get_enrolled_sql($context, ATTENDANCEREGISTER_CAPABILITY_TRACKED);
        $sql = "SELECT distinct u.id FROM {user} u JOIN ($esql) je ON je.id = u.id ORDER BY u.id";
        $users = $DB->get_records_sql($sql, $params);
        foreach ($users as $user) {
            $trackedusersincourse[$courseid][$user->id] = 1;
        }
    }

    return $trackedusersincourse;
}


/**
 * Builds a data structure based on users and registers and fills it with correlated log entries.
 *
 * @param array $logentries
 * @param array $dumpentries
 * @param array $courseidtoregistermap
 */
function attendanceregister__order_logs_by_user_and_register($logentries, $dumpentries,
    $courseidtoregistermap, $registeridtocoursesmap) {
    $tmp = [];

    foreach ($dumpentries as $logentry) {
        $registers = $courseidtoregistermap[$logentry->courseid];
        foreach ($registers as $register) {
            foreach ($registeridtocoursesmap[$register->id] as $courseid) {
                $tmp[$logentry->userid][$register->id][] = $logentry;
            }
        }
    }
    foreach ($logentries as $logentry) {
        $registers = $courseidtoregistermap[$logentry->courseid];
        foreach ($registers as $register) {
            foreach ($registeridtocoursesmap[$register->id] as $courseid) {
                $tmp[$logentry->userid][$register->id][] = $logentry;
            }
        }
    }
    return $tmp;
}

/**
 * Filter logs by users.
 *
 * @param array $logentries
 * @param array $trackedusersinregisters
 * @param array $courseidtoregistermap
 * @param array $maxusersregisterslogouts
 */
function attendanceregister__filter_logs_by_users($logentries, $trackedusersinregisters,
    $courseidtoregistermap, $maxusersregisterslogouts) {
    foreach (array_keys($logentries) as $key) {

        $trovato = 0;
        $registers = $courseidtoregistermap[$logentries[$key]->courseid];
        foreach ($registers as $register) {
            if (isset($trackedusersinregisters[$register->id][$logentries[$key]->userid])) {
                $trovato = 1;
                break;
            }
        }
        if (!$trovato) {
            unset($logentries[$key]);
        } else if (isset($maxusersregisterslogouts[$register->id][$logentries[$key]->userid]) &&
            $logentries[$key]->timecreated <= $maxusersregisterslogouts[$register->id][$logentries[$key]->userid]
            ) {
            // Unset the logentry if an older session already exists: maxusersregisterslogouts are
            // the logout timestamps from the *sessions* table.
            // This is used to avoid sessions duplication if a problem occurs and we need to recalc a chuck of logs
            // and when we switch from the old method to the new one.
            unset($logentries[$key]);
        }
    }
    return $logentries;
}


/**
 * Retrieves all log entries for all users for all activities in a given list of courses.
 * Log entries are sorted from oldest to newest
 *
 * @param int $fromid
 * @param array $trackedcoursesids
 * @param int $totallogentriescount count of records, passed by ref.
 */
function attendanceregister__get_log_entries_in_courses($fromid, $trackedcoursesids, &$totallogentriescount) {
    global $DB;

    $maxlogentriestoanalyze = get_config('attendanceregister', 'maxlogentriestoanalyze');
    $selectlistsql = " *";
    $fromwheresql = " FROM {logstore_standard_log} l WHERE l.id > :fromid";
    $orderbysql = " ORDER BY l.timecreated ASC";
    $limitsql = " LIMIT ". $maxlogentriestoanalyze;
    $querysql = "SELECT" . $selectlistsql . $fromwheresql . $orderbysql . $limitsql;

    $params = ['fromid' => $fromid];
    $logentries = $DB->get_records_sql($querysql, $params);
    if ($logentries) {
        $lastcronparsedlogid = $logentries[array_key_last($logentries)]->id;
    } else {
        $lastcronparsedlogid = $fromid;
    }

    $tmp = [];
    foreach ($trackedcoursesids as $trackedcoursesid) {
        $tmp[$trackedcoursesid] = $trackedcoursesid;
    }
    $trackedcoursesids = $tmp;
    foreach (array_keys($logentries) as $key) {
        if ($logentries[$key]->courseid === 0) {
            unset($logentries[$key]);
            continue;
        }
        if ($logentries[$key]->courseid === 1) {
            unset($logentries[$key]);
            continue;
        }

        if (!isset($trackedcoursesids[$logentries[$key]->courseid])) {
            unset($logentries[$key]);
        }
    }

    return [$logentries, $lastcronparsedlogid];
}




/**
 * Retrieves all log entries saved from previous elaborations in the dump table.
 * Log entries are sorted from oldest to newest
 *
 * @param int $fromid
 * @param array $trackedcoursesids
 * @param int $totalLogEntriesCount count of records, passed by ref.
 */
function attendanceregister__get_dump_entries() {
    global $DB;

    $selectlistsql = " *";
    $fromwheresql = " FROM {attendanceregister_log_dump} l";
    $orderbysql = " ORDER BY l.timecreated ASC";
    $querysql = "SELECT" . $selectlistsql . $fromwheresql . $orderbysql;

    $params = [];
    $dumpentries = $DB->get_records_sql($querysql, $params);

    $deletetable = $DB->execute('TRUNCATE TABLE {attendanceregister_log_dump}', []);

    return $dumpentries;
}


/**
 * Get last parsed log from the plugin config to make another run.
 */
function attendanceregister_get_last_parsed_log_id() {
    global $DB;

    $lastcronparsedlogid = get_config('attendanceregister', 'lastcronparsedlogid');

    // Needed during switchoff to version 2023050401.
    // If lastcronparsedlogid is not setted, we go back 1 day to retrieve old logs, but only if sessions are present.
    // Otherwise we just installed the module and lastcronparsedlogid is 0.
    // TODO this should be done in upgrade.php.
    if (!$lastcronparsedlogid) {
        $querysql = "SELECT max(logout) as maxlogout FROM {attendanceregister_session}";
        $maxlogout = $DB->get_record_sql($querysql, []);
        $maxlogout = $maxlogout->maxlogout;
        if (!$maxlogout) {
            $lastcronparsedlogid = 0;
        } else {
            $querysql = "SELECT id FROM {logstore_standard_log} WHERE timecreated >= :maxlogout limit 1";
            $tmp = $DB->get_record_sql($querysql, ['maxlogout' => ($maxlogout - 3600 * 24)]);
            $lastcronparsedlogid = $tmp->id;
        }
    }
    return $lastcronparsedlogid;
}
