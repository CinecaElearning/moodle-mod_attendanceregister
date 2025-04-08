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
 * Add Attendance Register instance form
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Attendance register form definition.
 */
class mod_attendanceregister_mod_form extends moodleform_mod {

    /**
     * Definition function.
     */
    public function definition() {
        global $CFG, $COURSE, $DB;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Instance name.
        $mform->addElement('text', 'name', get_string('registername', 'attendanceregister'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Intro.
        if ($CFG->branch < 29) {
            $this->add_intro_editor(true);
        } else {
            $this->standard_intro_elements();
        }

        // Register Type.
        $registertypes = attendanceregister_get_register_types( );
        $mform->addElement('select', 'type', get_string('registertype', 'attendanceregister'), $registertypes);
        $mform->addHelpButton('type', 'registertype', 'attendanceregister');
        $mform->setDefault('type', ATTENDANCEREGISTER_TYPE_COURSE );

        // Session timeout.
        $minutes = ' '.get_string('minutes');
        $sessiondurationchoices = [
                5 => ('5'.$minutes),
                10 => ('10'.$minutes),
                15 => ('15'.$minutes),
                20 => ('20'.$minutes),
                30 => ('30'.$minutes),
                45 => ('45'.$minutes),
                60 => ('60'.$minutes),
            ];
        $mform->addElement('select', 'sessiontimeout', get_string('sessiontimeout', 'attendanceregister'), $sessiondurationchoices);
        $mform->addHelpButton('sessiontimeout', 'sessiontimeout', 'attendanceregister');
        $mform->setDefault('sessiontimeout', ATTENDANCEREGISTER_DEFAULT_SESSION_TIMEOUT );

        // Offline Session self-certification.
        $mform->addElement('header', '', get_string('offline_sessions_certification', 'attendanceregister'));

        // Enable self-certifications.
        $mform->addElement('checkbox', 'offlinesessions',
            get_string('enable_offline_sessions_certification', 'attendanceregister'));
        $mform->addHelpButton('offlinesessions', 'offline_sessions_certification', 'attendanceregister');
        $mform->setDefault('offlinesessions', false );

        // Number of day before a self-certification will be accepted.
        $day = ' '.get_string('day');
        $days = ' '.get_string('days');
        $dayscertificable = [
            1 => ('1'.$day),
            2 => ('2'.$days),
            3 => ('3'.$days),
            4 => ('4'.$days),
            5 => ('5'.$days),
            6 => ('6'.$days),
            7 => ('7'.$days),
            10 => ('10'.$days),
            14 => ('14'.$days),
            21 => ('21'.$days),
            30 => ('30'.$days),
            60 => ('60'.$days),
            90 => ('90'.$days),
            120 => ('12'.$days),
            180 => ('180'.$days),
            365 => ('365'.$days),
        ];
        $mform->addElement('select', 'dayscertificable', get_string('dayscertificable', 'attendanceregister'), $dayscertificable);
        $mform->addHelpButton('dayscertificable', 'dayscertificable', 'attendanceregister');
        $mform->setDefault('dayscertificable', ATTENDANCEREGISTER_DEFAULT_DAYS_CERTIFICABLE );
        $mform->disabledIf('dayscertificable', 'offlinesessions');

        // Offline Sessions self-cert comments.
        $mform->addElement('checkbox', 'offlinecomments', get_string('offlinecomments', 'attendanceregister'));
        $mform->addHelpButton('offlinecomments', 'offlinecomments', 'attendanceregister');
        $mform->setDefault('offlinecomments', false );
        $mform->disabledIf('offlinecomments', 'offlinesessions');

        // Mandatory Offline Sessions self-cert comments.
        $mform->addElement('checkbox', 'mandatoryofflinecomm',
            get_string('mandatory_offline_sessions_comments', 'attendanceregister'));
        $mform->setDefault('mandatoryofflinecomm', false );
        $mform->disabledIf('mandatoryofflinecomm', 'offlinesessions');
        $mform->disabledIf('mandatoryofflinecomm', 'offlinecomments');

        // Offline Certifiations allow to specify Course.
        $mform->addElement('checkbox', 'offlinespecifycourse', get_string('offlinespecifycourse', 'attendanceregister'));
        $mform->addHelpButton('offlinespecifycourse', 'offlinespecifycourse', 'attendanceregister');
        $mform->setDefault('offlinespecifycourse', false );
        $mform->disabledIf('offlinespecifycourse', 'offlinesessions');

        // Mandatory Offline Certification Course specification.
        $mform->addElement('checkbox', 'mandofflspeccourse', get_string('mandatoryofflinespecifycourse', 'attendanceregister'));
        $mform->addHelpButton('mandofflspeccourse', 'mandatoryofflinespecifycourse', 'attendanceregister');
        $mform->setDefault('mandofflspeccourse', false );
        $mform->disabledIf('mandofflspeccourse', 'offlinesessions');
        $mform->disabledIf('mandofflspeccourse', 'offlinespecifycourse');

        // Standard controls.
        $this->standard_coursemodule_elements( );

        // Buttons.
        $this->add_action_buttons();
    }

    /**
     * Add completion rules
     * [feature #7]
     */
    public function add_completion_rules() {
        $mform =& $this->_form;
        $group = [];
        $group[] =& $mform->createElement('checkbox', 'completiontotaldurationenabled', ' ',
            get_string('completiontotalduration', 'attendanceregister'));
        $group[] =& $mform->createElement('text', 'completiontotaldurationmins', ' ', ['size' => 4]);
        $mform->setType('completiontotaldurationmins', PARAM_INT);
        $mform->addGroup($group, 'completiondurationgroup',
            get_string('completiondurationgroup', 'attendanceregister'), [' '], false);
        $mform->disabledIf('completiontotaldurationmins', 'completiontotaldurationenabled', 'notchecked');

        // When more tracked values will be supported, add fields here.

        return ['completiondurationgroup'];
    }

    /**
     * Validate completion rules
     * [feature #7]
     */
    public function completion_rule_enabled($data) {
        // When more tracked values will be supported, put checking here.
        return( (!empty($data['completiontotaldurationenabled']) && $data['completiontotaldurationmins'] != 0) );
    }

    /**
     * Extend get_data() to support completion checkbox behaviour
     * [feature #7]
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return false;
        }

        // Turn off completion settings if the checkboxes aren't ticked.
        if (!empty($data->completionunlocked)) {
            $autocompletion = !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->completiontotaldurationenabled) || !$autocompletion) {
                $data->completiontotaldurationmins = 0;
            }
        }

        // When more tracked values will be supported, set disabled value here.

        return $data;
    }

    /**
     * Prepare completion checkboxes when form is displayed
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        // Set up the completion checkboxes which aren't part of standard data.
        // We also make the default value (if you turn on the checkbox) for those
        // numbers to be 1, this will not apply unless checkbox is ticked.
        $defaultvalues['completiontotaldurationenabled'] = !empty($defaultvalues['completiontotaldurationmins']) ? 1 : 0;

        if (empty($defaultvalues['completiontotaldurationmins'])) {
            $defaultvalues['completiontotaldurationmins'] = ATTENDANCEREGISTER_DEFAULT_COMPLETION_TOTAL_DURATION_MINS;
        }
        // When more tracked values will be supported, set default value here.
    }
}
