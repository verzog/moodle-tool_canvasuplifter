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

use tool_canvasuplifter\local\ingest\package;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\parser\manifest_parser;
use tool_canvasuplifter\local\report\conversion_report;

/**
 * Adhoc task that downloads/extracts a Canvas package and builds the conversion
 * report, off the web request so a large package can't time out the analyse page.
 *
 * Custom data shape: {jobid: int, quizfrombank?: int, pagegrouping?: string}.
 * The chosen build options are carried into the stored report so the status
 * page's "Build this course" form can reuse them without asking again.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyse_package_task extends package_job_task {
    /**
     * Run the analysis.
     *
     * @return void
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        $jobid = (int) ($data['jobid'] ?? 0);
        $pagegrouping = (string) ($data['pagegrouping'] ?? '');
        $quizfrombank = empty($data['quizfrombank']) ? 0 : 1;
        if ($jobid <= 0) {
            mtrace('analyse_package_task: missing jobid');
            return;
        }

        $jobs = new job_manager();
        $job = $jobs->get($jobid);
        if (!$job) {
            mtrace("analyse_package_task: job $jobid not found");
            return;
        }
        // A retried task whose job already finished must not redo the work.
        if ($job->status === job_manager::STATUS_DONE) {
            mtrace("analyse_package_task: job $jobid already analysed; skipping");
            return;
        }

        $jobs->mark_running($jobid);

        // Run as the admin who queued the job so the file API draft areas and
        // any logging are attributed correctly.
        \core\cron::setup_user(\core_user::get_user((int) $job->userid, '*', MUST_EXIST));

        try {
            $temppackage = $this->resolve_package($jobs, $job);

            $jobs->set_progress($jobid, 40, get_string('progressextract', 'tool_canvasuplifter'));
            $root = (new package())->extract($temppackage, make_request_directory());

            $jobs->set_progress($jobid, 70, get_string('progressparse', 'tool_canvasuplifter'));
            $course = (new manifest_parser($root))->parse();
            $report = (new conversion_report($course, $root, $pagegrouping))->build();

            // Carry the chosen build options so the status page's build form
            // reuses them rather than asking again.
            $report['pagegrouping'] = $pagegrouping;
            $report['quizfrombank'] = $quizfrombank;

            $jobs->mark_analysed($jobid, $report);
            mtrace("analyse_package_task: job $jobid analysed");
        } catch (\Throwable $e) {
            mtrace("analyse_package_task: job $jobid failed: " . $e->getMessage());
            $jobs->mark_failed($jobid, $e->getMessage());
        }
    }
}
