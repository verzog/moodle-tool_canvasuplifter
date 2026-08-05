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
        // The scored value is the option text "False", not the ident "A2".
        $resp = '<resprocessing><outcomes><decvar varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition title="Correct"><conditionvar><varequal respident="RL">False</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="RL">True</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">0</setvar></respcondition></resprocessing>';

        $r = (new qti_parser())->parse($this->assessment($this->item('cc.true_false.v0p1', $pres, $resp)));

        $q = $r['questions'][0];
        $this->assertSame(qti_question::TYPE_TRUEFALSE, $q->type);
        $this->assertTrue($q->is_importable(), 'the text-scored true/false should be importable');
        $bytext = [];
        foreach ($q->answers as $a) {
            $bytext[trim($a['text'])] = $a['fraction'];
        }
        $this->assertSame(100.0, $bytext['False']);
        $this->assertSame(0.0, $bytext['True']);
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
     * Canvas's inline-choice "cloze" types (multiple_dropdowns_question and the
     * choice form of fill_in_multiple_blanks_question) are authored as one
     * response_lid + render_choice per blank, structurally a matching question,
     * so they import as a Moodle match (one stem/answer pair per blank).
     *
     * @return void
     */
    public function test_native_dropdowns_and_blanks_become_matching(): void {
        $blank = function (string $ident, string $stem, string $a1, string $a2): string {
            return '<response_lid ident="' . $ident . '"><material><mattext>' . $stem . '</mattext></material>'
                . '<render_choice>'
                . '<response_label ident="' . $a1 . '"><material><mattext texttype="text/plain">Sensory</mattext>'
                . '</material></response_label>'
                . '<response_label ident="' . $a2 . '"><material><mattext texttype="text/plain">Motor</mattext>'
                . '</material></response_label></render_choice></response_lid>';
        };
        foreach (['multiple_dropdowns_question', 'fill_in_multiple_blanks_question'] as $type) {
            $pres = '<presentation><material><mattext texttype="text/html">Pick for each [I] [II].</mattext></material>'
                . $blank('response_I', 'I', 'a1', 'a2') . $blank('response_II', 'II', 'b1', 'b2') . '</presentation>';
            $resp = '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
                . '<respcondition><conditionvar><varequal respident="response_I">a1</varequal></conditionvar>'
                . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
                . '<respcondition><conditionvar><varequal respident="response_II">b2</varequal></conditionvar>'
                . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing>';
            $item = '<item ident="c1" title="Cloze"><itemmetadata><qtimetadata>'
                . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>' . $type . '</fieldentry>'
                . '</qtimetadatafield></qtimetadata></itemmetadata>' . $pres . $resp . '</item>';

            $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

            $this->assertSame(qti_question::TYPE_MATCHING, $q->type, "$type should map to matching");
            $pairs = [];
            foreach ($q->subquestions as $sub) {
                if (trim($sub['text']) !== '') {
                    $pairs[$this->plain($sub['text'])] = $sub['answer'];
                }
            }
            $this->assertSame('Sensory', $pairs['I']);
            $this->assertSame('Motor', $pairs['II']);
            $this->assertTrue($q->is_importable(), "$type should be importable");
        }
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
     * A recognised-but-unconvertible Canvas type (e.g. numerical_question) is
     * left UNSUPPORTED and reported by its Canvas type name, rather than being
     * coerced into a wrong Moodle type by the cardinality fallback.
     *
     * @return void
     */
    public function test_native_numerical_stays_unsupported_but_named(): void {
        $item = '<item ident="n1" title="How many"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>numerical_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>How many bones?</mattext></material>'
            . '<response_str ident="r1"><render_fib/></response_str></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes></resprocessing></item>';

        $q = (new qti_parser())->parse($this->assessment($item))['questions'][0];

        $this->assertSame(qti_question::TYPE_UNSUPPORTED, $q->type);
        $this->assertSame('numerical_question', $q->profile);
        $this->assertFalse($q->is_importable());
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
}
