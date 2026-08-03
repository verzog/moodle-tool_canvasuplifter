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

use tool_canvasuplifter\launcher;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\task\analyse_package_task;
use tool_canvasuplifter\task\build_course_task;

/**
 * Tests the launcher facade: it creates the job row and queues the matching
 * adhoc task with the expected custom data, for URL, stored-file and on-disk
 * package sources.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\launcher
 */
final class launcher_test extends \advanced_testcase {
    /**
     * Fetch the single queued adhoc task of a given class, failing if there is
     * not exactly one.
     *
     * @param string $classname Fully qualified task class name.
     * @return \core\task\adhoc_task
     */
    private function single_queued_task(string $classname): \core\task\adhoc_task {
        $tasks = \core\task\manager::get_adhoc_tasks($classname);
        $this->assertCount(1, $tasks);
        return reset($tasks);
    }

    /**
     * A build request from a URL creates a build job carrying the URL (no file
     * id) and queues a build_course_task with the chosen options.
     *
     * @return void
     */
    public function test_queue_from_url_creates_build_job_and_task(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $jobid = launcher::queue_from_url(
            (int) $USER->id,
            (int) $category->id,
            job_manager::KIND_BUILD,
            'https://example.com/course.imscc',
            true,
            'book'
        );

        $job = (new job_manager())->get($jobid);
        $this->assertSame(job_manager::KIND_BUILD, $job->kind);
        $this->assertSame(job_manager::STATUS_QUEUED, $job->status);
        $this->assertSame('https://example.com/course.imscc', $job->packageurl);
        $this->assertNull($job->fileid);
        $this->assertEquals($category->id, $job->categoryid);

        $task = $this->single_queued_task(build_course_task::class);
        $data = (array) $task->get_custom_data();
        $this->assertEquals($jobid, $data['jobid']);
        $this->assertEquals(1, $data['quizfrombank']);
        $this->assertSame('book', $data['pagegrouping']);
        // A build must not have queued an analyse task.
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(analyse_package_task::class));
    }

    /**
     * An analyse request from an on-disk file stores the package in the plugin's
     * file area, points the job at that stored file, and queues an analyse task.
     *
     * @return void
     */
    public function test_queue_from_path_stores_package_and_queues_analyse(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $path = make_request_directory() . '/History 101.imscc';
        file_put_contents($path, 'PK-not-a-real-zip-but-fine-for-storage');

        $jobid = launcher::queue_from_path(
            (int) $USER->id,
            (int) $category->id,
            job_manager::KIND_ANALYSE,
            $path
        );

        $job = (new job_manager())->get($jobid);
        $this->assertSame(job_manager::KIND_ANALYSE, $job->kind);
        $this->assertNull($job->packageurl);
        $this->assertNotNull($job->fileid);

        // The stored file lives in this plugin's packages area, owned by the user,
        // and keeps the source basename.
        $stored = $DB->get_record('files', ['id' => $job->fileid]);
        $this->assertSame('tool_canvasuplifter', $stored->component);
        $this->assertSame(launcher::PACKAGE_FILEAREA, $stored->filearea);
        $this->assertEquals($USER->id, $stored->itemid);
        $this->assertSame('History 101.imscc', $stored->filename);

        $this->single_queued_task(analyse_package_task::class);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(build_course_task::class));
    }

    /**
     * An unrecognised kind is coerced to a safe, read-only analyse run rather
     * than accidentally building a course.
     *
     * @return void
     */
    public function test_unknown_kind_falls_back_to_analyse(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $jobid = launcher::queue_from_url(
            (int) $USER->id,
            (int) $category->id,
            'wat',
            'https://example.com/x.imscc'
        );

        $this->assertSame(job_manager::KIND_ANALYSE, (new job_manager())->get($jobid)->kind);
        $this->single_queued_task(analyse_package_task::class);
    }

    /**
     * queue_job rejects a call with neither a file id nor a URL, before any job
     * row or task is created.
     *
     * @return void
     */
    public function test_queue_job_requires_a_source(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_job((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, null, null);
        } finally {
            // No job row and no adhoc task should have been left behind.
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
            $this->assertCount(0, \core\task\manager::get_adhoc_tasks(analyse_package_task::class));
        }
    }

    /**
     * queue_job rejects a call giving both a file id and a URL.
     *
     * @return void
     */
    public function test_queue_job_rejects_two_sources(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        launcher::queue_job(
            (int) $USER->id,
            (int) $category->id,
            job_manager::KIND_BUILD,
            123,
            'https://example.com/x.imscc'
        );
    }
}
