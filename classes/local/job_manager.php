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

    /** Database table name. */
    public const TABLE = 'tool_canvasuplifter_jobs';

    /**
     * Insert a queued job row and return its id.
     *
     * @param int $userid Admin user.
     * @param int $categoryid Course category for the new course.
     * @param int $fileid stored_file::get_id() for the package file.
     * @return int Job id.
     */
    public function create(int $userid, int $categoryid, int $fileid): int {
        global $DB;
        $now = time();
        return $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'status' => self::STATUS_QUEUED,
            'categoryid' => $categoryid,
            'fileid' => $fileid,
            'courseid' => null,
            'report' => null,
            'errormsg' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
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
     * Move the job to the running state.
     *
     * @param int $jobid
     */
    public function mark_running(int $jobid): void {
        $this->update($jobid, ['status' => self::STATUS_RUNNING]);
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
