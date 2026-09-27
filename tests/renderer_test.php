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
use tool_canvasuplifter\local\report\conversion_report;

/**
 * Tests for the analysis renderer.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\output\renderer
 */
final class renderer_test extends \advanced_testcase {
    /**
     * An empty unlinked Canvas quiz is counted in the summary line, and the unreferenced
     * resources intro no longer claims that every listed resource is imported.
     *
     * @return void
     */
    public function test_analysis_accounts_for_empty_quizzes(): void {
        global $PAGE;
        $dir = make_request_directory();
        mkdir($dir . '/g1');
        file_put_contents(
            $dir . '/g1/assessment_qti.xml',
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="g1" title="Unnamed Quiz"><section ident="root_section"/>'
            . '</assessment></questestinterop>'
        );
        $course = new course_model();
        $orphan = new item('g1', 'Unnamed Quiz');
        $orphan->kind = item::KIND_QUIZ;
        $orphan->files = ['g1/assessment_qti.xml'];
        $course->orphans[] = $orphan;
        $report = (new conversion_report($course, $dir))->build();

        $PAGE->set_url(new \moodle_url('/admin/tool/canvasuplifter/index.php'));
        $html = $PAGE->get_renderer('tool_canvasuplifter')->analysis($report);

        $this->assertStringContainsString(get_string('buildsnowsummarynotbuilt', 'tool_canvasuplifter', 1), $html);
        $this->assertStringContainsString(get_string('orphansexplainnone', 'tool_canvasuplifter'), $html);
        $this->assertStringContainsString(get_string('placement_none', 'tool_canvasuplifter'), $html);
        // No syllabus is among the unreferenced resources, so the intro does not mention one.
        $this->assertStringNotContainsStringIgnoringCase('syllabus', $html);
    }
}
