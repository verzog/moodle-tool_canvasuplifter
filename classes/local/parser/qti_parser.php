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
     * @param string $xml The QTI assessment document.
     * @return array{title: string, questions: array, unresolved: int, hasassessment: bool} Parsed assessment.
     */
    public function parse(string $xml): array {
        $result = ['title' => '', 'questions' => [], 'unresolved' => 0, 'hasassessment' => false];
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
        return $result;
    }

    /**
     * Build a question model from a QTI <item> element.
     *
     * @param DOMElement $item The item element.
     * @return qti_question
     */
    protected function build_question(DOMElement $item): qti_question {
        $question = new qti_question();
        $question->profile = $this->metadata_field($item, 'cc_profile');
        $question->defaultmark = (float) ($this->metadata_field($item, 'cc_weighting') ?: 1) ?: 1.0;

        $presentation = $this->first_child_element($item, 'presentation');
        $question->questiontext = $presentation !== null ? $this->prompt_text($presentation) : '';
        $question->name = $this->derive_name($item, $question->questiontext);
        $question->generalfeedback = $this->feedback_text($item, 'general_fb');

        $type = $this->map_profile($question->profile, $presentation);
        $question->type = $type;

        switch ($type) {
            case qti_question::TYPE_MULTICHOICE:
            case qti_question::TYPE_MULTIANSWER:
            case qti_question::TYPE_TRUEFALSE:
                $this->fill_choice_answers($item, $presentation, $question);
                $this->recover_acknowledgment($question);
                break;
            case qti_question::TYPE_SHORTANSWER:
                $this->fill_text_answers($item, $question);
                break;
            case qti_question::TYPE_ESSAY:
            default:
                break;
        }
        return $question;
    }

    /**
     * Map a CC profile to one of our question types.
     *
     * @param string $profile The cc_profile value.
     * @param DOMElement|null $presentation The presentation element (for cardinality).
     * @return string A qti_question::TYPE_* constant.
     */
    protected function map_profile(string $profile, ?DOMElement $presentation): string {
        switch ($profile) {
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

        foreach ($presentation->getElementsByTagNameNS('*', 'response_label') as $label) {
            if (!($label instanceof DOMElement)) {
                continue;
            }
            $ident = $label->getAttribute('ident');
            $iscorrect = in_array($ident, $correct, true);
            $fraction = 0.0;
            if ($iscorrect) {
                $fraction = $question->type === qti_question::TYPE_MULTIANSWER
                    ? round(100 / $numcorrect, 5)
                    : 100.0;
            }
            $question->answers[] = [
                'text' => $this->material_text($label),
                'fraction' => $fraction,
                'feedback' => $feedback[$ident] ?? '',
            ];
        }
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
     * @param qti_question $question The question being built (modified in place).
     * @return void
     */
    protected function fill_text_answers(DOMElement $item, qti_question $question): void {
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
            if ($current !== [] && $score >= $best) {
                $best = $score;
                $idents = $current;
            }
        }
        return $idents;
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
     * The prompt text: the first material that is a direct child of presentation.
     *
     * @param DOMElement $presentation The presentation element.
     * @return string HTML.
     */
    protected function prompt_text(DOMElement $presentation): string {
        foreach ($presentation->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'material') {
                return $this->mattext($child);
            }
        }
        return '';
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
