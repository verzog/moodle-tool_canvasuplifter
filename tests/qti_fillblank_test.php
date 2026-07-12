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

use tool_canvasuplifter\local\parser\qti_parser;
use tool_canvasuplifter\local\model\qti_question;

/**
 * Tests that a single-blank Canvas fill-in-the-blank question converts to a
 * Moodle short answer (not a degenerate one-option multiple choice), keeping
 * every accepted answer.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\qti_parser
 */
final class qti_fillblank_test extends \advanced_testcase {
    /**
     * Wrap a QTI <item> body in an objectbank document.
     *
     * @param string $item The <item> XML.
     * @return string
     */
    private function doc(string $item): string {
        return '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<objectbank ident="ob">' . $item . '</objectbank></questestinterop>';
    }

    /**
     * A single-blank fill_in_multiple_blanks question with two acceptable
     * answers becomes a short answer accepting both, with the bracketed blank
     * marker stripped from the prompt.
     *
     * @return void
     */
    public function test_single_blank_becomes_shortanswer(): void {
        $item = '<item ident="q1" title="q1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield>'
            . '<qtimetadatafield><fieldlabel>points_possible</fieldlabel><fieldentry>1.0</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata><presentation>'
            . '<material><mattext texttype="text/html">Best practices balance information [blank1_1]</mattext></material>'
            . '<response_lid ident="response_blank1_1"><material><mattext>blank1_1</mattext></material>'
            . '<render_choice>'
            . '<response_label ident="1"><material><mattext texttype="text/plain">access</mattext></material></response_label>'
            . '<response_label ident="2"><material><mattext texttype="text/plain">availability</mattext></material>'
            . '</response_label></render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_blank1_1">1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">100.00</setvar></respcondition></resprocessing></item>';

        $result = (new qti_parser())->parse($this->doc($item));

        $this->assertCount(1, $result['questions']);
        $question = $result['questions'][0];
        $this->assertSame(qti_question::TYPE_SHORTANSWER, $question->type);
        $answers = array_map(fn($a) => $a['text'], $question->answers);
        sort($answers);
        $this->assertSame(['access', 'availability'], $answers);
        foreach ($question->answers as $answer) {
            $this->assertEqualsWithDelta(100.0, $answer['fraction'], 0.001);
        }
        $this->assertStringNotContainsString('[blank1_1]', $question->questiontext);
    }

    /**
     * A single inline dropdown (multiple_dropdowns_question) stays a multiple
     * choice — its render_choice options are genuine distractors to pick from.
     *
     * @return void
     */
    public function test_single_dropdown_stays_multichoice(): void {
        $item = '<item ident="q2" title="q2"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>multiple_dropdowns_question</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata><presentation>'
            . '<material><mattext texttype="text/html">The sky is [colour]</mattext></material>'
            . '<response_lid ident="response_colour"><material><mattext>colour</mattext></material>'
            . '<render_choice>'
            . '<response_label ident="1"><material><mattext texttype="text/plain">blue</mattext></material></response_label>'
            . '<response_label ident="2"><material><mattext texttype="text/plain">green</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_colour">1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">100.00</setvar></respcondition></resprocessing></item>';

        $result = (new qti_parser())->parse($this->doc($item));

        $this->assertCount(1, $result['questions']);
        $this->assertSame(qti_question::TYPE_MULTICHOICE, $result['questions'][0]->type);
    }
}
