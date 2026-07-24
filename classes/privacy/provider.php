<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for aiplacement_modgen.
 *
 * @package     aiplacement_modgen
 * @category    privacy
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

/**
 * Privacy Subsystem for aiplacement_modgen implementing metadata and data providers.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns meta data about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        // The aiplacement_modgen_jobs table stores background job records.
        $collection->add_database_table(
            'aiplacement_modgen_jobs',
            [
                'userid' => 'privacy:metadata:jobs:userid',
                'courseid' => 'privacy:metadata:jobs:courseid',
                'action' => 'privacy:metadata:jobs:action',
                'parameters' => 'privacy:metadata:jobs:parameters',
                'result' => 'privacy:metadata:jobs:result',
                'status' => 'privacy:metadata:jobs:status',
                'timecreated' => 'privacy:metadata:jobs:timecreated',
                'timestarted' => 'privacy:metadata:jobs:timestarted',
                'timecompleted' => 'privacy:metadata:jobs:timecompleted',
            ],
            'privacy:metadata:jobs'
        );

        // AI policy acceptance is handled by core AI subsystem, no need to declare here.

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Jobs are stored in course context based on the courseid field.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {aiplacement_modgen_jobs} j ON j.courseid = c.id
                 WHERE j.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Find users who have jobs in this course.
        $sql = "SELECT j.userid
                  FROM {aiplacement_modgen_jobs} j
                 WHERE j.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Export job records for this user in this course.
            $jobs = $DB->get_records('aiplacement_modgen_jobs', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            if (!empty($jobs)) {
                $jobdata = [];
                foreach ($jobs as $job) {
                    // Sanitize or remove sensitive data from parameters/result if needed.
                    $jobdata[] = [
                        'action' => $job->action,
                        'status' => $job->status,
                        'timecreated' => transform::datetime($job->timecreated),
                        'timestarted' => $job->timestarted ? transform::datetime($job->timestarted) : null,
                        'timecompleted' => $job->timecompleted ? transform::datetime($job->timecompleted) : null,
                        // Note: parameters and result may contain prompt data or AI responses.
                        // We export them for transparency but could redact if desired.
                        'parameters' => $job->parameters,
                        'result' => $job->result,
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('privacy:path:jobs', 'aiplacement_modgen')],
                    (object) ['jobs' => $jobdata]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Delete all jobs for this course.
        // This is appropriate when deleting a course entirely.
        $DB->delete_records('aiplacement_modgen_jobs', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            // Delete job records for this user in this course.
            // We delete jobs entirely rather than anonymizing since they're temporary operational data.
            $DB->delete_records('aiplacement_modgen_jobs', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        // Delete jobs for these users in this course.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $inparams['courseid'] = $context->instanceid;

        $DB->delete_records_select(
            'aiplacement_modgen_jobs',
            "userid $insql AND courseid = :courseid",
            $inparams
        );
    }
}
