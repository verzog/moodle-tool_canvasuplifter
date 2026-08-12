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

namespace tool_canvasuplifter\local\parser;

use DOMDocument;
use DOMElement;
use DOMNode;
use tool_canvasuplifter\local\model\qti_question;

/**
 * Parses a Canvas/Common Cartridge QTI 1.2 assessment into question models.
 *
 * Canvas exports the standard CC item profiles: multiple choice, multiple
 * response, true/false, fill-in-blank and essay. Each item carries its profile
 * in itemmetadata, its prompt in presentation/material, its options in
 * response_lid/render_choice, and its scoring in resprocessing. This class is
 * Moodle-free so it can be unit-tested directly from QTI strings.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qti_parser {
    /**
     * Parse an assessment XML document.
     *
     * The 'unresolved' count is how many items are bare references — an ident
     * with no presentation, i.e. a question whose body Canvas did not export
     * into the package (common with New Quizzes). It lets callers tell genuine
     * data loss apart from a truly empty assessment.
     *
     * The 'hasassessment' flag is true only when the document is a readable
     * QTI 1.2 assessment (a <assessment> with a <section>), even when it carries
     * no questions. It lets callers distinguish a genuine but empty Canvas shell
     * from a file the parser cannot read (malformed XML, or QTI 2.x/3.x), which
     * also yields no questions but is a conversion failure, not a shell.
     *
     * The 'selections' list captures each <selection_ordering>/<selection> group a
     * Canvas New Quiz uses to draw questions from a separate item bank rather than
     * inlining them: the referenced bank id (sourcebank_ref), how many questions to
     * draw (selection_number) and the per-question points. It lets the quiz builder
     * populate such a quiz from the imported bank instead of leaving a placeholder.
     *
     * @param string $xml The QTI assessment document.
     * @return array{title: string, questions: array, unresolved: int, hasassessment: bool, selections: array}
     *         Parsed assessment.
     */
    public function parse(string $xml): array {
        $result = ['title' => '', 'questions' => [], 'unresolved' => 0, 'hasassessment' => false, 'selections' => []];
        if (trim($xml) === '') {
            return $result;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $result;
        }

        $assessments = $dom->getElementsByTagNameNS('*', 'assessment');
        if ($assessments->length > 0 && $assessments->item(0) instanceof DOMElement) {
            $result['title'] = trim($assessments->item(0)->getAttribute('title'));
            // A QTI 1.2 <assessment> with a <section> is a valid assessment even
            // when empty. QTI 2.x/3.x use different element names (assessmentTest
            // / assessmentSection), so neither matches here.
            $result['hasassessment'] = $dom->getElementsByTagNameNS('*', 'section')->length > 0;
        }
        // A native item bank is rooted at <objectbank> and carries its name in a
        // bank_title metadata field rather than a title attribute; use it so each
        // imported bank keeps its Canvas name.
        if ($result['title'] === '') {
            $banks = $dom->getElementsByTagNameNS('*', 'objectbank');
            if ($banks->length > 0 && $banks->item(0) instanceof DOMElement) {
                $result['title'] = $this->metadata_field($banks->item(0), 'bank_title');
            }
        }

        foreach ($dom->getElementsByTagNameNS('*', 'item') as $itemnode) {
            if (!($itemnode instanceof DOMElement)) {
                continue;
            }
            // A bare reference (an ident with no presentation) points at a
            // question whose body is not in this file; count it so callers can
            // report missing content rather than an empty assessment.
            if ($this->first_child_element($itemnode, 'presentation') === null) {
                if ($itemnode->getAttribute('ident') !== '') {
                    $result['unresolved']++;
                }
                continue;
            }
            $result['questions'][] = $this->build_question($itemnode);
        }

        // Capture any item-bank draws (Canvas New Quizzes). A <selection> without a
        // sourcebank_ref isn't a bank draw, so it's skipped.
        foreach ($dom->getElementsByTagNameNS('*', 'selection') as $selnode) {
            if (!($selnode instanceof DOMElement)) {
                continue;
            }
            $bank = $this->descendant_text($selnode, 'sourcebank_ref');
            if ($bank === '') {
                continue;
            }
            // Keep a missing selection_number (null) distinct from an explicit value,
            // including a genuine 0 — an authored empty draw is not the same as "the
            // exporter omitted the count", which the builder reads as "draw all".
            $rawcount = $this->descendant_text($selnode, 'selection_number');
            $points = $this->descendant_text($selnode, 'points_per_item');
            $result['selections'][] = [
                'bank' => $bank,
                'count' => $rawcount !== '' ? max(0, (int) $rawcount) : null,
                'points' => $points !== '' ? (float) $points : null,
            ];
        }
        return $result;
    }

    /**
     * The trimmed text of the first descendant element with the given local name,
     * or '' when there is none. Namespace-agnostic, matching how the rest of the
     * parser reads Canvas's mixed-namespace QTI.
     *
     * @param \DOMElement $node The element to search within.
     * @param string $localname The element local name to find.
     * @return string
     */
    private function descendant_text(\DOMElement $node, string $localname): string {
        foreach ($node->getElementsByTagNameNS('*', $localname) as $child) {
            return trim($child->textContent);
        }
        return '';
    }

    /**
     * Build a question model from a QTI <item> element.
     *
     * @param DOMElement $item The item element.
     * @return qti_question
     */
    protected function build_question(DOMElement $item): qti_question {
        $question = new qti_question();
        // Common Cartridge items carry cc_profile; Canvas's native .xml.qti dump
        // (the non_cc_assessments/ files, used when the CC shell is empty) labels
        // each item with question_type instead. Read both and remember whichever
        // is present so the support report can name the question by its kind.
        $ccprofile = $this->metadata_field($item, 'cc_profile');
        $canvastype = $this->metadata_field($item, 'question_type');
        $question->profile = $ccprofile !== '' ? $ccprofile : $canvastype;
        // CC stores the weight in cc_weighting; Canvas native uses points_possible.
        $weight = $this->metadata_field($item, 'cc_weighting');
        if ($weight === '') {
            $weight = $this->metadata_field($item, 'points_possible');
        }
        $question->defaultmark = max(0.0, (float) ($weight ?: 1) ?: 1.0);

        $presentation = $this->first_child_element($item, 'presentation');
        $question->questiontext = $presentation !== null ? $this->prompt_text($presentation) : '';
        $question->name = $this->derive_name($item, $question->questiontext);
        $question->generalfeedback = $this->feedback_text($item, 'general_fb');

        $type = $this->map_type($ccprofile, $canvastype, $presentation);
        $question->type = $type;

        switch ($type) {
            case qti_question::TYPE_MULTICHOICE:
            case qti_question::TYPE_MULTIANSWER:
            case qti_question::TYPE_TRUEFALSE:
                $this->fill_choice_answers($item, $presentation, $question);
                $this->recover_acknowledgment($question);
                break;
            case qti_question::TYPE_SHORTANSWER:
                $this->fill_text_answers($item, $presentation, $question);
                if ($canvastype === 'fill_in_multiple_blanks_question') {
                    // Drop Canvas's bracketed blank marker ([blank1_1]) from the
                    // prompt so the short-answer text reads cleanly.
                    $question->questiontext = trim((string) preg_replace(
                        '/\[[a-z0-9_]+\]/',
                        '',
                        $question->questiontext
                    ));
                }
                break;
            case qti_question::TYPE_MATCHING:
                $this->fill_matching($item, $presentation, $question);
                break;
            case qti_question::TYPE_NUMERICAL:
                $this->fill_numerical_answers($item, $question);
                break;
            case qti_question::TYPE_CALCULATED:
                $this->fill_calculated($item, $question);
                break;
            case qti_question::TYPE_CLOZE:
                $this->fill_cloze($item, $presentation, $question);
                break;
            case qti_question::TYPE_ESSAY:
            default:
                break;
        }
        return $question;
    }

    /**
     * Map a Canvas/CC item to one of our question types.
     *
     * The CC profile (cc_profile) is authoritative when present. Canvas's native
     * .xml.qti dump omits it and labels the kind with question_type instead, so
     * that is mapped explicitly rather than left to the cardinality fallback —
     * which alone cannot tell a matching question (many response_lid groups) from
     * a single multiple choice, nor a recognised-but-unconvertible type (e.g.
     * numerical) from one we support. The cardinality fallback still catches
     * unlabelled choice items.
     *
     * @param string $ccprofile The cc_profile value (CC packages).
     * @param string $canvastype The Canvas question_type value (native dumps).
     * @param DOMElement|null $presentation The presentation element (for cardinality).
     * @return string A qti_question::TYPE_* constant.
     */
    protected function map_type(string $ccprofile, string $canvastype, ?DOMElement $presentation): string {
        switch ($ccprofile) {
            case 'cc.multiple_choice.v0p1':
                return qti_question::TYPE_MULTICHOICE;
            case 'cc.multiple_response.v0p1':
                return qti_question::TYPE_MULTIANSWER;
            case 'cc.true_false.v0p1':
                return qti_question::TYPE_TRUEFALSE;
            case 'cc.fib.v0p1':
            case 'cc.pattern_match.v0p1':
                return qti_question::TYPE_SHORTANSWER;
            case 'cc.essay.v0p1':
                return qti_question::TYPE_ESSAY;
        }
        // Canvas native question_type (non_cc_assessments/*.xml.qti). Types with a
        // faithful Moodle equivalent are mapped; the rest stay UNSUPPORTED so they
        // are reported by name rather than silently mis-imported.
        switch ($canvastype) {
            case 'multiple_choice_question':
                return qti_question::TYPE_MULTICHOICE;
            case 'multiple_answers_question':
                return qti_question::TYPE_MULTIANSWER;
            case 'true_false_question':
                return qti_question::TYPE_TRUEFALSE;
            case 'short_answer_question':
                return qti_question::TYPE_SHORTANSWER;
            case 'numerical_question':
                return qti_question::TYPE_NUMERICAL;
            case 'calculated_question':
                return qti_question::TYPE_CALCULATED;
            case 'essay_question':
                return qti_question::TYPE_ESSAY;
            case 'text_only_question':
                // A New Quizzes "text (no question)" stimulus item — no response,
                // just content — maps to a Moodle description.
                return qti_question::TYPE_DESCRIPTION;
            case 'matching_question':
                return qti_question::TYPE_MATCHING;
            case 'categorization_question':
            case 'ordering_question':
                // New Quizzes categorization/ordering items carry a response_lid
                // per bucket/position, so the cardinality fallback below would
                // mis-read them as multichoice/multianswer. Moodle has no faithful
                // equivalent (ddmarker/ordering are not core question types here),
                // so name them explicitly and leave them unsupported.
                return qti_question::TYPE_UNSUPPORTED;
            case 'fill_in_multiple_blanks_question':
                // Free-text blanks: each blank is a response_lid whose render_choice
                // lists the acceptable answers for that blank. Two or more blanks
                // become a Moodle Cloze (multianswer) — one SHORTANSWER field per
                // blank, embedded in the stem at its placeholder — which keeps each
                // blank's own answer set (unlike Moodle match's single global pool).
                // A single blank is a plain short answer (type the accepted word).
                $lidcount = $presentation !== null
                    ? $presentation->getElementsByTagNameNS('*', 'response_lid')->length : 0;
                if ($lidcount >= 2) {
                    return qti_question::TYPE_CLOZE;
                }
                if ($lidcount === 1) {
                    return qti_question::TYPE_SHORTANSWER;
                }
                break;
            case 'multiple_dropdowns_question':
                // Inline dropdowns: each blank is a response_lid with a fixed choice
                // set. Two or more become a Moodle match (one stem/answer pair per
                // blank) — but only when every blank offers the same choice set,
                // because Moodle match has a single global answer pool; with per-blank
                // choices one blank's options would wrongly be offered for another, so
                // leave it unsupported. A single dropdown is a pick-from-list multiple
                // choice and falls through to the cardinality fallback.
                $lidcount = $presentation !== null
                    ? $presentation->getElementsByTagNameNS('*', 'response_lid')->length : 0;
                if ($lidcount >= 2) {
                    return $this->blanks_share_choices($presentation) && $this->blanks_have_stems($presentation)
                        ? qti_question::TYPE_MATCHING
                        : qti_question::TYPE_UNSUPPORTED;
                }
                break;
        }
        // Fall back on the response cardinality for unprofiled multiple choice.
        $lid = $presentation !== null ? $this->descendant($presentation, 'response_lid') : null;
        if ($lid !== null) {
            return strtolower($lid->getAttribute('rcardinality')) === 'multiple'
                ? qti_question::TYPE_MULTIANSWER
                : qti_question::TYPE_MULTICHOICE;
        }
        return qti_question::TYPE_UNSUPPORTED;
    }

    /**
     * Populate answers for choice questions (multichoice/multianswer/truefalse).
     *
     * @param DOMElement $item The item element.
     * @param DOMElement|null $presentation The presentation element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_choice_answers(DOMElement $item, ?DOMElement $presentation, qti_question $question): void {
        if ($presentation === null) {
            return;
        }
        $correct = $this->correct_idents($item);
        $feedback = $this->label_feedback_map($item);
        $numcorrect = max(1, count($correct));

        // Which response_label idents actually exist among the choices?
        $identset = [];
        foreach ($presentation->getElementsByTagNameNS('*', 'response_label') as $label) {
            if ($label instanceof DOMElement && $label->getAttribute('ident') !== '') {
                $identset[$label->getAttribute('ident')] = true;
            }
        }
        // A <varequal> may score a choice by its response_label ident OR by the
        // choice's displayed text (e.g. <varequal>False</varequal>, as some IMS CC
        // exporters emit). Only treat a scored value as a text reference when it is
        // NOT an existing ident, so a choice whose text merely coincides with
        // another option's ident is never wrongly marked correct. The feedback map
        // is keyed by the same scored value, so index the text-keyed feedback the
        // same way to keep response-specific feedback on text-scored choices.
        $correcttext = [];
        foreach ($correct as $value) {
            if (!isset($identset[$value])) {
                $normalised = $this->normalise_answer_value($value);
                if ($normalised !== '') {
                    $correcttext[$normalised] = true;
                }
            }
        }
        $feedbacktext = [];
        foreach ($feedback as $key => $text) {
            if (!isset($identset[$key])) {
                $feedbacktext[$this->normalise_answer_value((string) $key)] = $text;
            }
        }

        foreach ($presentation->getElementsByTagNameNS('*', 'response_label') as $label) {
            if (!($label instanceof DOMElement)) {
                continue;
            }
            $ident = $label->getAttribute('ident');
            $labeltext = $this->normalise_answer_value($this->material_text($label));
            $iscorrect = in_array($ident, $correct, true)
                || ($labeltext !== '' && isset($correcttext[$labeltext]));
            $fraction = 0.0;
            if ($iscorrect) {
                $fraction = $question->type === qti_question::TYPE_MULTIANSWER
                    ? round(100 / $numcorrect, 5)
                    : 100.0;
            }
            $labelfeedback = $feedback[$ident] ?? ($labeltext !== '' ? ($feedbacktext[$labeltext] ?? '') : '');
            $question->answers[] = [
                'text' => $this->material_text($label),
                'fraction' => $fraction,
                'feedback' => $labelfeedback,
            ];
        }
    }

    /**
     * Populate the answer(s) for a Canvas numerical question.
     *
     * Canvas exports each accepted value as a scoring <respcondition>: an exact
     * <varequal>V</varequal>, or a range as <and><vargte>MIN</vargte>
     * <varlte>MAX</varlte></and> (routinely both forms inside one <or>, describing
     * the same value). A range maps to a Moodle numerical answer of its midpoint
     * with a tolerance of half its width; an exact value carries a zero tolerance.
     * Each condition's positive SCORE becomes the answer fraction, and the <or>'s
     * two equivalent forms collapse to a single answer.
     *
     * @param DOMElement $item The item element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_numerical_answers(DOMElement $item, qti_question $question): void {
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing === null) {
            return;
        }
        $scoremax = $this->score_max($resprocessing);
        $seen = [];
        foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
            if (!($cond instanceof DOMElement)) {
                continue;
            }
            $score = $this->condition_score($cond);
            if ($score <= 0) {
                continue;
            }
            // Scale the raw SCORE onto Moodle's 0–100 fraction using the outcome's
            // declared maximum, so an item scored out of, say, 1 still awards full credit.
            $fraction = min(100.0, max(0.0, $score / $scoremax * 100.0));
            $feedback = $this->condition_feedback($item, $cond);
            foreach ($this->numerical_answers($cond) as [$value, $tolerance]) {
                $key = $value . '/' . $tolerance;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $question->answers[] = [
                    'text' => $value,
                    'fraction' => $fraction,
                    'tolerance' => $tolerance,
                    'feedback' => $feedback,
                ];
            }
        }
    }

    /**
     * Populate a Canvas calculated (formula) question.
     *
     * Canvas stores the answer machinery in an <itemproc_extension><calculated>
     * block: a <formula> over named variables, each variable's range as
     * <vars><var name scale><min/><max/></var>, and a table of pre-generated value
     * tuples as <var_sets><var_set><var name>value</var>…<answer>result</answer>.
     * The formula and stem variable references (Canvas delimits them with backticks
     * or square brackets) are rewritten to Moodle's {var} wildcard syntax; each
     * variable becomes a dataset definition and each var_set a row of dataset items,
     * so Moodle re-computes the answer from the same tuples.
     *
     * @param DOMElement $item The item element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_calculated(DOMElement $item, qti_question $question): void {
        $calc = $this->descendant($item, 'calculated');
        if ($calc === null) {
            return;
        }

        // Variable definitions live under <vars>; var_set values under <var_sets>.
        $names = [];
        $vars = $this->descendant($calc, 'vars');
        if ($vars !== null) {
            foreach ($vars->getElementsByTagNameNS('*', 'var') as $var) {
                if (!($var instanceof DOMElement)) {
                    continue;
                }
                $name = trim($var->getAttribute('name'));
                if ($name === '') {
                    continue;
                }
                $question->variables[] = [
                    'name' => $name,
                    'min' => $this->child_value($var, 'min'),
                    'max' => $this->child_value($var, 'max'),
                    'decimals' => max(0, (int) $var->getAttribute('scale')),
                ];
                $names[] = $name;
            }
        }

        foreach ($calc->getElementsByTagNameNS('*', 'var_set') as $set) {
            if (!($set instanceof DOMElement)) {
                continue;
            }
            $row = [];
            foreach ($set->getElementsByTagNameNS('*', 'var') as $var) {
                if ($var instanceof DOMElement) {
                    $row[trim($var->getAttribute('name'))] = trim($var->textContent);
                }
            }
            if ($row !== []) {
                $question->datarows[] = $row;
            }
        }

        $formulanode = $this->descendant($calc, 'formula');
        $rawformula = $formulanode !== null ? trim($formulanode->textContent) : '';
        // Canvas writes exponentiation with a caret (a^2), but Moodle's calculated
        // grammar treats ^ as a (rejected) bitwise operator and spells exponents **,
        // so translate it — otherwise qformat_xml rejects the formula and rolls back
        // the whole imported bank. Both are right-associative with the same precedence,
        // so the swap is faithful.
        $formula = str_replace('^', '**', $this->templatise_formula($rawformula, $names));
        // Canvas's log is base-10 and its ln is natural, but Moodle's calculated
        // grammar spells base-10 log10 and natural log 'log'; translate so the graded
        // value matches Canvas. Rewrite log first so the ln→log step is not re-mapped,
        // and guard the {wildcard} braces so a variable named log/ln is left alone.
        $formula = (string) preg_replace('/(?<![A-Za-z0-9_{])log(?![A-Za-z0-9_}])/', 'log10', $formula);
        $formula = (string) preg_replace('/(?<![A-Za-z0-9_{])ln(?![A-Za-z0-9_}])/', 'log', $formula);
        $question->formula = $formula;
        $question->questiontext = $this->templatise_prompt($question->questiontext, $names);

        $tolerance = $this->descendant($calc, 'answer_tolerance');
        if ($tolerance !== null) {
            $value = trim($tolerance->textContent);
            $margin = strtolower($tolerance->getAttribute('margin_type'));
            if ($margin === 'percent' || str_ends_with($value, '%')) {
                $question->tolerancekind = 'percent';
            }
            $value = rtrim($value, '%');
            $question->answertolerance = $value === '' ? '0' : $value;
        }

        $formulas = $this->descendant($calc, 'formulas');
        if ($formulas !== null) {
            $decimals = trim($formulas->getAttribute('decimal_places'));
            if ($decimals !== '' && ctype_digit($decimals)) {
                $question->answerdecimals = (int) $decimals;
            }
        }
    }

    /**
     * The trimmed text of a parent's first descendant element with the given local
     * name, or '' when absent.
     *
     * @param DOMElement $parent The parent element.
     * @param string $localname The child element's local name.
     * @return string
     */
    protected function child_value(DOMElement $parent, string $localname): string {
        foreach ($parent->getElementsByTagNameNS('*', $localname) as $node) {
            if ($node instanceof DOMElement) {
                return trim($node->textContent);
            }
        }
        return '';
    }

    /**
     * Rewrite the variable names in a calculated formula to Moodle's {var} wildcard
     * syntax. Longer names are substituted first and identifier boundaries are
     * enforced so one variable is never rewritten inside another's name (or inside a
     * function name), and an already-wrapped name is left alone.
     *
     * @param string $formula The raw Canvas formula.
     * @param array $names The declared variable names.
     * @return string
     */
    protected function templatise_formula(string $formula, array $names): string {
        if ($formula === '' || $names === []) {
            return $formula;
        }
        usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($names as $name) {
            $formula = (string) preg_replace(
                '/(?<![A-Za-z0-9_{])' . preg_quote($name, '/') . '(?![A-Za-z0-9_}])/',
                '{' . $name . '}',
                $formula
            );
        }
        return $formula;
    }

    /**
     * Rewrite variable references in a calculated question's stem to Moodle's {var}
     * wildcard syntax. Canvas delimits an inline variable with a backtick or with
     * square brackets; only those delimited forms are replaced, so prose that merely
     * contains a one-letter variable name is left untouched.
     *
     * @param string $html The question stem HTML.
     * @param array $names The declared variable names.
     * @return string
     */
    protected function templatise_prompt(string $html, array $names): string {
        if ($html === '' || $names === []) {
            return $html;
        }
        // The backtick delimiter is built from chr(96) so the source carries no
        // literal backtick (moodle.Strings.ForbiddenStrings).
        $tick = chr(96);
        usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            $html = (string) preg_replace('/' . $tick . $quoted . $tick . '/', '{' . $name . '}', $html);
            $html = (string) preg_replace('/\[' . $quoted . '\]/', '{' . $name . '}', $html);
        }
        return $html;
    }

    /**
     * Populate a Canvas fill-in-multiple-blanks question as a Moodle Cloze.
     *
     * Each blank is a <response_lid ident="response_<id>"> whose <render_choice>
     * lists the acceptable answers as <response_label>s, and the stem carries a
     * [<id>] placeholder per blank. The accepted answers for a blank are the scoring
     * <varequal respident="response_<id>"> values, each resolved to the matching
     * response_label's display text. Each placeholder is replaced in the stem with a
     * Moodle SHORTANSWER Cloze field listing that blank's answers, so the whole
     * question becomes a single multianswer item.
     *
     * @param DOMElement $item The item element.
     * @param DOMElement|null $presentation The presentation element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_cloze(DOMElement $item, ?DOMElement $presentation, qti_question $question): void {
        if ($presentation === null) {
            return;
        }
        $stem = $question->questiontext;
        $blanks = $this->cloze_blank_answers($presentation);
        // Count every response_lid the presentation declares, including any that
        // cloze_blank_answers skipped for a missing ident: completeness is measured
        // against the source blanks, not the already-filtered map, so a blank that was
        // dropped there still fails the whole item rather than silently vanishing.
        $sourceblanks = 0;
        foreach ($presentation->getElementsByTagNameNS('*', 'response_lid') as $lid) {
            if ($lid instanceof DOMElement) {
                $sourceblanks++;
            }
        }
        $replacements = [];
        $placedblanks = [];
        foreach ($blanks as $blankid => $accepted) {
            $marker = '[' . $blankid . ']';
            // A blank we cannot place — it has no accepted answer, or its [id] marker
            // does not appear in the stem exactly once — would leave a silently truncated
            // or duplicated Cloze, so the whole item is left unsupported (reported by name)
            // rather than importing a partial question or grading one QTI response as two
            // independent subquestions.
            if ($accepted === [] || substr_count($stem, $marker) !== 1) {
                continue;
            }
            $replacements[$marker] = $this->cloze_field($accepted);
            $placedblanks[] = $blankid;
        }
        // Every source blank must have produced exactly one placed field; a skipped or
        // unresolved blank (ident-less node, missing/duplicated marker, no accepted
        // answer) leaves the count short and the item is reported unsupported rather than
        // imported with a gap.
        if ($sourceblanks === 0 || count($replacements) !== $sourceblanks) {
            $question->type = qti_question::TYPE_UNSUPPORTED;
            return;
        }
        // A Cloze weights every SHORTANSWER field equally, so only convert when Canvas
        // scores the blanks that way; an item with unequal, incomplete, or combined
        // (cross-blank) scoring is left unsupported rather than silently mis-graded.
        if (!$this->cloze_weighting_is_safe($item, $placedblanks)) {
            $question->type = qti_question::TYPE_UNSUPPORTED;
            return;
        }
        // Escape any Cloze grammar already present in the authored stem before inserting
        // the generated fields: an instructional example such as {1:SHORTANSWER:=x} would
        // otherwise be parsed by Moodle as an extra graded subquestion. Encoding the braces
        // to HTML entities keeps them visible but inert (Moodle's multianswer parser has no
        // backslash escape); the fields inserted next carry real braces.
        $escaped = strtr($stem, ['{' => '&#123;', '}' => '&#125;']);
        // Apply every marker substitution against the escaped stem in a single pass so a
        // generated field that happens to contain another blank's [id] marker is never
        // re-searched (strtr replaces simultaneously, longest key first).
        $question->questiontext = strtr($escaped, $replacements);
        // The name was derived from the raw stem, which for an untitled item still shows
        // the [blank] placeholders; re-derive it with the blanks shown as gaps so it
        // reads as prose. A titled item keeps its title (derive_name prefers it).
        if (trim($item->getAttribute('title')) === '') {
            $gaps = [];
            foreach (array_keys($blanks) as $blankid) {
                $gaps['[' . $blankid . ']'] = ' ____ ';
            }
            $question->name = $this->derive_name($item, strtr($stem, $gaps));
        }
    }

    /**
     * Map each fill-in-multiple-blanks blank to its accepted answers. The blank id is
     * the response_lid ident with the "response_" prefix removed. A
     * fill_in_multiple_blanks_question is a free-text (open-entry) question by type —
     * this method is only reached for that Canvas type; a genuine pick-from-a-fixed-set
     * question is a multiple_dropdowns_question, which {@see map_type} routes to a
     * Moodle match, not here. So every listed response_label is an acceptable spelling
     * (not a distractor) and all are kept, regardless of whether the label carries the
     * answer_type/scoring_algorithm attributes — mirroring how {@see fill_text_answers}
     * treats a single blank, where Canvas may enumerate several spellings but the
     * respcondition references only one. An HTML label's text is flattened (a SHORTANSWER
     * key is not rendered as HTML) while a text/plain label is kept verbatim so a literal
     * tag-like answer survives; each carries whether Canvas graded it as "contains" so the
     * writer can widen it to a Moodle wildcard match.
     *
     * @param DOMElement $presentation The presentation element.
     * @return array Map of blank id to a list of ['text' => string, 'contains' => bool].
     */
    protected function cloze_blank_answers(DOMElement $presentation): array {
        $result = [];
        foreach ($presentation->getElementsByTagNameNS('*', 'response_lid') as $lid) {
            if (!($lid instanceof DOMElement) || $lid->getAttribute('ident') === '') {
                continue;
            }
            $blankid = (string) preg_replace('/^response_/', '', $lid->getAttribute('ident'));
            $accepted = [];
            $seen = [];
            foreach ($lid->getElementsByTagNameNS('*', 'response_label') as $label) {
                if (!($label instanceof DOMElement)) {
                    continue;
                }
                $text = $this->label_answer_text($label);
                // A contains-match algorithm (Canvas TextContainsAnswer) accepts any
                // response holding the answer, so the answer text alone is not a
                // reliable dedup key; qualify it with the algorithm.
                $contains = strcasecmp($label->getAttribute('scoring_algorithm'), 'TextContainsAnswer') === 0;
                $key = ($contains ? '~' : '=') . $text;
                if ($text !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $accepted[] = ['text' => $text, 'contains' => $contains];
                }
            }
            $result[$blankid] = $accepted;
        }
        return $result;
    }

    /**
     * Flatten a response_label's answer text for use as a SHORTANSWER key. An HTML
     * label (a mattext declaring texttype="text/html") is stripped of markup, since
     * Moodle does not render a short-answer key as HTML; a plain-text label keeps its
     * content verbatim (only whitespace collapsed), so a literal tag-like answer such
     * as "<div>" survives rather than being deleted as markup.
     *
     * @param DOMElement $label The response_label element.
     * @return string
     */
    protected function label_answer_text(DOMElement $label): string {
        $raw = $this->material_text($label);
        if ($this->material_is_html($label)) {
            // A block or break element renders as a word boundary, so replace it with a
            // space (<p>New</p><p>York</p> -> "New York"); an inline element (<b>, <sub>…)
            // renders with no boundary, so strip it without a space (<b>New</b>York ->
            // "NewYork", H<sub>2</sub>O -> "H2O"). Then decode entities and collapse.
            $block = '/<\s*\/?\s*(?:p|div|br|li|tr|td|th|thead|tbody|table|ul|ol|dl|dd|dt'
                . '|h[1-6]|blockquote|section|article|header|footer|hr|pre|figure|figcaption)\b[^>]*>/i';
            $spaced = (string) preg_replace($block, ' ', $raw);
            return $this->collapse_ws(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5));
        }
        return $this->collapse_ws($raw);
    }

    /**
     * Collapse runs of whitespace to a single space and trim. The pattern is Unicode-aware
     * and includes U+00A0 so a non-breaking space (e.g. a decoded &nbsp;) becomes an
     * ordinary space rather than surviving into a SHORTANSWER key a learner can't type.
     *
     * @param string $text The text to normalise.
     * @return string
     */
    protected function collapse_ws(string $text): string {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * Whether a node's material is authored as HTML — any of its mattext children
     * declares a text/html texttype. Canvas marks plain answers text/plain, so the
     * default (no type, or a non-HTML type) is treated as literal plain text.
     *
     * @param DOMElement $node The element carrying a material descendant.
     * @return bool
     */
    protected function material_is_html(DOMElement $node): bool {
        $material = $this->descendant($node, 'material');
        if ($material === null) {
            return false;
        }
        foreach ($material->getElementsByTagNameNS('*', 'mattext') as $mt) {
            if ($mt instanceof DOMElement && stripos($mt->getAttribute('texttype'), 'html') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a Moodle SHORTANSWER Cloze field from a blank's accepted answers, e.g.
     * {1:SHORTANSWER:=ipsum~=ipsem}. Each answer is a fully-credited option (=) with
     * the Cloze/short-answer metacharacters escaped; a Canvas "contains" answer is
     * wrapped in * wildcards so Moodle accepts any response holding the text.
     *
     * @param array $accepted List of ['text' => string, 'contains' => bool].
     * @return string
     */
    protected function cloze_field(array $accepted): string {
        $options = [];
        foreach ($accepted as $answer) {
            $escaped = $this->cloze_escape((string) ($answer['text'] ?? ''));
            $options[] = '=' . (!empty($answer['contains']) ? '*' . $escaped . '*' : $escaped);
        }
        return '{1:SHORTANSWER:' . implode('~', $options) . '}';
    }

    /**
     * Escape the Cloze and short-answer metacharacters in answer text so it can't
     * break the field or be read as a wildcard: backslash first (so it is not
     * doubled), then the braces, the # feedback separator, the ~ option separator and
     * the * short-answer wildcard (a literal Canvas asterisk must stay literal).
     *
     * @param string $text The answer text.
     * @return string
     */
    protected function cloze_escape(string $text): string {
        return str_replace(
            ['\\', '{', '}', '#', '~', '*'],
            ['\\\\', '\\{', '\\}', '\\#', '\\~', '\\*'],
            $text
        );
    }

    /**
     * The declared maximum of the SCORE outcome variable in a resprocessing block,
     * used to scale a condition's raw SCORE onto Moodle's 0–100 answer fraction.
     * Defaults to 100 when unspecified or not positive.
     *
     * @param DOMElement $resprocessing The resprocessing element.
     * @return float
     */
    protected function score_max(DOMElement $resprocessing): float {
        foreach ($resprocessing->getElementsByTagNameNS('*', 'decvar') as $decvar) {
            if ($decvar instanceof DOMElement && strtoupper($decvar->getAttribute('varname')) === 'SCORE') {
                $max = (float) $decvar->getAttribute('maxvalue');
                return $max > 0 ? $max : 100.0;
            }
        }
        return 100.0;
    }

    /**
     * The answer-specific feedback a scoring respcondition links via
     * <displayfeedback linkrefid="…">, resolved to the matching <itemfeedback>
     * body, or '' when the condition carries no feedback link.
     *
     * @param DOMElement $item The item element.
     * @param DOMElement $cond The respcondition element.
     * @return string
     */
    protected function condition_feedback(DOMElement $item, DOMElement $cond): string {
        $df = $cond->getElementsByTagNameNS('*', 'displayfeedback');
        if ($df->length === 0 || !($df->item(0) instanceof DOMElement)) {
            return '';
        }
        $ref = $df->item(0)->getAttribute('linkrefid');
        if ($ref === '') {
            return '';
        }
        foreach ($item->getElementsByTagNameNS('*', 'itemfeedback') as $fb) {
            if ($fb instanceof DOMElement && $fb->getAttribute('ident') === $ref) {
                return $this->material_text($fb);
            }
        }
        return '';
    }

    /**
     * Read the accepted numerical answers from a scoring respcondition as a list of
     * [value, tolerance] pairs (plain decimal strings). A <vargte>/<varlte> pair is a
     * range (midpoint + half-width); a bare <varequal> is an exact value with a zero
     * tolerance, preserved verbatim. An <or> that lists a range together with an exact
     * value inside it collapses to one answer (Canvas emits both as equivalent forms),
     * but a distinct exact alternative is kept as its own answer. An inverted range
     * (lower bound above upper) can never match in QTI, so it is skipped rather than
     * turned into a valid interval.
     *
     * @param DOMElement $cond The respcondition element.
     * @return array A list of two-element [value, tolerance] lists (possibly empty).
     */
    protected function numerical_answers(DOMElement $cond): array {
        $answers = [];
        $gte = $this->condition_value($cond, 'vargte');
        $lte = $this->condition_value($cond, 'varlte');
        $hasrange = $gte !== null && $lte !== null && $this->dec_cmp($gte, $lte) <= 0;
        if ($hasrange) {
            $answers[] = $this->range_answer($gte, $lte);
        }
        foreach ($this->condition_values($cond, 'varequal') as $eq) {
            // Drop an exact value the range already covers, but keep a distinct one.
            if ($hasrange && $this->dec_cmp($gte, $eq) <= 0 && $this->dec_cmp($eq, $lte) <= 0) {
                continue;
            }
            $answers[] = [$eq, '0'];
        }
        return $answers;
    }

    /**
     * The value of the first non-negated named response test in a respcondition, as
     * the verbatim numeric source string, or null when absent or non-numeric.
     *
     * @param DOMElement $cond The respcondition element.
     * @param string $localname The test element name (varequal/vargte/varlte).
     * @return string|null
     */
    protected function condition_value(DOMElement $cond, string $localname): ?string {
        return $this->condition_values($cond, $localname)[0] ?? null;
    }

    /**
     * The values of all non-negated named response tests of a kind in a respcondition,
     * each as a plain decimal string (scientific notation expanded), skipping any that
     * are non-numeric or carry an unexpandable exponent.
     *
     * @param DOMElement $cond The respcondition element.
     * @param string $localname The test element name (varequal/vargte/varlte).
     * @return array The values in document order.
     */
    protected function condition_values(DOMElement $cond, string $localname): array {
        $values = [];
        foreach ($cond->getElementsByTagNameNS('*', $localname) as $node) {
            if ($node instanceof DOMElement && !$this->within($node, 'not')) {
                $text = trim($node->textContent);
                if (is_numeric($text)) {
                    $value = $this->normalise_decimal($text);
                    if ($value !== null) {
                        $values[] = $value;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * Compare two signed decimal strings: -1 if a < b, 0 if equal, 1 if a > b.
     *
     * @param string $a The first value.
     * @param string $b The second value.
     * @return int
     */
    protected function dec_cmp(string $a, string $b): int {
        $diff = $this->dec_add($a, $this->dec_negate($b));
        if ($this->trim_decimal(ltrim($diff, '-')) === '0') {
            return 0;
        }
        return $diff[0] === '-' ? -1 : 1;
    }

    /**
     * Expand a scientific-notation number (e.g. 1.5e-9) to a plain decimal string,
     * without a float round-trip, so exact values and range arithmetic keep full
     * precision. A plain-decimal number is returned unchanged; an exponent so extreme
     * that expansion would allocate a huge string is rejected (null, so the answer is
     * skipped) rather than exhausting memory.
     *
     * @param string $number The numeric string.
     * @return string|null
     */
    protected function normalise_decimal(string $number): ?string {
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?[eE]([+-]?\d+)$/', $number, $m)) {
            return $number;
        }
        $digits = $m[2] . ($m[3] ?? '');
        $point = strlen($m[2]) + (int) $m[4];
        // Reject only an exponent whose expansion would allocate an unreasonably large
        // string (a malformed or hostile 1e-9999999999), while still admitting any
        // genuine high-precision answer; the bound is a resource guard, not a precision
        // limit, so it is generous.
        $zeros = $point <= 0 ? -$point : max(0, $point - strlen($digits));
        if ($zeros > 100000) {
            return null;
        }
        if ($point <= 0) {
            $result = '0.' . str_repeat('0', -$point) . $digits;
        } else if ($point >= strlen($digits)) {
            $result = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $result = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        return ($m[1] === '-' ? '-' : '') . ($result === '' ? '0' : $result);
    }

    /**
     * Convert a Canvas [min, max] accepted range into a Moodle numerical answer: the
     * midpoint value and the half-width tolerance, computed with exact decimal-string
     * arithmetic (no float, no bcmath, no integer-size limit) so whatever precision the
     * endpoints carry is preserved.
     *
     * @param string $min The lower bound (vargte), a decimal string.
     * @param string $max The upper bound (varlte), a decimal string.
     * @return array Two-element list [value, tolerance].
     */
    protected function range_answer(string $min, string $max): array {
        $mid = $this->trim_decimal($this->dec_half($this->dec_add($min, $max)));
        $tol = $this->trim_decimal(ltrim($this->dec_half($this->dec_add($max, $this->dec_negate($min))), '-'));
        return [$mid, $tol];
    }

    /**
     * The number of fractional digits in a decimal string (0 when it has no point).
     *
     * @param string $number The decimal string.
     * @return int
     */
    protected function decimal_places(string $number): int {
        $dot = strpos($number, '.');
        return $dot === false ? 0 : strlen(substr($number, $dot + 1));
    }

    /**
     * Negate a decimal string (a zero value is returned unchanged).
     *
     * @param string $number The decimal string.
     * @return string
     */
    protected function dec_negate(string $number): string {
        if (ltrim($number, '-+0.') === '') {
            return $number;
        }
        return $number[0] === '-' ? substr($number, 1) : '-' . $number;
    }

    /**
     * Add two signed decimal strings exactly, returning a plain decimal string.
     *
     * @param string $a The first addend.
     * @param string $b The second addend.
     * @return string
     */
    protected function dec_add(string $a, string $b): string {
        $nega = $a[0] === '-';
        $negb = $b[0] === '-';
        $a = ltrim($a, '+-');
        $b = ltrim($b, '+-');
        $scale = max($this->decimal_places($a), $this->decimal_places($b));
        $ma = str_replace('.', '', $a) . str_repeat('0', $scale - $this->decimal_places($a));
        $mb = str_replace('.', '', $b) . str_repeat('0', $scale - $this->decimal_places($b));
        if ($nega === $negb) {
            $mag = $this->str_add($ma, $mb);
            $neg = $nega;
        } else {
            $cmp = $this->str_cmp($ma, $mb);
            if ($cmp === 0) {
                return '0';
            }
            $mag = $cmp > 0 ? $this->str_sub($ma, $mb) : $this->str_sub($mb, $ma);
            $neg = $cmp > 0 ? $nega : $negb;
        }
        $out = $this->place_point($mag, $scale);
        return ($neg && ltrim($out, '0.') !== '' ? '-' : '') . $out;
    }

    /**
     * Halve a signed decimal string exactly (the result gains at most one decimal place).
     *
     * @param string $number The decimal string.
     * @return string
     */
    protected function dec_half(string $number): string {
        $neg = $number[0] === '-';
        $number = ltrim($number, '+-');
        $scale = $this->decimal_places($number);
        $digits = str_replace('.', '', $number);
        $quotient = '';
        $rem = 0;
        for ($i = 0, $len = strlen($digits); $i < $len; $i++) {
            $cur = $rem * 10 + (int) $digits[$i];
            $quotient .= intdiv($cur, 2);
            $rem = $cur % 2;
        }
        if ($rem === 1) {
            $quotient .= '5';
            $scale++;
        }
        $out = $this->place_point($quotient, $scale);
        return ($neg && ltrim($out, '0.') !== '' ? '-' : '') . $out;
    }

    /**
     * Place a decimal point $scale digits from the right of an unsigned integer digit
     * string, normalising leading zeros.
     *
     * @param string $digits The unsigned integer digit string.
     * @param int $scale The number of digits that fall after the point.
     * @return string
     */
    protected function place_point(string $digits, int $scale): string {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        if ($scale === 0) {
            return $digits;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    /**
     * Add two unsigned integer digit strings.
     *
     * @param string $a The first addend.
     * @param string $b The second addend.
     * @return string
     */
    protected function str_add(string $a, string $b): string {
        $a = strrev($a);
        $b = strrev($b);
        $la = strlen($a);
        $lb = strlen($b);
        $carry = 0;
        $out = '';
        for ($i = 0, $len = max($la, $lb); $i < $len; $i++) {
            $sum = ($i < $la ? (int) $a[$i] : 0) + ($i < $lb ? (int) $b[$i] : 0) + $carry;
            $out .= $sum % 10;
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out .= $carry;
        }
        return strrev($out);
    }

    /**
     * Subtract unsigned integer digit string $b from $a, which must be >= $b.
     *
     * @param string $a The minuend (>= $b).
     * @param string $b The subtrahend.
     * @return string
     */
    protected function str_sub(string $a, string $b): string {
        $a = strrev($a);
        $b = strrev($b);
        $lb = strlen($b);
        $borrow = 0;
        $out = '';
        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $diff = (int) $a[$i] - ($i < $lb ? (int) $b[$i] : 0) - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out .= $diff;
        }
        $result = ltrim(strrev($out), '0');
        return $result === '' ? '0' : $result;
    }

    /**
     * Compare two unsigned integer digit strings: -1, 0 or 1.
     *
     * @param string $a The first operand.
     * @param string $b The second operand.
     * @return int
     */
    protected function str_cmp(string $a, string $b): int {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    /**
     * Drop a decimal string's trailing zeros (and a bare trailing point), normalising
     * an all-zero or empty result to '0'.
     *
     * @param string $number The decimal string.
     * @return string
     */
    protected function trim_decimal(string $number): string {
        if (strpos($number, '.') !== false) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        return $number === '' || $number === '-' || $number === '-0' ? '0' : $number;
    }

    /**
     * Recover Canvas acknowledgment questions so Moodle can save them.
     *
     * Canvas "readiness"/honor-code quizzes author each item as a statement with
     * a single correct affirmative option ("I understand ... -> YES"). Moodle
     * multiple choice needs at least two options, so such items would otherwise
     * be dropped. When a single-answer choice has exactly one non-empty option,
     * that option is correct, and it reads as an affirmation, add the obvious
     * complementary "No" distractor. We deliberately do not guess for other
     * single-option questions, which stay flagged for human review.
     *
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function recover_acknowledgment(qti_question $question): void {
        if ($question->type !== qti_question::TYPE_MULTICHOICE) {
            return;
        }
        $nonempty = [];
        foreach ($question->answers as $answer) {
            if (trim((string) ($answer['text'] ?? '')) !== '') {
                $nonempty[] = $answer;
            }
        }
        if (count($nonempty) !== 1) {
            return;
        }
        $only = $nonempty[0];
        if ((float) ($only['fraction'] ?? 0) <= 0 || !$this->looks_affirmative((string) $only['text'])) {
            return;
        }
        $question->answers[] = ['text' => 'No', 'fraction' => 0.0, 'feedback' => ''];
    }

    /**
     * Whether an answer's text reads as an affirmation (yes / I agree / etc.).
     *
     * @param string $html The answer HTML.
     * @return bool
     */
    protected function looks_affirmative(string $html): bool {
        $text = strtolower(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        // Normalise a curly apostrophe to a straight one so "don't" matches
        // regardless of the quote style the export used.
        $text = str_replace("\u{2019}", "'", $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return false;
        }
        $affirmverb = 'agree|accept|acknowledge|consent|certify|confirm';

        // Exact one-word / short-phrase affirmations.
        $affirmations = [
            'yes', 'y', 'true', 't', 'ok', 'okay', 'correct', 'i do',
            'agree', 'accept', 'acknowledge', 'understood',
            'i agree', 'i accept', 'i acknowledge', 'i understand', 'i consent', 'i confirm', 'i certify',
        ];
        if (in_array($text, $affirmations, true)) {
            return true;
        }

        // Decline guard: reject when a negation, refusal or "no" word governs a
        // nearby acceptance verb, so an explicit decline is never turned into a
        // fabricated "Yes". The acceptance verb must FOLLOW the deny word (within
        // a few words), which covers adjacent and short-gap negations ("I do not
        // agree", "I do not wish to agree", "I don't want to accept"), refusal
        // verbs ("I refuse to agree", "I reject consent"), negated understanding
        // ("Not understood", "I have not understood") and no/non consent ("No
        // consent", "Non-consent"). Hyphens are read as spaces so "Non-consent"
        // is two words. Because the acceptance verb must come after the deny
        // word, a prohibition the option merely acknowledges leaves the leading
        // affirmation intact ("I understand that I do not have permission ...",
        // "I acknowledge that I must reject plagiarism"). Run before the opener
        // match so a decline opening with an affirmative phrase ("I have read and
        // do not agree") is still rejected.
        $deny = "not|never|cannot|no|non|\\w*n't|disagree|decline|refuse|reject";
        $accept = $affirmverb . '|understood|understand';
        $guardtext = str_replace('-', ' ', $text);
        if (preg_match('/\b(?:' . $deny . ')(?:\s+\w+){0,3}\s+(?:' . $accept . ')\b/', $guardtext)) {
            return false;
        }

        // Affirmative opener: the option begins with a whole affirmation verb or
        // phrase ("I have read and agree", "Consent to participate", "Accept").
        // The trailing \b requires the complete word, so "Acceptable Use Policy"
        // and "Agreement form" (which only start with the letters) are left for
        // review.
        $openers = [
            'yes', 'i agree', 'i accept', 'i acknowledge', 'i understand', 'i consent',
            'i confirm', 'i certify', 'i have read', 'accept', 'agree', 'acknowledge',
            'confirm', 'certify', 'consent',
        ];
        $openerpattern = '/^(?:' . implode('|', array_map(fn($o) => preg_quote($o, '/'), $openers)) . ')\b/';
        if (preg_match($openerpattern, $text)) {
            return true;
        }

        // Closing affirmation verb ("By selecting this option, I agree.", "By
        // continuing, I accept!"). Tolerate trailing punctuation/whitespace. The
        // decline guard above has already removed negated/refused forms.
        return (bool) preg_match('/\b(?:' . $affirmverb . '|understood)[\s\p{P}]*$/u', $text);
    }

    /**
     * Populate accepted answers for fill-in-blank / pattern-match questions.
     *
     * @param DOMElement $item The item element.
     * @param DOMElement|null $presentation The presentation element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_text_answers(DOMElement $item, ?DOMElement $presentation, qti_question $question): void {
        // Canvas fill-in-the-blank (single blank) lists every accepted answer as a
        // render_choice label; the resprocessing references them by ident, not
        // text, so read the label text directly and accept them all. Scoped to
        // render_choice: Common Cartridge cc.fib uses render_fib (an empty
        // response_label placeholder) and Canvas short_answer has no choices, so
        // both fall through to the resprocessing text below.
        $choicelabels = [];
        if ($presentation !== null) {
            foreach ($presentation->getElementsByTagNameNS('*', 'render_choice') as $choice) {
                foreach ($choice->getElementsByTagNameNS('*', 'response_label') as $label) {
                    if ($label instanceof DOMElement) {
                        $choicelabels[] = $label;
                    }
                }
            }
        }
        if (!empty($choicelabels)) {
            $seen = [];
            foreach ($choicelabels as $label) {
                $text = trim($this->material_text($label));
                if ($text !== '' && !isset($seen[$text])) {
                    $seen[$text] = true;
                    $question->answers[] = ['text' => $text, 'fraction' => 100.0, 'feedback' => ''];
                }
            }
            return;
        }
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing === null) {
            return;
        }
        $seen = [];
        foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
            if (!($cond instanceof DOMElement) || $this->condition_score($cond) <= 0) {
                continue;
            }
            // Canvas fill-in-blank uses varequal (exact) or varsubstring (contains).
            foreach (['varequal', 'varsubstring'] as $tag) {
                foreach ($cond->getElementsByTagNameNS('*', $tag) as $node) {
                    if (!($node instanceof DOMElement) || $this->within($node, 'not')) {
                        continue;
                    }
                    // Strip Canvas's leading "^" regex anchor; it dedupes against the plain form.
                    $text = ltrim(trim($node->textContent), '^');
                    if ($text !== '' && !isset($seen[$text])) {
                        $seen[$text] = true;
                        $question->answers[] = ['text' => $text, 'fraction' => 100.0, 'feedback' => ''];
                    }
                }
            }
        }
    }

    /**
     * Populate the stem/answer pairs for a matching question.
     *
     * Canvas authors each match row as a <response_lid>: its own <material> is the
     * left-hand stem, its <render_choice> lists the candidate answers (the same
     * set repeated on every row), and <resprocessing> records which candidate is
     * correct for that row (respident -> label ident). Each row becomes one
     * Moodle subquestion (stem + correct answer); any candidate never used as a
     * correct match is carried as an answer-only distractor row.
     *
     * @param DOMElement $item The item element.
     * @param DOMElement|null $presentation The presentation element.
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_matching(DOMElement $item, ?DOMElement $presentation, qti_question $question): void {
        if ($presentation === null) {
            return;
        }
        $correct = $this->matching_correct_map($item);
        $pool = [];
        $usedanswers = [];
        // Traverse response_lid descendants (not just direct children) so blanks
        // wrapped in a <flow> are filled — matching the node set map_type counts.
        foreach ($presentation->getElementsByTagNameNS('*', 'response_lid') as $lid) {
            if (!($lid instanceof DOMElement)) {
                continue;
            }
            $stemmaterial = $this->first_child_element($lid, 'material');
            $stem = $stemmaterial !== null ? $this->mattext($stemmaterial) : '';
            $options = $this->choice_label_map($lid);
            foreach ($options as $ident => $text) {
                $pool[$ident] = $text;
            }
            $answerident = $correct[$lid->getAttribute('ident')] ?? '';
            $answer = $this->plain_answer($options[$answerident] ?? '');
            if (trim($stem) === '' && $answer === '') {
                continue;
            }
            $question->subquestions[] = ['text' => $stem, 'answer' => $answer];
            if ($answer !== '') {
                $usedanswers[$answer] = true;
            }
        }
        foreach ($pool as $text) {
            $plain = $this->plain_answer($text);
            if ($plain !== '' && !isset($usedanswers[$plain])) {
                $usedanswers[$plain] = true;
                $question->subquestions[] = ['text' => '', 'answer' => $plain];
            }
        }
    }

    /**
     * Map each matching row's respident to the label ident scored as correct.
     *
     * @param DOMElement $item The item element.
     * @return array Map of respident => correct response_label ident.
     */
    protected function matching_correct_map(DOMElement $item): array {
        $map = [];
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing === null) {
            return $map;
        }
        foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
            if (!($cond instanceof DOMElement) || $this->condition_score($cond) <= 0) {
                continue;
            }
            foreach ($cond->getElementsByTagNameNS('*', 'varequal') as $ve) {
                if (!($ve instanceof DOMElement) || $this->within($ve, 'not')) {
                    continue;
                }
                $respident = $ve->getAttribute('respident');
                if ($respident !== '') {
                    $map[$respident] = trim($ve->textContent);
                }
            }
        }
        return $map;
    }

    /**
     * Whether every dropdown/blank in the presentation offers the same set of
     * choices. Moodle's match type has one global answer pool, so converting an
     * inline-dropdown item to a match is only faithful when the blanks share a
     * choice set; otherwise one blank's options would be offered for another.
     * Compares the choice texts order-independently (Canvas gives the same option
     * a different ident in each blank, so idents can't be compared).
     *
     * @param DOMElement $presentation The presentation element.
     * @return bool
     */
    protected function blanks_share_choices(DOMElement $presentation): bool {
        $reference = null;
        foreach ($presentation->getElementsByTagNameNS('*', 'response_lid') as $lid) {
            if (!($lid instanceof DOMElement)) {
                continue;
            }
            $choices = array_map(fn($t) => $this->plain_answer($t), array_values($this->choice_label_map($lid)));
            sort($choices);
            if ($reference === null) {
                $reference = $choices;
            } else if ($choices !== $reference) {
                return false;
            }
        }
        return $reference !== null;
    }

    /**
     * Whether every dropdown/blank carries its own stem text (a direct <material>
     * child of the response_lid). Canvas authors some inline dropdowns with only
     * the render_choice in each response_lid and the blank labelled by a bracketed
     * reference word in the prompt; those have no per-blank stem, so a Moodle match
     * would import empty stems and be dropped. Such items are left unsupported
     * rather than converted to a broken match.
     *
     * @param DOMElement $presentation The presentation element.
     * @return bool
     */
    protected function blanks_have_stems(DOMElement $presentation): bool {
        $seen = false;
        foreach ($presentation->getElementsByTagNameNS('*', 'response_lid') as $lid) {
            if (!($lid instanceof DOMElement)) {
                continue;
            }
            $seen = true;
            $material = $this->first_child_element($lid, 'material');
            if ($material === null || trim($this->mattext($material)) === '') {
                return false;
            }
        }
        return $seen;
    }

    /**
     * Map a render_choice's response_label idents to their material text.
     *
     * @param DOMElement $lid The response_lid element.
     * @return array Map of label ident => text.
     */
    protected function choice_label_map(DOMElement $lid): array {
        $map = [];
        foreach ($lid->getElementsByTagNameNS('*', 'response_label') as $label) {
            if ($label instanceof DOMElement && $label->getAttribute('ident') !== '') {
                $map[$label->getAttribute('ident')] = $this->material_text($label);
            }
        }
        return $map;
    }

    /**
     * Reduce a candidate answer to the plain single-line text Moodle's match type
     * stores: strip any markup, decode entities and collapse whitespace.
     *
     * @param string $html The answer text or HTML.
     * @return string
     */
    protected function plain_answer(string $html): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Reduce a scored value or choice label to a case-insensitive plain form, so a
     * <varequal> that names a choice by its display text can be compared to the
     * response_label's text regardless of markup or letter case.
     *
     * @param string $value The raw value or label text/HTML.
     * @return string
     */
    protected function normalise_answer_value(string $value): string {
        $plain = $this->plain_answer($value);
        return function_exists('mb_strtolower') ? mb_strtolower($plain) : strtolower($plain);
    }

    /**
     * Identifiers of the correct response_labels (those under a positive-score
     * condition and not negated).
     *
     * @param DOMElement $item The item element.
     * @return string[] Correct response_label idents.
     */
    protected function correct_idents(DOMElement $item): array {
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing === null) {
            return [];
        }
        $best = 0.0;
        $idents = [];
        foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
            if (!($cond instanceof DOMElement)) {
                continue;
            }
            $score = $this->condition_score($cond);
            if ($score <= 0) {
                continue;
            }
            $current = [];
            foreach ($cond->getElementsByTagNameNS('*', 'varequal') as $ve) {
                if ($ve instanceof DOMElement && !$this->within($ve, 'not')) {
                    $current[] = trim($ve->textContent);
                }
            }
            if ($current === []) {
                continue;
            }
            // A higher-scoring condition replaces the answer; a condition with
            // the same top score adds to it, so a multiple-response question
            // whose correct options are split across sibling respconditions (one
            // positively-scored varequal each) keeps them all, not just the last.
            if ($score > $best) {
                $best = $score;
                $idents = $current;
            } else if ($score == $best) {
                $idents = array_merge($idents, $current);
            }
        }
        return array_values(array_unique($idents));
    }

    /**
     * The SCORE a respcondition sets, or 0 if it doesn't set a positive score.
     *
     * @param DOMElement $cond The respcondition element.
     * @return float
     */
    protected function condition_score(DOMElement $cond): float {
        foreach ($cond->getElementsByTagNameNS('*', 'setvar') as $setvar) {
            if ($setvar instanceof DOMElement && strtoupper($setvar->getAttribute('varname')) === 'SCORE') {
                return (float) trim($setvar->textContent);
            }
        }
        return 0.0;
    }

    /**
     * Whether a respcondition's SCORE setvar adds to the running total (action="Add")
     * rather than setting it. Additive scoring is what lets each blank's points be read as
     * an independent weight; a "Set" or action-less setvar is treated as non-additive.
     *
     * @param DOMElement $cond The respcondition element.
     * @return bool
     */
    protected function condition_is_additive(DOMElement $cond): bool {
        foreach ($cond->getElementsByTagNameNS('*', 'setvar') as $setvar) {
            if ($setvar instanceof DOMElement && strtoupper($setvar->getAttribute('varname')) === 'SCORE') {
                return strcasecmp($setvar->getAttribute('action'), 'Add') === 0;
            }
        }
        return false;
    }

    /**
     * Whether a fill-in-multiple-blanks item can be faithfully converted to an
     * evenly-weighted Cloze. A Cloze grades every SHORTANSWER field with the same weight,
     * so this holds only when Canvas scores the blanks that way. Reading the resprocessing:
     *
     * - no resprocessing, or none of its positive conditions name a blank, means there is
     *   no per-blank weighting to preserve, so an even split is the only assumption — safe;
     * - a positive condition that names more than one blank (an <and>/<or> awarding points
     *   only for a combination) can't be split into independent even fields — unsafe;
     * - a placed blank that no positive condition scores would be granted credit Canvas
     *   withholds — unsafe;
     * - placed blanks scored with unequal positive values would have their ratio flattened
     *   to 1:1 — unsafe.
     *
     * @param DOMElement $item The item element.
     * @param array $placed The blank ids that produced a Cloze field.
     * @return bool
     */
    protected function cloze_weighting_is_safe(DOMElement $item, array $placed): bool {
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing === null) {
            return true;
        }
        $scores = [];
        foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
            if (!($cond instanceof DOMElement) || $this->condition_score($cond) <= 0) {
                continue;
            }
            // Only additive scoring (SCORE action="Add") maps to independent per-blank
            // weights. A "Set" (or action-less, whose QTI default is Set) condition awards
            // its value once regardless of how many blanks are right, which an even split
            // would mis-grade, so the item is left unsupported.
            if (!$this->condition_is_additive($cond)) {
                return false;
            }
            $blanks = [];
            foreach ($cond->getElementsByTagNameNS('*', 'varequal') as $ve) {
                if (!($ve instanceof DOMElement) || $this->within($ve, 'not')) {
                    continue;
                }
                $blankid = (string) preg_replace('/^response_/', '', $ve->getAttribute('respident'));
                if ($blankid !== '') {
                    $blanks[$blankid] = true;
                }
            }
            if (count($blanks) > 1) {
                // A single condition scoring several blanks together (e.g. an <and>) can't
                // be represented as independent even fields.
                return false;
            }
            $score = $this->condition_score($cond);
            foreach (array_keys($blanks) as $blankid) {
                $scores[$blankid] = max($scores[$blankid] ?? 0.0, $score);
            }
        }
        if ($scores === []) {
            // Resprocessing names no scored blank: nothing to preserve, even split is fine.
            return true;
        }
        $values = [];
        foreach ($placed as $blankid) {
            if (!isset($scores[$blankid])) {
                // Some blanks are scored but this placed one is not — an even split would
                // credit a blank Canvas awards nothing.
                return false;
            }
            $values[] = $scores[$blankid];
        }
        return !$this->scores_are_uneven($values);
    }

    /**
     * Whether a set of per-blank scores disagree — two or more positive scores that are
     * not all equal. Fewer than two scores (or all equal) is treated as even, so an
     * unscored or equally-weighted item is not rejected.
     *
     * @param array $scores The per-blank scores.
     * @return bool
     */
    protected function scores_are_uneven(array $scores): bool {
        $values = array_values($scores);
        if (count($values) < 2) {
            return false;
        }
        $first = $values[0];
        foreach ($values as $value) {
            if (abs($value - $first) > 1e-6) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map response_label ident -> per-answer feedback HTML.
     *
     * @param DOMElement $item The item element.
     * @return array<string, string>
     */
    protected function label_feedback_map(DOMElement $item): array {
        $feedbacks = [];
        foreach ($item->getElementsByTagNameNS('*', 'itemfeedback') as $fb) {
            if ($fb instanceof DOMElement && $fb->getAttribute('ident') !== '') {
                $feedbacks[$fb->getAttribute('ident')] = $this->material_text($fb);
            }
        }
        $map = [];
        $resprocessing = $this->first_child_element($item, 'resprocessing');
        if ($resprocessing !== null) {
            foreach ($resprocessing->getElementsByTagNameNS('*', 'respcondition') as $cond) {
                if (!($cond instanceof DOMElement)) {
                    continue;
                }
                $ves = $cond->getElementsByTagNameNS('*', 'varequal');
                $df = $cond->getElementsByTagNameNS('*', 'displayfeedback');
                if ($ves->length === 1 && $df->length >= 1) {
                    $ident = trim($ves->item(0)->textContent);
                    $ref = $df->item(0) instanceof DOMElement ? $df->item(0)->getAttribute('linkrefid') : '';
                    if ($ident !== '' && isset($feedbacks[$ref])) {
                        $map[$ident] = $feedbacks[$ref];
                    }
                }
            }
        }
        return $map;
    }

    /**
     * Read the value of an itemmetadata qtimetadatafield by label.
     *
     * @param DOMElement $item The item element.
     * @param string $label The fieldlabel to find.
     * @return string The fieldentry value, or ''.
     */
    protected function metadata_field(DOMElement $item, string $label): string {
        foreach ($item->getElementsByTagNameNS('*', 'qtimetadatafield') as $field) {
            if (!($field instanceof DOMElement)) {
                continue;
            }
            $l = $field->getElementsByTagNameNS('*', 'fieldlabel');
            $e = $field->getElementsByTagNameNS('*', 'fieldentry');
            if ($l->length && $e->length && trim($l->item(0)->textContent) === $label) {
                return trim($e->item(0)->textContent);
            }
        }
        return '';
    }

    /**
     * The prompt text: every material that belongs to the question prompt rather
     * than an answer. It is normally a single direct child of presentation, but
     * Canvas may wrap the whole presentation in a <flow> and interleave several
     * prompt fragments around the dropdown blanks, so descend through wrappers and
     * join all materials that are not inside a response_lid or response_str (those
     * carry option/blank text, not the prompt), in document order.
     *
     * @param DOMElement $presentation The presentation element.
     * @return string HTML.
     */
    protected function prompt_text(DOMElement $presentation): string {
        $parts = [];
        foreach ($presentation->getElementsByTagNameNS('*', 'material') as $material) {
            if (!($material instanceof DOMElement)) {
                continue;
            }
            if ($this->within($material, 'response_lid') || $this->within($material, 'response_str')) {
                continue;
            }
            $text = $this->mattext($material);
            if (trim($text) !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n", $parts);
    }

    /**
     * The mattext HTML inside the first material descendant of a node.
     *
     * @param DOMElement $node The container (e.g. response_label, itemfeedback).
     * @return string HTML.
     */
    protected function material_text(DOMElement $node): string {
        $material = $this->descendant($node, 'material');
        return $material !== null ? $this->mattext($material) : '';
    }

    /**
     * Concatenate the mattext children of a material element.
     *
     * @param DOMElement $material The material element.
     * @return string HTML.
     */
    protected function mattext(DOMElement $material): string {
        $parts = [];
        foreach ($material->getElementsByTagNameNS('*', 'mattext') as $mt) {
            $parts[] = $mt->textContent;
        }
        return trim(implode("\n", $parts));
    }

    /**
     * Read a named itemfeedback's text.
     *
     * @param DOMElement $item The item element.
     * @param string $ident The itemfeedback ident.
     * @return string HTML, or ''.
     */
    protected function feedback_text(DOMElement $item, string $ident): string {
        foreach ($item->getElementsByTagNameNS('*', 'itemfeedback') as $fb) {
            if ($fb instanceof DOMElement && $fb->getAttribute('ident') === $ident) {
                return $this->material_text($fb);
            }
        }
        return '';
    }

    /**
     * Derive a short question name from the item title or its text.
     *
     * @param DOMElement $item The item element.
     * @param string $questiontext The question HTML.
     * @return string
     */
    protected function derive_name(DOMElement $item, string $questiontext): string {
        $title = trim($item->getAttribute('title'));
        if ($title !== '') {
            return $this->shorten($title);
        }
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($questiontext, ENT_QUOTES | ENT_HTML5))));
        return $plain !== '' ? $this->shorten($plain) : 'Question';
    }

    /**
     * Trim a string to a reasonable question-name length.
     *
     * @param string $text The text.
     * @return string
     */
    protected function shorten(string $text): string {
        if (mb_strlen($text) <= 80) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, 77)) . '...';
    }

    /**
     * First direct child element with the given local name.
     *
     * @param DOMElement $parent The parent.
     * @param string $localname The local name.
     * @return DOMElement|null
     */
    protected function first_child_element(DOMElement $parent, string $localname): ?DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localname) {
                return $child;
            }
        }
        return null;
    }

    /**
     * First descendant element with the given local name.
     *
     * @param DOMElement $parent The parent.
     * @param string $localname The local name.
     * @return DOMElement|null
     */
    protected function descendant(DOMElement $parent, string $localname): ?DOMElement {
        $nodes = $parent->getElementsByTagNameNS('*', $localname);
        $first = $nodes->item(0);
        return $first instanceof DOMElement ? $first : null;
    }

    /**
     * Whether a node has an ancestor with the given local name.
     *
     * @param DOMNode $node The node.
     * @param string $localname The ancestor local name to look for.
     * @return bool
     */
    protected function within(DOMNode $node, string $localname): bool {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof DOMElement && $p->localName === $localname) {
                return true;
            }
        }
        return false;
    }
}
