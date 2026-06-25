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
use tool_canvasuplifter\chunkupload\form_element;

/**
 * Privacy provider.
 *
 * The plugin stores two kinds of personal data: one row per build run in
 * {tool_canvasuplifter_jobs} (attributed to the user who started it, always in
 * the system context), and a transient row per chunked upload in
 * {tool_canvasuplifter_chunks} (attributed to the uploading user and the
 * context the upload was started in, with the partial file on the filesystem
 * until the cleanup task removes it). Both are covered here.
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
        $collection->add_database_table('tool_canvasuplifter_chunks', [
            'userid' => 'privacy:metadata:tool_canvasuplifter_chunks:userid',
            'contextid' => 'privacy:metadata:tool_canvasuplifter_chunks:contextid',
            'filename' => 'privacy:metadata:tool_canvasuplifter_chunks:filename',
            'lastmodified' => 'privacy:metadata:tool_canvasuplifter_chunks:lastmodified',
        ], 'privacy:metadata:tool_canvasuplifter_chunks');
        return $collection;
    }

    /**
     * Get the list of contexts that contain data for the given user.
     *
     * Build jobs live in the system context; chunked uploads live in whatever
     * context they were started in.
     *
     * @param int $userid The user to search for.
     * @return contextlist The contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql("SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {tool_canvasuplifter_jobs} j ON j.userid = :userid
                 WHERE c.contextlevel = :contextlevel", [
            'userid' => $userid,
            'contextlevel' => CONTEXT_SYSTEM,
        ]);
        $contextlist->add_from_sql("SELECT DISTINCT contextid
                  FROM {tool_canvasuplifter_chunks}
                 WHERE userid = :userid AND contextid IS NOT NULL", ['userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        // Chunked uploads are attributed to the context they were started in.
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {tool_canvasuplifter_chunks} WHERE contextid = :contextid AND userid IS NOT NULL",
            ['contextid' => $context->id]
        );

        // Build jobs all live in the system context.
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {tool_canvasuplifter_jobs}", []);
        }
    }

    /**
     * Export all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            // Chunked uploads in this context.
            $records = $DB->get_records(
                'tool_canvasuplifter_chunks',
                ['userid' => $user->id, 'contextid' => $context->id]
            );
            if ($records) {
                $files = [];
                foreach ($records as $record) {
                    $files[] = (object) [
                        'filename' => $record->filename,
                        'state' => $record->state,
                        'lastmodified' => $record->lastmodified ?
                            transform::datetime($record->lastmodified) : null,
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:chunkspath', 'tool_canvasuplifter')],
                    (object) ['files' => $files]
                );
            }

            // Build jobs live only in the system context.
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
     * Delete all data in the given context.
     *
     * @param context $context The context to delete data in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        // Chunked uploads started in this context (plus their on-disk files).
        $ids = $DB->get_fieldset_select(
            'tool_canvasuplifter_chunks',
            'id',
            'contextid = :contextid',
            ['contextid' => $context->id]
        );
        self::delete_chunk_files($ids);

        if ($context instanceof \context_system) {
            $DB->delete_records('tool_canvasuplifter_jobs');
        }
    }

    /**
     * Delete all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();

        // Chunked uploads owned by the user in the approved contexts.
        $contextids = $contextlist->get_contextids();
        if ($contextids) {
            [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
            $params['userid'] = $user->id;
            $ids = $DB->get_fieldset_select(
                'tool_canvasuplifter_chunks',
                'id',
                "userid = :userid AND contextid $insql",
                $params
            );
            self::delete_chunk_files($ids);
        }

        // Build jobs in the system context.
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
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        // Chunked uploads owned by those users in this context.
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $ids = $DB->get_fieldset_select(
            'tool_canvasuplifter_chunks',
            'id',
            "contextid = :contextid AND userid $insql",
            $params
        );
        self::delete_chunk_files($ids);

        // Build jobs in the system context.
        if ($context instanceof \context_system) {
            [$jinsql, $jparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('tool_canvasuplifter_jobs', "userid $jinsql", $jparams);
        }
    }

    /**
     * Delete the records and on-disk files for the given chunked-upload ids.
     *
     * @param array $ids The chunked-upload ids to delete.
     * @return void
     */
    protected static function delete_chunk_files(array $ids): void {
        foreach ($ids as $id) {
            form_element::delete_file($id);
        }
    }
}
