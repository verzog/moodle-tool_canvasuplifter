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
 * Privacy provider for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy provider for repository_largefile.
 *
 * Chunked uploads are short-lived working state: one row per in-flight upload in
 * {repository_largefile_chunks} plus a partial file on disk, both removed by the
 * cleanup task once consumed or expired. The rows carry the owning user's id and
 * the uploaded file's name, so they are declared and made exportable/erasable.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var int Largest staged payload whose bytes are inlined into a privacy export (10 MB). */
    private const EXPORT_INLINE_MAXBYTES = 10485760;

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('repository_largefile_chunks', [
            'userid' => 'privacy:metadata:repository_largefile_chunks:userid',
            'contextid' => 'privacy:metadata:repository_largefile_chunks:contextid',
            'filename' => 'privacy:metadata:repository_largefile_chunks:filename',
            'lastmodified' => 'privacy:metadata:repository_largefile_chunks:lastmodified',
        ], 'privacy:metadata:repository_largefile_chunks');
        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {repository_largefile_chunks} WHERE userid = :userid AND contextid IS NOT NULL",
            ['userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {repository_largefile_chunks} WHERE contextid = :contextid AND userid IS NOT NULL",
            ['contextid' => $context->id]
        );
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records('repository_largefile_chunks', [
                'contextid' => $context->id,
                'userid' => $user->id,
            ]);
            if (!$records) {
                continue;
            }
            $subcontext = [get_string('privacy:chunkspath', 'repository_largefile')];
            $writer = \core_privacy\local\request\writer::with_context($context);
            $data = [];
            foreach ($records as $record) {
                // The staged upload lives under dataroot (outside the file API).
                // Include its actual bytes when it is small enough to hold in
                // memory safely; a larger transient payload (this plugin can stage
                // multi-gigabyte files) is documented by its size in the metadata
                // rather than loaded whole, which would risk an out-of-memory
                // failure and drop the whole export.
                $path = \repository_largefile\chunk_store::get_path_for_id((string) $record->id);
                $size = ($path !== null && is_readable($path)) ? (int) filesize($path) : 0;
                $data[] = (object) [
                    'filename' => $record->filename,
                    'filesize' => $size,
                    'lastmodified' => $record->lastmodified ? userdate($record->lastmodified) : '',
                ];
                if ($size > 0 && $size <= self::EXPORT_INLINE_MAXBYTES) {
                    $filename = (string) $record->filename !== '' ? (string) $record->filename : ('upload_' . $record->id);
                    $writer->export_custom_file($subcontext, $filename, (string) file_get_contents($path));
                }
            }
            $writer->export_data($subcontext, (object) ['uploads' => $data]);
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::delete_rows(['contextid' => $context->id]);
    }

    /**
     * Delete all data for the given user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_rows(['contextid' => $context->id, 'userid' => $userid]);
        }
    }

    /**
     * Delete data for the listed users in the userlist's context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $select = "contextid = :contextid AND userid $insql";
        $records = $DB->get_records_select('repository_largefile_chunks', $select, $params);
        foreach ($records as $record) {
            \repository_largefile\chunk_store::delete((string) $record->id);
        }
    }

    /**
     * Delete every chunk row matching the conditions, removing its partial file too.
     *
     * @param array $conditions Column => value conditions for the rows to delete.
     * @return void
     */
    private static function delete_rows(array $conditions): void {
        global $DB;
        $records = $DB->get_records('repository_largefile_chunks', $conditions);
        foreach ($records as $record) {
            \repository_largefile\chunk_store::delete((string) $record->id);
        }
    }
}
