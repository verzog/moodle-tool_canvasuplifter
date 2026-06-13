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

use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\model\section_model;
use tool_canvasuplifter\local\report\conversion_report;

/**
 * Tests for the conversion report.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\report\conversion_report
 */
final class conversion_report_test extends \advanced_testcase {
    /**
     * The report should split builds-now from later, and surface detail/orphans.
     *
     * @return void
     */
    public function test_build_reports_detail_and_orphans(): void {
        $course = new course_model();
        $course->fullname = 'Demo course';

        $section = new section_model('Week 1');
        $page = new item('i1', 'Welcome');
        $page->kind = item::KIND_PAGE;
        $assign = new item('i2', 'Essay');
        $assign->kind = item::KIND_ASSIGNMENT;
        $section->add_item($page);
        $section->add_item($assign);
        $course->add_section($section);

        $orphan = new item('r9', '');
        $orphan->kind = item::KIND_FILE;
        $orphan->resourcetype = 'webcontent';
        $course->orphans[] = $orphan;

        $report = (new conversion_report($course))->build();

        // Page, the orphan file and the assignment all build now (3).
        $this->assertSame(3, $report['buildsnowtotal']);
        $this->assertSame(0, $report['latertotal']);

        $bykind = [];
        foreach ($report['rows'] as $row) {
            $bykind[$row['kind']] = $row;
        }
        $this->assertTrue($bykind['page']['buildsnow']);
        $this->assertTrue($bykind['assignment']['buildsnow']);
        $this->assertSame('note_assignment', $bykind['assignment']['note']);

        // Per-section drill-down.
        $this->assertCount(1, $report['sections']);
        $this->assertSame('Week 1', $report['sections'][0]['title']);
        $this->assertCount(2, $report['sections'][0]['items']);

        // The orphan is surfaced and falls back to its identifier for a title.
        $this->assertCount(1, $report['orphans']);
        $this->assertSame('r9', $report['orphans'][0]['title']);
        $this->assertSame('file', $report['orphans'][0]['kind']);
    }
}
