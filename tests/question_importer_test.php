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

use tool_canvasuplifter\local\build\question_importer;
use tool_canvasuplifter\local\model\qti_question;

/**
 * Tests for the question importer's skip-reason summary.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\question_importer::describe_unconvertible
 */
final class question_importer_test extends \basic_testcase {
    /**
     * An assessment that parsed no questions is described as empty, not as a
     * batch of unconvertible content (a Canvas exam shell with an empty section).
     *
     * @return void
     */
    public function test_empty_assessment_reason(): void {
        $this->assertSame(
            'assessment contains no questions',
            question_importer::describe_unconvertible([], [])
        );
    }

    /**
     * When questions parsed but none are importable, the reason names the
     * unconvertible profiles and the counts.
     *
     * @return void
     */
    public function test_unconvertible_questions_reason(): void {
        $q = new qti_question();
        $q->type = qti_question::TYPE_UNSUPPORTED;
        $q->profile = 'cc.essay.v0p1';

        $reason = question_importer::describe_unconvertible([$q], []);

        $this->assertStringContainsString('1 parsed', $reason);
        $this->assertStringContainsString('cc.essay.v0p1 (1)', $reason);
    }
}
