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
     * Parse a single-option multiple-choice item whose only (correct) option is
     * the given text, returning the built question. Used to probe the
     * acknowledgment-recovery heuristic.
     *
     * @param string $optiontext The single option's text.
     * @return qti_question
     */
    private function single_option_question(string $optiontext): qti_question {
        $pres = '<presentation><material><mattext>Statement</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext texttype="text/plain">' . $optiontext
            . '</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
        return $r['questions'][0];
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
     * A Canvas acknowledgment question (a single correct "YES" option) gains a
     * synthesised "No" distractor so Moodle, which needs two options, can save it.
     *
     * @return void
     */
    public function test_single_option_acknowledgment_gets_no_distractor(): void {
        $pres = '<presentation><material><mattext>I understand the syllabus.</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext texttype="text/plain">YES</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertSame(qti_question::TYPE_MULTICHOICE, $q->type);
        $this->assertCount(2, $q->answers);
        $this->assertSame('YES', trim($q->answers[0]['text']));
        $this->assertSame(100.0, $q->answers[0]['fraction']);
        $this->assertSame('No', $q->answers[1]['text']);
        $this->assertSame(0.0, $q->answers[1]['fraction']);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A single-option question that is not an affirmation is left untouched (we
     * do not guess a distractor); it stays unimportable for human review.
     *
     * @return void
     */
    public function test_single_option_non_affirmative_is_left_alone(): void {
        $pres = '<presentation><material><mattext>Capital of France?</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>Paris</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A consent/acceptance statement ("I have read and agree", "I accept") is an
     * affirmation too, so its single correct option also gains a "No" distractor
     * and becomes importable. Covers Canvas copyright/acceptance click-throughs.
     *
     * @return void
     */
    public function test_acceptance_statement_gets_no_distractor(): void {
        foreach (['I have read and agree', 'I accept', 'I acknowledge the terms', 'Accept'] as $optiontext) {
            $pres = '<presentation><material><mattext>Copyright Notice</mattext></material>'
                . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
                . '<response_label ident="A"><material><mattext texttype="text/plain">' . $optiontext
                . '</mattext></material></response_label>'
                . '</render_choice></response_lid></presentation>';
            $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
                . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
                . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

            $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
            $q = $r['questions'][0];

            $this->assertCount(2, $q->answers, "$optiontext should gain a distractor");
            $this->assertSame($optiontext, trim($q->answers[0]['text']));
            $this->assertSame('No', $q->answers[1]['text']);
            $this->assertTrue($q->is_importable(), "$optiontext should be importable");
        }
    }

    /**
     * A single-option statement that declines ("I do not agree") is not an
     * affirmation, so it is left unimportable rather than gaining a false "No".
     *
     * @return void
     */
    public function test_single_option_decline_is_left_alone(): void {
        $pres = '<presentation><material><mattext>Terms</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext texttype="text/plain">I do not agree</mattext>'
            . '</material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertFalse($q->is_importable());
    }

    /**
     * An acknowledgment whose statement contains an internal prohibition ("I
     * understand that I do not have permission ...") is still an affirmation:
     * only a negation of the acceptance verb itself counts as a decline, so it
     * gains a "No" distractor rather than being dropped as unimportable.
     *
     * @return void
     */
    public function test_acknowledgment_with_embedded_prohibition_gets_no_distractor(): void {
        $statement = 'I understand that I do not have permission to share answers';
        $pres = '<presentation><material><mattext>Honor code</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext texttype="text/plain">' . $statement
            . '</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
        $q = $r['questions'][0];

        $this->assertCount(2, $q->answers);
        $this->assertSame($statement, trim($q->answers[0]['text']));
        $this->assertSame('No', $q->answers[1]['text']);
        $this->assertTrue($q->is_importable());
    }

    /**
     * Bare confirm/certify click-throughs ("Confirm", "Confirm receipt",
     * "Certify compliance") are the same single-option affirmations as Accept
     * and Acknowledge, so they too gain a "No" distractor and import.
     *
     * @return void
     */
    public function test_bare_confirm_certify_get_no_distractor(): void {
        foreach (['Confirm', 'Confirm receipt', 'Certify compliance', 'Certify'] as $optiontext) {
            $pres = '<presentation><material><mattext>Receipt</mattext></material>'
                . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
                . '<response_label ident="A"><material><mattext texttype="text/plain">' . $optiontext
                . '</mattext></material></response_label>'
                . '</render_choice></response_lid></presentation>';
            $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
                . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
                . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

            $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));
            $q = $r['questions'][0];

            $this->assertCount(2, $q->answers, "$optiontext should gain a distractor");
            $this->assertSame('No', $q->answers[1]['text']);
            $this->assertTrue($q->is_importable(), "$optiontext should be importable");
        }
    }

    /**
     * An option that merely starts with the letters of an affirmation verb but
     * is a noun phrase ("Acceptable Use Policy", "Agreement form") is not an
     * affirmation: the opener match requires the whole word, so no distractor is
     * fabricated and the single-option item is left for review.
     *
     * @return void
     */
    public function test_opener_requires_whole_word(): void {
        foreach (['Acceptable Use Policy', 'Agreement form'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * A decline whose negation is separated from the affirmation verb ("I do not
     * wish to agree", "I don't want to accept") is still a refusal, so it is left
     * unimportable rather than gaining a false "No".
     *
     * @return void
     */
    public function test_separated_negation_decline_is_left_alone(): void {
        foreach (['I do not wish to agree', "I don't want to accept"] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * An affirmative acknowledgment that mentions a prohibited action ("I
     * acknowledge that I must reject plagiarism", "I understand I may refuse
     * unsafe work") is recognised: reject/refuse inside the acknowledged policy
     * text must not be mistaken for the option itself being a decline.
     *
     * @return void
     */
    public function test_acknowledgment_mentioning_prohibition_is_affirmative(): void {
        foreach (['I acknowledge that I must reject plagiarism', 'I understand I may refuse unsafe work'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(2, $q->answers, "$optiontext should gain a distractor");
            $this->assertSame('No', $q->answers[1]['text']);
            $this->assertTrue($q->is_importable(), "$optiontext should be importable");
        }
    }

    /**
     * Bare consent click-throughs ("Consent to participate", "Consent to
     * recording") are affirmations too, so their single correct option gains a
     * "No" distractor and imports.
     *
     * @return void
     */
    public function test_bare_consent_gets_no_distractor(): void {
        foreach (['Consent to participate', 'Consent to recording'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(2, $q->answers, "$optiontext should gain a distractor");
            $this->assertSame('No', $q->answers[1]['text']);
            $this->assertTrue($q->is_importable(), "$optiontext should be importable");
        }
    }

    /**
     * A consent option that closes with an affirmation verb followed by ordinary
     * punctuation ("By selecting this option, I agree.", "By continuing, I
     * accept!") is recognised, so it gains a distractor and imports.
     *
     * @return void
     */
    public function test_trailing_affirmation_tolerates_punctuation(): void {
        foreach (['By selecting this option, I agree.', 'By continuing, I accept!'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(2, $q->answers, "$optiontext should gain a distractor");
            $this->assertSame('No', $q->answers[1]['text']);
            $this->assertTrue($q->is_importable(), "$optiontext should be importable");
        }
    }

    /**
     * A decline that opens with an affirmative read-confirmation phrase but then
     * negates the acceptance verb ("I have read and do not agree", "I have read
     * and do not consent") is still a refusal, so it is left for review rather
     * than gaining a false "No".
     *
     * @return void
     */
    public function test_read_and_decline_opener_is_rejected(): void {
        foreach (['I have read and do not agree', 'I have read and do not consent'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * An explicit refusal phrase whose refusal verb governs a trailing
     * affirmation verb ("I refuse to agree", "I decline to accept", "I reject
     * consent") is a decline, so no distractor is fabricated.
     *
     * @return void
     */
    public function test_refusal_verb_phrases_are_left_alone(): void {
        foreach (['I refuse to agree', 'I decline to accept', 'I reject consent'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * A negated "understood" response ("Not understood", "I have not
     * understood") is a negative, so it is left for review rather than being
     * read as the closing affirmation "understood".
     *
     * @return void
     */
    public function test_negated_understood_is_left_alone(): void {
        foreach (['Not understood', 'I have not understood'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * Explicit negative-consent labels ("No consent", "Non-consent", "I give no
     * consent") are declines, so the trailing "consent" must not fabricate a
     * "No" and make the refusal importable.
     *
     * @return void
     */
    public function test_no_consent_labels_are_declines(): void {
        foreach (['No consent', 'Non-consent', 'I give no consent'] as $optiontext) {
            $q = $this->single_option_question($optiontext);
            $this->assertCount(1, $q->answers, "$optiontext must not gain a distractor");
            $this->assertFalse($q->is_importable(), "$optiontext must stay for review");
        }
    }

    /**
     * A bare item reference (an ident with no presentation, as Canvas New
     * Quizzes export) yields no question but is counted as unresolved, so the
     * builder can report missing content rather than an empty assessment.
     *
     * @return void
     */
    public function test_bare_item_reference_is_counted_unresolved(): void {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Shell"><section ident="s1">'
            . '<item ident="iabc-missing-body" />'
            . '</section></assessment></questestinterop>';

        $r = (new qti_parser())->parse($xml);

        $this->assertCount(0, $r['questions']);
        $this->assertSame(1, $r['unresolved']);
    }

    /**
     * A genuinely empty assessment (no items at all) reports zero unresolved
     * references, distinct from one that lost its question bodies.
     *
     * @return void
     */
    public function test_empty_section_has_no_unresolved(): void {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Empty"><section ident="s1"/></assessment></questestinterop>';

        $r = (new qti_parser())->parse($xml);

        $this->assertCount(0, $r['questions']);
        $this->assertSame(0, $r['unresolved']);
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

    /**
     * hasassessment marks a readable QTI 1.2 assessment (even an empty shell),
     * and is false for QTI 2.x or malformed input — so callers can tell a
     * genuine empty Canvas shell from a file the parser cannot read.
     *
     * @return void
     */
    public function test_hasassessment_distinguishes_shell_from_unreadable(): void {
        // Empty QTI 1.2 <assessment>/<section> — a valid shell with no questions.
        $shell = (new qti_parser())->parse($this->assessment(''));
        $this->assertTrue($shell['hasassessment']);
        $this->assertSame([], $shell['questions']);

        // QTI 2.1 (no QTI 1.2 <assessment>/<section>) is not a readable shell.
        $qti2 = '<?xml version="1.0"?>'
            . '<assessmentTest xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="t1">'
            . '<testPart identifier="p1"><assessmentSection identifier="s1" title="S"/></testPart>'
            . '</assessmentTest>';
        $this->assertFalse((new qti_parser())->parse($qti2)['hasassessment']);

        // Malformed XML is not.
        $this->assertFalse((new qti_parser())->parse('not xml <<<')['hasassessment']);
    }
}
