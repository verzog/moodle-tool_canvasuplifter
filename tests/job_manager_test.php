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

/**
 * Tests the job listing helper used by import-history views (e.g. tool_automate's
 * "Staged Canvas imports" page).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\job_manager::list_jobs
 */
final class job_manager_test extends \advanced_testcase {
    /**
     * list_jobs filters by user, kind and status, and returns newest first.
     *
     * @return void
     */
    public function test_list_jobs_filters_and_orders(): void {
        $this->resetAfterTest(true);
        $ua = $this->getDataGenerator()->create_user();
        $ub = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();
        $jobs = new job_manager();

        // Create a spread of jobs for two users, kinds and statuses.
        $a1 = $jobs->create((int) $ua->id, (int) $category->id, job_manager::KIND_ANALYSE, null, 'https://e/a1.imscc');
        $a2 = $jobs->create((int) $ua->id, (int) $category->id, job_manager::KIND_BUILD, null, 'https://e/a2.imscc');
        $b1 = $jobs->create((int) $ub->id, (int) $category->id, job_manager::KIND_ANALYSE, null, 'https://e/b1.imscc');
        $jobs->mark_analysed($a1, ['itemcount' => 1]);

        // All of user A's jobs, newest first (a2 created after a1).
        $foruser = $jobs->list_jobs((int) $ua->id);
        $this->assertSame([$a2, $a1], array_keys($foruser));
        $this->assertArrayNotHasKey($b1, $foruser);

        // Filter by kind.
        $analyse = $jobs->list_jobs((int) $ua->id, job_manager::KIND_ANALYSE);
        $this->assertSame([$a1], array_keys($analyse));

        // Filter by status (only a1 was marked done).
        $done = $jobs->list_jobs((int) $ua->id, null, job_manager::STATUS_DONE);
        $this->assertSame([$a1], array_keys($done));

        // No user filter returns every job.
        $this->assertCount(3, $jobs->list_jobs());

        // The limit is honoured.
        $this->assertCount(1, $jobs->list_jobs((int) $ua->id, null, null, 1));
    }
}
