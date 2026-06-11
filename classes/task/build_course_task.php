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

namespace tool_canvasuplifter\task;

use core\task\adhoc_task;
use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\ingest\package;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * Adhoc task that extracts the stored Canvas package and builds the course.
 *
 * Custom data shape: {jobid: int}.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_course_task extends adhoc_task {
    /**
     * Run the build.
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $jobid = (int) ($data['jobid'] ?? 0);
        if ($jobid <= 0) {
            mtrace('build_course_task: missing jobid');
            return;
        }

        $jobs = new job_manager();
        $job = $jobs->get($jobid);
        if (!$job) {
            mtrace("build_course_task: job $jobid not found");
            return;
        }

        $jobs->mark_running($jobid);

        // Build runs as the admin who queued the job so file API draft
        // areas, capabilities and event logging are attributed correctly.
        \core\cron::setup_user(\core_user::get_user((int) $job->userid, '*', MUST_EXIST));

        $extractdir = make_request_directory();
        $temppackage = null;
        try {
            $temppackage = $this->copy_stored_file_to_temp((int) $job->fileid);
            $root = (new package())->extract($temppackage, $extractdir);
            $coursemodel = (new manifest_parser($root))->parse();
            $report = (new course_builder((int) $job->categoryid, $root))->build($coursemodel);
            $jobs->mark_done($jobid, $report['courseid'], $report);
            mtrace("build_course_task: job $jobid built course {$report['courseid']}");
        } catch (\Throwable $e) {
            mtrace("build_course_task: job $jobid failed: " . $e->getMessage());
            $jobs->mark_failed($jobid, $e->getMessage());
        } finally {
            if ($temppackage !== null && file_exists($temppackage)) {
                @unlink($temppackage);
            }
        }
    }

    /**
     * Copy the stored package file out to a normal temp file so the existing
     * ingest pipeline (which uses ZipArchive on a path) can read it.
     *
     * @param int $fileid stored_file id.
     * @return string Path to the temp copy.
     */
    private function copy_stored_file_to_temp(int $fileid): string {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) {
            throw new \RuntimeException("Stored file $fileid not found");
        }
        $target = tempnam(make_request_directory(), 'canvasuplifter_');
        $file->copy_content_to($target);
        return $target;
    }
}
