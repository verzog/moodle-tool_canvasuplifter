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

/**
 * Tests the asynchronous analyse task: it extracts a stored package, builds the
 * conversion report onto the job, and creates no course.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\task\analyse_package_task
 * @covers     \tool_canvasuplifter\task\package_job_task
 */
final class analyse_package_task_test extends \advanced_testcase {
    /**
     * Zip a minimal one-page Canvas package and store it in the plugin's file
     * area, returning the stored file's id.
     *
     * @param int $userid Owner of the stored file.
     * @return int stored_file id.
     */
    private function store_minimal_package(int $userid): int {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        file_put_contents(
            $dir . '/wiki_content/welcome.html',
            '<html><head><title>Welcome</title></head><body><p>Hi</p></body></html>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_p" identifierref="r_page"><title>Welcome</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $zippath = $dir . '/package.imscc';
        get_file_packer('application/zip')->archive_to_pathname([
            'imsmanifest.xml' => $dir . '/imsmanifest.xml',
            'wiki_content/welcome.html' => $dir . '/wiki_content/welcome.html',
        ], $zippath);

        $fs = get_file_storage();
        $file = $fs->create_file_from_pathname((object) [
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => 'packages',
            'itemid' => $userid,
            'filepath' => '/',
            'filename' => 'package.imscc',
            'userid' => $userid,
        ], $zippath);
        return (int) $file->get_id();
    }

    /**
     * An analyse job's task extracts the package, stores the conversion report
     * on the job, marks it done, and creates no course.
     *
     * @return void
     */
    public function test_analyse_job_produces_report_without_building(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        // The task mtrace()s on success; capture it so the test isn't risky.
        $this->expectOutputRegex('/analysed/');

        $category = $this->getDataGenerator()->create_category();
        $fileid = $this->store_minimal_package((int) $USER->id);
        $coursesbefore = $DB->count_records('course');

        $jobs = new job_manager();
        $jobid = $jobs->create((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, $fileid);
        $task = new analyse_package_task();
        $task->set_custom_data(['jobid' => $jobid, 'pagegrouping' => '', 'quizfrombank' => 0]);
        $task->execute();

        $job = $jobs->get($jobid);
        $this->assertSame(job_manager::STATUS_DONE, $job->status);
        $this->assertNull($job->courseid);
        $this->assertEquals(100, (int) $job->progress);

        $report = json_decode((string) $job->report, true);
        $this->assertIsArray($report);
        $this->assertSame(1, (int) $report['itemcount']);
        $this->assertArrayHasKey('rows', $report);
        // The chosen build options are carried for the status page's build form.
        $this->assertSame('', $report['pagegrouping']);
        $this->assertSame(0, (int) $report['quizfrombank']);

        // Analyse must not create a course.
        $this->assertSame($coursesbefore, $DB->count_records('course'));
    }
}
