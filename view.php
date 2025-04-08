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
 * Attendance Register view page
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable output buffering.
define('NO_OUTPUT_BUFFERING', true);


require('../../config.php');
require_once("lib.php");
require_once($CFG->libdir . '/completionlib.php');

// Main parameters.
$userid = optional_param('userid', 0, PARAM_INT);   // If $userid = 0 you'll see all logs.
$id = optional_param('id', 0, PARAM_INT);           // Course Module ID.
$a = optional_param('a', 0, PARAM_INT);             // Or register ID.
$groupid = optional_param('groupid', 0, PARAM_INT);             // Group ID.
// Other parameters.
// Available actions are defined as ATTENDANCEREGISTER_ACTION_*.
$inputaction = optional_param('action', '', PARAM_ALPHA);
// Parameter for deleting offline session.
$inputsessionid = optional_param('session', null, PARAM_INT);

/**************************/
// Retrieve objects.
/**************************/

if ($id) {
    $cm = get_coursemodule_from_id('attendanceregister', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $register = $DB->get_record('attendanceregister', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $register = $DB->get_record('attendanceregister', ['id' => $a], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('attendanceregister', $register->id, $register->course, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $id = $cm->id;
}

// Retrive session to delete.
$sessiontodelete = null;
if ($inputsessionid) {
    $sessiontodelete = attendanceregister_get_session($inputsessionid);
}

/**************************/
// Basic security checks.
/**************************/
// Requires login.
require_course_login($course, false, $cm);

// Retrieve Context.
if (!($context = context_module::instance($cm->id))) {
    throw new moodle_exception('badcontext');
}

// Preload User's Capabilities.
$usercapabilities = new attendanceregister_user_capablities($context);

// If user is not defined AND the user has NOT the capability to view other's Register
// force $userid to User's own ID.
if ( !$userid && !$usercapabilities->canviewotherregisters) {
    $userid = $USER->id;
}
// Beyond this point, if $userid is specified means you are working on one User's Register
// if not you are viewing all users Sessions.


/***************************************************/
// Determine Action and checks specific permissions.
/***************************************************/
// These capabilities checks block the page execution if failed.

// Requires capabilities to view own or others' register.
if ( attendanceregister__isCurrentUser($userid) ) {
    require_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OWN_REGISTERS, $context);
} else {
    require_capability(ATTENDANCEREGISTER_CAPABILITY_VIEW_OTHER_REGISTERS, $context);
}

// Require capability to recalculate.
$dorecalculate = false;
$doschedulerecalc = false;
if ($inputaction == ATTENDANCEREGISTER_ACTION_RECALCULATE ) {
    require_capability(ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS, $context);
    $dorecalculate = true;
}
if ($inputaction == ATTENDANCEREGISTER_ACTION_SCHEDULERECALC ) {
    require_capability(ATTENDANCEREGISTER_CAPABILITY_RECALC_SESSIONS, $context);
    $doschedulerecalc = true;
}


// Printable version?
$doshowprintableversion = false;
if ($inputaction == ATTENDANCEREGISTER_ACTION_PRINTABLE) {
    $doshowprintableversion = true;
}

// Check permissions and ownership for showing offline session form or saving them.
$doshowofflinesessionform = false;
$dosaveofflinesession = false;
// Only if Offline Sessions are enabled (and No printable-version action).
if ( $register->offlinesessions &&  !$doshowprintableversion  ) {
    // Only if User is NOT logged-in-as, or ATTENDANCEREGISTER_ALLOW_LOGINAS_OFFLINE_SESSIONS is enabled.
    if ( !(\core\session\manager::is_loggedinas()) || ATTENDANCEREGISTER_ALLOW_LOGINAS_OFFLINE_SESSIONS ) {
        // If user is on his own Register and may save own Sessions
        // or is on other's Register and may save other's Sessions..
        if ( $usercapabilities->canAddThisUserOfflineSession($register, $userid) ) {
            // Do show Offline Sessions Form.
            $doshowofflinesessionform = true;

            // If action is saving Offline Session...
            if ( $inputaction == ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION  ) {
                // Check Capabilities, to show an error if a security violation attempt occurs.
                if ( attendanceregister__isCurrentUser($userid) ) {
                    require_capability(ATTENDANCEREGISTER_CAPABILITY_ADD_OWN_OFFLINE_SESSIONS, $context);
                } else {
                    require_capability(ATTENDANCEREGISTER_CAPABILITY_ADD_OTHER_OFFLINE_SESSIONS, $context);
                }

                // Do save Offline Session.
                $dosaveofflinesession = true;
            }
        }
    }
}


// Check capabilities to delete self cert
// (in the meanwhile retrieve the record to delete).
$dodeleteofflinesession = false;
if ($sessiontodelete) {
    // Check if logged-in-as Session Delete.
    if (\core\session\manager::is_loggedinas() && !ATTENDANCEREGISTER_ACTION_SAVE_OFFLINE_SESSION) {
        throw new moodle_exception('onlyrealusercandeleteofflinesessions', 'attendanceregister');
    } else if ( attendanceregister__isCurrentUser($userid) ) {
        require_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OWN_OFFLINE_SESSIONS, $context);
        $dodeleteofflinesession = true;
    } else {
        require_capability(ATTENDANCEREGISTER_CAPABILITY_DELETE_OTHER_OFFLINE_SESSIONS, $context);
        $dodeleteofflinesession = true;
    }
}

/**************************/
// Retrieve data to be shown.
/**************************/

// Retrieve Course Completion info object.
$completion = new completion_info($course);


// If viewing/updating one User's Register, load the user into $userToProcess
// and retireve User's Sessions or retrieve the Register's Tracked Users.
// If viewing all Users load tracked user list.
$usertoprocess = null;
$usersessions = null;
$trackedusers = null;
if ( $userid ) {
    $usertoprocess = attendanceregister__getUser($userid);
    $usertoprocessfullname = fullname($usertoprocess);
    $usersessions = new attendanceregister_user_sessions($register, $userid, $usercapabilities);
} else {
    $trackedusers = new attendanceregister_tracked_users($register, $usercapabilities);
}


/**************************/
// Pepare PAGE for rendering.
/**************************/
// Setup PAGE.
$url = attendanceregister_makeUrl($register, $userid, $groupid, $inputaction);
$PAGE->set_url($url->out());
$PAGE->set_context($context);
$titlestr = $course->shortname . ': ' . $register->name . ( ($userid) ? ( ': ' . $usertoprocessfullname ) : ('') );
$PAGE->set_title(format_string($titlestr));

$PAGE->set_heading($course->fullname);
if ($doshowprintableversion) {
    $PAGE->set_pagelayout('embedded');
}

// Add User's Register Navigation node.
if ( $usertoprocess ) {
    $registernavnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
    $usernavnode = $registernavnode->add( $usertoprocessfullname, $url );
    $usernavnode->make_active();
}


/***************************************************/
// Logs User's action and update completion-by-view.
/***************************************************/

attendanceregister_logging($register, $cm->id, $inputaction, $userid, $groupid);

// On View Completion [fixed with isse #52].
// If current user is the selected user (and completion is enabled) mark module as viewed.
if ( $userid == $USER->id && $completion->is_enabled($cm) ) {
    $completion->set_module_viewed($cm, $userid);
}


/***************************************************/
// Start Page Rendering.
/***************************************************/

echo $OUTPUT->header();
$headingstr = ( ( $userid ) ? $usertoprocessfullname : ('') );
echo $OUTPUT->heading(format_string($headingstr), 3);

/***************************************************/
// Pepare Offline Session insert form, if needed.
/***************************************************/

// If a userID is defined, offline sessions are enabled and the user may insert Self.certificatins...
// ...prepare the Form for Self.Cert.
// Process the form (if submitted).
// Note that the User is always the CURRENT User (no UserId param is passed by the form).
$doshowcontents = true;
$mform = null;
if ($userid && $doshowofflinesessionform && !$doshowprintableversion ) {

    // Prepare Form.
    $customformdata = ['register' => $register, 'courses' => $usersessions->trackedcourses->courses];
    // Also pass userid only if is saving for another user.
    if (!attendanceregister__isCurrentUser($userid)) {
        $customformdata['userid'] = $userid;
    }
    $mform = new mod_attendanceregister_selfcertification_edit_form(null, $customformdata);


    // Process Self.Cert Form submission.
    if ($mform->is_cancelled()) {
        // Cancel.
        redirect($PAGE->url);
    } else if ($dosaveofflinesession && ($formdata = $mform->get_data())) {
        // Save Session.
        attendanceregister_save_offline_session($register, $formdata);

        // Notification & Continue button.
        echo $OUTPUT->notification(get_string('offline_session_saved', 'attendanceregister'), 'notifysuccess');
        echo $OUTPUT->continue_button(attendanceregister_makeUrl($register, $userid));
        $doshowcontents = false;
    }
}

// Process Recalculate.
if ($doshowcontents && ($dorecalculate||$doschedulerecalc)) {

    // Recalculate Session for one User.
    if ($usertoprocess) {
        $progressbar = new progress_bar('recalcbar', 500, true);
        attendanceregister_force_recalc_user_sessions($register, $userid, $progressbar);

        // Reload User's Sessions.
        $usersessions = new attendanceregister_user_sessions($register, $userid, $usercapabilities);
    } else {
        // Recalculate (or schedule recalculation) of all User's Sessions.
        // Schedule Recalculation?
        if ( $doschedulerecalc ) {
            // Set peding recalc, if set.
            if ( !$register->pendingrecalc ) {
                attendanceregister_set_pending_recalc($register, true);
            }
        }

        // Recalculate Session for all User.
        if ( $dorecalculate ) {
            // Reset peding recalc, if set.
            if ( $register->pendingrecalc ) {
                attendanceregister_set_pending_recalc($register, false);
            }

            // Turn off time limit: recalculation can be slow.
            set_time_limit(0);

            // Cleanup all online Sessions & Aggregates before recalculating [issue #14].
            attendanceregister_delete_all_users_online_sessions_and_aggregates($register);

            // Reload tracked Users list before Recalculating [issue #14].
            $newtrackedusers = attendanceregister_get_tracked_users($register);

            // Iterate each user and recalculate Sessions.
            foreach ($newtrackedusers as $user) {

                // Recalculate Session for one User.
                $progressbar = new progress_bar('recalcbar_' . $user->id, 500, true);
                attendanceregister_force_recalc_user_sessions($register, $user->id,
                    $progressbar, false); // No delete needed, having done before [issue #14].
            }
            // Reload All Users Sessions.
            $trackedusers = new attendanceregister_tracked_users($register, $usercapabilities);
        }
    }

    // Notification & Continue button.
    if ( $dorecalculate || $doschedulerecalc ) {
        $notificationstr = get_string( ($dorecalculate) ? 'recalc_complete' : 'recalc_scheduled', 'attendanceregister');
        echo $OUTPUT->notification($notificationstr, 'notifysuccess');
    }
    echo $OUTPUT->continue_button(attendanceregister_makeUrl($register, $userid));
    $doshowcontents = false;
} else if ($doshowcontents && $dodeleteofflinesession) {
    // Process Delete Offline Session Action.
    // Delete Offline Session.
    attendanceregister_delete_offline_session($register, $sessiontodelete->userid, $sessiontodelete->id);

    // Notification & Continue button.
    echo $OUTPUT->notification(get_string('offline_session_deleted', 'attendanceregister'), 'notifysuccess');
    echo $OUTPUT->continue_button(attendanceregister_makeUrl($register, $userid));
    $doshowcontents = false;
} else if ($doshowcontents) {
    // Show Contents: User's Sesions (if $userID) or Tracked Users summary.
    // Show User's Sessions.
    if ($userid) {
        // Button bar.

        echo $OUTPUT->container_start('attendanceregister_buttonbar btn-group');

        // Printable version button or Back to normal version.
        $linkurl = attendanceregister_makeUrl($register, $userid, null,
            ( ($doshowprintableversion) ? (null) : (ATTENDANCEREGISTER_ACTION_PRINTABLE)));
        echo $OUTPUT->single_button($linkurl, (($doshowprintableversion) ? (get_string('back_to_normal', 'attendanceregister')) :
            (get_string('show_printable', 'attendanceregister'))), 'get');
        // Back to Users List Button (if allowed & !printable).
        if ($usercapabilities->canviewotherregisters && !$doshowprintableversion) {
            echo $OUTPUT->single_button(attendanceregister_makeUrl($register),
                get_string('back_to_tracked_user_list', 'attendanceregister'), 'get');
        }
        echo $OUTPUT->container_end();  // Button Bar.
        echo '<br />';

        // Offline Session Form.
        // Show Offline Session Self-Certifiation Form (not in printable).
        if ($mform && $register->offlinesessions && !$doshowprintableversion) {
            echo "<br />";
            echo $OUTPUT->box_start('generalbox attendanceregister_offlinesessionform');
            $mform->display();
            echo $OUTPUT->box_end();
        }

        // Show User's Sessions summary.
        echo '<div class="table-responsive">';
        echo html_writer::table($usersessions->useraggregates->html_table());
        echo '</div>';

        echo '<div class="table-responsive">';
        echo html_writer::table($usersessions->html_table());
        echo '</div>';
    } else {
        // Show list of Tracked Users summary.
        // Button bar.
        $manager = get_log_manager();
        $allreaders = $manager->get_readers();
        if (isset($allreaders['logstore_standard'])) {
             $standardreader = $allreaders['logstore_standard'];
            if ($standardreader->is_logging()) {
                // OK.
                $donothing = true;
            } else {
                // Standard log non scrive.
                echo $OUTPUT->notification( get_string('standardlog_readonly', 'attendanceregister')  );
            }
        } else {
             // Standard log disabilitato.
            echo $OUTPUT->notification( get_string('standardlog_disabled', 'attendanceregister')  );
        }
        // Show Recalc pending warning.
        if ( $register->pendingrecalc && $usercapabilities->canrecalcsessions && !$doshowprintableversion ) {
            echo $OUTPUT->notification( get_string('recalc_scheduled_on_next_cron', 'attendanceregister')  );
        } else if ( !attendanceregister__didCronRanAfterInstanceCreation($cm) ) {
            // Show cron not yet run on this instance.
            echo $OUTPUT->notification( get_string('first_calc_at_next_cron_run', 'attendanceregister')  );
        }

        echo $OUTPUT->container_start('attendanceregister_buttonbar btn-group');

        // If current user is tracked, show view-my-sessions button [feature #28].
        if ( $usercapabilities->istracked ) {
            $linkurl = attendanceregister_makeUrl($register, $USER->id);
            echo $OUTPUT->single_button($linkurl, get_string('show_my_sessions' , 'attendanceregister'), 'get' );
        }

        // Printable version button or Back to normal version.
        $linkurl = attendanceregister_makeUrl($register, null, null, ( ($doshowprintableversion) ?
            (null) : (ATTENDANCEREGISTER_ACTION_PRINTABLE)));
        echo $OUTPUT->single_button($linkurl, (($doshowprintableversion) ?
            (get_string('back_to_normal', 'attendanceregister')) :
            (get_string('show_printable', 'attendanceregister'))), 'get');

        echo $OUTPUT->container_end();  // Button Bar.
        echo '<br />';

        // Show list of tracked courses.
        echo '<div class="table-responsive">';
        echo html_writer::table($trackedusers->trackedcourses->html_table());
        echo '</div>';

        // Show tracked Users list.
        echo '<div class="table-responsive">';
        echo html_writer::table($trackedusers->html_table());
        echo '</div>';
    }
}

// Output page footer.
if (!$doshowprintableversion) {
    echo $OUTPUT->footer();
}
