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

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\task\analyse_package_task;
use tool_canvasuplifter\task\build_course_task;

/**
 * Stable entry point for queuing an analyse or build run.
 *
 * The main upload page (index.php) has always driven the pipeline by creating a
 * job row (job_manager::create) and queuing the matching adhoc task with
 * {jobid, quizfrombank, pagegrouping} in its custom data. This class captures
 * that contract in one place so:
 *
 *  - index.php does not repeat the create-and-queue dance in three spots, and
 *  - other plugins (e.g. tool_automate's bulk Canvas import) can kick off a run
 *    for a package they hold as a URL or an on-disk file, without reaching into
 *    job_manager, the file storage layout or the task classes directly.
 *
 * Callers are responsible for their own capability checks first - neither this
 * class nor the adhoc tasks re-check capabilities (the tasks run under cron as
 * the job's user). Every caller must enforce tool/canvasuplifter:use at the
 * system context; a caller requesting a *build* (job_manager::KIND_BUILD) must
 * additionally enforce moodle/course:create on the target category context, as
 * the upload page does. An *analyse* run creates no course, so it needs only
 * the tool/canvasuplifter:use check - do not gate analysis on course:create.
 *
 * This class sits in the plugin's public namespace (not local\) precisely
 * because it is an integration surface other components may depend on; the
 * job-status contract they poll afterwards is job_manager.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launcher {
    /** File area holding stored packages, keyed by user id. */
    public const PACKAGE_FILEAREA = 'packages';

    /**
     * Queue a run from a package that is already a stored file id or a remote URL.
     *
     * This is the lowest-level entry point and mirrors index.php exactly: it
     * creates the job row and queues the adhoc task, returning the new job id so
     * the caller can poll job_manager::get() for status/progress/courseid.
     *
     * Exactly one of $fileid or $packageurl is given; the other is null.
     *
     * @param int $userid User the run executes as (cron sets up $USER to this).
     * @param int $categoryid Target course category for a build.
     * @param string $kind One of job_manager::KIND_ANALYSE or KIND_BUILD.
     * @param int|null $fileid stored_file id of an uploaded package, or null.
     * @param string|null $packageurl Remote package URL to fetch in the task, or null.
     * @param bool $quizfrombank Also build a runnable quiz from each standalone bank.
     * @param string $pagegrouping '' | 'book' | 'lesson' (anything else = combine off).
     * @return int The new job id.
     * @throws \InvalidArgumentException When not exactly one package source is given.
     */
    public static function queue_job(
        int $userid,
        int $categoryid,
        string $kind,
        ?int $fileid = null,
        ?string $packageurl = null,
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ): int {
        // Normalise the two possible sources to "present or not". A file id is
        // only real when positive - the conventional optional-id sentinel 0 (or
        // a negative id) is not a package. A URL is only real when non-empty
        // once trimmed, and the trimmed form is what gets stored and later handed
        // to curl, so leading/trailing whitespace can't slip through.
        $fileid = ($fileid !== null && $fileid > 0) ? $fileid : null;
        $url = ($packageurl !== null && trim($packageurl) !== '') ? trim($packageurl) : null;

        // Exactly one source must be supplied. With neither, the task would
        // resolve a missing file and fail asynchronously with a confusing error;
        // with both, the stored file would win while build fallback naming
        // preferred the unrelated URL. Reject both cases up front so an invalid
        // call fails immediately instead of leaving a misleading job record.
        if (($fileid === null) === ($url === null)) {
            throw new \InvalidArgumentException(
                'launcher: pass exactly one of $fileid (positive) or $packageurl (non-empty)'
            );
        }

        // A URL source must be a syntactically valid, absolute http(s) URL with
        // a host: url_fetcher (and the site's cURL security layer) only accept
        // those, so reject anything else - an ftp:// URL, a filesystem path, or a
        // hostless/malformed value like "https://" - here rather than queueing a
        // job that can only fail once the task tries to fetch it.
        if ($url !== null && !self::is_fetchable_url($url)) {
            throw new \InvalidArgumentException('launcher: $packageurl must be an absolute http(s) URL with a host');
        }

        // A stored-file source must actually exist, belong to this plugin's
        // package storage, AND be owned by the run's user. A stale/mistyped id
        // would otherwise queue a job that fails later when the file cannot be
        // loaded; a file from another component or another user would analyse
        // fine but then be refused by the "Build this course" handler (which
        // requires a tool_canvasuplifter file owned by the job user), so a
        // successful analyse could never be built. Reject all three here.
        // Callers with an on-disk package should use queue_from_path(), which
        // stores it into this user's package area first.
        if ($fileid !== null) {
            $storedfile = get_file_storage()->get_file_by_id($fileid);
            $badfile = !$storedfile
                || $storedfile->get_component() !== 'tool_canvasuplifter'
                || (int) $storedfile->get_userid() !== $userid;
            if ($badfile) {
                throw new \InvalidArgumentException(
                    'launcher: $fileid must be an existing tool_canvasuplifter package file owned by the user'
                );
            }
        }

        // The run executes as this user (the tasks load it with MUST_EXIST). An
        // unknown id would otherwise leave a job stuck "running" when that lookup
        // throws outside the task's failure handling; reject it up front.
        if (!\core_user::get_user($userid)) {
            throw new \InvalidArgumentException('launcher: unknown user id ' . $userid);
        }

        $kind = self::normalise_kind($kind);

        $jobs = new job_manager();
        if ($fileid !== null) {
            // Insert the job row under the same per-file lock delete_job() takes,
            // re-checking the package still exists first: a concurrent delete_job()
            // could have freed it since the validation above, and the lock keeps it
            // from being freed between this check and the insert - so the new job
            // can never reference a package that has just been deleted.
            $fid = $fileid;
            $jobid = self::with_package_lock($fid, function () use ($jobs, $userid, $categoryid, $kind, $fid) {
                if (!get_file_storage()->get_file_by_id($fid)) {
                    throw new \InvalidArgumentException(
                        'launcher: $fileid must be an existing tool_canvasuplifter package file owned by the user'
                    );
                }
                return $jobs->create($userid, $categoryid, $kind, $fid, null);
            });
        } else {
            $jobid = $jobs->create($userid, $categoryid, $kind, null, $url);
        }

        $task = $kind === job_manager::KIND_BUILD ? new build_course_task() : new analyse_package_task();
        $task->set_custom_data([
            'jobid' => $jobid,
            'quizfrombank' => $quizfrombank ? 1 : 0,
            // Anything other than 'book'/'lesson' is ignored by course_builder.
            'pagegrouping' => $pagegrouping,
        ]);
        \core\task\manager::queue_adhoc_task($task);

        return $jobid;
    }

    /**
     * Queue a run for a package identified by a remote URL.
     *
     * The download is deferred to the adhoc task, so a large or slow fetch never
     * blocks the caller, and it goes through Moodle's SSRF-aware \curl wrapper
     * (subject to the site's HTTP security settings) inside the task.
     *
     * @param int $userid User the run executes as.
     * @param int $categoryid Target course category for a build.
     * @param string $kind One of job_manager::KIND_ANALYSE or KIND_BUILD.
     * @param string $url Remote package URL.
     * @param bool $quizfrombank Also build a runnable quiz from each standalone bank.
     * @param string $pagegrouping '' | 'book' | 'lesson'.
     * @return int The new job id.
     */
    public static function queue_from_url(
        int $userid,
        int $categoryid,
        string $kind,
        string $url,
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ): int {
        return self::queue_job($userid, $categoryid, $kind, null, $url, $quizfrombank, $pagegrouping);
    }

    /**
     * Queue a run for a package that exists as a file on the server's disk.
     *
     * The file is copied into this plugin's 'packages' file area (owned by
     * $userid) so the adhoc task can read it after the request ends, then a job
     * is queued against that stored file. The caller's on-disk file is left
     * untouched - it is the caller's to keep or remove.
     *
     * @param int $userid User the run executes as (and owner of the stored copy).
     * @param int $categoryid Target course category for a build.
     * @param string $kind One of job_manager::KIND_ANALYSE or KIND_BUILD.
     * @param string $path Absolute path to a readable .imscc/.zip package file.
     * @param string $filename Name to store the package under; defaults to the
     *                         source basename, so a title-less package can be
     *                         named after its file.
     * @param bool $quizfrombank Also build a runnable quiz from each standalone bank.
     * @param string $pagegrouping '' | 'book' | 'lesson'.
     * @return int The new job id.
     */
    public static function queue_from_path(
        int $userid,
        int $categoryid,
        string $kind,
        string $path,
        string $filename = '',
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ): int {
        $fileid = self::store_package($userid, $path, $filename);
        try {
            return self::queue_job($userid, $categoryid, $kind, $fileid, null, $quizfrombank, $pagegrouping);
        } catch (\Throwable $e) {
            // The queue_job() call validates (user, category, ...) and can reject
            // the call after the package is already stored. Delete the just-stored
            // copy so a rejected call never leaves an orphaned file in the packages
            // area with no job to process it, then re-raise the original error.
            $stored = get_file_storage()->get_file_by_id($fileid);
            if ($stored) {
                $stored->delete();
            }
            throw $e;
        }
    }

    /**
     * List import jobs, newest first (optionally filtered).
     *
     * The public entry point for an import-history view - for example
     * tool_automate's "Staged Canvas imports" list, which lists a user's analyse
     * jobs so they can review each report and build it. Exposed here on the
     * public facade so external callers do not have to reach into the internal
     * local\job_manager; the returned rows are that plugin's job records.
     *
     * @param int|null $userid Only this user's jobs, or null for all.
     * @param string|null $kind One of job_manager::KIND_*, or null for all.
     * @param string|null $status One of job_manager::STATUS_*, or null for all.
     * @param int $limit Maximum rows (0 = no limit).
     * @return array Job records keyed by id, newest first.
     */
    public static function list_jobs(
        ?int $userid = null,
        ?string $kind = null,
        ?string $status = null,
        int $limit = 0
    ): array {
        return (new job_manager())->list_jobs($userid, $kind, $status, $limit);
    }

    /**
     * Fetch one import job's current record, for status polling.
     *
     * The public counterpart to the job id the queue_* methods return: a caller
     * polls this for the job's status (queued/running/done/failed), progress and,
     * for a completed build, the created courseid - without having to reach into
     * the internal local\job_manager. Returns null when the job does not exist
     * (for example after delete_job()).
     *
     * @param int $jobid Job id, as returned by a queue_* method.
     * @return \stdClass|null The job record, or null if there is no such job.
     */
    public static function get_job(int $jobid): ?\stdClass {
        $job = (new job_manager())->get($jobid);
        return $job ?: null;
    }

    /**
     * Delete a finished import job and free its stored package, to reclaim space.
     *
     * Removes the job row and, when no other job still references it, the stored
     * .imscc package (the space-consuming part). A course a build already created
     * is left in place - this frees cached package storage, it does not undo an
     * import. Only a finished (done/failed) job is deleted, so this never races a
     * queued or running task; when $userid is given, the job must belong to that
     * user.
     *
     * @param int $jobid Job id.
     * @param int|null $userid Require the job to belong to this user, or null to skip the check.
     * @return bool True if a job was deleted; false if it is missing, not the
     *              user's, or not yet finished.
     */
    public static function delete_job(int $jobid, ?int $userid = null): bool {
        global $DB;
        $jobs = new job_manager();
        $job = $jobs->get($jobid);
        if (!$job) {
            return false;
        }
        if ($userid !== null && (int) $job->userid !== $userid) {
            return false;
        }
        // Only a finished job is safe to delete. A queued or running job's adhoc
        // task would otherwise run - or finish - against a now-missing row: a
        // running URL job could store an orphaned package after the row is gone,
        // and a queued job's task would fail loading the deleted row.
        if ($job->status !== job_manager::STATUS_DONE && $job->status !== job_manager::STATUS_FAILED) {
            return false;
        }
        // Free the stored package only when no other job still references it. The
        // "Build this course" flow builds from the analyse job's stored file, so
        // the analyse and build jobs share a fileid; deleting one must not pull
        // the package out from under the other. The reference check, the file
        // delete and this job's row delete all run under a per-file lock (which
        // queue_job() also takes) so they are atomic against a build being queued
        // for the same package or a parallel delete of a sharing job - otherwise
        // a job could be left with a dead fileid, or the package orphaned.
        if (!empty($job->fileid)) {
            $fileid = (int) $job->fileid;
            self::with_package_lock($fileid, function () use ($DB, $jobs, $fileid, $jobid) {
                $shared = $DB->record_exists_select(
                    job_manager::TABLE,
                    'fileid = :fileid AND id <> :id',
                    ['fileid' => $fileid, 'id' => $jobid]
                );
                if (!$shared) {
                    $file = get_file_storage()->get_file_by_id($fileid);
                    if ($file) {
                        $file->delete();
                    }
                }
                // Delete the row inside the lock, before releasing it, so a
                // concurrent delete of a sharing job sees this row already gone
                // and correctly frees the now-unreferenced package.
                $jobs->delete($jobid);
            });
        } else {
            $jobs->delete($jobid);
        }
        return true;
    }

    /**
     * Run a callback while holding an exclusive lock for one stored package.
     *
     * Serialises delete_job()'s reference-count-check-and-free against
     * queue_job()'s validate-file-then-insert-job for the same stored file,
     * closing the window where a build queued from an analyse job's package could
     * race that package's deletion (leaving a job with a dead fileid, or orphaning
     * the package when two sharing jobs are deleted at once). The lock is keyed on
     * the file id, so unrelated packages never contend.
     *
     * @param int $fileid Stored package file id to lock on.
     * @param callable $fn Work to run under the lock; its return value is returned.
     * @return mixed Whatever $fn returns.
     * @throws \moodle_exception When the lock cannot be acquired in time.
     */
    protected static function with_package_lock(int $fileid, callable $fn) {
        $factory = \core\lock\lock_config::get_lock_factory('tool_canvasuplifter_packages');
        $lock = $factory->get_lock('package_' . $fileid, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'core');
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Total size, in bytes, of the stored .imscc packages this plugin holds.
     *
     * Sums the package file area so a UI can show how much space staged imports
     * are using. Directory placeholder rows are excluded; when $userid is given,
     * only that user's packages are counted.
     *
     * @param int|null $userid Only this user's packages, or null for all.
     * @return int Total bytes.
     */
    public static function package_storage_used(?int $userid = null): int {
        global $DB;
        $where = "component = :component AND filearea = :filearea AND filename <> '.'";
        $params = ['component' => 'tool_canvasuplifter', 'filearea' => self::PACKAGE_FILEAREA];
        if ($userid !== null) {
            $where .= ' AND itemid = :itemid';
            $params['itemid'] = $userid;
        }
        return (int) $DB->get_field_sql("SELECT COALESCE(SUM(filesize), 0) FROM {files} WHERE {$where}", $params);
    }

    /**
     * Copy an on-disk package into the plugin's 'packages' file area.
     *
     * @param int $userid Owner of the stored file (itemid).
     * @param string $path Absolute path to the source file.
     * @param string $filename Stored filename; defaults to basename($path).
     * @return int stored_file id.
     */
    public static function store_package(int $userid, string $path, string $filename = ''): int {
        $name = clean_param($filename !== '' ? $filename : basename($path), PARAM_FILE);
        if ($name === '') {
            $name = 'package.imscc';
        }
        $fs = get_file_storage();
        $filerecord = (object) [
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => self::PACKAGE_FILEAREA,
            'itemid' => $userid,
            // A unique filepath lets distinct packages keep their original names
            // without colliding in the file area.
            'filepath' => '/' . uniqid() . '/',
            'filename' => $name,
            'userid' => $userid,
        ];
        return (int) $fs->create_file_from_pathname($filerecord, $path)->get_id();
    }

    /**
     * Coerce an incoming kind to a known value, defaulting to analyse.
     *
     * Keeping this lenient (rather than throwing) means a caller can pass a
     * user-chosen string and always get a safe, read-only run when it is not a
     * recognised build request.
     *
     * @param string $kind Candidate kind.
     * @return string job_manager::KIND_BUILD or KIND_ANALYSE.
     */
    private static function normalise_kind(string $kind): string {
        return $kind === job_manager::KIND_BUILD ? job_manager::KIND_BUILD : job_manager::KIND_ANALYSE;
    }

    /**
     * Is this a structurally valid, absolute http(s) URL with a host?
     *
     * A prefix check alone would pass hostless or malformed values (e.g.
     * "https://" or "http:// bad-host"); this requires the value to pass PHP's
     * URL filter and to parse to an http/https scheme with a non-empty host.
     * This is a structural gate only: whether the host actually resolves and is
     * reachable is left to url_fetcher and the site's cURL security layer at
     * fetch time (and such a failure is reported on the job, not silent). It is
     * public so the upload form can validate the URL field the same way.
     *
     * @param string $url Candidate URL (already trimmed).
     * @return bool
     */
    public static function is_fetchable_url(string $url): bool {
        // A space (or other whitespace) inside the value is never valid in a URL
        // and parse_url tolerates some of it, so reject it outright first.
        if (preg_match('/\s/', $url)) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}
