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
            '  https://example.com/course.imscc  ',
            true,
            'book'
        );

        $job = (new job_manager())->get($jobid);
        $this->assertSame(job_manager::KIND_BUILD, $job->kind);
        $this->assertSame(job_manager::STATUS_QUEUED, $job->status);
        // The stored URL is trimmed, so curl never sees stray whitespace.
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
     * launcher::list_jobs exposes a user's jobs through the public facade,
     * without callers reaching into local\job_manager.
     *
     * @return void
     */
    public function test_list_jobs_lists_the_users_jobs(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $jobid = launcher::queue_from_url(
            (int) $USER->id,
            (int) $category->id,
            job_manager::KIND_ANALYSE,
            'https://example.com/c.imscc'
        );

        $jobs = launcher::list_jobs((int) $USER->id);
        $this->assertArrayHasKey($jobid, $jobs);
        $this->assertSame(job_manager::KIND_ANALYSE, $jobs[$jobid]->kind);

        // Filtering flows through to job_manager.
        $this->assertCount(0, launcher::list_jobs((int) $USER->id, job_manager::KIND_BUILD));
    }

    /**
     * delete_job removes a finished job and frees its stored package, refuses a
     * job that is not yet finished, and refuses another user's job.
     *
     * @return void
     */
    public function test_delete_job_removes_job_and_package(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $jobs = new job_manager();

        $path = make_request_directory() . '/c.imscc';
        file_put_contents($path, 'data');
        $jobid = launcher::queue_from_path((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, $path);
        $fileid = (int) $jobs->get($jobid)->fileid;
        $this->assertNotFalse(get_file_storage()->get_file_by_id($fileid));

        // A still-queued job is not deletable (its task may still run).
        $this->assertFalse(launcher::delete_job($jobid, (int) $USER->id));
        $this->assertNotFalse($jobs->get($jobid));

        // Once finished it is deletable - but not by another user.
        $jobs->mark_analysed($jobid, ['itemcount' => 1]);
        $this->assertFalse(launcher::delete_job($jobid, (int) $USER->id + 9999));
        $this->assertNotFalse($jobs->get($jobid));

        // The owner deletes it: row and stored package are both gone.
        $this->assertTrue(launcher::delete_job($jobid, (int) $USER->id));
        $this->assertFalse($jobs->get($jobid));
        $this->assertFalse(get_file_storage()->get_file_by_id($fileid));

        // Deleting a missing job is a no-op false.
        $this->assertFalse(launcher::delete_job($jobid, (int) $USER->id));
    }

    /**
     * A package shared by another job (the analyse-then-build flow reuses the
     * same stored file) is only freed once the last referencing job is deleted.
     *
     * @return void
     */
    public function test_delete_job_keeps_a_package_another_job_shares(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $jobs = new job_manager();

        $path = make_request_directory() . '/shared.imscc';
        file_put_contents($path, 'data');
        $fileid = launcher::store_package((int) $USER->id, $path);

        // An analyse job and a build job that share the one stored package.
        $analyse = $jobs->create((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, $fileid);
        $build = $jobs->create((int) $USER->id, (int) $category->id, job_manager::KIND_BUILD, $fileid);
        $jobs->mark_analysed($analyse, []);
        $jobs->mark_done($build, 0, []);

        // Deleting the analyse job leaves the package - the build job still needs it.
        $this->assertTrue(launcher::delete_job($analyse, (int) $USER->id));
        $this->assertNotFalse(get_file_storage()->get_file_by_id($fileid));

        // Deleting the last referencing job frees the package.
        $this->assertTrue(launcher::delete_job($build, (int) $USER->id));
        $this->assertFalse(get_file_storage()->get_file_by_id($fileid));
    }

    /**
     * package_storage_used sums the bytes of a user's stored packages.
     *
     * @return void
     */
    public function test_package_storage_used_sums_user_packages(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $before = launcher::package_storage_used((int) $USER->id);
        $p1 = make_request_directory() . '/a.imscc';
        file_put_contents($p1, str_repeat('x', 100));
        $p2 = make_request_directory() . '/b.imscc';
        file_put_contents($p2, str_repeat('y', 250));
        launcher::store_package((int) $USER->id, $p1);
        launcher::store_package((int) $USER->id, $p2);

        $this->assertSame($before + 350, launcher::package_storage_used((int) $USER->id));
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
     * A non-positive file id (the conventional 0 sentinel) is not a package
     * source, so a call giving only that and no URL is rejected.
     *
     * @return void
     */
    public function test_queue_job_rejects_zero_fileid(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_job((int) $USER->id, (int) $category->id, job_manager::KIND_BUILD, 0, null);
        } finally {
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
        }
    }

    /**
     * A URL that is only whitespace is not a source, and is rejected rather than
     * stored and handed to curl to fail later.
     *
     * @return void
     */
    public function test_queue_job_rejects_blank_url(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        launcher::queue_from_url((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, "   ");
    }

    /**
     * A positive but non-existent file id is rejected up front, rather than
     * queueing a job that fails later when the file cannot be loaded.
     *
     * @return void
     */
    public function test_queue_job_rejects_missing_fileid(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_job((int) $USER->id, (int) $category->id, job_manager::KIND_BUILD, 999999, null);
        } finally {
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
        }
    }

    /**
     * A stored file that belongs to another component is rejected: it would
     * analyse fine but the build-from-report handler only accepts this plugin's
     * own package files, so a successful analyse could never be built.
     *
     * @return void
     */
    public function test_queue_job_rejects_foreign_component_file(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $foreign = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'course.imscc',
        ], 'data');

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_job(
                (int) $USER->id,
                (int) $category->id,
                job_manager::KIND_ANALYSE,
                (int) $foreign->get_id()
            );
        } finally {
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
        }
    }

    /**
     * A tool_canvasuplifter package file owned by a different user is rejected:
     * it would analyse fine but the build-from-report handler only accepts a
     * file owned by the job user, so a successful analyse could never be built.
     *
     * @return void
     */
    public function test_queue_job_rejects_file_owned_by_another_user(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $other = $this->getDataGenerator()->create_user();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => launcher::PACKAGE_FILEAREA,
            'itemid' => (int) $other->id,
            'filepath' => '/',
            'filename' => 'course.imscc',
            'userid' => (int) $other->id,
        ], 'data');

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_job(
                (int) $USER->id,
                (int) $category->id,
                job_manager::KIND_ANALYSE,
                (int) $file->get_id()
            );
        } finally {
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
        }
    }

    /**
     * A non-http(s) URL (ftp, a filesystem path) is rejected, since url_fetcher
     * only accepts absolute http(s) URLs.
     *
     * @return void
     */
    public function test_queue_job_rejects_non_http_url(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $this->expectException(\InvalidArgumentException::class);
        launcher::queue_from_url(
            (int) $USER->id,
            (int) $category->id,
            job_manager::KIND_ANALYSE,
            'ftp://example.com/course.imscc'
        );
    }

    /**
     * A hostless or otherwise malformed URL is rejected, even when it starts
     * with http(s):// - the scheme prefix alone is not a fetchable URL.
     *
     * @return void
     */
    public function test_queue_job_rejects_malformed_urls(): void {
        global $USER, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $bad = ['https://', 'http:// bad-host/course.imscc', 'example.com/course.imscc'];
        foreach ($bad as $url) {
            try {
                launcher::queue_from_url((int) $USER->id, (int) $category->id, job_manager::KIND_ANALYSE, $url);
                $this->fail('Expected malformed URL to be rejected: ' . $url);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('http(s) URL', $e->getMessage());
            }
        }
        // None of the rejected calls should have created a job.
        $this->assertSame(0, $DB->count_records(job_manager::TABLE));
    }

    /**
     * A run for an unknown user id is rejected up front, rather than queueing a
     * job that the task later cannot set up a user for (leaving it stuck).
     *
     * @return void
     */
    public function test_queue_job_rejects_unknown_user(): void {
        global $DB;
        $this->resetAfterTest(true);
        $category = $this->getDataGenerator()->create_category();
        $nouser = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;

        $this->expectException(\InvalidArgumentException::class);
        try {
            launcher::queue_from_url($nouser, (int) $category->id, job_manager::KIND_ANALYSE, 'https://e.edu/c.imscc');
        } finally {
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
        }
    }

    /**
     * When queue_from_path is rejected (here, an unknown user) after the package
     * is already stored, the stored copy is cleaned up so no orphaned file is
     * left in the packages area.
     *
     * @return void
     */
    public function test_queue_from_path_cleans_up_on_rejection(): void {
        global $DB;
        $this->resetAfterTest(true);
        $category = $this->getDataGenerator()->create_category();
        $nouser = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;

        $path = make_request_directory() . '/History 101.imscc';
        file_put_contents($path, 'PK-not-a-real-zip-but-fine-for-storage');
        // Count real package files only: the file API also stores directory
        // placeholder rows (filename '.') that delete() leaves behind.
        $select = "component = :c AND filearea = :a AND filename <> '.'";
        $params = ['c' => 'tool_canvasuplifter', 'a' => launcher::PACKAGE_FILEAREA];
        $before = $DB->count_records_select('files', $select, $params);

        try {
            launcher::queue_from_path($nouser, (int) $category->id, job_manager::KIND_ANALYSE, $path);
            $this->fail('Expected an unknown user to be rejected');
        } catch (\InvalidArgumentException $e) {
            $after = $DB->count_records_select('files', $select, $params);
            $this->assertSame($before, $after);
            $this->assertSame(0, $DB->count_records(job_manager::TABLE));
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
