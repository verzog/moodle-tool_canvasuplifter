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

use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\ingest\package;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * Adhoc task that obtains the Canvas package (downloading a URL job, or reading
 * a stored upload) and builds the course.
 *
 * Custom data shape: {jobid: int, quizfrombank?: int, pagegrouping?: string}.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_course_task extends package_job_task {
    /**
     * Run the build.
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $jobid = (int) ($data['jobid'] ?? 0);
        $quizfrombank = !empty($data['quizfrombank']);
        $pagegrouping = (string) ($data['pagegrouping'] ?? '');
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

        // If a previous run already finished this job, do not build a second
        // copy. The course build can succeed and mark the job done, yet the task
        // still be reported failed afterwards (e.g. a leaked DB transaction),
        // which makes Moodle retry the adhoc task; without this guard each retry
        // would build another duplicate course.
        if ($job->status === job_manager::STATUS_DONE && !empty($job->courseid)) {
            mtrace("build_course_task: job $jobid already built course {$job->courseid}; skipping rebuild");
            return;
        }

        $jobs->mark_running($jobid);

        // Build runs as the admin who queued the job so file API draft
        // areas, capabilities and event logging are attributed correctly.
        \core\cron::setup_user(\core_user::get_user((int) $job->userid, '*', MUST_EXIST));

        $extractdir = make_request_directory();
        $temppackage = null;
        try {
            // Downloads a URL job (recording the stored file for reuse) or copies
            // the stored upload.
            $temppackage = $this->resolve_package($jobs, $job);
            $jobs->set_progress($jobid, 2, get_string('progressextract', 'tool_canvasuplifter'));
            $root = (new package())->extract($temppackage, $extractdir);

            $jobs->set_progress($jobid, 3, get_string('progressparse', 'tool_canvasuplifter'));
            $coursemodel = (new manifest_parser($root))->parse();

            $report = (new course_builder(
                (int) $job->categoryid,
                $root,
                $jobs,
                $jobid,
                $quizfrombank,
                $pagegrouping
            ))->build($coursemodel);
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
}
