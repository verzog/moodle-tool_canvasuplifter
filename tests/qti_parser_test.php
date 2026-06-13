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
 * Tests for the QTI parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\qti_parser
 */
final class qti_parser_test extends \basic_testcase {
    /**
     * Wrap item XML in a minimal QTI assessment document.
     *
     * @param string $itemsxml The <item> elements.
     * @param string $title The assessment title.
     * @return string
     */
    private function assessment(string $itemsxml, string $title = 'Bank'): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="' . $title . '"><section ident="s1">'
            . $itemsxml . '</section></assessment></questestinterop>';
    }

    /**
     * Build one item with the given profile, presentation and resprocessing.
     *
     * @param string $profile The cc_profile.
     * @param string $presentation The <presentation> block.
     * @param string $resprocessing The <resprocessing> block.
     * @param string $feedback Optional trailing itemfeedback blocks.
     * @return string
     */
    private function item(string $profile, string $presentation, string $resprocessing, string $feedback = ''): string {
        return '<item ident="q1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>' . $profile . '</fieldentry></qtimetadatafield>'
            . '<qtimetadatafield><fieldlabel>cc_weighting</fieldlabel><fieldentry>2</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata>' . $presentation . $resprocessing . $feedback . '</item>';
    }

    /**
     * Multiple choice: correct option scores 100, others 0; feedback attaches.
     *
     * @return void
     */
    public function test_parses_multiple_choice(): void {
        $pres = '<presentation><material><mattext texttype="text/html">&lt;p&gt;2+2?&lt;/p&gt;</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>3</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>4</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">B</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<displayfeedback feedbacktype="Response" linkrefid="A_fb"/></respcondition></resprocessing>';
        $fb = '<itemfeedback ident="A_fb"><material><mattext>Not quite</mattext></material></itemfeedback>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp, $fb)));

        $this->assertCount(1, $r['questions']);
        $q = $r['questions'][0];
        $this->assertSame(qti_question::TYPE_MULTICHOICE, $q->type);
        $this->assertSame(2.0, $q->defaultmark);
        $this->assertStringContainsString('2+2', $q->name);
        $this->assertCount(2, $q->answers);
        $bylabel = ['3' => null, '4' => null];
        foreach ($q->answers as $a) {
            $bylabel[trim($a['text'])] = $a;
        }
        $this->assertSame(100.0, $bylabel['4']['fraction']);
        $this->assertSame(0.0, $bylabel['3']['fraction']);
        $this->assertSame('Not quite', $bylabel['3']['feedback']);
    }

    /**
     * Multiple response: each of two correct options gets an even split.
     *
     * @return void
     */
    public function test_parses_multiple_response(): void {
        $pres = '<presentation><material><mattext>Pick two</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Multiple"><render_choice>'
            . '<response_label ident="A"><material><mattext>a</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>b</mattext></material></response_label>'
            . '<response_label ident="C"><material><mattext>c</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><and>'
            . '<varequal respident="r1">A</varequal><varequal respident="r1">B</varequal>'
            . '</and></conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_response.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertSame(qti_question::TYPE_MULTIANSWER, $q->type);
        $fractions = [];
        foreach ($q->answers as $a) {
            $fractions[trim($a['text'])] = $a['fraction'];
        }
        $this->assertSame(50.0, $fractions['a']);
        $this->assertSame(50.0, $fractions['b']);
        $this->assertSame(0.0, $fractions['c']);
    }

    /**
     * Fill-in-blank uses varsubstring; the "^" anchor form dedupes away.
     *
     * @return void
     */
    public function test_parses_fib_with_varsubstring(): void {
        $pres = '<presentation><material><mattext>Name it</mattext></material>'
            . '<response_str ident="r1"><render_fib><response_label ident="A"/></render_fib></response_str></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar>'
            . '<varsubstring respident="r1">four</varsubstring>'
            . '<varsubstring respident="r1">^four</varsubstring>'
            . '<varsubstring respident="r1">4</varsubstring>'
            . '</conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.fib.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertSame(qti_question::TYPE_SHORTANSWER, $q->type);
        $accepted = array_map(fn($a) => $a['text'], $q->answers);
        $this->assertSame(['four', '4'], $accepted);
        $this->assertSame(100.0, $q->answers[0]['fraction']);
    }

    /**
     * Bare item references (exam shells) carry no presentation and are ignored.
     *
     * @return void
     */
    public function test_ignores_item_references(): void {
        $r = (new qti_parser())->parse($this->assessment('<item ident="ref1" />'));
        $this->assertCount(0, $r['questions']);
    }
}
