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
 * Privacy provider.
 *
 * @package    mod_attendanceregister
 * @copyright  2012-2016 Lorenzo Nicora, 2016-today CINECA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_attendanceregister\privacy;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $items) : collection {
        $items->add_database_table(
        'attendanceregister_aggregate',
        [
        'userid' => 'privacy:metadata:attendanceregister_aggregate:userid',
        'duration' => 'privacy:metadata:attendanceregister_aggregate:duration',
        'onlinesess' => 'privacy:metadata:attendanceregister_aggregate:onlinesess',
        'total' => 'privacy:metadata:attendanceregister_aggregate:total',
        'grandtotal' => 'privacy:metadata:attendanceregister_aggregate:grandtotal',
        'refcourse' => 'privacy:metadata:attendanceregister_aggregate:refcourse',
        'lastsessionlogout' => 'privacy:metadata:attendanceregister_aggregate:lastsessionlogout',
        ],
        'privacy:metadata:attendanceregister_aggregate'
        );

        $items->add_database_table(
        'attendanceregister_lock',
        [
        'userid' => 'privacy:metadata:attendanceregister_lock:userid',
        ],
        'privacy:metadata:attendanceregister_lock'
        );

        $items->add_database_table(
        'attendanceregister_session',
        [
        'login' => 'privacy:metadata:attendanceregister_session:login',
        'logout' => 'privacy:metadata:attendanceregister_session:logout',
        'duration' => 'privacy:metadata:attendanceregister_session:duration',
        'userid' => 'privacy:metadata:attendanceregister_session:userid',
        'onlinesess' => 'privacy:metadata:attendanceregister_session:onlinesess',
        'refcourse' => 'privacy:metadata:attendanceregister_session:refcourse',
        'comments' => 'privacy:metadata:attendanceregister_session:comments',
        'addedbyuserid' => 'privacy:metadata:attendanceregister_session:addedbyuserid',
        ],
        'privacy:metadata:attendanceregister_session'
        );

        return $items;
    }

    public static function get_contexts_for_userid(int $userid) : contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();

        $params = [
            'modname'           => 'attendanceregister',
            'contextlevel'      => CONTEXT_MODULE,
            'registeruserid'    => $userid,
        ];

        $sql = "SELECT c.id
                 FROM {context} c
           INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
           INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
           INNER JOIN {attendanceregister} a ON a.id = cm.instance
            LEFT JOIN {attendanceregister_aggregate} aa ON aa.register = a.id
                WHERE (
                aa.userid        = :registeruserid
                )
        ";

        // No need to search in mdl_attendanceregister_session and in mdl_attendanceregister_aggregate, contexts are the same.
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $contexts = array_reduce($contextlist->get_contexts(), function($carry, $context) {
                $carry[] = $context->id;
            return $carry;
        }, []);

        if (empty($contexts)) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contexts as $contextid) {
            $context = \context::instance_by_id($contextid);
            $data = helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $data);
        }

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params = $contextparams;

        // Aggregate values.
        $sql = "SELECT
                    c.id AS contextid,
                    agg.duration AS duration,
                    agg.refcourse AS refcourse,
                    agg.onlinesess AS onlinesess,
                    agg.lastsessionlogout AS lastsessionlogout,
                    agg.total AS total,
                    agg.grandtotal AS grandtotal
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {attendanceregister} a ON a.id = cm.instance
                  JOIN {attendanceregister_aggregate} agg ON agg.register = a.id
                 WHERE (
                    agg.userid = :userid AND
                    c.id {$contextsql}
                )
        ";
        $params['userid'] = $userid;

        $alldata = [];
        $aggregates = $DB->get_recordset_sql($sql, $params);
        foreach ($aggregates as $aggregate) {
            $alldata[$aggregate->contextid][] = (object)[
                    'duration' => $aggregate->duration,
                    'refcourse' => $aggregate->refcourse,
                    'onlinesess' => $aggregate->onlinesess,
                    'lastsessionlogout' => $aggregate->lastsessionlogout,
                    'total' => $aggregate->total,
                    'grandtotal' => $aggregate->grandtotal,
                ];
        }
        $aggregates->close();

        array_walk($alldata, function($data, $contextid) {
            $context = \context::instance_by_id($contextid);
            $subcontext = [
                get_string('myattendanceregisteraggregates', 'attendanceregister'),
            ];
            writer::with_context($context)->export_data(
                $subcontext,
                (object)['attendanceregister_aggregates_values' => $data]
            );
        });

        // Sessions values.
        $sql = "SELECT
                    c.id AS contextid,
                    sess.duration AS duration,
                    sess.onlinesess AS onlinesess,
                    sess.login AS login,
                    sess.logout AS logout,
                    sess.refcourse AS refcourse,
                    sess.comments AS comments,
                    sess.addedbyuserid AS addedbyuserid
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {attendanceregister} a ON a.id = cm.instance
                  JOIN {attendanceregister_session} sess ON sess.register = a.id
                 WHERE (
                    sess.userid = :userid AND
                    c.id {$contextsql}
                )
        ";

        $alldata = [];
        $aggregates = $DB->get_recordset_sql($sql, $params);
        foreach ($aggregates as $aggregate) {
            $alldata[$aggregate->contextid][] = (object)[
                    'duration' => $aggregate->duration,
                    'onlinesess' => $aggregate->onlinesess,
                    'login' => $aggregate->login,
                    'logout' => $aggregate->logout,
                    'refcourse' => $aggregate->refcourse,
                    'comments' => $aggregate->comments,
                    'addedbyuserid' => $aggregate->addedbyuserid,
                ];
        }
        $aggregates->close();

        array_walk($alldata, function($data, $contextid) {
            $context = \context::instance_by_id($contextid);
            $subcontext = [
                get_string('myattendanceregistersessions', 'attendanceregister'),
            ];
            writer::with_context($context)->export_data(
                $subcontext,
                (object)['attendanceregister_sessios_values' => $data]
            );
        });
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        // This should not happen, but just in case.
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        // Prepare SQL to gather all IDs to delete.
        $sql = "SELECT ss.id
                  FROM {%s} ss
                  JOIN {modules} m
                    ON m.name = 'attendanceregister'
                  JOIN {course_modules} cm
                    ON cm.instance = ss.register
                   AND cm.module = m.id
                 WHERE cm.id = :cmid";
        $params = ['cmid' => $context->instanceid];

        static::delete_data('attendanceregister_aggregate', $sql, $params);
        static::delete_data('attendanceregister_session', $sql, $params);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        // Prepare SQL to gather all completed IDs.
        $userids = $userlist->get_userids();
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $sql = "SELECT ss.id
                  FROM {%s} ss
                  JOIN {modules} m
                    ON m.name = 'attendanceregister'
                  JOIN {course_modules} cm
                    ON cm.instance = ss.register
                   AND cm.module = m.id
                  JOIN {context} ctx
                    ON ctx.instanceid = cm.id
                 WHERE ctx.id = :contextid
                   AND ss.userid $insql";
        $params = array_merge($inparams, ['contextid' => $context->id]);

        static::delete_data('attendanceregister_aggregate', $sql, $params);
        static::delete_data('attendanceregister_session', $sql, $params);
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $sql = "SELECT ss.userid
                  FROM {%s} ss
                  JOIN {modules} m
                    ON m.name = 'attendanceregister'
                  JOIN {course_modules} cm
                    ON cm.instance = ss.register
                   AND cm.module = m.id
                  JOIN {context} ctx
                    ON ctx.instanceid = cm.id
                   AND ctx.contextlevel = :modlevel
                 WHERE ctx.id = :contextid";

        $params = ['modlevel' => CONTEXT_MODULE, 'contextid' => $context->id];

        $userlist->add_from_sql('userid', sprintf($sql, 'attendanceregister_aggregate'), $params);
        $userlist->add_from_sql('userid', sprintf($sql, 'attendanceregister_session'), $params);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        // Remove contexts different from COURSE_MODULE.
        $contextids = array_reduce($contextlist->get_contexts(), function($carry, $context) {
            if ($context->contextlevel == CONTEXT_MODULE) {
                $carry[] = $context->id;
            }
            return $carry;
        }, []);

        if (empty($contextids)) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        // Prepare SQL to gather all completed IDs.
        list($insql, $inparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);

        $sql = "SELECT a.id
                  FROM {%s} a
                  JOIN {modules} m
                    ON m.name = 'attendanceregister'
                  JOIN {course_modules} cm
                    ON cm.instance = a.register
                   AND cm.module = m.id
                  JOIN {context} ctx
                    ON ctx.instanceid = cm.id
                 WHERE a.userid = :userid
                   AND ctx.id $insql";
        $params = array_merge($inparams, ['userid' => $userid]);

        // Key "register" is in both attendanceregister_aggregate and attendanceregister_session, so the query remains the same.
        static::delete_data('attendanceregister_aggregate', $sql, $params);
        static::delete_data('attendanceregister_session', $sql, $params);
    }

    /**
     * Delete data from $tablename with the IDs returned by $sql query.
     *
     * @param  string $tablename  Table name where executing the SQL query.
     * @param  string $sql    SQL query for getting the IDs of the scoestrack entries to delete.
     * @param  array  $params SQL params for the query.
     */
    protected static function delete_data(string $tablename, string $sql, array $params) {
        global $DB;

        $scoestracksids = $DB->get_fieldset_sql(sprintf($sql, $tablename), $params);
        if (!empty($scoestracksids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($scoestracksids, SQL_PARAMS_NAMED);
            $DB->delete_records_select($tablename, "id $insql", $inparams);
        }
    }

}
