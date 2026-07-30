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
        // Multichoice and multianswer import as Moodle multichoice, and
        // true/false as Moodle truefalse; all three need at least two answers.
        return $nonempty >= 2;
    }
}
