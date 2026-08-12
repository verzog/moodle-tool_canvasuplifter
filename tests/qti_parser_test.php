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
     * A choice whose correct <varequal> names the option by its displayed text
     * ("False") rather than its response_label ident is still scored correctly -
     * some IMS Common Cartridge exporters emit true/false items this way, and
     * without the text fallback the question would import with no correct answer
     * (and be dropped as not importable).
     *
     * @return void
     */
    public function test_varequal_matches_choice_text_not_only_ident(): void {
        $pres = '<presentation><material><mattext texttype="text/html">The sky is green</mattext></material>'
            . '<response_lid ident="RL" rcardinality="Single"><render_choice>'
            . '<response_label ident="A1"><material><mattext>True</mattext></material></response_label>'
            . '<response_label ident="A2"><material><mattext>False</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        // The scored value is the option text "False", not the ident "A2"; the
        // per-response feedback is likewise keyed by the text.
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition title="Correct"><conditionvar><varequal respident="RL">False</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar>'
            . '<displayfeedback feedbacktype="Response" linkrefid="False_fb"/></respcondition>'
            . '<respcondition><conditionvar><varequal respident="RL">True</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">0</setvar></respcondition></resprocessing>';
        $fb = '<itemfeedback ident="False_fb"><material><mattext>Correct - it is blue</mattext></material></itemfeedback>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.true_false.v0p1', $pres, $resp, $fb)));

        $q = $r['questions'][0];
        $this->assertSame(qti_question::TYPE_TRUEFALSE, $q->type);
        $this->assertTrue($q->is_importable(), 'the text-scored true/false should be importable');
        $byanswer = [];
        foreach ($q->answers as $a) {
            $byanswer[trim($a['text'])] = $a;
        }
        $this->assertSame(100.0, $byanswer['False']['fraction']);
        $this->assertSame(0.0, $byanswer['True']['fraction']);
        // Feedback keyed by the scored text is preserved on the matched answer.
        $this->assertSame('Correct - it is blue', $byanswer['False']['feedback']);
    }

    /**
     * The text fallback must not fire for a scored value that is a real
     * response_label ident: a normal multiple-choice item with correct ident "A"
     * and a *different* option whose displayed text is "A" must keep only the
     * ident-matched option correct, not both.
     *
     * @return void
     */
    public function test_text_fallback_does_not_fire_on_ident_collision(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Pick the first</mattext></material>'
            . '<response_lid ident="RL" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>First option</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>A</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        // Correct answer is ident "A"; option B's *text* happens to be "A".
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="RL">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_choice.v0p1', $pres, $resp)));

        $byanswer = [];
        foreach ($r['questions'][0]->answers as $a) {
            $byanswer[trim($a['text'])] = $a['fraction'];
        }
        // Only the ident-"A" option ("First option") is correct; option B (text "A") is not.
        $this->assertSame(100.0, $byanswer['First option']);
        $this->assertSame(0.0, $byanswer['A']);
    }

    /**
     * A multiple-response question whose correct options are split across sibling
     * respconditions (one positively-scored varequal each) keeps every correct
     * option, not just the last condition's.
     *
     * @return void
     */
    public function test_multiple_response_split_conditions_keeps_all_correct(): void {
        $pres = '<presentation><material><mattext>Pick two</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Multiple"><render_choice>'
            . '<response_label ident="A"><material><mattext>Alpha</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>Beta</mattext></material></response_label>'
            . '<response_label ident="C"><material><mattext>Gamma</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        // A and B are each marked correct by their own sibling respcondition.
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">50</setvar></respcondition>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">B</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">50</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.multiple_response.v0p1', $pres, $resp)));

        $this->assertCount(1, $r['questions']);
        $correct = [];
        foreach ($r['questions'][0]->answers as $a) {
            if ((float) $a['fraction'] > 0) {
                $correct[] = trim($a['text']);
            }
        }
        sort($correct);
        $this->assertSame(['Alpha', 'Beta'], $correct);
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
     * Canvas's native dump (non_cc_assessments/*.xml.qti) labels items with
     * question_type rather than cc_profile. Those types are mapped explicitly:
     * a true_false_question becomes a two-option choice with the right answer
     * scored, and points_possible carries the weight.
     *
     * @return void
     */
    public function test_native_question_type_true_false(): void {
        $pres = '<presentation><material><mattext texttype="text/html">&lt;p&gt;Cells form tissues.&lt;/p&gt;</mattext>'
            . '</material><response_lid ident="response1" rcardinality="Single"><render_choice>'
            . '<response_label ident="t"><material><mattext texttype="text/plain">True</mattext></material></response_label>'
            . '<response_label ident="f"><material><mattext texttype="text/plain">False</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="response1">t</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing>';
        $item = '<item ident="tf1" title="Cells"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>true_false_question</fieldentry>'
            . '</qtimetadatafield>'
            . '<qtimetadatafield><fieldlabel>points_possible</fieldlabel><fieldentry>0.5</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_TRUEFALSE, $q->type);
        $this->assertSame('true_false_question', $q->profile);
        $this->assertSame(0.5, $q->defaultmark);
        $this->assertCount(2, $q->answers);
        $bytext = [];
        foreach ($q->answers as $a) {
            $bytext[trim($a['text'])] = $a['fraction'];
        }
        $this->assertSame(100.0, $bytext['True']);
        $this->assertSame(0.0, $bytext['False']);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A New Quizzes text_only_question (a stimulus item with no response) parses
     * as a description: importable, carrying its text, with no answers.
     *
     * @return void
     */
    public function test_native_text_only_becomes_description(): void {
        $pres = '<presentation><material><mattext texttype="text/html">&lt;p&gt;Read this passage&lt;/p&gt;</mattext>'
            . '</material></presentation>';
        $item = '<item ident="t1" title="Intro"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>text_only_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_DESCRIPTION, $q->type);
        $this->assertTrue($q->is_importable());
        $this->assertCount(0, $q->answers);
        $this->assertStringContainsString('Read this passage', $q->questiontext);
    }

    /**
     * A Canvas matching_question parses into one stem/answer subquestion per
     * response_lid (stem from the row's own material, answer from the
     * resprocessing-scored choice), and any choice never used as a correct match
     * is carried as an answer-only distractor.
     *
     * @return void
     */
    public function test_native_matching_question(): void {
        $row = function (string $ident, string $stem): string {
            return '<response_lid ident="' . $ident . '"><material><mattext texttype="text/html">' . $stem
                . '</mattext></material><render_choice>'
                . '<response_label ident="o1"><material><mattext>carpal</mattext></material></response_label>'
                . '<response_label ident="o2"><material><mattext>popliteal</mattext></material></response_label>'
                . '<response_label ident="o3"><material><mattext>prone</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        $pres = '<presentation><material><mattext texttype="text/html">Match the term.</mattext></material>'
            . $row('rA', 'Wrist area') . $row('rB', 'Back of the knee') . '</presentation>';
        $resp = '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="rA">o1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50.00</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="rB">o2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50.00</setvar></respcondition></resprocessing>';
        $item = '<item ident="m1" title="Anatomical terms"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>matching_question</fieldentry>'
            . '</qtimetadatafield>'
            . '<qtimetadatafield><fieldlabel>points_possible</fieldlabel><fieldentry>2.0</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_MATCHING, $q->type);
        $this->assertSame(2.0, $q->defaultmark);
        // Two scored pairs plus one answer-only distractor ("prone").
        $this->assertCount(3, $q->subquestions);
        $pairs = [];
        $distractors = [];
        foreach ($q->subquestions as $sub) {
            if (trim($sub['text']) === '') {
                $distractors[] = $sub['answer'];
            } else {
                $pairs[$this->plain($sub['text'])] = $sub['answer'];
            }
        }
        $this->assertSame('carpal', $pairs['Wrist area']);
        $this->assertSame('popliteal', $pairs['Back of the knee']);
        $this->assertSame(['prone'], $distractors);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A Canvas multiple_dropdowns_question is authored as one response_lid +
     * render_choice per blank; when every blank shares one choice set it is
     * structurally a matching question, so it imports as a Moodle match (one
     * stem/answer pair per blank).
     *
     * @return void
     */
    public function test_native_dropdowns_become_matching(): void {
        $blank = function (string $ident, string $stem, string $a1, string $a2): string {
            return '<response_lid ident="' . $ident . '"><material><mattext>' . $stem . '</mattext></material>'
                . '<render_choice>'
                . '<response_label ident="' . $a1 . '"><material><mattext texttype="text/plain">Sensory</mattext>'
                . '</material></response_label>'
                . '<response_label ident="' . $a2 . '"><material><mattext texttype="text/plain">Motor</mattext>'
                . '</material></response_label></render_choice></response_lid>';
        };
        $pres = '<presentation><material><mattext texttype="text/html">Pick for each [I] [II].</mattext></material>'
            . $blank('response_I', 'I', 'a1', 'a2') . $blank('response_II', 'II', 'b1', 'b2') . '</presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_I">a1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_II">b2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="c1" title="Cloze"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_dropdowns_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_MATCHING, $q->type);
        $pairs = [];
        foreach ($q->subquestions as $sub) {
            if (trim($sub['text']) !== '') {
                $pairs[$this->plain($sub['text'])] = $sub['answer'];
            }
        }
        $this->assertSame('Sensory', $pairs['I']);
        $this->assertSame('Motor', $pairs['II']);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A Canvas fill_in_multiple_blanks_question with two or more free-text blanks
     * becomes a Moodle Cloze: each [blank] placeholder in the stem is replaced by a
     * SHORTANSWER field built from that blank's accepted answers (its varequal values
     * resolved to the response_label display text).
     *
     * @return void
     */
    public function test_native_fill_in_multiple_blanks_becomes_cloze(): void {
        $q = (new qti_parser())->parse($this->assessment($this->fib_item()))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        $this->assertSame('fill_in_multiple_blanks_question', $q->profile);
        $this->assertTrue($q->is_importable());
        $this->assertStringContainsString('Lorem {1:SHORTANSWER:=ipsum} dolor', $q->questiontext);
        $this->assertStringContainsString('elit {1:SHORTANSWER:=sed} do', $q->questiontext);
        // The name reads as prose (blanks shown as gaps), not the raw [blank] ids.
        $this->assertStringNotContainsString('[b1]', $q->name);
        $this->assertStringNotContainsString('{1:SHORTANSWER', $q->name);
    }

    /**
     * A blank with several accepted spellings becomes a SHORTANSWER field listing each
     * as a fully-credited (=) option, and Cloze metacharacters in an answer are escaped
     * so they cannot break the field.
     *
     * @return void
     */
    public function test_native_cloze_multiple_answers_are_escaped(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Sum: [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice>'
            . '<response_label ident="b1-0"><material><mattext texttype="text/plain">2~x</mattext></material></response_label>'
            . '<response_label ident="b1-1"><material><mattext texttype="text/plain">two</mattext></material></response_label>'
            . '</render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice>'
            . '<response_label ident="b2-0"><material><mattext texttype="text/plain">3</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="c2"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>fill_in_multiple_blanks_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // First blank: two options, and the ~ in "2~x" is escaped as \~.
        $this->assertStringContainsString('{1:SHORTANSWER:=2\~x~=two} and {1:SHORTANSWER:=3}', $q->questiontext);
    }

    /**
     * A Canvas open-entry blank scored by "contains" (scoring_algorithm=
     * TextContainsAnswer) widens to a Moodle SHORTANSWER wildcard match (=*answer*),
     * so a response that merely holds the answer still earns credit, and a literal
     * asterisk in the answer is escaped rather than treated as a wildcard.
     *
     * @return void
     */
    public function test_native_cloze_contains_becomes_wildcard(): void {
        $pres = '<presentation><material><mattext texttype="text/html">The [b1] and [b2] room.</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice>'
            . '<response_label ident="b1-0" scoring_algorithm="TextContainsAnswer" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">a*b</mattext></material></response_label>'
            . '</render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice>'
            . '<response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">exact</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>';
        $item = '<item ident="c3"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // The contains blank widens to a wildcard match and its literal * is escaped;
        // the plain blank stays an exact match.
        $this->assertStringContainsString('{1:SHORTANSWER:=*a\*b*} and {1:SHORTANSWER:=exact}', $q->questiontext);
    }

    /**
     * A fill-in-multiple-blanks blank is free-text, so every listed spelling is an
     * accepted answer even when the scoring respcondition references only one and the
     * labels carry no answer_type attribute. Their HTML is flattened to plain text,
     * because a Moodle SHORTANSWER key is not rendered as markup.
     *
     * @return void
     */
    public function test_native_cloze_keeps_all_spellings_and_flattens_html(): void {
        $pres = '<presentation><material><mattext texttype="text/html">The [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice>'
            . '<response_label ident="b1-0"><material><mattext texttype="text/html">&lt;p&gt;colour&lt;/p&gt;</mattext>'
            . '</material></response_label>'
            . '<response_label ident="b1-1"><material><mattext texttype="text/plain">color</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice>'
            . '<response_label ident="b2-0"><material><mattext texttype="text/plain">two</mattext></material>'
            . '</response_label></render_choice></response_lid></presentation>';
        // Scoring references only b1-0 and b2-0, yet the b1-1 spelling must still be kept.
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="sp1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // Both spellings retained (the unreferenced b1-1 too), and the HTML label is
        // flattened to plain "colour" rather than "<p>colour</p>".
        $this->assertStringContainsString('{1:SHORTANSWER:=colour~=color} and {1:SHORTANSWER:=two}', $q->questiontext);
        $this->assertStringNotContainsString('&lt;p&gt;', $q->questiontext);
        $this->assertStringNotContainsString('<p>colour', $q->questiontext);
    }

    /**
     * A multi-blank item where one blank cannot be placed — its [id] marker is absent
     * from the stem — would import as a silently truncated Cloze, so it is left
     * unsupported (dropped) rather than converted.
     *
     * @return void
     */
    public function test_native_cloze_unresolved_blank_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Only [b1] here.</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="ur1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A blank whose response_lid carries no ident is counted by map_type (which routes
     * the item to a Cloze) but cannot be resolved to a placed field, so it must fail the
     * whole item: completeness is measured against every source response_lid, not only
     * the resolvable ones, so a dropped blank cannot silently vanish.
     *
     * @return void
     */
    public function test_native_cloze_ident_less_blank_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">First [b1] then more.</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid><render_choice><response_label ident="x-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="il1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertFalse($q->is_importable());
    }

    /**
     * When one blank's accepted answer literally contains another blank's [id] marker,
     * the marker substitutions are applied against the original stem in a single pass, so
     * the marker text inside the generated field is left literal rather than expanded
     * into a nested Cloze field.
     *
     * @return void
     */
    public function test_native_cloze_answer_containing_marker_stays_literal(): void {
        $pres = '<presentation><material><mattext texttype="text/html">First [b1] then [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">see [b2]</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">value</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="cm1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // The [b2] inside b1's field is literal; only the real trailing [b2] became a field.
        $this->assertStringContainsString('{1:SHORTANSWER:=see [b2]} then {1:SHORTANSWER:=value}', $q->questiontext);
    }

    /**
     * A text/plain response label whose answer is a literal tag-like string (e.g. the
     * QTI encoding <mattext>&lt;div&gt;</mattext>) is kept verbatim: only HTML labels
     * are stripped of markup, so the angle brackets survive as the accepted answer
     * rather than being deleted (which would otherwise drop the blank and the item).
     *
     * @return void
     */
    public function test_native_cloze_plain_text_literal_markup_survives(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Enter [b1] then [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">&lt;div&gt;</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">value</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="pl1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // The literal <div> is preserved as the SHORTANSWER key, not stripped to empty.
        $this->assertStringContainsString('{1:SHORTANSWER:=<div>}', $q->questiontext);
    }

    /**
     * When a blank's [id] marker appears more than once in the stem, one QTI response
     * would become two independently graded Cloze fields, so the whole item is left
     * unsupported rather than converted with duplicated interactions.
     *
     * @return void
     */
    public function test_native_cloze_duplicate_marker_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">First [b1] again [b1] then [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="dm1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * An HTML answer label whose block elements abut is flattened with the word boundary
     * preserved, so <p>New</p><p>York</p> becomes the key "New York" (which a learner can
     * type) rather than "NewYork".
     *
     * @return void
     */
    public function test_native_cloze_html_answer_preserves_word_boundary(): void {
        $pres = '<presentation><material><mattext texttype="text/html">City [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/html">&lt;p&gt;New&lt;/p&gt;&lt;p&gt;York&lt;/p&gt;</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">value</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="wb1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        $this->assertStringContainsString('{1:SHORTANSWER:=New York}', $q->questiontext);
    }

    /**
     * When Canvas awards different points to different blanks, the even Cloze weighting
     * would change the partial-credit ratio, so the item is left unsupported rather than
     * silently mis-graded.
     *
     * @return void
     */
    public function test_native_cloze_unequal_blank_scores_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">75</setvar></respcondition></resprocessing>';
        $item = '<item ident="uw1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * An HTML answer whose tags are inline (no rendered word boundary) is flattened with
     * the characters kept adjacent — H<sub>2</sub>O yields the key "H2O", not "H 2 O" —
     * while block elements still insert a space.
     *
     * @return void
     */
    public function test_native_cloze_inline_html_answer_keeps_adjacency(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Formula [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/html">H&lt;sub&gt;2&lt;/sub&gt;O</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">value</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="ih1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        $this->assertStringContainsString('{1:SHORTANSWER:=H2O}', $q->questiontext);
    }

    /**
     * When Canvas scores only some blanks (a positive condition for one blank, none for
     * another), an even Cloze split would credit a blank Canvas awards nothing, so the
     * item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_partial_scoring_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="ps1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A single scoring condition that awards points only when several blanks are all
     * correct (an <and> across blanks) can't be split into independent even fields, so the
     * item is left unsupported rather than granting partial credit Canvas withholds.
     *
     * @return void
     */
    public function test_native_cloze_cross_blank_condition_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><and>'
            . '<varequal respident="response_b1">b1-0</varequal><varequal respident="response_b2">b2-0</varequal>'
            . '</and></conditionvar><setvar varname="SCORE" action="Add">100</setvar></respcondition></resprocessing>';
        $item = '<item ident="xb1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A stem that literally contains Moodle Cloze grammar (e.g. an instructional example
     * {1:SHORTANSWER:=foo}) has those braces HTML-encoded so Moodle does not parse them as
     * an extra graded subquestion; only the generated blank fields remain real Cloze
     * fields.
     *
     * @return void
     */
    public function test_native_cloze_preexisting_syntax_in_stem_is_escaped(): void {
        $pres = '<presentation><material><mattext texttype="text/html">'
            . 'Example {1:SHORTANSWER:=foo}: fill [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="es1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // The authored example is inert (braces encoded); only the two blanks are fields.
        $this->assertStringContainsString('&#123;1:SHORTANSWER:=foo&#125;', $q->questiontext);
        $this->assertSame(2, substr_count($q->questiontext, '{1:SHORTANSWER:'));
    }

    /**
     * A non-breaking space in an HTML answer (a decoded &nbsp;, U+00A0) is normalised to an
     * ordinary space, so the SHORTANSWER key is one a learner typing a normal space can
     * match.
     *
     * @return void
     */
    public function test_native_cloze_html_answer_normalises_nbsp(): void {
        $pres = '<presentation><material><mattext texttype="text/html">City [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/html">New&amp;nbsp;York</mattext></material>'
            . '</response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">value</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="nb1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        $this->assertStringContainsString('{1:SHORTANSWER:=New York}', $q->questiontext);
    }

    /**
     * Non-additive scoring (a SCORE setvar with action="Set" rather than "Add") does not map
     * to independent per-blank weights — each condition sets the total regardless of how
     * many blanks are right — so the item is left unsupported rather than mis-graded by an
     * even split.
     *
     * @return void
     */
    public function test_native_cloze_non_additive_scoring_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Set">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Set">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="na1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A blank whose [id] marker survives only inside markup (an attribute such as
     * <img alt="[b1]">) has no rendered-text placeholder, so substituting a Cloze field
     * there would embed it in an attribute; the item is left unsupported instead.
     *
     * @return void
     */
    public function test_native_cloze_marker_only_in_markup_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">'
            . '&lt;img alt="[b1]"/&gt; then [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="mk1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A negative SCORE adjustment (a penalty for a wrong blank) reduces the Canvas total
     * but has no equivalent in an even Cloze split, so the item is left unsupported rather
     * than over-credited.
     *
     * @return void
     */
    public function test_native_cloze_negative_score_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><not><varequal respident="response_b2">b2-0</varequal></not></conditionvar>'
            . '<setvar varname="SCORE" action="Add">-10</setvar></respcondition></resprocessing>';
        $item = '<item ident="ng1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * Even per-blank scores that fall short of the declared SCORE maximum (e.g. two blanks
     * adding 25 each against a max of 100) award only part of the item for a fully-correct
     * response in Canvas, so an even Cloze that awards it in full is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_scores_below_max_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition></resprocessing>';
        $item = '<item ident="sm1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * Even per-blank scores that do sum to the declared SCORE maximum still convert (a
     * full-credit response earns the whole item), including small rounding like 50+50=100.
     *
     * @return void
     */
    public function test_native_cloze_scores_reaching_max_convert(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="sx1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
    }

    /**
     * A setvar that omits varname targets SCORE by QTI default, so unequal additive scores
     * written that way (25 vs 75) are still detected as uneven and the item is left
     * unsupported rather than flattened to an even 1:1 Cloze.
     *
     * @return void
     */
    public function test_native_cloze_default_score_varname_is_honoured(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar action="Add">25</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar action="Add">75</setvar></respcondition></resprocessing>';
        $item = '<item ident="dv1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A positive additive condition tied to no placed blank (e.g. an <other/> bonus) awards
     * Canvas credit an even Cloze cannot reproduce, so the item is left unsupported even when
     * the genuine per-blank conditions are individually even.
     *
     * @return void
     */
    public function test_native_cloze_bonus_condition_without_blank_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><other/></conditionvar>'
            . '<setvar varname="SCORE" action="Add">10</setvar></respcondition></resprocessing>';
        $item = '<item ident="bn1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A nonzero starting SCORE (decvar defaultval) shifts the whole grading scale, so even
     * per-blank additions that reach the maximum no longer represent an even 0..max split;
     * the item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_nonzero_initial_score_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" defaultval="10" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="iv1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * When the SCORE decvar omits maxvalue the maximum falls back to 100 (as the rest of the
     * parser reads it), so even additions that fall short of 100 (25+25) are treated as
     * partial scoring and the item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_undeclared_max_uses_fallback(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition></resprocessing>';
        $item = '<item ident="um1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A condition carrying more than one SCORE update (e.g. Add 50 then Add -10) has a net
     * value the single-value read cannot see, so the item is left unsupported rather than
     * treated as an even scheme built from the first update alone.
     *
     * @return void
     */
    public function test_native_cloze_multiple_score_updates_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar>'
            . '<setvar varname="SCORE" action="Add">-10</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="ms1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A marker surviving strip_tags only because it sits inside inert element content
     * (e.g. <script>[b1]</script>) is not an ordinary rendered-text placeholder, so the
     * blank cannot be placed and the item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_marker_in_script_is_unsupported(): void {
        // Canvas escapes HTML inside a text/html mattext, so the stem's textContent is the
        // literal string "Answer <script>var x = "[b1]";</script> then [b2]." where the only
        // occurrence of [b1] sits inside inert script content.
        $pres = '<presentation><material><mattext texttype="text/html">Answer '
            . '&lt;script&gt;var x = "[b1]";&lt;/script&gt; then [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $item = '<item ident="sc1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A scoring condition that also constrains another blank negatively (a <not> predicate)
     * credits its blank only in combination, which an even Cloze can't reproduce, so the item
     * is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_negated_predicate_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><and><varequal respident="response_b1">b1-0</varequal>'
            . '<not><varequal respident="response_b2">b2-0</varequal></not></and></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="ng1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A non-additive zero SCORE reset (Set 0, typically on a wrong response) can erase credit
     * earlier conditions accrued; a Cloze can't undo credit, so the item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_zero_score_reset_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><other/></conditionvar>'
            . '<setvar varname="SCORE" action="Set">0</setvar></respcondition></resprocessing>';
        $item = '<item ident="zr1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * A blank whose accepted alternatives are scored differently (one spelling adds 50,
     * another 25) can't be an even field — a single field would credit the cheaper spelling
     * in full — so the item is left unsupported.
     *
     * @return void
     */
    public function test_native_cloze_uneven_alternatives_is_unsupported(): void {
        $pres = '<presentation><material><mattext texttype="text/html">A [b1] B [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice>'
            . '<response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">foo</mattext></material></response_label>'
            . '<response_label ident="b1-1" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">fou</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">25</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="ua1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
    }

    /**
     * Answer-specific Canvas feedback (a scoring condition's displayfeedback linked to an
     * itemfeedback body) is carried into the generated Cloze option after a # separator, so
     * authored per-answer feedback survives the conversion.
     *
     * @return void
     */
    public function test_native_cloze_answer_feedback_is_carried(): void {
        $pres = '<presentation><material><mattext texttype="text/html">Fill [b1] and [b2].</mattext></material>'
            . '<response_lid ident="response_b1"><render_choice><response_label ident="b1-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">red</mattext></material></response_label></render_choice></response_lid>'
            . '<response_lid ident="response_b2"><render_choice><response_label ident="b2-0" answer_type="openEntry">'
            . '<material><mattext texttype="text/plain">blue</mattext></material></response_label></render_choice>'
            . '</response_lid></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" maxvalue="100"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar><displayfeedback linkrefid="fb_b1"/></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $fb = '<itemfeedback ident="fb_b1"><material><mattext texttype="text/plain">Correct, well done.</mattext>'
            . '</material></itemfeedback>';
        $item = '<item ident="af1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . $pres . $resp . $fb . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_CLOZE, $q->type);
        // The b1 field carries the feedback after #; the b2 field (no feedback) does not.
        $this->assertStringContainsString('{1:SHORTANSWER:=red#Correct, well done.}', $q->questiontext);
        $this->assertStringContainsString('{1:SHORTANSWER:=blue}', $q->questiontext);
    }

    /**
     * A Canvas fill_in_multiple_blanks_question with three free-text blanks (the shape
     * a real Canvas export uses: [blank] stem placeholders, response_lid per blank with
     * its accepted answers as response_labels, scored by varequal to the label idents).
     *
     * @return string
     */
    private function fib_item(): string {
        $blank = function (string $id, string $ans): string {
            return '<response_lid ident="response_' . $id . '"><material><mattext>' . $ans . '</mattext></material>'
                . '<render_choice><response_label ident="' . $id . '-0">'
                . '<material><mattext texttype="text/plain">' . $ans . '</mattext></material>'
                . '</response_label></render_choice></response_lid>';
        };
        $pres = '<presentation><material><mattext texttype="text/html">'
            . 'Lorem [b1] dolor sit amet, consectetur adipiscing elit [b2] do tempor [b3] aliqua.</mattext></material>'
            . $blank('b1', 'ipsum') . $blank('b2', 'sed') . $blank('b3', 'magna') . '</presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_b1">b1-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">33.33</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b2">b2-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">33.33</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_b3">b3-0</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">33.33</setvar></respcondition></resprocessing>';
        return '<item ident="fib1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';
    }

    /**
     * Inline-dropdown blanks wrapped in a <flow> (a normal QTI presentation
     * wrapper) are still found: map_type counts response_lid descendants and
     * fill_matching traverses the same descendants, so the item converts to a
     * match with one pair per blank rather than an empty (dropped) match.
     *
     * @return void
     */
    public function test_flow_wrapped_dropdown_blanks_are_traversed(): void {
        $blank = function (string $ident, string $stem, string $a1, string $a2): string {
            return '<response_lid ident="' . $ident . '"><material><mattext>' . $stem . '</mattext></material>'
                . '<render_choice>'
                . '<response_label ident="' . $a1 . '"><material><mattext>Sensory</mattext></material></response_label>'
                . '<response_label ident="' . $a2 . '"><material><mattext>Motor</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        $pres = '<presentation><flow><material><mattext>Pick [I] [II].</mattext></material>'
            . $blank('response_I', 'I', 'a1', 'a2') . $blank('response_II', 'II', 'b1', 'b2') . '</flow></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_I">a1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="response_II">b2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="f1" title="Flow"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_dropdowns_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_MATCHING, $q->type);
        $pairs = array_filter($q->subquestions, fn($s) => trim($s['text']) !== '');
        $this->assertCount(2, $pairs);
        $this->assertTrue($q->is_importable());
        // The prompt inside the <flow> is preserved, not dropped.
        $this->assertStringContainsString('Pick', $q->questiontext);
    }

    /**
     * When a <flow> interleaves several prompt fragments around the blanks, every
     * fragment is kept in questiontext — not just the first — so the imported
     * match keeps its full instructions.
     *
     * @return void
     */
    public function test_flow_interleaved_prompt_fragments_are_all_kept(): void {
        $blank = function (string $ident, string $stem, string $a1, string $a2): string {
            return '<response_lid ident="' . $ident . '"><material><mattext>' . $stem . '</mattext></material>'
                . '<render_choice>'
                . '<response_label ident="' . $a1 . '"><material><mattext>One</mattext></material></response_label>'
                . '<response_label ident="' . $a2 . '"><material><mattext>Two</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        $pres = '<presentation><flow>'
            . '<material><mattext>Start fragment.</mattext></material>'
            . $blank('rA', 'A', 'a1', 'a2')
            . '<material><mattext>Middle fragment.</mattext></material>'
            . $blank('rB', 'B', 'b1', 'b2')
            . '<material><mattext>End fragment.</mattext></material>'
            . '</flow></presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="rA">a1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="rB">b2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="i1" title="Interleaved"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_dropdowns_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_MATCHING, $q->type);
        // Every prompt fragment is kept, not just the first.
        $this->assertStringContainsString('Start fragment.', $q->questiontext);
        $this->assertStringContainsString('Middle fragment.', $q->questiontext);
        $this->assertStringContainsString('End fragment.', $q->questiontext);
    }

    /**
     * A dropdown/blank whose response_lids carry only their render_choice (no
     * per-blank stem material — the blanks are labelled by bracketed reference
     * words in the prompt) would convert to a match with empty stems, which the
     * importer drops. It is left unsupported instead.
     *
     * @return void
     */
    public function test_dropdown_without_stems_is_unsupported(): void {
        $blank = function (string $ident, string $a1, string $a2): string {
            return '<response_lid ident="' . $ident . '"><render_choice>'
                . '<response_label ident="' . $a1 . '"><material><mattext>Red</mattext></material></response_label>'
                . '<response_label ident="' . $a2 . '"><material><mattext>Blue</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        $pres = '<presentation><material><mattext>The [c1] and [c2].</mattext></material>'
            . $blank('response_c1', 'a1', 'a2') . $blank('response_c2', 'b1', 'b2') . '</presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_c1">a1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="r1" title="Refs"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_dropdowns_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A dropdown/blank item whose blanks offer different choice sets must NOT be
     * converted to a Moodle match (whose single global pool would offer one
     * blank's options for another); it stays unsupported, reported by Canvas type.
     *
     * @return void
     */
    public function test_dropdown_with_unshared_choices_is_unsupported(): void {
        $blank = function (string $ident, string $stem, string $o1, string $o2): string {
            return '<response_lid ident="' . $ident . '"><material><mattext>' . $stem . '</mattext></material>'
                . '<render_choice>'
                . '<response_label ident="x1"><material><mattext>' . $o1 . '</mattext></material></response_label>'
                . '<response_label ident="x2"><material><mattext>' . $o2 . '</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        // Blank one offers colours, blank two offers animals — different pools.
        $pres = '<presentation><material><mattext>Match [I] [II].</mattext></material>'
            . $blank('response_I', 'I', 'Red', 'Blue') . $blank('response_II', 'II', 'Cat', 'Dog') . '</presentation>';
        $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_I">x1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
        $item = '<item ident="u1" title="Unshared"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_dropdowns_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertSame('multiple_dropdowns_question', $q->profile);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A dropdown/blank question with a single blank is one choice, not a match:
     * it has only one response_lid, so it falls through to the cardinality
     * fallback and imports as multiple choice (not a one-pair match, which Moodle
     * would drop).
     *
     * @return void
     */
    public function test_single_blank_dropdown_imports_as_multichoice(): void {
        foreach (['multiple_dropdowns_question', 'fill_in_multiple_blanks_question'] as $type) {
            $pres = '<presentation><material><mattext texttype="text/html">Pick for [I].</mattext></material>'
                . '<response_lid ident="response_I"><material><mattext>I</mattext></material><render_choice>'
                . '<response_label ident="a1"><material><mattext texttype="text/plain">Sensory</mattext></material>'
                . '</response_label>'
                . '<response_label ident="a2"><material><mattext texttype="text/plain">Motor</mattext></material>'
                . '</response_label></render_choice></response_lid></presentation>';
            $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
                . '<respcondition><conditionvar><varequal respident="response_I">a1</varequal></conditionvar>'
                . '<setvar varname="SCORE" action="Add">100</setvar></respcondition></resprocessing>';
            $item = '<item ident="d1" title="One"><itemmetadata><qtimetadata>'
                . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>' . $type . '</fieldentry>'
                . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

            $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

            if ($type === 'fill_in_multiple_blanks_question') {
                // A single fill-in-the-blank is a short answer: the student types
                // the word, and every listed answer is accepted.
                $this->assertSame(qti_question::TYPE_SHORTANSWER, $q->type, "$type with one blank should be shortanswer");
            } else {
                // A single dropdown is a pick-from-list multiple choice.
                $this->assertSame(qti_question::TYPE_MULTICHOICE, $q->type, "$type with one blank should be multichoice");
            }
            $this->assertCount(2, $q->answers);
            $this->assertTrue($q->is_importable(), "$type with one blank should import");
        }
    }

    /**
     * A free-text fill_in_multiple_blanks_question (response_str, no
     * render_choice) has no choices to match, so it stays UNSUPPORTED and is
     * reported by its Canvas type name rather than mis-imported as an empty match.
     *
     * @return void
     */
    public function test_free_text_fill_in_blanks_stays_unsupported(): void {
        $item = '<item ident="fb1" title="Blank"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel>'
            . '<fieldentry>fill_in_multiple_blanks_question</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>The [a] bone connects to the [b] bone.</mattext></material>'
            . '<response_str ident="response_a"><render_fib/></response_str>'
            . '<response_str ident="response_b"><render_fib/></response_str></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="response_a">hip</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing></item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertSame('fill_in_multiple_blanks_question', $q->profile);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A recognised-but-unconvertible Canvas type (e.g. file_upload_question) is
     * left UNSUPPORTED and reported by its Canvas type name, rather than being
     * coerced into a wrong Moodle type by the cardinality fallback.
     *
     * @return void
     */
    public function test_native_unconvertible_type_stays_unsupported_but_named(): void {
        $item = '<item ident="f1" title="Upload"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>file_upload_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Upload your work</mattext></material></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes></resprocessing></item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertSame('file_upload_question', $q->profile);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A Canvas numerical_question with an exact <varequal> answer becomes a Moodle
     * numerical question with a zero-tolerance answer.
     *
     * @return void
     */
    public function test_native_numerical_exact_answer_converts(): void {
        $item = $this->numerical_item(
            '<or><varequal respident="response1">42</varequal></or>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_NUMERICAL, $q->type);
        $this->assertSame('numerical_question', $q->profile);
        $this->assertTrue($q->is_importable());
        $this->assertCount(1, $q->answers);
        $this->assertSame('42', $q->answers[0]['text']);
        $this->assertSame('0', $q->answers[0]['tolerance']);
        $this->assertSame(100.0, $q->answers[0]['fraction']);
    }

    /**
     * A Canvas numerical_question whose accepted answer is a <vargte>/<varlte>
     * range becomes a numerical answer of the midpoint with a half-width tolerance,
     * and the equivalent <varequal> in the same <or> does not double the answer.
     *
     * @return void
     */
    public function test_native_numerical_range_answer_converts(): void {
        $item = $this->numerical_item(
            '<or><varequal respident="response1">42</varequal>'
            . '<and><vargte respident="response1">41.5</vargte>'
            . '<varlte respident="response1">42.5</varlte></and></or>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_NUMERICAL, $q->type);
        $this->assertCount(1, $q->answers);
        $this->assertSame('42', $q->answers[0]['text']);
        $this->assertSame('0.5', $q->answers[0]['tolerance']);
    }

    /**
     * A numerical_question with no scoring condition (Canvas exported no answer)
     * stays a numerical question but is not importable, so it is dropped rather
     * than saved as an unanswerable question.
     *
     * @return void
     */
    public function test_native_numerical_without_answer_is_unimportable(): void {
        $item = '<item ident="n0" title="How many"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>numerical_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>How many bones?</mattext></material>'
            . '<response_str ident="response1"><render_fib fibtype="Decimal"/></response_str></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes></resprocessing></item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_NUMERICAL, $q->type);
        $this->assertSame([], $q->answers);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A numerical question whose exact answer has more than eight decimal places is
     * preserved verbatim, not truncated by a float round-trip (which would change the
     * accepted value and grade the question wrongly).
     *
     * @return void
     */
    public function test_native_numerical_preserves_exact_precision(): void {
        $item = $this->numerical_item('<or><varequal respident="response1">0.000000001</varequal></or>');

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertSame('0.000000001', $q->answers[0]['text']);
        $this->assertSame('0', $q->answers[0]['tolerance']);
    }

    /**
     * A high-precision <vargte>/<varlte> range keeps its exact midpoint and half-width
     * (computed without float truncation).
     *
     * @return void
     */
    public function test_native_numerical_range_precision_is_exact(): void {
        $item = $this->numerical_item(
            '<and><vargte respident="response1">0.12345</vargte>'
            . '<varlte respident="response1">0.12347</varlte></and>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertSame('0.12346', $q->answers[0]['text']);
        $this->assertSame('0.00001', $q->answers[0]['tolerance']);
    }

    /**
     * Scientific-notation endpoints are expanded to plain decimals (no float
     * round-trip), so both an exact exponent value and an exponent-form range keep
     * full precision instead of collapsing to zero.
     *
     * @return void
     */
    public function test_native_numerical_scientific_notation_is_expanded(): void {
        $exact = (new qti_parser())->parse($this->assessment(
            $this->numerical_item('<or><varequal respident="response1">1.5e-9</varequal></or>')
        ))['questions'][0];
        $this->assertSame('0.0000000015', $exact->answers[0]['text']);
        $this->assertSame('0', $exact->answers[0]['tolerance']);

        $range = (new qti_parser())->parse($this->assessment($this->numerical_item(
            '<and><vargte respident="response1">1e-9</vargte><varlte respident="response1">3e-9</varlte></and>'
        )))['questions'][0];
        $this->assertSame('0.000000002', $range->answers[0]['text']);
        $this->assertSame('0.000000001', $range->answers[0]['tolerance']);

        // A small exponent well beyond a few dozen places is still cheap to expand and
        // must be kept, not rejected as if it were an abusive value.
        $tiny = (new qti_parser())->parse($this->assessment(
            $this->numerical_item('<or><varequal respident="response1">1e-66</varequal></or>')
        ))['questions'][0];
        $this->assertSame('0.' . str_repeat('0', 65) . '1', $tiny->answers[0]['text']);
        $this->assertTrue($tiny->is_importable());
    }

    /**
     * A range far beyond 64-bit integer range keeps full precision through the
     * decimal-string arithmetic (no float truncation, no overflow).
     *
     * @return void
     */
    public function test_native_numerical_large_range_is_exact(): void {
        $item = $this->numerical_item(
            '<and><vargte respident="response1">100000000000000001</vargte>'
            . '<varlte respident="response1">100000000000000003</varlte></and>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertSame('100000000000000002', $q->answers[0]['text']);
        $this->assertSame('1', $q->answers[0]['tolerance']);
    }

    /**
     * A numerical answer with an absurd exponent (which would otherwise pad on a huge
     * string) is skipped rather than exhausting memory, so one malformed answer never
     * crashes the import.
     *
     * @return void
     */
    public function test_native_numerical_extreme_exponent_is_skipped(): void {
        $item = $this->numerical_item('<or><varequal respident="response1">1e-9999999999</varequal></or>');

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_NUMERICAL, $q->type);
        $this->assertSame([], $q->answers);
        $this->assertFalse($q->is_importable());
    }

    /**
     * Answer-specific feedback linked from a numerical scoring condition via
     * <displayfeedback> is carried onto the imported answer.
     *
     * @return void
     */
    public function test_native_numerical_answer_feedback_preserved(): void {
        $item = $this->numerical_item(
            '<or><varequal respident="response1">42</varequal></or>',
            '<displayfeedback feedbacktype="Response" linkrefid="correct_fb"/>',
            '<itemfeedback ident="correct_fb"><flow_mat><material>'
            . '<mattext texttype="text/html">Spot on</mattext></material></flow_mat></itemfeedback>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertStringContainsString('Spot on', $q->answers[0]['feedback']);
    }

    /**
     * A numerical item scored against a non-100 SCORE maximum still awards full credit:
     * the condition's score is scaled onto Moodle's 0–100 fraction by the declared max.
     *
     * @return void
     */
    public function test_native_numerical_scales_score_by_outcome_max(): void {
        $item = '<item ident="n1" title="Answer"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>numerical_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>The answer?</mattext></material></presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="1" minvalue="0" varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar>'
            . '<or><varequal respident="response1">42</varequal></or>'
            . '</conditionvar><setvar action="Set" varname="SCORE">1</setvar></respcondition></resprocessing></item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertCount(1, $q->answers);
        $this->assertSame(100.0, $q->answers[0]['fraction']);
    }

    /**
     * An <or> that offers a distinct exact value alongside a range keeps both accepted
     * answers, rather than dropping the exact alternative in favour of the range.
     *
     * @return void
     */
    public function test_native_numerical_keeps_distinct_or_alternatives(): void {
        $item = $this->numerical_item(
            '<or><varequal respident="response1">10</varequal>'
            . '<and><vargte respident="response1">20</vargte>'
            . '<varlte respident="response1">30</varlte></and></or>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $answers = array_map(fn($a) => $a['text'] . '±' . $a['tolerance'], $q->answers);
        $this->assertContains('25±5', $answers);
        $this->assertContains('10±0', $answers);
        $this->assertCount(2, $q->answers);
    }

    /**
     * An inverted range (lower bound above upper) can never match, so it is skipped
     * rather than turned into a valid accepted interval.
     *
     * @return void
     */
    public function test_native_numerical_inverted_range_is_skipped(): void {
        $item = $this->numerical_item(
            '<and><vargte respident="response1">10</vargte><varlte respident="response1">4</varlte></and>'
        );

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame([], $q->answers);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A Canvas calculated_question becomes a Moodle calculated question: the formula
     * and stem variable references are rewritten to {var} wildcards, each variable is
     * captured with its range, and each var_set becomes an aligned data row.
     *
     * @return void
     */
    public function test_native_calculated_converts(): void {
        $q = (new qti_parser())->parse($this->assessment($this->calculated_item()))['questions'][0];

        $this->assertSame(qti_question::TYPE_CALCULATED, $q->type);
        $this->assertSame('calculated_question', $q->profile);
        $this->assertTrue($q->is_importable());
        $this->assertSame('{a}+{b}', $q->formula);
        $this->assertStringContainsString('{a} + {b}', $q->questiontext);
        $this->assertSame('absolute', $q->tolerancekind);
        $this->assertSame('0', $q->answertolerance);
        $this->assertSame(
            [
                ['name' => 'a', 'min' => '1', 'max' => '3', 'decimals' => 0],
                ['name' => 'b', 'min' => '1', 'max' => '3', 'decimals' => 0],
            ],
            $q->variables
        );
        $this->assertSame([['a' => '2', 'b' => '3'], ['a' => '1', 'b' => '1']], $q->datarows);
    }

    /**
     * A calculated question with a percent margin keeps the tolerance kind so the
     * writer can map it to Moodle's relative tolerance type.
     *
     * @return void
     */
    public function test_native_calculated_percent_tolerance(): void {
        $calc = '<answer_tolerance margin_type="percent">5</answer_tolerance>'
            . '<formulas decimal_places="2"><formula>a+b</formula></formulas>'
            . '<vars><var name="a" scale="1"><min>1</min><max>3</max></var>'
            . '<var name="b" scale="1"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><var name="b">3</var><answer>5</answer></var_set>'
            . '</var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame('percent', $q->tolerancekind);
        $this->assertSame('5', $q->answertolerance);
        $this->assertSame(2, $q->answerdecimals);
        $this->assertSame(1, $q->variables[0]['decimals']);
    }

    /**
     * Canvas writes exponentiation with a caret (a^2); Moodle spells it **, so the
     * translated formula uses ** and the question stays importable.
     *
     * @return void
     */
    public function test_native_calculated_translates_caret_exponent(): void {
        $calc = '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places="0"><formula>a^2+b</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var>'
            . '<var name="b" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><var name="b">3</var><answer>7</answer></var_set>'
            . '</var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame('{a}**2+{b}', $q->formula);
        $this->assertTrue($q->is_importable());
    }

    /**
     * Canvas's base-10 log and natural ln are translated to Moodle's log10 and log, so
     * the graded value matches Canvas rather than being silently mis-computed.
     *
     * @return void
     */
    public function test_native_calculated_translates_logarithms(): void {
        $calc = '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places="0"><formula>log(a)+ln(a)</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">100</var><answer>2</answer></var_set></var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame('log10({a})+log({a})', $q->formula);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A scientific-notation literal (1e-3) is a valid Moodle formula constant, so a
     * calculated question that uses one stays importable rather than being rejected by
     * the formula-support check mistaking the exponent 'e' for a function.
     *
     * @return void
     */
    public function test_native_calculated_accepts_scientific_notation(): void {
        $calc = '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places="0"><formula>a*1e-3</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><answer>0</answer></var_set></var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame('{a}*1e-3', $q->formula);
        $this->assertTrue($q->is_importable());
    }

    /**
     * A data row that omits a declared variable cannot reconstruct Canvas's value tuple,
     * so the calculated question is treated as not importable rather than emitting an
     * empty dataset value.
     *
     * @return void
     */
    public function test_native_calculated_rejects_incomplete_rows(): void {
        $calc = '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places="0"><formula>a+b</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var>'
            . '<var name="b" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><answer>2</answer></var_set></var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame([['a' => '2']], $q->datarows);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A calculated formula using a function Moodle's calculated grammar does not accept
     * (here factorial) would be rejected by qformat_xml and roll back the whole bank, so
     * the question is treated as not importable and dropped instead.
     *
     * @return void
     */
    public function test_native_calculated_rejects_unsupported_formula(): void {
        $calc = '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places="0"><formula>factorial(a)</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><answer>2</answer></var_set></var_sets>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame(qti_question::TYPE_CALCULATED, $q->type);
        $this->assertSame('factorial({a})', $q->formula);
        $this->assertFalse($q->is_importable());
    }

    /**
     * A calculated question that carries no generated value rows cannot build a
     * variant, so it stays a calculated question but is not importable.
     *
     * @return void
     */
    public function test_native_calculated_without_rows_is_unimportable(): void {
        $calc = '<formulas decimal_places="0"><formula>a+b</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var>'
            . '<var name="b" scale="0"><min>1</min><max>3</max></var></vars>';

        $q = (new qti_parser())->parse($this->assessment($this->calculated_item($calc)))['questions'][0];

        $this->assertSame(qti_question::TYPE_CALCULATED, $q->type);
        $this->assertSame([], $q->datarows);
        $this->assertFalse($q->is_importable());
    }

    /**
     * Square-bracket variable references in the stem (Canvas's other delimiter) are
     * rewritten to {var} wildcards too, while bare prose is left untouched.
     *
     * @return void
     */
    public function test_native_calculated_bracket_stem_templatised(): void {
        $stem = '&lt;p&gt;A rectangle [a] wide leaves a alone.&lt;/p&gt;';
        $q = (new qti_parser())->parse($this->assessment($this->calculated_item(null, $stem)))['questions'][0];

        $this->assertStringContainsString('{a} wide leaves a alone', $q->questiontext);
    }

    /**
     * A native Canvas calculated_question over variables a and b, formula "a+b", with
     * two pre-generated rows. Pass a replacement <calculated> body or stem to vary it.
     *
     * @param string|null $calc The inner <calculated> body, or null for the default.
     * @param string|null $stem The presentation stem HTML (entity-encoded), or null for the default.
     * @return string
     */
    private function calculated_item(?string $calc = null, ?string $stem = null): string {
        // Canvas delimits an inline variable with a backtick; build it via chr(96) so
        // the fixture avoids a literal backtick (moodle.Strings.ForbiddenStrings).
        $tick = chr(96);
        $stem ??= '&lt;p&gt;What is ' . $tick . 'a' . $tick . ' + ' . $tick . 'b' . $tick . '?&lt;/p&gt;';
        $calc ??= '<answer_tolerance margin_type="absolute">0</answer_tolerance>'
            . '<formulas decimal_places=""><formula>a+b</formula></formulas>'
            . '<vars><var name="a" scale="0"><min>1</min><max>3</max></var>'
            . '<var name="b" scale="0"><min>1</min><max>3</max></var></vars>'
            . '<var_sets><var_set ident="s1"><var name="a">2</var><var name="b">3</var><answer>5</answer></var_set>'
            . '<var_set ident="s2"><var name="a">1</var><var name="b">1</var><answer>2</answer></var_set></var_sets>';
        return '<item ident="c1" title="Sum"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>calculated_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">' . $stem . '</mattext></material></presentation>'
            . '<itemproc_extension><calculated>' . $calc . '</calculated></itemproc_extension></item>';
    }

    /**
     * A native Canvas numerical_question item whose accepted answer is a scoring
     * condition var block.
     *
     * @param string $conditionvar The <conditionvar> body.
     * @param string $displayfeedback Optional <displayfeedback> node for the condition.
     * @param string $itemfeedback Optional trailing <itemfeedback> node.
     * @return string
     */
    private function numerical_item(string $conditionvar, string $displayfeedback = '', string $itemfeedback = ''): string {
        return '<item ident="n1" title="Answer"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>numerical_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>The answer?</mattext></material>'
            . '<response_str ident="response1" rcardinality="Single"><render_fib fibtype="Decimal">'
            . '<response_label ident="answer1"/></render_fib></response_str></presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar>' . $conditionvar . '</conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar>' . $displayfeedback . '</respcondition>'
            . '</resprocessing>' . $itemfeedback . '</item>';
    }

    /**
     * Collapse an HTML fragment to plain single-line text (test helper).
     *
     * @param string $html The HTML.
     * @return string
     */
    private function plain(string $html): string {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))));
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

        // A stray bare <item> with no <assessment>/<section> is not a shell,
        // even though it bumps the unresolved count.
        $stray = '<?xml version="1.0"?><questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<item ident="orphan"/></questestinterop>';
        $r = (new qti_parser())->parse($stray);
        $this->assertFalse($r['hasassessment']);
        $this->assertSame(1, $r['unresolved']);
    }

    /**
     * A Canvas New Quiz that draws questions from an item bank is captured as a
     * selection: the bank id, how many to draw and the per-question points. A plain
     * assessment reports no selections.
     *
     * @return void
     */
    public function test_captures_item_bank_selections(): void {
        $xml = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="q1" title="New Quiz"><section ident="root_section">'
            . '<section ident="grp" title="Group"><selection_ordering><selection>'
            . '<sourcebank_ref>gbank42</sourcebank_ref><selection_number>25</selection_number>'
            . '<selection_extension><points_per_item>2.5</points_per_item></selection_extension>'
            . '</selection></selection_ordering></section>'
            . '</section></assessment></questestinterop>';
        $r = (new qti_parser())->parse($xml);

        $this->assertSame([], $r['questions']);
        $this->assertTrue($r['hasassessment']);
        $this->assertSame(
            [['bank' => 'gbank42', 'count' => 25, 'points' => 2.5, 'hasfilter' => false]],
            $r['selections']
        );

        // A selection without a sourcebank_ref is not a bank draw and is ignored.
        $this->assertSame([], (new qti_parser())->parse($this->assessment(''))['selections']);

        // A missing selection_number is kept null (draw all); an explicit 0 stays 0
        // (an authored empty draw), so the two are not conflated.
        $nonum = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="q2" title="Q"><section ident="root_section"><section ident="g">'
            . '<selection_ordering><selection><sourcebank_ref>b1</sourcebank_ref></selection>'
            . '<selection><sourcebank_ref>b2</sourcebank_ref><selection_number>0</selection_number></selection>'
            . '</selection_ordering></section></section></assessment></questestinterop>';
        $this->assertSame(
            [
                ['bank' => 'b1', 'count' => null, 'points' => null, 'hasfilter' => false],
                ['bank' => 'b2', 'count' => 0, 'points' => null, 'hasfilter' => false],
            ],
            (new qti_parser())->parse($nonum)['selections']
        );
    }

    /**
     * A <selection_metadata> filter on a bank draw is flagged (hasfilter true) so the
     * builder can treat the draw as not faithfully reproducible rather than drawing the
     * whole bank.
     *
     * @return void
     */
    public function test_selection_metadata_filter_is_flagged(): void {
        $xml = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="q3" title="Q"><section ident="root_section"><section ident="g">'
            . '<selection_ordering><selection><sourcebank_ref>b9</sourcebank_ref>'
            . '<selection_number>3</selection_number>'
            . '<selection_metadata>{"filter":{"tags":["unit1"]}}</selection_metadata>'
            . '</selection></selection_ordering></section></section></assessment></questestinterop>';
        $this->assertSame(
            [['bank' => 'b9', 'count' => 3, 'points' => null, 'hasfilter' => true]],
            (new qti_parser())->parse($xml)['selections']
        );
    }

    /**
     * The parser records the authored order of inline items and bank-selection groups in
     * 'sequence', interleaving a selection that sits between two inline items so the
     * builder can add quiz slots in Canvas order rather than inline-first.
     *
     * @return void
     */
    public function test_captures_authored_sequence(): void {
        $item = function (string $id): string {
            return '<item ident="' . $id . '"><presentation><material>'
                . '<mattext texttype="text/plain">Q ' . $id . '</mattext></material>'
                . '<response_lid ident="r"><render_choice>'
                . '<response_label ident="a"><material><mattext>A</mattext></material></response_label>'
                . '<response_label ident="b"><material><mattext>B</mattext></material></response_label>'
                . '</render_choice></response_lid></presentation>'
                . '<resprocessing><respcondition><conditionvar><varequal respident="r">a</varequal>'
                . '</conditionvar><setvar varname="SCORE" action="Set">100</setvar></respcondition>'
                . '</resprocessing></item>';
        };
        $sel = '<section ident="grp"><selection_ordering><selection>'
            . '<sourcebank_ref>bx</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>';
        $xml = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="q4" title="Q"><section ident="root_section">'
            . $item('one') . $sel . $item('two')
            . '</section></assessment></questestinterop>';

        $r = (new qti_parser())->parse($xml);

        $this->assertCount(2, $r['questions']);
        $this->assertCount(1, $r['selections']);
        // Authored order: inline #0, then the bank draw, then inline #1.
        $this->assertSame(
            [
                ['kind' => 'inline', 'index' => 0],
                ['kind' => 'selection', 'index' => 0],
                ['kind' => 'inline', 'index' => 1],
            ],
            $r['sequence']
        );
    }
}
