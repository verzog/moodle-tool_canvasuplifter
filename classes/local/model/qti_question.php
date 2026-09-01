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

namespace tool_canvasuplifter\local\model;

/**
 * One question parsed from a Canvas/CC QTI assessment.
 *
 * Plain data holder with no Moodle dependencies, so the QTI parser and the
 * Moodle-XML writer can be unit-tested in isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qti_question {
    /** Single-answer multiple choice -> Moodle multichoice (single). */
    public const TYPE_MULTICHOICE = 'multichoice';
    /** Multiple-answer multiple choice -> Moodle multichoice (multiple). */
    public const TYPE_MULTIANSWER = 'multianswer';
    /** Fill-in-the-blank / pattern match -> Moodle shortanswer. */
    public const TYPE_SHORTANSWER = 'shortanswer';
    /** True/false -> Moodle truefalse. */
    public const TYPE_TRUEFALSE = 'truefalse';
    /** Essay -> Moodle essay. */
    public const TYPE_ESSAY = 'essay';
    /** Matching (Canvas matching_question) -> Moodle match. */
    public const TYPE_MATCHING = 'matching';
    /** Numerical (Canvas numerical_question) -> Moodle numerical. */
    public const TYPE_NUMERICAL = 'numerical';
    /** Calculated / formula (Canvas calculated_question) -> Moodle calculated. */
    public const TYPE_CALCULATED = 'calculated';
    /** Free-text multi-blank (Canvas fill_in_multiple_blanks_question) -> Moodle Cloze (multianswer). */
    public const TYPE_CLOZE = 'cloze';
    /** Text-only stimulus item (Canvas text_only_question) -> Moodle description. */
    public const TYPE_DESCRIPTION = 'description';
    /** Recognised QTI item we can't yet convert. */
    public const TYPE_UNSUPPORTED = 'unsupported';

    /** @var string One of the TYPE_* constants. */
    public string $type = self::TYPE_UNSUPPORTED;

    /** @var string Short question name. */
    public string $name = '';

    /** @var string Question text (HTML). */
    public string $questiontext = '';

    /** @var float Default mark/weighting. */
    public float $defaultmark = 1.0;

    /**
     * @var array Answer rows. Each: ['text' => string, 'fraction' => float, 'feedback' => string].
     */
    public array $answers = [];

    /**
     * @var array Matching rows (TYPE_MATCHING only). Each: ['text' => string stem HTML,
     *            'answer' => string answer text]. A row with an empty stem is an
     *            extra answer-only distractor, as Moodle's match type allows.
     */
    public array $subquestions = [];

    /** @var string General feedback (HTML), shown after answering. */
    public string $generalfeedback = '';

    /** @var string Essay response format (TYPE_ESSAY only): 'editor', 'noinline', etc. */
    public string $responseformat = 'editor';

    /** @var int Whether an online text response is required for an essay (1) or not (0). */
    public int $responserequired = 1;

    /** @var int Number of file attachments an essay allows: 0, 1, 2, 3, or -1 for unlimited. */
    public int $attachments = 0;

    /** @var int Number of file attachments an essay requires (0..3, not exceeding attachments). */
    public int $attachmentsrequired = 0;

    /** @var string Calculated formula (TYPE_CALCULATED only), in Moodle {var} syntax. */
    public string $formula = '';

    /** @var string Calculated answer tolerance (TYPE_CALCULATED only), a decimal string. */
    public string $answertolerance = '0';

    /** @var string Tolerance kind for a calculated answer: 'absolute' or 'percent'. */
    public string $tolerancekind = 'absolute';

    /** @var int Correct-answer decimal places for a calculated answer, or -1 when unspecified. */
    public int $answerdecimals = -1;

    /**
     * @var array Calculated variable definitions (TYPE_CALCULATED only). Each:
     *            ['name' => string, 'min' => string, 'max' => string, 'decimals' => int].
     */
    public array $variables = [];

    /**
     * @var array Calculated data rows (TYPE_CALCULATED only), one per Canvas var_set.
     *            Each is a map of variable name => value string, aligned across variables
     *            by row so Moodle draws a consistent tuple per generated variant.
     */
    public array $datarows = [];

    /** @var string The raw CC profile, e.g. "cc.fib.v0p1" (for the support matrix). */
    public string $profile = '';

    /**
     * Whether Moodle's question importer can save this question as it stands.
     *
     * Moodle rejects — and rolls the whole import batch back on — choice
     * questions with fewer than two answers and short-answer questions with no
     * answer, so callers should drop questions this returns false for before
     * importing. No Moodle dependency, so it stays unit-testable.
     *
     * @return bool
     */
    public function is_importable(): bool {
        if ($this->type === self::TYPE_UNSUPPORTED) {
            return false;
        }
        if ($this->type === self::TYPE_ESSAY) {
            return true;
        }
        if ($this->type === self::TYPE_DESCRIPTION) {
            // A description carries only text (no answers to validate), so it is
            // always importable.
            return true;
        }
        if ($this->type === self::TYPE_MATCHING) {
            // Moodle's match type needs at least two complete stem/answer pairs
            // and at least two distinct answers to choose between; drop anything
            // thinner so qformat_xml doesn't roll back the whole batch.
            $pairs = 0;
            $answers = [];
            foreach ($this->subquestions as $sub) {
                $stem = trim((string) ($sub['text'] ?? ''));
                $answer = trim((string) ($sub['answer'] ?? ''));
                if ($answer === '') {
                    continue;
                }
                $answers[$answer] = true;
                if ($stem !== '') {
                    $pairs++;
                }
            }
            return $pairs >= 2 && count($answers) >= 2;
        }
        $nonempty = 0;
        foreach ($this->answers as $answer) {
            if (trim((string) ($answer['text'] ?? '')) !== '') {
                $nonempty++;
            }
        }
        if ($this->type === self::TYPE_SHORTANSWER) {
            return $nonempty >= 1;
        }
        if ($this->type === self::TYPE_TRUEFALSE) {
            // Imported as Moodle truefalse, whose writer synthesises the
            // true/false labels — so option display text is not required, but the
            // correct side must be resolvable. Require two options with at least
            // one scoring above zero; a pair the parser could not score (e.g. a
            // negated <not><varequal> condition, which correct_idents() skips)
            // stays unimportable rather than guessing the key.
            $positives = 0;
            foreach ($this->answers as $answer) {
                if (((float) ($answer['fraction'] ?? 0)) > 0) {
                    $positives++;
                }
            }
            return count($this->answers) >= 2 && $positives >= 1;
        }
        if ($this->type === self::TYPE_NUMERICAL) {
            // Moodle numerical needs at least one answer whose text is a number (or
            // the '*' accept-any wildcard); a condition the parser couldn't read a
            // value from is dropped rather than imported as an unanswerable question.
            foreach ($this->answers as $answer) {
                $text = trim((string) ($answer['text'] ?? ''));
                if ($text === '*' || is_numeric($text)) {
                    return true;
                }
            }
            return false;
        }
        if ($this->type === self::TYPE_CALCULATED) {
            // Moodle calculated needs a formula, at least one variable to define a
            // dataset from, and at least one generated data row to build a variant
            // with; anything thinner cannot produce a graded question. The formula
            // must also use only syntax Moodle's calculated grammar accepts, or
            // qformat_xml rejects it and rolls back the whole imported bank — so a
            // formula with an unsupported operator or function is dropped instead.
            return trim($this->formula) !== '' && $this->variables !== [] && $this->datarows !== []
                && $this->calculated_formula_is_supported() && $this->calculated_rows_are_complete();
        }
        if ($this->type === self::TYPE_CLOZE) {
            // A Moodle Cloze carries its sub-questions inline in the question text; it
            // needs at least one embedded field — a SHORTANSWER (free-text blank) or a
            // MULTICHOICE (inline dropdown) — or qtype_multianswer rejects it on import
            // and rolls back the whole batch.
            return (bool) preg_match('/\{[0-9]*:(SHORTANSWER|MULTICHOICE(?:_[A-Z]+)?):/', $this->questiontext);
        }
        // Multichoice and multianswer import as Moodle multichoice; both need at
        // least two answers.
        return $nonempty >= 2;
    }

    /**
     * The functions Moodle's calculated question grammar accepts in a formula
     * (mirrors qtype_calculated's validator). A formula naming any other function is
     * rejected on import, so a calculated question that uses one is treated as not
     * importable.
     */
    private const CALCULATED_FUNCTIONS = [
        'pi', 'abs', 'acos', 'acosh', 'asin', 'asinh', 'atan', 'atanh', 'bindec', 'ceil',
        'cos', 'cosh', 'decbin', 'decoct', 'deg2rad', 'exp', 'expm1', 'floor', 'is_finite',
        'is_infinite', 'is_nan', 'log10', 'log1p', 'octdec', 'rad2deg', 'sin', 'sinh', 'sqrt',
        'tan', 'tanh', 'log', 'round', 'atan2', 'fmod', 'pow', 'min', 'max',
    ];

    /**
     * Whether this calculated question's formula uses only operators and functions
     * Moodle's calculated grammar accepts. Any wildcard is a number for the purposes
     * of the check; every bare identifier must be a supported function name, and only
     * safe operator/number characters may remain once identifiers are removed. Kept
     * conservative so an unsupported formula is dropped (skipped) rather than rolling
     * back the whole imported bank.
     *
     * @return bool
     */
    private function calculated_formula_is_supported(): bool {
        // Treat each {wildcard} as a number, then strip numeric literals — including
        // scientific notation like 1e-3 — so the exponent's 'e'/'E' is not mistaken for
        // a bare function identifier and a valid literal is not rejected.
        $formula = (string) preg_replace('/\{[^}]*\}/', '1', $this->formula);
        $formula = (string) preg_replace('/(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', ' ', $formula);
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $formula, $matches)) {
            foreach ($matches[0] as $identifier) {
                if (!in_array(strtolower($identifier), self::CALCULATED_FUNCTIONS, true)) {
                    return false;
                }
            }
        }
        // Once numbers and function names are stripped, only safe math operators and
        // grouping may remain (** for exponent is two of the safe '*'). Anything else —
        // a caret, a bitwise or comparison operator — means Moodle would reject it.
        $operators = (string) preg_replace('/[A-Za-z_][A-Za-z0-9_]*/', '', $formula);
        return preg_match('~[^.,+\-*/%()\s]~', $operators) === 0;
    }

    /**
     * Whether every generated data row carries a numeric value for every declared
     * calculated variable. A Canvas var_set that omits a variable would otherwise be
     * emitted with an empty dataset value while still claiming the full item count,
     * which Moodle cannot reconstruct — so such a question is dropped instead.
     *
     * @return bool
     */
    private function calculated_rows_are_complete(): bool {
        foreach ($this->datarows as $row) {
            foreach ($this->variables as $variable) {
                $value = trim((string) ($row[$variable['name']] ?? ''));
                if ($value === '' || !is_numeric($value)) {
                    return false;
                }
            }
        }
        return true;
    }
}
