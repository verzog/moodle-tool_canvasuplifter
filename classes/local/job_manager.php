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

namespace tool_canvasuplifter\local;

/**
 * Read/write the tool_canvasuplifter_jobs table.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_manager {
    /** Newly inserted, waiting for the adhoc runner. */
    public const STATUS_QUEUED = 'queued';
    /** Currently being processed by the adhoc task. */
    public const STATUS_RUNNING = 'running';
    /** Finished successfully. */
    public const STATUS_DONE = 'done';
    /** Aborted; see errormsg. */
    public const STATUS_FAILED = 'failed';

    /** Run that produces a conversion report only. */
    public const KIND_ANALYSE = 'analyse';
    /** Run that creates a course. */
    public const KIND_BUILD = 'build';

    /** Database table name. */
    public const TABLE = 'tool_canvasuplifter_jobs';

    /**
     * Insert a queued job row and return its id.
     *
     * Exactly one of $fileid (an already-stored upload) or $packageurl (a remote
     * package the task will fetch) is given; the other is null.
     *
     * @param int $userid Admin user.
     * @param int $categoryid Course category for the new course.
     * @param string $kind One of the KIND_* constants.
     * @param int|null $fileid stored_file::get_id() for an uploaded package, or null.
     * @param string|null $packageurl Remote package URL to fetch in the task, or null.
     * @return int Job id.
     */
    public function create(
        int $userid,
        int $categoryid,
        string $kind,
        ?int $fileid = null,
        ?string $packageurl = null
    ): int {
        global $DB;
        $now = time();
        return $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'kind' => $kind,
            'status' => self::STATUS_QUEUED,
            'categoryid' => $categoryid,
            'fileid' => $fileid,
            'packageurl' => $packageurl,
            'courseid' => null,
            'report' => null,
            'errormsg' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Record the stored package file a URL run's task fetched, so a later build
     * can reuse it without downloading again.
     *
     * @param int $jobid Job id.
     * @param int $fileid stored_file::get_id() of the fetched package.
     * @return void
     */
    public function set_fileid(int $jobid, int $fileid): void {
        $this->update($jobid, ['fileid' => $fileid]);
    }

    /**
     * Load a job row by id.
     *
     * @param int $jobid Job id.
     * @return \stdClass|false
     */
    public function get(int $jobid) {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $jobid]);
    }

    /**
     * List job rows, newest first, optionally filtered.
     *
     * Intended for a caller that wants to show a user their import history - for
     * example tool_automate's "Staged Canvas imports" list, which lists a user's
     * analyse jobs so they can review each report and build it. Any combination
     * of the filters may be given; a null filter is not applied.
     *
     * @param int|null $userid Only this user's jobs, or null for all.
     * @param string|null $kind One of the KIND_* constants, or null for all.
     * @param string|null $status One of the STATUS_* constants, or null for all.
     * @param int $limit Maximum rows to return; 0 for no limit.
     * @return array Job records keyed by id, newest first.
     */
    public function list_jobs(
        ?int $userid = null,
        ?string $kind = null,
        ?string $status = null,
        int $limit = 0
    ): array {
        global $DB;
        $conditions = [];
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        if ($kind !== null) {
            $conditions['kind'] = $kind;
        }
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        // Tie-break on id so jobs sharing a timecreated second (common in a bulk
        // import) still order deterministically newest-first, rather than in a
        // database-dependent order.
        return $DB->get_records(self::TABLE, $conditions, 'timecreated DESC, id DESC', '*', 0, $limit);
    }

    /**
     * Move the job to the running state.
     *
     * @param int $jobid
     */
    public function mark_running(int $jobid): void {
        $this->update($jobid, ['status' => self::STATUS_RUNNING]);
    }

    /**
     * Record the task's current progress percentage and message.
     *
     * @param int $jobid Job id.
     * @param int $percent 0-100.
     * @param string $message Short status message.
     * @return void
     */
    public function set_progress(int $jobid, int $percent, string $message): void {
        $this->update($jobid, [
            'progress' => max(0, min(100, $percent)),
            'progressmessage' => $message,
        ]);
    }

    /**
     * Mark the job done and store the course id and report.
     *
     * @param int $jobid
     * @param int $courseid
     * @param array $report Build report.
     */
    public function mark_done(int $jobid, int $courseid, array $report): void {
        $this->update($jobid, [
            'status' => self::STATUS_DONE,
            'courseid' => $courseid,
            'report' => json_encode($report),
            'progress' => 100,
            'progressmessage' => '',
        ]);
    }

    /**
     * Mark an analyse job done and store its conversion report. No course is
     * created, so courseid stays null.
     *
     * @param int $jobid Job id.
     * @param array $report Conversion report.
     * @return void
     */
    public function mark_analysed(int $jobid, array $report): void {
        $this->update($jobid, [
            'status' => self::STATUS_DONE,
            'report' => json_encode($report),
            'progress' => 100,
            'progressmessage' => '',
        ]);
    }

    /**
     * Mark the job failed with a free-form error message.
     *
     * @param int $jobid
     * @param string $errormsg
     */
    public function mark_failed(int $jobid, string $errormsg): void {
        $this->update($jobid, [
            'status' => self::STATUS_FAILED,
            'errormsg' => $errormsg,
        ]);
    }

    /**
     * Persist a partial update to a job row and bump timemodified.
     *
     * @param int $jobid Job id.
     * @param array $fields Column => value pairs to merge.
     * @return void
     */
    private function update(int $jobid, array $fields): void {
        global $DB;
        $fields['id'] = $jobid;
        $fields['timemodified'] = time();
        $DB->update_record(self::TABLE, (object) $fields);
    }
}
