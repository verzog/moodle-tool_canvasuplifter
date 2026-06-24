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

/**
 * Reads a Canvas assessment_meta.xml document into a plain value object.
 *
 * Canvas exports a quiz as a QTI assessment (the questions) plus a sibling
 * assessment_meta.xml carrying the quiz configuration: time limit, allowed
 * attempts, scoring policy, availability dates, navigation, password and so on.
 * The QTI file alone has none of that, so without this parser an imported quiz
 * fell back to generic defaults. This class pulls out the fields Moodle's
 * mod_quiz understands and exposes them as neutral values; the mapping to
 * Moodle's QUIZ_* constants lives in quiz_builder so this stays Moodle-free and
 * unit-testable directly from XML strings.
 *
 * Numeric/string fields use a zero/empty sentinel for "Canvas did not say", so
 * the builder only overrides a default where Canvas actually specified a value.
 * The booleans are nullable for the same reason: null means absent, not false.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_settings {
    /** @var string Quiz title. */
    public string $title = '';

    /** @var string Quiz description / instructions (HTML). */
    public string $description = '';

    /** @var string Canvas quiz_type: assignment, practice_quiz, graded_survey, survey. */
    public string $quiztype = '';

    /** @var float Maximum points (0.0 when not point-graded or unset). */
    public float $points = 0.0;

    /**
     * @var bool Whether Canvas actually exported a points value. Distinguishes an
     *           explicit zero-point assessment (ungraded quiz/survey) from a
     *           package that simply omits points, so the builder can set the
     *           Moodle max grade to 0 in the former case rather than keeping the
     *           generic 100-point default.
     */
    public bool $haspoints = false;

    /**
     * @var int Allowed attempts: Canvas uses -1 for unlimited and a positive
     *          count otherwise. 0 means Canvas did not specify (never a real
     *          Canvas value), so the builder keeps its default.
     */
    public int $allowedattempts = 0;

    /** @var string Canvas scoring_policy: keep_highest, keep_latest, keep_average ('' when unset). */
    public string $scoringpolicy = '';

    /** @var int Time limit in minutes (0 = no limit / unset). */
    public int $timelimit = 0;

    /** @var bool|null Whether to shuffle answers (null when Canvas didn't say). */
    public ?bool $shuffleanswers = null;

    /** @var bool|null Whether students may see the correct answers (null when unset). */
    public ?bool $showcorrectanswers = null;

    /**
     * @var string Canvas hide_results: '' (results shown), 'always' (never shown)
     *             or 'until_after_last_attempt' (shown only once attempts are
     *             used up). Lower-cased.
     */
    public string $hideresults = '';

    /** @var bool|null Whether to present one question per page (null when unset). */
    public ?bool $onequestionatatime = null;

    /** @var bool|null Whether students are prevented from going back (null when unset). */
    public ?bool $cantgoback = null;

    /** @var string Access code required to attempt the quiz ('' when none). */
    public string $accesscode = '';

    /** @var string IP filter / subnet restriction ('' when none). */
    public string $ipfilter = '';

    /** @var int Due date as a Unix timestamp (0 when unset). */
    public int $duedate = 0;

    /** @var int Available-from (unlock) date as a Unix timestamp (0 when unset). */
    public int $unlockat = 0;

    /** @var int Lock (no-more-attempts) date as a Unix timestamp (0 when unset). */
    public int $lockat = 0;

    /**
     * Parse an assessment_meta.xml string into a value object.
     *
     * Reads namespace-agnostically by local name so it copes whether the root
     * <quiz> carries Canvas's default namespace or a prefix. Quiz-level fields
     * are read from the root; availability dates are read from the nested
     * <assignment> child (where Canvas puts them) and fall back to the root for
     * exports that flatten them.
     *
     * @param string $xml The assessment_meta.xml contents.
     * @return self Populated value object (fields stay at defaults on parse failure).
     */
    public static function parse(string $xml): self {
        $settings = new self();
        if (trim($xml) === '') {
            return $settings;
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // LIBXML_NOCDATA flattens <description><![CDATA[...]]></description> so the
        // HTML body is read directly; LIBXML_NONET blocks network access.
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return $settings;
        }
        $root = $dom->documentElement;

        $settings->title = self::child_text($root, 'title');
        $settings->description = self::child_text($root, 'description');
        $settings->quiztype = strtolower(self::child_text($root, 'quiz_type'));
        $settings->scoringpolicy = strtolower(self::child_text($root, 'scoring_policy'));
        $settings->hideresults = strtolower(self::child_text($root, 'hide_results'));
        $settings->accesscode = self::child_text($root, 'access_code');
        $settings->ipfilter = self::child_text($root, 'ip_filter');

        $points = self::child_text($root, 'points_possible');
        $timelimit = self::child_text($root, 'time_limit');
        $attempts = self::child_text($root, 'allowed_attempts');
        if ($timelimit !== '') {
            $settings->timelimit = max(0, (int) round((float) $timelimit));
        }
        if ($attempts !== '') {
            // Canvas writes -1 for unlimited or a positive count; keep it verbatim.
            $settings->allowedattempts = (int) $attempts;
        }

        $settings->shuffleanswers = self::bool_text($root, 'shuffle_answers');
        $settings->showcorrectanswers = self::bool_text($root, 'show_correct_answers');
        $settings->onequestionatatime = self::bool_text($root, 'one_question_at_a_time');
        $settings->cantgoback = self::bool_text($root, 'cant_go_back');

        // Availability dates live inside the <assignment> child for graded
        // quizzes; fall back to the root per field, so a partially flattened
        // export that carries one date on the root and another on the child
        // still surfaces both.
        $assignment = self::first_child_named($root, 'assignment');
        $settings->duedate = self::timestamp(self::date_value($assignment, $root, 'due_at'));
        $settings->unlockat = self::timestamp(self::date_value($assignment, $root, 'unlock_at'));
        $settings->lockat = self::timestamp(self::date_value($assignment, $root, 'lock_at'));
        // The graded quiz's points usually live on the <assignment> child; prefer
        // the quiz-level value when present, otherwise borrow the assignment's.
        if ($points === '' && $assignment !== null) {
            $points = self::child_text($assignment, 'points_possible');
        }
        if ($points !== '') {
            $settings->points = max(0.0, (float) $points);
            $settings->haspoints = true;
        }

        return $settings;
    }

    /**
     * The Moodle timeclose value: Canvas's lock date (the hard cut-off after
     * which no attempt is accepted) when set, falling back to the due date.
     * Moodle's quiz has a single close date rather than Canvas's due/lock pair.
     *
     * @return int Unix timestamp, or 0 when neither date is set.
     */
    public function close_time(): int {
        return $this->lockat !== 0 ? $this->lockat : $this->duedate;
    }

    /**
     * Whether this is a Canvas survey (graded or ungraded) rather than a quiz
     * scored on right/wrong answers.
     *
     * @return bool
     */
    public function is_survey(): bool {
        return $this->quiztype === 'survey' || $this->quiztype === 'graded_survey';
    }

    /**
     * Read a date-ish field preferring the <assignment> child but falling back
     * to the quiz root, per field. Canvas puts availability dates on the child
     * for graded quizzes, but some exports flatten one or more onto the root.
     *
     * @param DOMElement|null $assignment The <assignment> child, or null.
     * @param DOMElement $root The quiz root element.
     * @param string $name Local name of the date field.
     * @return string The first non-empty value (child then root), or ''.
     */
    private static function date_value(?DOMElement $assignment, DOMElement $root, string $name): string {
        if ($assignment !== null) {
            $value = self::child_text($assignment, $name);
            if ($value !== '') {
                return $value;
            }
        }
        return self::child_text($root, $name);
    }

    /**
     * Read and trim the text of the first direct child with the given local name.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to find (namespace-agnostic).
     * @return string Trimmed text, or '' when no such child exists.
     */
    private static function child_text(DOMElement $parent, string $name): string {
        $child = self::first_child_named($parent, $name);
        return $child === null ? '' : trim($child->textContent);
    }

    /**
     * Read a child element's text as a boolean, or null when the child is absent.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to find.
     * @return bool|null The parsed boolean, or null when the child is missing.
     */
    private static function bool_text(DOMElement $parent, string $name): ?bool {
        $child = self::first_child_named($parent, $name);
        if ($child === null) {
            return null;
        }
        return filter_var(trim($child->textContent), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Return the first direct child element with the given local name.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to find.
     * @return DOMElement|null
     */
    private static function first_child_named(DOMElement $parent, string $name): ?DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Convert a Canvas date string to a Unix timestamp.
     *
     * @param string $value An ISO-8601-ish date, or empty.
     * @return int Timestamp, or 0 if empty/unparseable.
     */
    private static function timestamp(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time !== false ? $time : 0;
    }
}
