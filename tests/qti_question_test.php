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

use tool_canvasuplifter\local\model\qti_question;

/**
 * Unit tests for the qti_question model.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\model\qti_question
 */
final class qti_question_test extends \basic_testcase {
    /**
     * Build a question with the given type and answer texts.
     *
     * @param string $type A qti_question::TYPE_* constant.
     * @param array $texts Answer texts.
     * @return qti_question
     */
    private function make(string $type, array $texts): qti_question {
        $q = new qti_question();
        $q->type = $type;
        foreach ($texts as $text) {
            $q->answers[] = ['text' => $text, 'fraction' => 100.0, 'feedback' => ''];
        }
        return $q;
    }

    /**
     * Choice questions need at least two answers; one or zero is not importable.
     *
     * @return void
     */
    public function test_choice_needs_two_answers(): void {
        $this->assertTrue($this->make(qti_question::TYPE_MULTICHOICE, ['A', 'B'])->is_importable());
        $this->assertTrue($this->make(qti_question::TYPE_MULTIANSWER, ['A', 'B', 'C'])->is_importable());
        $this->assertTrue($this->make(qti_question::TYPE_TRUEFALSE, ['True', 'False'])->is_importable());
        $this->assertFalse($this->make(qti_question::TYPE_MULTICHOICE, ['A'])->is_importable());
        // Blank options don't count towards the two-answer minimum.
        $this->assertFalse($this->make(qti_question::TYPE_MULTICHOICE, ['A', '  '])->is_importable());
        $this->assertFalse($this->make(qti_question::TYPE_MULTICHOICE, [])->is_importable());
    }

    /**
     * Short answer needs one answer; essay needs none; unsupported is never importable.
     *
     * @return void
     */
    public function test_shortanswer_essay_and_unsupported(): void {
        $this->assertTrue($this->make(qti_question::TYPE_SHORTANSWER, ['four'])->is_importable());
        $this->assertFalse($this->make(qti_question::TYPE_SHORTANSWER, [])->is_importable());
        $this->assertTrue($this->make(qti_question::TYPE_ESSAY, [])->is_importable());
        $this->assertFalse($this->make(qti_question::TYPE_UNSUPPORTED, ['A', 'B'])->is_importable());
    }
}
