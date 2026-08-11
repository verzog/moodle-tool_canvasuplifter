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

namespace tool_canvasuplifter\local\report;

use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\model\qti_question;
use tool_canvasuplifter\local\parser\outcomes_parser;
use tool_canvasuplifter\local\parser\qti_parser;

/**
 * Builds a read-only "what is in this package" report from a course model.
 *
 * This is the analyse-only output: it tells an administrator what the package
 * contains and how cleanly each part will map to Moodle, without creating
 * anything. The actual build is handled separately by the course builder.
 *
 * Warnings are returned as language string keys (not text) so this class keeps
 * no Moodle dependency and stays unit-testable; the rendering page resolves
 * them with get_string().
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversion_report {
    /** Maps cleanly and automatically. */
    public const CONFIDENCE_FULL = 'full';
    /** Maps, but some detail may be lost. */
    public const CONFIDENCE_PARTIAL = 'partial';
    /** Imports as a placeholder; needs a human to finish it. */
    public const CONFIDENCE_MANUAL = 'manual';
    /** Cannot be mapped yet. */
    public const CONFIDENCE_NONE = 'none';

    /** File extensions for content modern browsers can no longer play (dead Flash). */
    private const OBSOLETE_EXTENSIONS = ['swf'];

    /** @var course_model The parsed course. */
    protected course_model $course;

    /** @var array<int, string> Memoised file_source_name() result per item object. */
    private array $sourcenamecache = [];

    /** @var string|null Extracted package root, for reading QTI files; null if unavailable. */
    protected ?string $packageroot;

    /** @var string Page-grouping choice to reflect: '' (off), 'book' or 'lesson'. */
    protected string $pagegrouping;

    /** @var bool Whether the run will also build a runnable quiz from each standalone bank. */
    protected bool $quizfrombank;

    /**
     * Constructor.
     *
     * @param course_model $course The parsed course model.
     * @param string|null $packageroot Extracted package root, enabling the question-type matrix.
     * @param string $pagegrouping Page-grouping choice to reflect: '' (off), 'book' or 'lesson'.
     * @param bool $quizfrombank Whether standalone assessments will also build a runnable quiz.
     */
    public function __construct(
        course_model $course,
        ?string $packageroot = null,
        string $pagegrouping = '',
        bool $quizfrombank = false
    ) {
        $this->course = $course;
        $this->packageroot = $packageroot !== null ? rtrim($packageroot, '/') : null;
        $this->pagegrouping = in_array($pagegrouping, ['book', 'lesson'], true) ? $pagegrouping : '';
        $this->quizfrombank = $quizfrombank;
    }

    /**
     * The planned Moodle target for each item kind.
     *
     * The 'note' value is a language string key describing conversion caveats.
     *
     * @return array<string, array{target: string, confidence: string, note: string}>
     */
    public static function mapping_plan(): array {
        $full = self::CONFIDENCE_FULL;
        $partial = self::CONFIDENCE_PARTIAL;
        $manual = self::CONFIDENCE_MANUAL;
        $none = self::CONFIDENCE_NONE;
        return [
            item::KIND_PAGE => ['target' => 'mod_page', 'confidence' => $full, 'note' => 'note_page'],
            item::KIND_FILE => ['target' => 'mod_resource', 'confidence' => $full, 'note' => 'note_file'],
            item::KIND_URL => ['target' => 'mod_url', 'confidence' => $full, 'note' => 'note_url'],
            item::KIND_ASSIGNMENT => ['target' => 'mod_assign', 'confidence' => $partial, 'note' => 'note_assignment'],
            item::KIND_DISCUSSION => ['target' => 'mod_forum', 'confidence' => $partial, 'note' => 'note_discussion'],
            item::KIND_QUIZ => ['target' => 'mod_quiz', 'confidence' => $partial, 'note' => 'note_quiz'],
            item::KIND_QUESTIONBANK => ['target' => 'mod_qbank', 'confidence' => $partial, 'note' => 'note_questionbank'],
            item::KIND_LTI => ['target' => 'mod_lti', 'confidence' => $manual, 'note' => 'note_lti'],
            item::KIND_SUBHEADER => ['target' => 'mod_label', 'confidence' => $full, 'note' => 'note_subheader'],
            item::KIND_UNKNOWN => ['target' => '-', 'confidence' => $none, 'note' => 'note_unknown'],
        ];
    }

    /**
     * Whether the builder can create this item kind in the current phase.
     *
     * @param string $kind One of the item::KIND_* constants.
     * @return bool
     */
    public static function builds_now(string $kind): bool {
        return in_array($kind, item::BUILDS_NOW, true);
    }

    /**
     * Look up the mapping plan entry for a kind, with a safe default.
     *
     * @param string $kind One of the item::KIND_* constants.
     * @return array{target: string, confidence: string, note: string}
     */
    protected function plan_for(string $kind): array {
        $plan = self::mapping_plan();
        return $plan[$kind] ?? ['target' => '-', 'confidence' => self::CONFIDENCE_NONE, 'note' => 'note_unknown'];
    }

    /**
     * The plan the builder will actually follow for an item, accounting for the
     * referenced/orphan split: an unreferenced (orphan) assessment is built as a
     * question bank, while one linked in the course becomes a quiz.
     *
     * @param item $modelitem The item.
     * @param bool $referenced Whether it is linked from the course.
     * @param bool $grouped Whether this page occurrence folds into a book/lesson.
     * @return array Plan {target, confidence, note}.
     */
    protected function effective_plan(item $modelitem, bool $referenced, bool $grouped = false): array {
        if ($modelitem->kind === item::KIND_QUIZ && !$referenced) {
            return $this->plan_for(item::KIND_QUESTIONBANK);
        }
        // A page in a run of two or more consecutive pages folds into a single
        // book/lesson rather than its own mod_page.
        if ($grouped && $modelitem->kind === item::KIND_PAGE) {
            return $this->grouped_page_plan();
        }
        // A Flash resource imports fine but no modern browser will play it, so
        // report it honestly rather than as a clean file conversion.
        if ($this->is_obsolete_file($modelitem)) {
            return ['target' => 'mod_resource', 'confidence' => self::CONFIDENCE_PARTIAL, 'note' => 'note_file_obsolete'];
        }
        return $this->plan_for($modelitem->kind);
    }

    /**
     * The plan a grouped page reports against: a book chapter or lesson page.
     *
     * @return array{target: string, confidence: string, note: string}
     */
    protected function grouped_page_plan(): array {
        if ($this->pagegrouping === 'lesson') {
            return ['target' => 'mod_lesson', 'confidence' => self::CONFIDENCE_FULL, 'note' => 'note_page_grouped_lesson'];
        }
        return ['target' => 'mod_book', 'confidence' => self::CONFIDENCE_FULL, 'note' => 'note_page_grouped_book'];
    }

    /**
     * Per-position "is this page grouped?" flags for one ordered item list,
     * mirroring course_builder::segment_items(): a maximal run of two or more
     * consecutive pages is grouped (a lone page stays a mod_page). Flags are
     * keyed by position so the SAME resource object appearing once in a run and
     * elsewhere as a lone page is reported correctly at each occurrence (the
     * manifest parser shares one object across sections).
     *
     * @param array $items The items in build order.
     * @return array<int, bool> A flag per item index; true where the page groups.
     */
    protected function grouped_flags(array $items): array {
        $flags = array_fill(0, count($items), false);
        if ($this->pagegrouping === '') {
            return $flags;
        }
        $run = [];
        foreach ($items as $i => $modelitem) {
            if ($modelitem->kind === item::KIND_PAGE) {
                $run[] = $i;
                continue;
            }
            $this->flush_run_flags($run, $flags);
            $run = [];
        }
        $this->flush_run_flags($run, $flags);
        return $flags;
    }

    /**
     * Mark a run's positions grouped when it holds two or more pages.
     *
     * @param array $run Item indices of the current consecutive-page run.
     * @param array $flags Per-position flags (modified in place).
     * @return void
     */
    protected function flush_run_flags(array $run, array &$flags): void {
        if (count($run) >= 2) {
            foreach ($run as $pos) {
                $flags[$pos] = true;
            }
        }
    }

    /**
     * Per-position grouped flags for the orphan list, aligned to
     * course_model::$orphans. Only the "extras" (everything but the syllabus,
     * which is lifted to the course top and built individually) are segmented,
     * exactly as course_builder does.
     *
     * @return array<int, bool> A flag per orphan index; true where the page groups.
     */
    protected function orphan_grouped_flags(): array {
        $orphans = $this->course->orphans;
        $flags = array_fill(0, count($orphans), false);
        if ($this->pagegrouping === '') {
            return $flags;
        }
        $extraindices = [];
        $extras = [];
        foreach ($orphans as $i => $modelitem) {
            if (!$modelitem->is_syllabus()) {
                $extraindices[] = $i;
                $extras[] = $modelitem;
            }
        }
        foreach ($this->grouped_flags($extras) as $k => $isgrouped) {
            $flags[$extraindices[$k]] = $isgrouped;
        }
        return $flags;
    }

    /**
     * Add an item to the aggregate map, grouped by content type and the Moodle
     * target it will actually build into.
     *
     * @param array $grouped Accumulator keyed by "kind|target" (modified in place).
     * @param item $modelitem The item.
     * @param bool $referenced Whether it is linked from the course.
     * @param bool $isgrouped Whether this page occurrence folds into a book/lesson.
     * @return void
     */
    protected function accumulate(array &$grouped, item $modelitem, bool $referenced, bool $isgrouped = false): void {
        $plan = $this->effective_plan($modelitem, $referenced, $isgrouped);
        // Include the note so items of one kind and target that convert with
        // different caveats (e.g. a normal file vs an obsolete Flash file, both
        // mod_resource) are reported as separate rows rather than merged.
        $key = $modelitem->kind . '|' . $plan['target'] . '|' . $plan['note'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'kind' => $modelitem->kind,
                'count' => 0,
                'target' => $plan['target'],
                'confidence' => $plan['confidence'],
                'note' => $plan['note'],
                'buildsnow' => self::builds_now($modelitem->kind),
            ];
        }
        $grouped[$key]['count']++;
    }

    /**
     * Count unreferenced quizzes the quiz-from-bank toggle could actually build a
     * runnable quiz from — orphan KIND_QUIZ items with at least one importable
     * question. This is what the nudge is keyed on.
     *
     * An orphan quiz with no importable questions (an empty Canvas QTI shell, or a
     * file of only unsupported types) builds nothing: questionbank_builder returns
     * null, and course_builder only runs the standalone-quiz build when the bank
     * built, so enabling the toggle would create neither a bank nor a quiz.
     * Nudging for it would promise something the toggle can't deliver.
     * KIND_QUESTIONBANK orphans are excluded too — they are inherently banks and
     * the toggle never turns them into quizzes. Determining importability needs
     * package access to read the QTI; without it the count is zero (no
     * over-promising).
     *
     * @return int Number of unreferenced, buildable quiz items.
     */
    protected function orphan_quiz_count(): int {
        $count = 0;
        foreach ($this->course->orphans as $modelitem) {
            if ($modelitem->kind === item::KIND_QUIZ && $this->quiz_has_importable_questions($modelitem)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether an assessment has at least one question Moodle can import, so it
     * would build a question bank (and, with the quiz-from-bank toggle, a runnable
     * quiz). Mirrors the questionbank_builder/quiz_builder eligibility gate.
     * Requires package access to read the QTI; returns false without it.
     *
     * @param item $modelitem The assessment item.
     * @return bool
     */
    protected function quiz_has_importable_questions(item $modelitem): bool {
        // Since #127 questionbank_builder falls back to the native dump, so an
        // orphan New-Quiz shell whose native questions are importable does build a
        // bank (and, with the toggle, a runnable quiz); judge with the same native
        // fallback so the nudge count matches what would actually build.
        return $this->any_importable($this->assessment_questions($modelitem, true));
    }

    /**
     * Count how many items there are of each kind.
     *
     * @return array<string, int> Keyed by item kind.
     */
    public function counts_by_kind(): array {
        $counts = [];
        foreach ($this->course->all_items() as $modelitem) {
            $counts[$modelitem->kind] = ($counts[$modelitem->kind] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Group items by their raw Common Cartridge resource type.
     *
     * Useful for diagnosing why a package has "unknown" items: it shows the
     * exact `type=` strings the manifest carried so the classifier can be
     * tightened up.
     *
     * @param string|null $onlykind If set, restrict to items of this {@see item}::KIND_* value.
     * @return array<string, int> Map of raw type -> count, ordered by descending count.
     */
    public function counts_by_resourcetype(?string $onlykind = null): array {
        $counts = [];
        foreach ($this->course->all_items() as $modelitem) {
            if ($onlykind !== null && $modelitem->kind !== $onlykind) {
                continue;
            }
            $type = $modelitem->resourcetype !== '' ? $modelitem->resourcetype : '(empty)';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Produce the full report as a structured array, ready to render.
     *
     * Includes aggregate rows (with builds-now status and a note key), a
     * per-section item breakdown, unreferenced resources, and warning keys.
     *
     * @return array<string, mixed>
     */
    public function build(): array {
        $counts = $this->counts_by_kind();

        // Group by content type and the target it will actually build into, so a
        // kind that splits by reference (e.g. quiz -> mod_quiz when linked,
        // mod_qbank when orphaned) is reported honestly. Grouping is resolved per
        // list position, matching the builder's per-section segmentation.
        $grouped = [];
        foreach ($this->course->sections as $sectionmodel) {
            $flags = $this->grouped_flags($sectionmodel->items);
            foreach ($sectionmodel->items as $i => $modelitem) {
                $this->accumulate($grouped, $modelitem, true, $flags[$i]);
            }
        }
        $orphanflags = $this->orphan_grouped_flags();
        foreach ($this->course->orphans as $i => $modelitem) {
            $this->accumulate($grouped, $modelitem, false, $orphanflags[$i]);
        }
        ksort($grouped);

        $rows = [];
        $buildsnowtotal = 0;
        $latertotal = 0;
        foreach ($grouped as $row) {
            $rows[] = $row;
            if ($row['buildsnow']) {
                $buildsnowtotal += $row['count'];
            } else {
                $latertotal += $row['count'];
            }
        }

        // Warnings are language string keys, resolved to text by the caller.
        $warnings = [];
        if (($counts[item::KIND_UNKNOWN] ?? 0) > 0) {
            $warnings[] = 'warnreportunclassified';
        }
        if (($counts[item::KIND_LTI] ?? 0) > 0) {
            $warnings[] = 'warnreportlti';
        }
        if (($counts[item::KIND_QUIZ] ?? 0) > 0 || ($counts[item::KIND_QUESTIONBANK] ?? 0) > 0) {
            $warnings[] = 'warnreportquiz';
        }
        // Unreferenced quizzes build as question banks only; when the runnable-quiz
        // toggle is off, nudge the user toward it so the downgrade isn't a surprise.
        if (!$this->quizfrombank && $this->orphan_quiz_count() > 0) {
            $warnings[] = 'warnreportquizfrombank';
        }
        if ($this->has_obsolete_files()) {
            $warnings[] = 'warnreportobsolete';
        }
        if ($this->duplicate_file_count() > 0) {
            $warnings[] = 'warnreportduplicates';
        }

        return [
            'coursename' => $this->course->fullname,
            'source' => $this->course->source,
            'sectioncount' => count($this->course->sections),
            'itemcount' => count($this->course->all_items()),
            'buildsnowtotal' => $buildsnowtotal,
            'latertotal' => $latertotal,
            'rows' => $rows,
            'sections' => $this->section_detail(),
            'orphans' => $this->orphan_detail(),
            'warnings' => $warnings,
            'unknowntypes' => $this->counts_by_resourcetype(item::KIND_UNKNOWN),
            'questionmatrix' => $this->question_type_matrix(),
            'outcomes' => $this->outcomes_summary(),
        ];
    }

    /**
     * Summarise the Canvas learning outcomes the build would import, so the
     * analyse preview reflects them (the build creates a course grade outcome per
     * usable outcome and skips those whose ratings can't form a scale). Requires a
     * package root; returns an empty array when there is no outcomes file or it
     * holds none.
     *
     * @return array Empty, or {total:int, importable:int, skipped:int}.
     */
    protected function outcomes_summary(): array {
        if ($this->packageroot === null) {
            return [];
        }
        $path = $this->packageroot . '/course_settings/learning_outcomes.xml';
        if (!is_readable($path)) {
            return [];
        }
        $parser = new outcomes_parser();
        $outcomes = $parser->parse((string) @file_get_contents($path));
        if ($parser->malformed) {
            // File present but unreadable as XML: flag the total loss rather than
            // report it as a package with no outcomes.
            return ['total' => 0, 'importable' => 0, 'skipped' => 0, 'malformed' => true];
        }
        if (empty($outcomes)) {
            return [];
        }
        $importable = 0;
        foreach ($outcomes as $outcome) {
            // Mirrors outcome_builder: a usable scale needs two distinct labels.
            if (count($outcome->scale_labels()) >= 2) {
                $importable++;
            }
        }
        $total = count($outcomes);
        return ['total' => $total, 'importable' => $importable, 'skipped' => $total - $importable, 'malformed' => false];
    }

    /**
     * Tally the question types across all quiz/question-bank packages.
     *
     * Requires a package root (set in the constructor); without it the matrix is
     * empty. Supported types are listed by Moodle question type; anything we
     * can't convert is listed by its raw Canvas cc_profile so it's obvious what
     * will be skipped.
     *
     * @return array Empty, or {total:int, supported:int, rows: array} of rows
     *               {label:string, count:int, supported:bool, status:string,
     *               sources: array}. status is 'yes' (imports), 'incomplete' (a
     *               supported type missing data Moodle needs, e.g. a single-option
     *               choice) or 'unsupported' (a type we cannot map). For skipped
     *               rows (incomplete/unsupported) sources lists the assessments the
     *               dropped questions came from as {name:string, count:int},
     *               most-affected first; converting rows carry an empty list.
     */
    public function question_type_matrix(): array {
        if ($this->packageroot === null) {
            return [];
        }
        // Collect every (question, source-name) pair the build would import, then
        // tally by type. The source is the assessment (or item bank) name graders
        // will see, so a dropped question is attributed where they can find it.
        $pairs = [];
        $bankids = [];             // Unique item-bank ids referenced by a built quiz.
        // Walk orphans then section items (matching all_items() order) but keep the
        // referenced flag: an orphan quiz builds as a named bank, and only a
        // referenced quiz (quiz_builder) resolves its item-bank draws.
        $entries = [];
        foreach ($this->course->orphans as $modelitem) {
            $entries[] = [$modelitem, false];
        }
        foreach ($this->course->sections as $sectionmodel) {
            foreach ($sectionmodel->items as $modelitem) {
                $entries[] = [$modelitem, true];
            }
        }
        foreach ($entries as [$modelitem, $referenced]) {
            if (!in_array($modelitem->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true)) {
                continue;
            }
            // Both builders fall back to the native non_cc_assessments dump when the
            // Common Cartridge QTI is an empty shell: a referenced quiz through
            // quiz_builder, an orphan quiz/bank through questionbank_builder (#127).
            $parsed = $this->assessment_parse($modelitem, true);
            // A referenced New Quiz can draw questions from a separate item bank via
            // <selection_ordering>/<sourcebank_ref>; quiz_builder imports each bank,
            // so its questions are part of what builds. Orphan assessments build as
            // banks and don't (yet) resolve selections, so their draws are excluded.
            if ($referenced) {
                foreach ($parsed['selections'] ?? [] as $selection) {
                    if (($selection['bank'] ?? '') !== '') {
                        $bankids[(string) $selection['bank']] = true;
                    }
                }
            }
            if (empty($parsed['questions'])) {
                continue;
            }
            $assessment = $this->display_title($modelitem, $referenced);
            foreach ($parsed['questions'] as $question) {
                $pairs[] = [$question, $assessment];
            }
        }
        // Each referenced item bank is imported once by the build (shared across the
        // quizzes that draw from it), so tally each unique bank a single time under
        // its own name.
        foreach (array_keys($bankids) as $bankid) {
            [$questions, $bankname] = $this->bank_questions($bankid);
            foreach ($questions as $question) {
                $pairs[] = [$question, $bankname];
            }
        }
        return $this->tally_matrix($pairs);
    }

    /**
     * Tally a list of [qti_question, source-name] pairs into the question-type
     * matrix: importable types are counted by Moodle question type; the rest are
     * split into 'incomplete' (a supported type missing data Moodle needs) and
     * 'unsupported' (by Canvas cc_profile), each carrying the source assessments
     * their dropped questions came from.
     *
     * @param array $pairs List of [qti_question, string source-name].
     * @return array Empty, or {total:int, supported:int, rows: array}.
     */
    private function tally_matrix(array $pairs): array {
        $supported = [];           // Importable, keyed by Moodle question type.
        $incomplete = [];          // Supported type Moodle would reject, keyed by type.
        $unsupported = [];         // Unrecognised, keyed by Canvas cc_profile.
        $incompletesources = [];   // Type -> [assessment name -> count] for dropped questions.
        $unsupportedsources = [];  // Profile -> [assessment name -> count] for dropped questions.
        $total = 0;
        foreach ($pairs as [$question, $assessment]) {
            $total++;
            if ($question->is_importable()) {
                $supported[$question->type] = ($supported[$question->type] ?? 0) + 1;
            } else if ($question->type === qti_question::TYPE_UNSUPPORTED) {
                $label = $question->profile !== '' ? $question->profile : '(unknown)';
                $unsupported[$label] = ($unsupported[$label] ?? 0) + 1;
                $unsupportedsources[$label][$assessment] = ($unsupportedsources[$label][$assessment] ?? 0) + 1;
            } else {
                // A recognised type that Moodle can't actually save (e.g. a
                // choice question with fewer than two answers).
                $incomplete[$question->type] = ($incomplete[$question->type] ?? 0) + 1;
                $incompletesources[$question->type][$assessment] = ($incompletesources[$question->type][$assessment] ?? 0) + 1;
            }
        }
        if ($total === 0) {
            return [];
        }
        ksort($supported);
        ksort($incomplete);
        arsort($unsupported);
        $rows = [];
        $supportedtotal = 0;
        foreach ($supported as $type => $count) {
            $rows[] = ['label' => $type, 'count' => $count, 'supported' => true, 'status' => 'yes', 'sources' => []];
            $supportedtotal += $count;
        }
        foreach ($incomplete as $type => $count) {
            $rows[] = ['label' => $type, 'count' => $count, 'supported' => false, 'status' => 'incomplete',
                'sources' => $this->format_sources($incompletesources[$type] ?? [])];
        }
        foreach ($unsupported as $profile => $count) {
            $rows[] = ['label' => $profile, 'count' => $count, 'supported' => false, 'status' => 'unsupported',
                'sources' => $this->format_sources($unsupportedsources[$profile] ?? [])];
        }
        return ['total' => $total, 'supported' => $supportedtotal, 'rows' => $rows];
    }

    /**
     * Read an item bank (non_cc_assessments/<id>.xml.qti) a New Quiz draws from,
     * returning its parsed questions and the display name graders will see (its
     * bank_title, else the bank id). Empty list and '' when the file is absent.
     *
     * @param string $bankid The Canvas sourcebank_ref (a package resource id).
     * @return array [qti_question array, string bank name].
     */
    protected function bank_questions(string $bankid): array {
        $path = $this->resolve_within('non_cc_assessments/' . $bankid . '.xml.qti');
        if ($path === null) {
            return [[], ''];
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        $name = $parsed['title'] !== '' ? $parsed['title'] : $bankid;
        return [$parsed['questions'], $name];
    }

    /**
     * Order an assessment-name => count map into a source list for a skipped
     * question-type row, most-affected assessment first.
     *
     * @param array $counts Map of assessment display name to dropped-question count.
     * @return array List of {name: string, count: int}, count-descending.
     */
    private function format_sources(array $counts): array {
        arsort($counts);
        $sources = [];
        foreach ($counts as $name => $count) {
            $sources[] = ['name' => (string) $name, 'count' => (int) $count];
        }
        return $sources;
    }

    /**
     * The package file name that best identifies a resource, for extension and
     * duplicate checks. Mirrors file_builder::source_path(): it tries the href
     * first, then each file in order, and — when the package root is available —
     * skips candidates that do not resolve to a readable file, so the report
     * judges the payload Moodle will actually import. A resource whose stale
     * href="missing.html" falls through to a readable slides.swf is therefore
     * judged on the Flash file, and one whose readable href="ex/index.html" lists
     * a secondary ex/movie.swf first is judged on the HTML. Without package
     * access it falls back to the same ordering on the raw manifest names.
     *
     * @param item $modelitem The resource.
     * @return string A file name, possibly path-qualified; '' when none is known.
     */
    private function file_source_name(item $modelitem): string {
        // The same item is examined across several passes (accumulate, the
        // detail views, the obsolete/duplicate scans); resolving it — which
        // stats the filesystem when a package root is set — once per object
        // keeps a large package to a single pass of syscalls.
        $cachekey = spl_object_id($modelitem);
        if (isset($this->sourcenamecache[$cachekey])) {
            return $this->sourcenamecache[$cachekey];
        }
        $candidates = [];
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        $candidates = array_merge($candidates, $modelitem->files);
        $name = $this->pick_source_name($candidates, $modelitem->title);
        $this->sourcenamecache[$cachekey] = $name;
        return $name;
    }

    /**
     * Choose the payload name from a resource's ordered candidate paths, mirroring
     * file_builder::source_path(): with package access, the first candidate that
     * resolves to a readable file; otherwise the first named candidate, else the
     * fallback title.
     *
     * @param array $candidates Ordered candidate paths (href first, then files).
     * @param string $fallback The title to use when no candidate is usable.
     * @return string
     */
    private function pick_source_name(array $candidates, string $fallback): string {
        if ($this->packageroot !== null) {
            foreach ($candidates as $relative) {
                if ($this->resolve_readable((string) $relative) !== null) {
                    return (string) $relative;
                }
            }
        }
        foreach ($candidates as $relative) {
            if ((string) $relative !== '') {
                return (string) $relative;
            }
        }
        return $fallback;
    }

    /**
     * Resolve a package-relative path to a readable path safely within the package
     * root. Returns null when there is no package root, the path escapes it, or the
     * target is missing or unreadable. Shared by resolve_readable() and
     * resolve_qti() so the boundary check lives in one place.
     *
     * @param string $relative The package-relative candidate path.
     * @return string|null The absolute path, or null.
     */
    private function resolve_within(string $relative): ?string {
        if ($this->packageroot === null || $relative === '') {
            return null;
        }
        $root = realpath($this->packageroot);
        if ($root === false) {
            return null;
        }
        $absolute = realpath($this->packageroot . '/' . ltrim($relative, '/'));
        if ($absolute === false || !is_readable($absolute)) {
            return null;
        }
        // Require a directory boundary, not a bare string prefix, so a sibling
        // directory sharing the root's name (e.g. "<root>-x") cannot pass.
        return ($absolute === $root || str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) ? $absolute : null;
    }

    /**
     * Resolve a package-relative path to a readable file (not a directory) within
     * the package root, mirroring the readability check the builder applies before
     * it serves a candidate.
     *
     * @param string $relative The package-relative candidate path.
     * @return string|null The absolute path, or null.
     */
    private function resolve_readable(string $relative): ?string {
        $absolute = $this->resolve_within($relative);
        return ($absolute !== null && is_file($absolute)) ? $absolute : null;
    }

    /**
     * Whether a file resource is an obsolete format (dead Flash) that imports as a
     * file but will not play in a modern browser.
     *
     * @param item $modelitem The resource.
     * @return bool
     */
    private function is_obsolete_file(item $modelitem): bool {
        if ($modelitem->kind !== item::KIND_FILE) {
            return false;
        }
        $ext = strtolower(pathinfo($this->file_source_name($modelitem), PATHINFO_EXTENSION));
        return in_array($ext, self::OBSOLETE_EXTENSIONS, true);
    }

    /**
     * Whether the package carries any obsolete-format (Flash) file resource.
     *
     * @return bool
     */
    private function has_obsolete_files(): bool {
        foreach ($this->course->all_items() as $modelitem) {
            if ($this->is_obsolete_file($modelitem)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count file resources that look like duplicate copies of another file.
     *
     * A file is counted only when stripping a trailing " (n)" or short "-n" copy
     * marker from its name yields a different name that another imported file
     * already uses, so a genuine copy (an original plus its "(2)"/"-1" sibling) is
     * flagged. This is a heuristic, kept conservative: files that share no bare
     * original (Lesson1 vs Lesson2, with no Lesson) are not flagged, and a
     * four-digit year suffix (syllabus-2024 vs syllabus) is treated as a distinct
     * edition rather than a copy. A same-name file that carries no copy marker at
     * all is likewise not counted.
     *
     * @return int Number of resources that duplicate an earlier file.
     */
    private function duplicate_file_count(): int {
        $names = [];
        foreach ($this->course->all_items() as $modelitem) {
            if ($modelitem->kind !== item::KIND_FILE) {
                continue;
            }
            $base = strtolower(basename($this->file_source_name($modelitem)));
            if ($base !== '') {
                $names[$base] = true;
            }
        }
        $count = 0;
        foreach ($this->course->all_items() as $modelitem) {
            if ($modelitem->kind !== item::KIND_FILE) {
                continue;
            }
            $base = strtolower(basename($this->file_source_name($modelitem)));
            $stripped = $this->strip_copy_marker($base);
            if ($stripped !== $base && isset($names[$stripped])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Remove a trailing " (n)" or short "-n" copy marker (before the extension)
     * from a file name, so "syllabus (2).pdf" and "syllabus-1.pdf" both reduce to
     * "syllabus.pdf". The "-n" form is limited to one to three digits so a
     * four-digit year suffix ("syllabus-2024.pdf") is left intact and treated as a
     * distinct edition rather than a copy.
     *
     * @param string $name A lower-case base file name.
     * @return string The name with any copy marker removed.
     */
    private function strip_copy_marker(string $name): string {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/\s*\(\d+\)$/', '', $stem);
        $stem = preg_replace('/-\d{1,3}$/', '', $stem);
        $stem = rtrim($stem);
        return $ext !== '' ? $stem . '.' . $ext : $stem;
    }

    /**
     * The questions an assessment would actually build, mirroring the builders:
     * read the Common Cartridge QTI file, and when that is an empty shell (Canvas
     * exports New Quizzes — and some Classic quizzes — with the questions only in
     * its native dump) fall back to non_cc_assessments/<id>.xml.qti. Without this
     * the report reads the shell, finds nothing, and shows an empty question-type
     * matrix for New Quizzes even though the builder imports (and drops) real
     * questions from the native dump.
     *
     * Since #127 both builders use this fallback — quiz_builder for a referenced
     * quiz, questionbank_builder for an orphan quiz or question bank — so callers
     * pass true for every assessment and the matrix reflects what the build imports.
     *
     * @param item $modelitem The quiz/question-bank item.
     * @param bool $nativefallback Whether to fall back to the native dump.
     * @return array The parsed qti_question objects (empty when none resolve).
     */
    protected function assessment_questions(item $modelitem, bool $nativefallback): array {
        return $this->assessment_parse($modelitem, $nativefallback)['questions'];
    }

    /**
     * The parsed assessment the build would evaluate — the Common Cartridge QTI, or
     * its native non_cc_assessments dump when the CC file is a shell — returning the
     * full parse (questions, selections, hasassessment) so callers can read the
     * item-bank draws as well as the questions. Mirrors quiz_builder's adoption
     * rule so the analyse view matches what the build imports.
     *
     * @param item $modelitem The quiz/question-bank item.
     * @param bool $nativefallback Whether to fall back to the native dump.
     * @return array The chosen parse (empty questions/selections when none resolve).
     */
    protected function assessment_parse(item $modelitem, bool $nativefallback): array {
        $empty = ['title' => '', 'questions' => [], 'unresolved' => 0, 'hasassessment' => false, 'selections' => []];
        $path = $this->resolve_qti($modelitem);
        if ($path === null) {
            return $empty;
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        // Mirror quiz_builder: fall back to the native dump when the CC file has
        // no *importable* questions (not merely no questions) — a shell may carry
        // unconvertible items while the real, importable ones live in the dump.
        if ($nativefallback && !$this->any_importable($parsed['questions'])) {
            $native = $this->resolve_native_qti($modelitem, $path);
            if ($native !== null) {
                $nativeparsed = (new qti_parser())->parse((string) @file_get_contents($native));
                // Adopt the native dump when it carries importable questions or its
                // own item-bank selections (matching quiz_builder), so a New Quiz
                // whose real content — inline or bank draws — lives in the dump shows.
                $nativeselections = !empty($nativeparsed['hasassessment'])
                    ? ($nativeparsed['selections'] ?? []) : [];
                if ($this->any_importable($nativeparsed['questions']) || !empty($nativeselections)) {
                    return $nativeparsed;
                }
                // Neither side is importable. When the CC parse is a genuinely
                // empty shell the builder would evaluate nothing, so surface the
                // native (all-unsupported) questions instead of an empty matrix —
                // these New Quizzes are the ones most at risk of silent loss. If
                // the CC file has its own unsupported questions, the builder keeps
                // them, so leave them in place to match. Require hasassessment, as
                // quiz_builder::$isshell does: a malformed or QTI 2.x/3.x CC file
                // also parses to zero questions, but the builder skips it as an
                // unreadable assessment rather than falling through to the dump.
                $isshell = empty($parsed['questions']) && !empty($parsed['hasassessment']);
                if ($isshell && !empty($nativeparsed['questions'])) {
                    return $nativeparsed;
                }
            }
        }
        return $parsed;
    }

    /**
     * Whether any of the given questions is importable.
     *
     * @param array $questions Parsed qti_question objects.
     * @return bool
     */
    private function any_importable(array $questions): bool {
        foreach ($questions as $question) {
            if ($question->is_importable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the native Canvas question dump for an assessment whose Common
     * Cartridge QTI is an empty shell, mirroring quiz_builder::locate_native_qti:
     * an explicit non_cc_assessments/<id>.xml.qti on the item's file list, else
     * the file keyed by the resolved QTI folder id at the package root. Never
     * returns the shell we already parsed.
     *
     * @param item $modelitem The quiz item.
     * @param string $qtipath Absolute path of the resolved CC QTI file.
     * @return string|null Absolute path within the package, or null.
     */
    protected function resolve_native_qti(item $modelitem, string $qtipath): ?string {
        $already = realpath($qtipath);
        foreach ($modelitem->files as $relative) {
            if (!preg_match('~(^|/)non_cc_assessments/[^/]+\.xml\.qti$~i', (string) $relative)) {
                continue;
            }
            $absolute = $this->resolve_within((string) $relative);
            if ($absolute !== null && realpath($absolute) !== $already) {
                return $absolute;
            }
        }
        // Canvas keys the native dump by the assessment id: the CC folder name for
        // a foldered QTI, but the resource identifier when the QTI sits at the
        // package root (where basename(dirname()) yields the extraction dir). Try
        // both, mirroring the builders' locate_native_qti.
        foreach ([basename(dirname($qtipath)), $modelitem->identifier] as $id) {
            if ($id === '' || $id === '.' || $id === '/') {
                continue;
            }
            $absolute = $this->resolve_within('non_cc_assessments/' . $id . '.xml.qti');
            if ($absolute !== null && realpath($absolute) !== $already) {
                return $absolute;
            }
        }
        return null;
    }

    /**
     * Resolve the QTI assessment XML for a quiz/question-bank item, safely
     * within the package root.
     *
     * @param item $modelitem The item.
     * @return string|null Absolute path, or null.
     */
    protected function resolve_qti(item $modelitem): ?string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            // Match the builders' locate_qti(): a Canvas assessment can be a plain
            // .xml or a native .xml.qti dump, and both build, so both make the
            // quiz-from-bank nudge (and the question-type matrix) applicable.
            if (!preg_match('/\.xml(\.qti)?$/i', $relative)) {
                continue;
            }
            $absolute = $this->resolve_within((string) $relative);
            if ($absolute !== null) {
                return $absolute;
            }
        }
        return null;
    }

    /**
     * Per-section, per-item detail for the drill-down view.
     *
     * @return array<int, array{title: string, items: array<int, array{
     *     title: string, kind: string, target: string, confidence: string, buildsnow: bool}>}>
     */
    protected function section_detail(): array {
        $sections = [];
        foreach ($this->course->sections as $sectionmodel) {
            $items = [];
            $flags = $this->grouped_flags($sectionmodel->items);
            foreach ($sectionmodel->items as $i => $modelitem) {
                $entry = $this->effective_plan($modelitem, true, $flags[$i]);
                $items[] = [
                    'title' => $this->display_title($modelitem, true),
                    'kind' => $modelitem->kind,
                    'target' => $entry['target'],
                    'confidence' => $entry['confidence'],
                    'buildsnow' => self::builds_now($modelitem->kind),
                ];
            }
            $sections[] = ['title' => $sectionmodel->title, 'items' => $items];
        }
        return $sections;
    }

    /**
     * Resources present in the package but not referenced by any module.
     *
     * @return array<int, array{title: string, kind: string, target: string, resourcetype: string}>
     */
    protected function orphan_detail(): array {
        $orphans = [];
        $flags = $this->orphan_grouped_flags();
        foreach ($this->course->orphans as $i => $modelitem) {
            $entry = $this->effective_plan($modelitem, false, $flags[$i]);
            $orphans[] = [
                'title' => $this->display_title($modelitem, false),
                'kind' => $modelitem->kind,
                'target' => $entry['target'],
                'resourcetype' => $modelitem->resourcetype,
                // The syllabus is lifted to the course top, not the extras section.
                'placement' => $modelitem->is_syllabus() ? 'top' : 'extras',
            ];
        }
        return $orphans;
    }

    /**
     * Best available display title: explicit title, else source file name,
     * else the raw identifier. When the item will build as a mod_qbank,
     * prefer banktitle so the report matches the activity name graders will
     * see (the bank suffix landing on twin-titled bank/quiz pairs).
     *
     * @param item $modelitem The resource.
     * @param bool $referenced Whether the item is linked from the course.
     * @return string
     */
    protected function display_title(item $modelitem, bool $referenced): string {
        $buildsasbank = $modelitem->kind === item::KIND_QUESTIONBANK
            || ($modelitem->kind === item::KIND_QUIZ && !$referenced);
        if ($buildsasbank && $modelitem->banktitle !== '') {
            return $modelitem->banktitle;
        }
        if ($modelitem->title !== '') {
            return $modelitem->title;
        }
        $source = $modelitem->files[0] ?? ($modelitem->href !== '' ? $modelitem->href : '');
        if ($source !== '') {
            return basename($source);
        }
        return $modelitem->identifier;
    }
}
