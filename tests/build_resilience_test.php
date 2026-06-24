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

use tool_canvasuplifter\local\build\lti_builder;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\task\build_course_task;

/**
 * Tests that a failing external tool and a retried job do not corrupt the build:
 * a leaked DB transaction is rolled back, and an already-built job is not built
 * a second time (which would create duplicate courses).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\lti_builder
 * @covers     \tool_canvasuplifter\task\build_course_task
 */
final class build_resilience_test extends \advanced_testcase {
    /**
     * An LTI cartridge whose launch URL mod_lti tries (and fails) to re-fetch as
     * a cartridge must not leave a transaction open: add_moduleinfo() throws with
     * its delegated transaction still active, and the builder has to roll it back
     * and skip the tool rather than poison the rest of the build.
     *
     * @return void
     */
    public function test_unreadable_cartridge_url_does_not_leak_transaction(): void {
        global $DB;
        $this->resetAfterTest(true);
        // PHPUnit normally wraps each test in a transaction; drop that so this
        // mirrors the real adhoc task, which builds with no outer transaction.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $course = $DB->get_record('course', ['id' => $this->getDataGenerator()->create_course()->id], '*', MUST_EXIST);

        $dir = make_request_directory();
        // A launch URL ending in .xml makes mod_lti treat it as a cartridge and
        // fetch it; an unreachable host (closed local port) makes that fetch
        // throw, exactly like the dead tool host that triggered the bug.
        $xml = '<cartridge_basiclti_link xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Dead Tool</blti:title>'
            . '<blti:launch_url>https://localhost:9/dead.xml</blti:launch_url>'
            . '</cartridge_basiclti_link>';
        file_put_contents($dir . '/lti.xml', $xml);

        $modelitem = new item('r_lti', 'Dead Tool');
        $modelitem->kind = item::KIND_LTI;
        $modelitem->href = 'lti.xml';
        $modelitem->files = ['lti.xml'];

        $builder = new lti_builder($dir);
        $cmid = $builder->build($course, 0, $modelitem);

        $this->assertNull($cmid);
        $this->assertNotNull($builder->skipreason);
        // The orphaned transaction add_moduleinfo() opened was rolled back.
        $this->assertFalse($DB->is_transaction_started());
        // Nothing was left half-created.
        $this->assertSame(0, $DB->count_records('lti'));
    }

    /**
     * When the caller already holds its own transaction, a failing tool build
     * must NOT force-roll-back that outer transaction (which would silently
     * discard the caller's earlier writes). The failure is re-thrown for the
     * caller to handle, and the still-open transaction is left untouched.
     *
     * @return void
     */
    public function test_caller_owned_transaction_is_not_force_rolled_back(): void {
        global $DB;
        $this->resetAfterTest(true);
        // Start from no ambient transaction so the only open one is the caller's,
        // started explicitly below.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $course = $DB->get_record('course', ['id' => $this->getDataGenerator()->create_course()->id], '*', MUST_EXIST);

        $dir = make_request_directory();
        $xml = '<cartridge_basiclti_link xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Dead Tool</blti:title>'
            . '<blti:launch_url>https://localhost:9/dead.xml</blti:launch_url>'
            . '</cartridge_basiclti_link>';
        file_put_contents($dir . '/lti.xml', $xml);

        $modelitem = new item('r_lti', 'Dead Tool');
        $modelitem->kind = item::KIND_LTI;
        $modelitem->href = 'lti.xml';
        $modelitem->files = ['lti.xml'];

        $transaction = $DB->start_delegated_transaction();
        $threw = false;
        try {
            (new lti_builder($dir))->build($course, 0, $modelitem);
        } catch (\Throwable $e) {
            $threw = true;
        }

        // The failure was propagated, not swallowed, and the caller's
        // transaction was left open for the caller to roll back itself.
        $this->assertTrue($threw);
        $this->assertTrue($DB->is_transaction_started());

        // Clean up the transaction we opened in the test.
        $DB->force_transaction_rollback();
    }

    /**
     * A job already marked done with a course id is not built again, so a task
     * that was retried after a post-build failure does not create duplicate
     * courses.
     *
     * @return void
     */
    public function test_completed_job_is_not_rebuilt_on_retry(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $admin = get_admin();
        $category = $this->getDataGenerator()->create_category();
        $fs = get_file_storage();
        $file = $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => 'packages',
            'itemid' => (int) $admin->id,
            'filepath' => '/',
            'filename' => 'x.imscc',
            'userid' => (int) $admin->id,
        ], 'dummy');

        $jobs = new job_manager();
        $jobid = $jobs->create((int) $admin->id, (int) $category->id, job_manager::KIND_BUILD, (int) $file->get_id());
        // Pretend a prior run already built course 4242 and marked the job done.
        $jobs->mark_done($jobid, 4242, ['courseid' => 4242]);

        $coursesbefore = $DB->count_records('course');

        $task = new build_course_task();
        $task->set_custom_data(['jobid' => $jobid, 'quizfrombank' => 1]);
        // The guard logs that it is skipping; capturing the output keeps the test
        // from being marked risky and asserts the skip happened.
        $this->expectOutputRegex('/already built course 4242/');
        $task->execute();

        // No second course was created and the recorded course id is unchanged.
        $this->assertSame($coursesbefore, $DB->count_records('course'));
        $this->assertSame(4242, (int) $jobs->get($jobid)->courseid);
    }
}
