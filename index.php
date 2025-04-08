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
 * Definition of upgrade operations
 *
 * @package    mod_attendanceregister
 * @copyright 2012-2016 Lorenzo Nicora, 2016-today CINECA
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$id = required_param('id', PARAM_INT);   // Course.

$PAGE->set_url('/mod/choice/index.php', ['id' => $id]);

if (!$course = $DB->get_record('course', ['id' => $id])) {
    throw new moodle_exception('invalidcourseid');
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');

$strregister = get_string("modulename", "attendanceregister");
$strregisters = get_string("modulenameplural", "attendanceregister");
$strsectionname  = get_string('sectionname', 'format_'.$course->format);
$PAGE->set_title($strregisters);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strregisters);
echo $OUTPUT->header();

if (! $registers = get_all_instances_in_course("attendanceregister", $course)) {
    notice(get_string('thereareno', 'moodle', $strregisters), "../../course/view.php?id=$course->id");
}

$usesections = course_format_uses_sections($course->format);
if ($usesections) {
    $sections = get_fast_modinfo($course->id)->get_section_info_all();
}

// XXX Count tracked users.

$timenow = time();

$table = new html_table();

if ($usesections) {
    $table->head  = [$strsectionname, $strregister, get_string('registertype', 'attendanceregister'),
        get_string("tracked_users", 'attendanceregister')];
    $table->align = ["center", "left", "left", "center"];
} else {
    $table->head  = [$strregister, get_string('registertype', 'attendanceregister'),  get_string("tracked_users",
        'attendanceregister')];
    $table->align = ["left", "left", "center"];
}

$currentsection = "";

foreach ($registers as $register) {
    $trackedusers = attendanceregister_get_tracked_users($register);
    $aa = 0;
    if (is_array($trackedusers) ) {
        $aa = count($trackedusers);
    }

    if ($usesections) {
        $printsection = "";
        if ($register->section !== $currentsection) {
            if ($register->section) {
                $printsection = get_section_name($course, $sections[$register->section]);
            }
            if ($currentsection !== "") {
                $table->data[] = 'hr';
            }
            $currentsection = $register->section;
        }
    }
    // Calculate the href.
    if (!$register->visible) {
        // Show dimmed if the mod is hidden.
        $tthref = "<a class=\"dimmed\" href=\"view.php?id=$register->coursemodule\">".format_string($register->name, true)."</a>";
    } else {
        // Show normal if the mod is visible.
        $tthref = "<a href=\"view.php?id=$register->coursemodule\">".format_string($register->name, true)."</a>";
    }
    if ($usesections) {
        $table->data[] = [$printsection, $tthref, get_string( 'type_'.$register->type, 'attendanceregister'),  $aa];
    } else {
        $table->data[] = [$tthref, get_string( $register->type, 'attendanceregister'), $aa];
    }
}
echo "<br />";
echo html_writer::table($table);

echo $OUTPUT->footer();
