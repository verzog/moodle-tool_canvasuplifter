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

use tool_canvasuplifter\local\build\media_report;

/**
 * Tests for the unresolved-media collector.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\media_report
 */
final class media_report_test extends \basic_testcase {
    /**
     * A fresh report is empty.
     *
     * @return void
     */
    public function test_empty(): void {
        $report = new media_report();
        $this->assertSame(0, $report->count());
        $this->assertSame([], $report->references());
    }

    /**
     * Distinct references are counted once each and listed sorted; duplicates
     * (and blanks) collapse.
     *
     * @return void
     */
    public function test_records_distinct_sorted(): void {
        $report = new media_report();
        $report->record('web_resources/logo.png');
        $report->record('content/handout.pdf');
        $report->record('web_resources/logo.png');
        $report->record('  content/handout.pdf  ');
        $report->record('');
        $report->record('   ');

        $this->assertSame(2, $report->count());
        $this->assertSame(['content/handout.pdf', 'web_resources/logo.png'], $report->references());
    }
}
