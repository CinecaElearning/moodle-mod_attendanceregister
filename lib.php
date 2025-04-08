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
 * lib.php - Library of functions and constants for module Attendance Register
 * Mandatory public API of AttendanceRegister module
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

// Include private functions.
require_once("locallib.php");

// Include DTO classes.
require_once("attendanceregister_user_aggregates_summary.class.php");
require_once("attendanceregister_user_aggregates.class.php");
require_once("attendanceregister_user_sessions.class.php");
require_once("attendanceregister_tracked_courses.class.php");
require_once("attendanceregister_tracked_users.class.php");


// Define constants.


/**
 * Average timeout between user's requests to be considered in the same user's session
 */
define("ATTENDANCEREGISTER_DEFAULT_SESSION_TIMEOUT", 30);

/**
 * Max number of days back, a user may insert an offline-work certification (if enabled)
 */
define("ATTENDANCEREGISTER_DEFAULT_DAYS_CERTIFICABLE", 10);


// Register Types.
define("ATTENDANCEREGISTER_TYPE_COURSE", "course");
define("ATTENDANCEREGISTER_TYPE_METAENROL", "meta");
define("ATTENDANCEREGISTER_TYPE_CATEGORY", "category");


// View Actions.
define("ATTENDANCEREGISTER_ACTION_PRINTABLE", "printable");
define("ATTENDANCEREGISTER_ACTION_RECALCULATE", "recalc");
define("ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION", "saveoffline");
define("ATTENDANCEREGISTER_ACTION_DELETE_OFFLINE_SESSION", "deloffline");
define("ATTENDANCEREGISTER_ACTION_SCHEDULERECALC", "schedrecalc");

// Logging Actions.
define('ATTENDANCEREGISTER_LOGACTION_VIEW', 'view');
define('ATTENDANCEREGISTER_LOGACTION_VIEW_ALL', 'view all');
define('ATTENDANCEREGISTER_LOGACTION_ADD_OFFLINE', 'add offline');
define('ATTENDANCEREGISTER_LOGACTION_DELETE_OFFLINE', 'delete offline');
define('ATTENDANCEREGISTER_LOGACTION_RECALCULTATE', 'recalculate');


// Capabilities.
define("ATTENDANCEREGISTER_CAPABILITY_TRACKED", "mod/attendanceregister:tracked");
define("ATTENDANCEREGISTER_CAPABILITY_VIEW_OTHER_REGISTERS", "mod/attendanceregister:viewotherregisters");
define("ATTENDANCEREGISTER_CAPABILITY_VIEW_OWN_REGISTERS", "mod/attendanceregister:viewownregister");
define("ATTENDANCEREGISTER_CAPABILITY_ADD_OWN_OFFLINE_SESSIONS", "mod/attendanceregister:addownofflinesess");
define("ATTENDANCEREGISTER_CAPABILITY_ADD_OTHER_OFFLINE_SESSIONS", "mod/attendanceregister:addotherofflinesess");
define("ATTENDANCEREGISTER_CAPABILITY_DELETE_OWN_OFFLINE_SESSIONS", "mod/attendanceregister:deleteownofflinesess");
define("ATTENDANCEREGISTER_CAPABILITY_DELETE_OTHER_OFFLINE_SESSIONS", "mod/attendanceregister:deleteotherofflinesess");
define("ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS", "mod/attendanceregister:recalcsessions");

// Other Params that define behaviour of all Register instances.

/**
 * Allow Self Certifications while in Login-as
 * This should be turned on only for testing!
 */
define("ATTENDANCEREGISTER_ALLOW_LOGINAS_OFFLINE_SESSIONS", false);


/**
 * Define the maximum Offline session length that will be considered reasonable
 * (in seconds)
 * Now 12h!
 */
define("ATTENDANCEREGISTER_MAX_REASONEABLE_OFFLINE_SESSION_SECONDS", 12 * 3600);

/**
 * Max length for Comments shortening
 */
define('ATTENDANCEREGISTER_COMMENTS_SHORTEN_LENGTH', 25);

/**
 * After how long a Lock is considered an orphan?
 */
define('ATTENDANCEREGISTER_ORPHANED_LOCKS_DELAY_SECONDS', 30 * 60);

/**
 * Default completion total duration (in minutes): 1h
 */
define('ATTENDANCEREGISTER_DEFAULT_COMPLETION_TOTAL_DURATION_MINS', 60);

/********************************
   Moodle Module API functions.
 ********************************/

/**
 * Setup a new instance
 * @param object $register
 * @return int new instance id
 */
function attendanceregister_add_instance($register) {
    global $DB;

    $register->timemodified = time();

    // Setup defaults (if not set).
    if (!isset($register->type)) {
        $register->type = ATTENDANCEREGISTER_TYPE_COURSE;
    }
    if (!isset($register->sessiontimeout)) {
        $register->sessiontimeout = ATTENDANCEREGISTER_DEFAULT_SESSION_TIMEOUT;
    }
    if (!isset($register->dayscertificable)) {
        $register->dayscertificable = ATTENDANCEREGISTER_DEFAULT_DAYS_CERTIFICABLE;
    }
    if (!isset($register->offlinesessions)) {
        $register->offlinesessions = 0;
    }
    if (!isset($register->offlinecomments)) {
        $register->offlinecomments = 1;
    }
    if (!isset($register->mandatoryofflinecomments)) {
        $register->mandatoryofflinecomments = 0;
    }
    if (!isset($register->offlinespecifycourse)) {
        $register->offlinespecifycourse = 0;
    }
    if (!isset($register->mandatoryofflinespecifycourse)) {
        $register->mandatoryofflinespecifycourse = 0;
    }

    // Save the new instance.
    $register->id = $DB->insert_record('attendanceregister', $register);

    return $register->id;
}

/**
 * Update mod instance.
 *
 * @param stdClass $register
 * @return bool true
 */
function attendanceregister_update_instance($register) {
    global $DB;

    $register->id = $register->instance;
    $register->timemodified = time();

    // Fixes unchecked checkbox fields
    // (advcheckbox would fix this problem, but disabledIf() apparently do not work with advcheckboxes).
    if (!isset($register->offlinesessions)) {
        $register->offlinesessions = 0;
    }
    if (!isset($register->offlinecomments)) {
        $register->offlinecomments = 0;
    }
    if (!isset($register->mandatoryofflinecomm)) {
        $register->mandatoryofflinecomm = 0;
    }
    if (!isset($register->offlinespecifycourse)) {
        $register->offlinespecifycourse = 0;
    }
    if (!isset($register->mandofflspeccourse)) {
        $register->mandofflspeccourse = 0;
    }

    // Check if any setting requiring recalculating Sessions has been changed.
    $oldregister = $DB->get_record('attendanceregister', ['id' => $register->id] );
    if ( $oldregister &&  $oldregister->sessiontimeout != $register->sessiontimeout ) {
        $register->pendingrecalc = true;
    }

    return $DB->update_record('attendanceregister', $register);
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id
 * @return bool
 */
function attendanceregister_delete_instance($id) {
    global $DB;

    if (! $register = $DB->get_record("attendanceregister", ["id" => "$id"])) {
        return false;
    }

    $result = true;

    if (!$DB->delete_records("attendanceregister_session", ["register" => "$register->id"])) {
        $result = false;
    }
    if (!$DB->delete_records("attendanceregister_lock", ["register" => "$register->id"])) {
        $result = false;
    }
    if (!$DB->delete_records("attendanceregister_aggregate", ["register" => "$register->id"])) {
        $result = false;
    }

    if (!$DB->delete_records("attendanceregister", ["id" => "$register->id"])) {
        return false;
    }

    return $result;
}

/**
 * Supported features
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function attendanceregister_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
return false;
        case FEATURE_GROUPINGS:
return false;
        case FEATURE_GROUPMEMBERSONLY:
return false;
        case FEATURE_MOD_INTRO:
return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
return true;
        case FEATURE_COMPLETION_HAS_RULES:
return true;
        case FEATURE_GRADE_HAS_GRADE:
return false;
        case FEATURE_GRADE_OUTCOMES:
return false;
        case FEATURE_RATE:
return false;
        case FEATURE_BACKUP_MOODLE2:
return true;
        case FEATURE_SHOW_DESCRIPTION:
return false;

        default:
return null;
    }
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing
 * this activity in a course listing.
 * See get_array_of_activities() in course/lib.php
 *
 * @param object $coursemodule
 * @return object|null
 */
function attendanceregister_get_coursemodule_info($coursemodule) {
    global $DB;

    if (!$register = $DB->get_record('attendanceregister',
        ['id' => $coursemodule->instance], 'id, name, intro, introformat, type')) {
        return false;
    }

    $info = new stdClass();
    $info->name = $register->name;

    return $info;
}

/**
 * List of view style log actions
 * @return array
 */
function attendanceregister_get_view_actions() {
    return ['view', 'view all'];
}

/**
 * List of update style log actions
 * @return array
 */
function attendanceregister_get_post_actions() {
    return ['add offline session', 'delete offline session', 'delete offline session'];
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function attendanceregister_reset_userdata($data) {
    // XXX TODO understand if cleaning sessions table data is needed, aggregates etc.
    return [];
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function attendanceregister_get_extra_capabilities() {
    return [
        ATTENDANCEREGISTER_CAPABILITY_TRACKED,
        ATTENDANCEREGISTER_CAPABILITY_VIEW_OTHER_REGISTERS,
        ATTENDANCEREGISTER_CAPABILITY_VIEW_OWN_REGISTERS,
        ATTENDANCEREGISTER_CAPABILITY_ADD_OWN_OFFLINE_SESSIONS,
        ATTENDANCEREGISTER_CAPABILITY_DELETE_OTHER_OFFLINE_SESSIONS,
        ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS,
    ];
}

/**
 * Function run periodically by cron
 * Execute a Session Update on all Tracked Users of all Registers, if needed
 */
function attendanceregister_cron() {
    global $DB;

    // Remove orphaned Locks [issue #1].
    $orphaniftakenonbefore = time() - ATTENDANCEREGISTER_ORPHANED_LOCKS_DELAY_SECONDS;
    $locks = $DB->delete_records_select('attendanceregister_lock', 'takenon < :takenon',
        ['takenon' => $orphaniftakenonbefore] );

    $registers = $DB->get_records('attendanceregister');

    if (!count($registers)) {
        mtrace(get_string('no_registers', 'attendanceregister'));
    } else {
        require_once('cronlib.php');
        $startid = attendanceregister_get_last_parsed_log_id();

        // We used to call attendanceregister_updates_all_users_sessions for every register
        // now we call attendanceregister_update_sessions_from_id.
        attendanceregister_update_sessions_from_id($registers, $startid);

        foreach ($registers as $register) {
            // Updates online Sessions.

            // Process pending recalculation or update only users that need updating.
            if ( $register->pendingrecalc ) {
                 mtrace(get_string('force_recalculating', 'attendanceregister', $register));
                 attendanceregister_force_recalc_all($register);

                 // Reset pendingrecalc flag.
                 attendanceregister_set_pending_recalc($register, false);
            }
        }
    }

    return true;
}


/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settingsnav The settings navigation object
 * @param navigation_node $booknode The node to add module settings to
 * @return void
 */
function attendanceregister_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $attendanceregisternode) {
    global $PAGE, $USER;
    if ($PAGE->cm->modname !== 'attendanceregister') {
        return;
    }
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = context_module::instance($PAGE->cm->instance);
    }

    $register = $PAGE->activityrecord;
    $params = $PAGE->url->params();
    $usercapabilities = new attendanceregister_user_capablities($PAGE->cm->context);

    // Add Recalc menu entries to Settings Menu.
    if ( $usercapabilities->canrecalcsessions ) {
        if ( !empty($params['userid']) || !$usercapabilities->canviewotherregisters ) {
            // Single User view.
            $userid = clean_param($params['userid'], PARAM_INT);
            $linkurl = attendanceregister_makeUrl($register, $userid, null, ATTENDANCEREGISTER_ACTION_RECALCULATE);
            $menuentrystr = get_string('force_recalc_user_session', 'attendanceregister');
            $attendanceregisternode->add($menuentrystr, $linkurl, navigation_node::TYPE_SETTING);

        } else {
            // All Users view.

            $linkurl = attendanceregister_makeUrl($register, null, null, ATTENDANCEREGISTER_ACTION_RECALCULATE);
            $menuentrystr = get_string('force_recalc_all_session_now', 'attendanceregister');
            if ( $register->pendingrecalc ) {
                $menuentrystr  = $menuentrystr . ' ' . get_string('recalc_already_pending', 'attendanceregister');
                $attendanceregisternode->add($menuentrystr, $linkurl, navigation_node::TYPE_SETTING);
            } else {
                $attendanceregisternode->add($menuentrystr, $linkurl, navigation_node::TYPE_SETTING);

                // Also adds Schedule Entry.
                $linkurl2 = attendanceregister_makeUrl($register, null, null, ATTENDANCEREGISTER_ACTION_SCHEDULERECALC);
                $menuentrystr2 = get_string('schedule_reclalc_all_session', 'attendanceregister');
                $attendanceregisternode->add($menuentrystr2, $linkurl2, navigation_node::TYPE_SETTING);
            }
        }
    }
}

/********************************
   Module specific functions
 *******************************/

/**
 * Returns list of available Register types
 * @return array
 */
function attendanceregister_get_register_types() {
    $options = [
        ATTENDANCEREGISTER_TYPE_COURSE => get_string('type_'.ATTENDANCEREGISTER_TYPE_COURSE, 'attendanceregister'),
        ATTENDANCEREGISTER_TYPE_CATEGORY => get_string('type_'.ATTENDANCEREGISTER_TYPE_CATEGORY, 'attendanceregister'),
        ATTENDANCEREGISTER_TYPE_METAENROL => get_string('type_'.ATTENDANCEREGISTER_TYPE_METAENROL, 'attendanceregister'),
    ];
    return $options;
}

/**
 * Retrieve a Register Session by id
 * @param int $sessionId
 * @return object the session record
 */
function attendanceregister_get_session($sessionid) {
    global $DB;

    return $DB->get_record('attendanceregister_session', ['id' => $sessionid], '*', MUST_EXIST);
}

/**
 * Retrieve all User's Session in a given Register
 *
 * @param object $register
 * @param int $userid
 * @return array of AttendanceRegisterSession
 */
function attendanceregister_get_user_sessions($register, $userid) {
    global $DB;
    $params = ['register' => $register->id, 'userid' => $userid];
    $usersessions = $DB->get_records('attendanceregister_session', $params, 'login DESC');
    return $usersessions;
}

/**
 * Updates recorded Sessions of a User
 * if User's Register is not Locked
 * AND if $recalculation is set
 *      OR attendanceregister_check_user_sessions_need_update(...) returns true
 *
 * @param object $register Register instance
 * @param int $userid User->id
 * @param progress_bar $progressbar optional instance of progress_bar to update
 * @param boolean $recalculation true on recalculation: ignore locks and needUpdate check
 * @return boolean true if any new session has been found
 */
function attendanceregister_update_user_sessions($register, $userid, ?progress_bar $progressbar = null, $recalculation = false) {

    // If not running in Recalc,
    // check if a Lock exists on this User's Register; if so, exit immediately.
    if (!$recalculation && attendanceregister__check_lock_exists($register, $userid)) {
        // If a progress bar exists, before exiting reset it.
        attendanceregister__finalize_progress_bar($progressbar, get_string('online_session_updated', 'attendanceregister'));
        $a = new stdClass();
        $a->registerid = $register->id;
        $a->userid = $userid;
        mtrace(get_string('lock_found', 'attendanceregister', $a));
        return false;
    }

    // Check if Update is needed.
    if ($recalculation) {
        $lastsessionlogout = 0;
        $needupdate = true;
        mtrace(get_string('forced_recalc', 'attendanceregister'));
    } else {
        $lastsessionlogout = 0;
        $needupdate = attendanceregister_check_user_sessions_need_update($register, $userid, $lastsessionlogout);
        $a = new stdClass();
        $a->registerid = $register->id;
        $a->userid = $userid;
        mtrace(get_string((($needupdate) ? "user_update" : "user_update_doesnt"), 'attendanceregister', $a));
    }

    if ($needupdate) {
        // Calculate all new sesssions after that timestamp.
        $newsessionsfound = ( attendanceregister__build_new_user_sessions($register,
            $userid, $lastsessionlogout, $progressbar) > 0);
        return $newsessionsfound;
    } else {
        attendanceregister__finalize_progress_bar($progressbar, get_string('online_session_updated', 'attendanceregister'));
        return false;
    }
}

/**
 * Delete all online Sessions and Aggregates in a given Register
 * @param object $register
 */
function attendanceregister_delete_all_users_online_sessions_and_aggregates($register) {
    global $DB;
    $DB->delete_records('attendanceregister_aggregate', ['register' => $register->id]);
    $DB->delete_records('attendanceregister_session', ['register' => $register->id, 'onlinesess' => 1]);
}

/**
 * Force recalculation of all sessions for a given User.
 * First delete currently saved Session, then launch update sessions
 * During the process, attain a Lock on the User's Register
 *
 * @param object $register
 * @param int $userid
 * @param progress_bar $progressbar
 * @param boolean $deleteOldData before recalculating (default: true)
 */
function attendanceregister_force_recalc_user_sessions($register, $userid,
    ?progress_bar $progressbar = null, $deleteolddata = true) {
    // Create a Lock on this User's Register.
    attendanceregister__attain_lock($register, $userid);

    // If needed, delete old data [issue #14].
    if ( $deleteolddata ) {
        // Retrieve the oldest User's log entry timestamp.
        $oldestlogentrytime = attendanceregister__get_user_oldest_log_entry_timestamp($userid);

        // Delete all online Sessions of the given User in the Register.
        attendanceregister__delete_user_online_sessions($register, $userid, $oldestlogentrytime);

        // Delete aggregates if needed [issue #14].
        attendanceregister__delete_user_aggregates($register, $userid);
    }

    // Recalculate (ignore frozed bit, as it has been just set).
    attendanceregister_update_user_sessions($register, $userid, $progressbar, true);

    // Release Lock on this User's Register.
    attendanceregister__release_lock($register, $userid);
}


/**
 * Force Recalculating all User's Sessions
 * Executes quietly (no Progress Bar)
 * (called after Restore and by Cron)
 * @param object $register
 */
function attendanceregister_force_recalc_all($register) {
    $users = attendanceregister_get_tracked_users($register);

    // Iterate each user and recalculate Sessions.
    foreach ($users as $user) {
        attendanceregister_force_recalc_user_sessions($register, $user->id);
    }
}


/**
 * Checks if a User's Sessions need update.
 * Need update if:
 * User->currentlogin is after User's Last Session Logout AND older than Register SessionTimeout
 *
 *
 * It uses Last Session Logout cached in Aggregates
 *
 * Note that this will report "update needed" also if the user logged in the site
 * after the last Session tracked in this Register, but did not touch any Course
 * tracked by this Register
 *
 * @param object $register
 * @param int $userid
 * @param int $lastsessionlogout (by ref.) lastsessionlogout returned if update needed
 * @return boolean true if update needed
 */
function attendanceregister_check_user_sessions_need_update($register, $userid, & $lastsessionlogout = null) {
    global $DB;
    // Retrive User.
    $user = attendanceregister__getUser($userid);

    // Retrieve User's Grand Total Aggregate (if any).
    $usergrandtotalaggregate = attendanceregister__get_cached_user_grandtotal($register, $userid);

    // If user never logged in, no update needed ;).
    if (!$user->lastaccess) {
        debugging("userid:$userid never logged in: no session update needed on registerId:$register->id");
        return false;
    }

    // If No User Aggregate yet, need update and lastSessionLogout is 0.
    if (!$usergrandtotalaggregate) {
        debugging("userid:$userid has no User Aggregate: session update needed on registerId:$register->id");
        $lastsessionlogout = 0;
        return true;
    }

    $now = time();
    // TODO this business rule is duplicated in attendanceregister__get_tracked_users_need_update()
    // but in a different form: SQL subqery, negated (to exclude users that do not need updating). This is no good!

    if (($user->lastaccess > $usergrandtotalaggregate->lastsessionlogout)
            && ( ($now - $user->lastaccess) > ($register->sessiontimeout * 60) )) {
        $lastsessionlogout = $usergrandtotalaggregate->lastsessionlogout;
        debugging("userid:$userid; session update needed on registerId:$register->id as LastSession ".
            "logout=$lastsessionlogout, lastAccess=$user->lastaccess");
        return true;
    } else {
        debugging("userid:$userid; no session update needed on registerId:$register->id");
        return false;
    }
}



/**
 * Retrieve all Users tracked by a given Register
 *
 * All Users that in the Register's Course have any Role with "mod/attendanceregister:tracked" Capability assigned.
 * (NOT Users having this Capability in all tracked Courses!)
 *
 * @param object $register
 * @return array of users
 */
function attendanceregister_get_tracked_users($register) {
    return attendanceregister__get_tracked_users($register, false);
}

/**
 * Checks if a given User is tracked by a Register instance
 */
function attendanceregister_is_tracked_user($register, $user) {
    $course = attendanceregister__get_register_course($register);
    $context = context_course::instance($course->id);

    return has_capability(ATTENDANCEREGISTER_CAPABILITY_TRACKED, $context, $user);
}

/**
 * Retrieve all Courses tracked by this Register
 * @param object $register
 * @return array of Course
 */
function attendanceregister_get_tracked_courses($register) {
    global $DB;
    $thiscourse = attendanceregister__get_register_course($register);
    $trackedcoursedids = attendanceregister__get_tracked_courses_ids($register, $thiscourse);

    $trackedcourses = $DB->get_records_list('course', 'id', $trackedcoursedids, 'sortorder ASC, fullname ASC');

    return $trackedcourses;
}

/**
 * Format duration (in seconds) in a human-readable format
 * If duration is null  shows '0' or a default string optionally passed as param
 *
 * @param int $duration
 * @param string $default (opt)
 * @return string
 */
function attendanceregister_format_duration($duration, $default = null) {

    if ($duration == null) {
        if ($default) {
            return $default;
        } else {
            $duration = 0;
        }
    }

    $dur = new stdClass();
    $dur->hours = floor($duration / 3600);
    $dur->minutes = floor(($duration % 3600 ) / 60);
    if ($dur->hours) {
        $durstr = get_string('duration_hh_mm', 'attendanceregister', $dur);
    } else {
        $durstr = get_string('duration_mm', 'attendanceregister', $dur);
    }
    return $durstr;
}

/**
 * Save a new offline session
 * Data should have been validated before saving
 *
 * Updates Aggregates after saving
 *
 * @param object $register
 * @param array $formData
 */
function attendanceregister_save_offline_session($register, $formdata) {
    global $DB, $USER;

    $session = new stdClass();
    $session->register = $register->id;
    // If a userid has not been set in the form (the user is saving in his own Register) use current $USER.
    $session->userid = (isset($formdata->userid)) ? ($formdata->userid) : ($USER->id);
    $session->onlinesess = 0;
    $session->login = $formdata->login;
    $session->logout = $formdata->logout;
    $session->duration = $formdata->logout - $formdata->login;
    // Hack needed as 0 is passed as refcourse if no refcourse has been selected.
    $session->refcourse = (isset($formdata->refcourse)) ? ($formdata->refcourse) : null;
    $session->comments = (isset( $formdata->comments)) ? $formdata->comments : null;
    // If saved for another user, record the current user.
    if ( !attendanceregister__isCurrentUser( $session->userid ) ) {
        $session->addedbyuserid = $USER->id;
    }

    $DB->insert_record('attendanceregister_session', $session);

    // Update aggregates.
    attendanceregister__update_user_aggregates($register,  $session->userid );
}

/**
 * Delete an offline Session
 * then updates Aggregates
 *
 * @param int $sessionId
 */
function attendanceregister_delete_offline_session($register, $userid, $sessionid) {
    global $DB;
    $DB->delete_records('attendanceregister_session', ['id' => $sessionid, 'userid' => $userid, 'onlinesess' => 0]);
    // Update aggregates.
    attendanceregister__update_user_aggregates($register, $userid);
}

/**
 * Updates pendingrecalc flag of a Register
 *
 * @param object $register
 * @param boolean $pendingRecalc
 */
function attendanceregister_set_pending_recalc($register, $pendingrecalc) {
    global $DB;
    $DB->update_record_raw('attendanceregister', [ 'id' => $register->id, 'pendingrecalc' => ($pendingrecalc ? 1 : 0) ] );
}


/**
 * Build the URL to the view.php page
 * of this Register
 *
 * @param object $register
 * @param int $userid User ID (optional)
 * @param int $groupId Group ID (optional)
 * @param string $action Action to execute (optional)
 * @param array $additionalParams (opt) other parameters
 * @param boolean $forLog (def=false) if true prepare the URL for logging
 *  (i.e. w/o the prefix '/mod/attendanceregister/') NEVER USED
 */
function attendanceregister_makeurl($register, $userid = null, $groupid = null,
    $action = null, $additionalparams = null, $forlog = false) {
    $params = ['a' => $register->id];
    if ($userid) {
        $params['userid'] = $userid;
    }
    if ($groupid) {
        $params['group'] = $groupid;
    }
    if ($action) {
        $params['action'] = $action;
    }
    if (is_array($additionalparams)) {
        $params = array_merge($params, $additionalparams);
    }

    $baseurl = ((!$forlog) ? '/mod/attendanceregister/' : '') . 'view.php';
    $url = new moodle_url($baseurl, $params);
    return $url;
}

/**
 * Call for event logging()
 * @param object $register
 * @param int $cmId course_module->id
 * @param string $action
 * @param int $userid
 * @param int $groupId
 */
function attendanceregister_logging($register, $cmid, $action, $userid = null, $groupid = null) {

    // Action for logging.
    switch ($action) {
        case ATTENDANCEREGISTER_ACTION_RECALCULATE:
            $event = \mod_attendanceregister\event\mod_attendance_recalculation::create([
                'objectid' => $register->id,
                'context' => context_module::instance($cmid),
            ]);
            $event->trigger();
            break;
        case ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION:
            $event = \mod_attendanceregister\event\user_attendance_addoffline::create([
                'objectid' => $register->id,
                'context' => context_module::instance($cmid),
            ]);
            $event->trigger();
            break;
        case ATTENDANCEREGISTER_ACTION_DELETE_OFFLINE_SESSION:
            $event = \mod_attendanceregister\event\user_attendance_deloffline::create([
                'objectid' => $register->id,
                'context' => context_module::instance($cmid),
            ]);
            $event->trigger();
            break;
        default:
            if ($userid) {
                $event = \mod_attendanceregister\event\user_attendance_details_viewed::create([
                    'objectid' => $register->id,
                    'context' => context_module::instance($cmid),
                    'relateduserid' => $userid,
                ]);
                $event->trigger();
            } else {
                $event = \mod_attendanceregister\event\participants_attendance_report_viewed::create([
                    'objectid' => $register->id,
                    'context' => context_module::instance($cmid),
                ]);
                $event->trigger();
            }
    }

}


/**
 * Implements activity completion conditions
 * [feature #7]
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not, $type if conditions not set.
 */
function attendanceregister_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;

    // Get instance details.
    if (!($register = $DB->get_record('attendanceregister', ['id' => $cm->instance]))) {
        throw new Exception("Can't find attendanceregister {$cm->instance}");
    }

    // If completion option is enabled, evaluate it and return true/false.
    if ($register->completiontotaldurationmins) {
        return attendanceregister__calculateUserCompletion($register, $userid);
    } else {
        return $type;
    }
}
