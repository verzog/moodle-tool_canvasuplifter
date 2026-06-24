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

namespace tool_canvasuplifter\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The plugin records one row per build run in {tool_canvasuplifter_jobs},
 * attributed to the user who started it. Those rows are personal data, so this
 * is a full metadata + request provider rather than a null provider.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_canvasuplifter_jobs', [
            'userid' => 'privacy:metadata:tool_canvasuplifter_jobs:userid',
            'status' => 'privacy:metadata:tool_canvasuplifter_jobs:status',
            'categoryid' => 'privacy:metadata:tool_canvasuplifter_jobs:categoryid',
            'packageurl' => 'privacy:metadata:tool_canvasuplifter_jobs:packageurl',
            'courseid' => 'privacy:metadata:tool_canvasuplifter_jobs:courseid',
            'errormsg' => 'privacy:metadata:tool_canvasuplifter_jobs:errormsg',
            'timecreated' => 'privacy:metadata:tool_canvasuplifter_jobs:timecreated',
            'timemodified' => 'privacy:metadata:tool_canvasuplifter_jobs:timemodified',
        ], 'privacy:metadata:tool_canvasuplifter_jobs');
        return $collection;
    }

    /**
     * Get the list of contexts that contain data for the given user.
     *
     * Every job row lives in the system context, so this returns the system
     * context when the user has started at least one build.
     *
     * @param int $userid The user to search for.
     * @return contextlist The contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {tool_canvasuplifter_jobs} j ON j.userid = :userid
                 WHERE c.contextlevel = :contextlevel";
        $contextlist->add_from_sql($sql, [
            'userid' => $userid,
            'contextlevel' => CONTEXT_SYSTEM,
        ]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', "SELECT userid FROM {tool_canvasuplifter_jobs}", []);
    }

    /**
     * Export all job data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $jobs = $DB->get_records('tool_canvasuplifter_jobs', ['userid' => $user->id]);
            if (!$jobs) {
                continue;
            }
            $data = [];
            foreach ($jobs as $job) {
                $data[] = (object) [
                    'status' => $job->status,
                    'categoryid' => $job->categoryid,
                    'packageurl' => $job->packageurl,
                    'courseid' => $job->courseid,
                    'errormsg' => $job->errormsg,
                    'timecreated' => transform::datetime($job->timecreated),
                    'timemodified' => transform::datetime($job->timemodified),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:jobspath', 'tool_canvasuplifter')],
                (object) ['jobs' => $data]
            );
        }
    }

    /**
     * Delete all job data in the given context.
     *
     * @param context $context The context to delete data in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('tool_canvasuplifter_jobs');
        }
    }

    /**
     * Delete all job data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('tool_canvasuplifter_jobs', ['userid' => $user->id]);
            }
        }
    }

    /**
     * Delete data for multiple users in the given context.
     *
     * @param approved_userlist $userlist The approved users to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('tool_canvasuplifter_jobs', "userid $insql", $inparams);
    }
}
