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
     * Distinct references are counted once each and listed sorted; exact duplicates
     * collapse and all-whitespace/empty references are skipped, but a real filename's
     * own leading/trailing whitespace is preserved (a different file).
     *
     * @return void
     */
    public function test_records_distinct_sorted(): void {
        $report = new media_report();
        $report->record('web_resources/logo.png');
        $report->record('content/handout.pdf');
        $report->record('web_resources/logo.png');
        $report->record('');
        $report->record('   ');
        // A filename whose first character is a real (decoded) space is not the same
        // file as "logo.png" and must not be trimmed into it.
        $report->record(' logo.png');

        $this->assertSame(3, $report->count());
        $this->assertSame([' logo.png', 'content/handout.pdf', 'web_resources/logo.png'], $report->references());
    }

    /**
     * A reference carrying invalid UTF-8 bytes (a stale %FF token decodes to one) is
     * scrubbed to valid UTF-8, so the stored set can always be json_encode()d into the
     * build report rather than blanking it.
     *
     * @return void
     */
    public function test_scrubs_invalid_utf8(): void {
        $report = new media_report();
        $report->record("bad\xFF.png");

        $this->assertSame(1, $report->count());
        foreach ($report->references() as $ref) {
            $this->assertTrue(mb_check_encoding($ref, 'UTF-8'));
        }
        // The scrubbed set survives JSON encoding (the persisted-report path).
        $this->assertNotFalse(json_encode($report->references()));
    }

    /**
     * The embedded-file set records the package paths a build actually inlined, is
     * queried by exact path, ignores a blank path, and is independent of the
     * unresolved-reference set (recording an embed adds no unresolved reference).
     *
     * @return void
     */
    public function test_records_embedded_paths(): void {
        $report = new media_report();
        $this->assertFalse($report->was_embedded('/pkg/web_resources/logo.png'));

        $report->record_embedded('/pkg/web_resources/logo.png');
        $report->record_embedded('/pkg/web_resources/logo.png');
        $report->record_embedded('');

        $this->assertTrue($report->was_embedded('/pkg/web_resources/logo.png'));
        $this->assertFalse($report->was_embedded('/pkg/web_resources/other.png'));
        $this->assertFalse($report->was_embedded(''));
        // Embeds are a separate channel from unresolved references.
        $this->assertSame(0, $report->count());
    }

    /**
     * merge() folds a provisional report's embedded-file records into the shared one,
     * so an asset embedded by a kept activity is seen as embedded after promotion,
     * alongside the unresolved references it already merged.
     *
     * @return void
     */
    public function test_merge_carries_embedded_paths(): void {
        $shared = new media_report();
        $provisional = new media_report();
        $provisional->record('web_resources/missing.png');
        $provisional->record_embedded('/pkg/web_resources/inlined.png');

        $this->assertFalse($shared->was_embedded('/pkg/web_resources/inlined.png'));
        $shared->merge($provisional);

        $this->assertTrue($shared->was_embedded('/pkg/web_resources/inlined.png'));
        $this->assertSame(['web_resources/missing.png'], $shared->references());
    }
}
