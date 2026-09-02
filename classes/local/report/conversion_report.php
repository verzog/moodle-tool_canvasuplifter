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
use tool_canvasuplifter\local\parser\events_parser;
use tool_canvasuplifter\local\parser\outcomes_parser;
use tool_canvasuplifter\local\parser\qti_parser;
use tool_canvasuplifter\local\parser\source_detector;

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
     * @var bool Set while tallying the question matrix when at least one all-or-nothing
     *           categorization was converted to a partial-credit match, so the report can
     *           warn that those questions' grading should be reviewed.
     */
    private bool $categorizationapprox = false;

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

        // Build the question-type matrix first: it sets $this->categorizationapprox as a
        // side effect, which the warnings below read.
        $questionmatrix = $this->question_type_matrix();

        // Warnings are language string keys, resolved to text by the caller.
        $warnings = [];
        // A Blackboard-native package is not Common Cartridge, so nothing builds from
        // it; lead with a clear, actionable message rather than a wall of
        // "unclassified" items so the user knows to re-export as Canvas CC.
        if ($this->course->source === source_detector::BLACKBOARD_NATIVE) {
            $warnings[] = 'warnblackboardnative';
        }
        if (($counts[item::KIND_UNKNOWN] ?? 0) > 0) {
            $warnings[] = 'warnreportunclassified';
        }
        if (($counts[item::KIND_LTI] ?? 0) > 0) {
            $warnings[] = 'warnreportlti';
        }
        // Course-navigation external tools with no launch configuration in the
        // package (and not already imported as a module item) cannot be built, so
        // nudge the admin to add them by hand rather than dropping them silently.
        if ($this->course->navtoolsunimported > 0) {
            $warnings[] = 'warnreportnavtools';
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
        // A Canvas all-or-nothing categorization imports as a partial-credit Moodle match,
        // so partially-correct responses score higher than Canvas would award; flag it so a
        // grader can review the converted questions' grading.
        if ($this->categorizationapprox) {
            $warnings[] = 'warnreportcategorization';
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
            'questionmatrix' => $questionmatrix,
            'outcomes' => $this->outcomes_summary(),
            'events' => $this->events_summary(),
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
     * Summarise the package's calendar events so the analyse preview reflects them (the
     * build creates a course calendar event per event with a usable start time and skips
     * those without one). Requires a package root; returns an empty array when there is no
     * events file or it holds none.
     *
     * @return array Empty, or {total:int, importable:int, skipped:int, malformed:bool}.
     */
    protected function events_summary(): array {
        if ($this->packageroot === null) {
            return [];
        }
        $path = $this->packageroot . '/course_settings/events.xml';
        if (!is_readable($path)) {
            return [];
        }
        $parser = new events_parser();
        $events = $parser->parse((string) @file_get_contents($path));
        if ($parser->malformed) {
            // File present but unreadable as XML: flag the total loss rather than report it
            // as a package with no events.
            return ['total' => 0, 'importable' => 0, 'skipped' => 0, 'malformed' => true];
        }
        if (empty($events)) {
            return [];
        }
        $importable = 0;
        foreach ($events as $event) {
            // Mirrors calendar_builder: an event needs a usable start time to be placed.
            if ($event->timestart > 0) {
                $importable++;
            }
        }
        $total = count($events);
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
        // Tally counters as each assessment/bank is parsed, so a large package's
        // parsed question objects are released before the next file is read rather
        // than all being held until a final pass.
        $acc = ['supported' => [], 'incomplete' => [], 'unsupported' => [],
            'incompletesources' => [], 'unsupportedsources' => []];
        $total = 0;
        $bankids = [];             // Unique item-bank ids a built quiz draws from.
        $standalonebankids = [];   // Bank ids already tallied as a standalone objectbank item.
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
            // A standalone objectbank builds through the shared registry from its own native
            // dump, keyed by bank id and shared with any quiz that draws from it. Tally that
            // exact dump — not resolve_qti()'s first XML, which may be an earlier sibling file
            // in the same resource — once per bank id, and record the id so a repeated resource
            // (or a quiz draw below) can't double-count the one shared bank the build imports.
            if ($modelitem->kind === item::KIND_QUESTIONBANK && $modelitem->objectbankid !== '') {
                $path = $this->standalone_bank_path($modelitem);
                $identity = $path !== null ? (realpath($path) ?: $path) : $modelitem->objectbankid;
                if (isset($standalonebankids[$identity])) {
                    continue;
                }
                $standalonebankids[$identity] = true;
                $parsed = $path !== null
                    ? (new qti_parser())->parse((string) @file_get_contents($path))
                    : ['questions' => [], 'unresolved' => 0];
                $source = $this->display_title($modelitem, $referenced);
                if (!empty($parsed['questions'])) {
                    $total += $this->tally_batch($acc, $parsed['questions'], $source);
                }
                // A bank whose <item>s are only bare references (Canvas omitted their bodies)
                // yields no questions but real data loss the build reports as a skip; surface
                // it so Analyze doesn't advertise the bank as fully importable via an empty
                // matrix.
                $total += $this->tally_omitted($acc, (int) ($parsed['unresolved'] ?? 0), $source);
                continue;
            }
            // Only a referenced quiz takes the quiz_builder path, which alone adopts
            // the native dump for a pure bank draw and resolves <selection_ordering>.
            // An orphan quiz or any question bank builds through questionbank_builder,
            // which does neither, so parse it that way to match what the build evaluates.
            $quizpath = $referenced && $modelitem->kind === item::KIND_QUIZ;
            $parsed = $this->assessment_parse($modelitem, true, $quizpath);
            // A referenced New Quiz's item-bank draws are imported by quiz_builder — but
            // only from a genuine assessment (hasassessment), and never an explicit
            // zero-question group (which it skips without importing). Orphan draws build
            // through questionbank_builder, which doesn't resolve selections (tracked in
            // #144), so they're excluded here.
            if ($quizpath && !empty($parsed['hasassessment'])) {
                foreach ($parsed['selections'] ?? [] as $selection) {
                    $count = $selection['count'] ?? null;
                    if ($count !== null && (int) $count < 1) {
                        continue;
                    }
                    if (($selection['bank'] ?? '') !== '') {
                        $bankids[(string) $selection['bank']] = true;
                    }
                }
            }
            if (!empty($parsed['questions'])) {
                $total += $this->tally_batch($acc, $parsed['questions'], $this->display_title($modelitem, $referenced));
            }
        }
        // Each referenced item bank is imported once by the build (shared across the
        // quizzes that draw from it), so tally each unique bank a single time under its own
        // name — keyed by resolved file identity so a bank already counted above as a
        // standalone objectbank item (or a second sourcebank_ref differing only by case)
        // isn't tallied twice.
        $seenbanks = $standalonebankids;
        foreach (array_keys($bankids) as $bankid) {
            $identity = $this->bank_identity($bankid);
            if (isset($seenbanks[$identity])) {
                continue;
            }
            $seenbanks[$identity] = true;
            [$questions, $bankname, $unresolved] = $this->bank_questions($bankid);
            $total += $this->tally_batch($acc, $questions, $bankname);
            $total += $this->tally_omitted($acc, $unresolved, $bankname);
        }
        return $this->finalize_matrix($acc, $total);
    }

    /**
     * Fold a bank's unresolved bare item references — questions whose bodies Canvas did not
     * export — into the matrix as an unsupported 'omitted' row sourced to the bank, so the
     * analysis surfaces the same data loss the build reports rather than dropping it silently.
     *
     * @param array $acc Running counters, modified in place.
     * @param int $count The number of unresolved references.
     * @param string $source The bank name graders will see.
     * @return int The number of references tallied.
     */
    private function tally_omitted(array &$acc, int $count, string $source): int {
        if ($count < 1) {
            return 0;
        }
        $acc['unsupported']['omitted'] = ($acc['unsupported']['omitted'] ?? 0) + $count;
        $acc['unsupportedsources']['omitted'][$source] = ($acc['unsupportedsources']['omitted'][$source] ?? 0) + $count;
        return $count;
    }

    /**
     * Fold one assessment's (or bank's) parsed questions into the running matrix
     * counters: importable types by Moodle question type; the rest split into
     * 'incomplete' (a supported type missing data Moodle needs) and 'unsupported'
     * (by Canvas cc_profile), each recording the source name for its dropped rows.
     *
     * @param array $acc Running counters, modified in place.
     * @param array $questions Parsed qti_question objects for one source.
     * @param string $source The assessment/bank name graders will see.
     * @return int The number of questions tallied.
     */
    private function tally_batch(array &$acc, array $questions, string $source): int {
        foreach ($questions as $question) {
            if ($question->is_importable()) {
                $acc['supported'][$question->type] = ($acc['supported'][$question->type] ?? 0) + 1;
                // An all-or-nothing categorization imports as a partial-credit match, which
                // grades partial responses more leniently than Canvas; note it so the report
                // warns the grading should be reviewed.
                if ($question->type === qti_question::TYPE_MATCHING && $question->scoremethod === 'all_or_nothing') {
                    $this->categorizationapprox = true;
                }
            } else if ($question->type === qti_question::TYPE_UNSUPPORTED) {
                $label = $question->profile !== '' ? $question->profile : '(unknown)';
                $acc['unsupported'][$label] = ($acc['unsupported'][$label] ?? 0) + 1;
                $acc['unsupportedsources'][$label][$source] = ($acc['unsupportedsources'][$label][$source] ?? 0) + 1;
            } else {
                // A recognised type that Moodle can't actually save (e.g. a
                // choice question with fewer than two answers).
                $type = $question->type;
                $acc['incomplete'][$type] = ($acc['incomplete'][$type] ?? 0) + 1;
                $acc['incompletesources'][$type][$source] = ($acc['incompletesources'][$type][$source] ?? 0) + 1;
            }
        }
        return count($questions);
    }

    /**
     * Turn the accumulated matrix counters into ordered rows: supported types first
     * (question-type order), then incomplete, then unsupported (most-affected first),
     * the latter two carrying their source assessments.
     *
     * @param array $acc The accumulated counters from tally_batch().
     * @param int $total Total questions tallied.
     * @return array Empty, or {total:int, supported:int, rows: array}.
     */
    private function finalize_matrix(array $acc, int $total): array {
        if ($total === 0) {
            return [];
        }
        $supported = $acc['supported'];
        $incomplete = $acc['incomplete'];
        $unsupported = $acc['unsupported'];
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
                'sources' => $this->format_sources($acc['incompletesources'][$type] ?? [])];
        }
        foreach ($unsupported as $profile => $count) {
            $rows[] = ['label' => $profile, 'count' => $count, 'supported' => false, 'status' => 'unsupported',
                'sources' => $this->format_sources($acc['unsupportedsources'][$profile] ?? [])];
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
        $path = $this->resolve_bank_dump($bankid);
        if ($path === null) {
            return [[], '', 0];
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        $name = $parsed['title'] !== '' ? $parsed['title'] : $bankid;
        return [$parsed['questions'], $name, (int) ($parsed['unresolved'] ?? 0)];
    }

    /**
     * The resolved identity of a bank dump — its realpath when it resolves, else the raw
     * bank id — so ids that differ only by case (a New Quiz's sourcebank_ref vs a standalone
     * resource) but name one physical file dedupe to a single matrix tally, matching the
     * build's shared import.
     *
     * @param string $bankid The bank id.
     * @return string
     */
    private function bank_identity(string $bankid): string {
        $path = $this->resolve_bank_dump($bankid);
        return $path !== null ? (realpath($path) ?: $path) : $bankid;
    }

    /**
     * The absolute dump path for a standalone objectbank item: its exact matched
     * objectbankpath when set (so a nested or case-varied path resolves verbatim, matching
     * the build), else the id resolved under non_cc_assessments. Null when unreadable.
     *
     * @param item $modelitem The standalone objectbank item.
     * @return string|null
     */
    private function standalone_bank_path(item $modelitem): ?string {
        if ($modelitem->objectbankpath !== '') {
            return $this->resolve_readable($modelitem->objectbankpath);
        }
        return $this->resolve_bank_dump($modelitem->objectbankid);
    }

    /**
     * Resolve a native item-bank dump (non_cc_assessments/<id>.xml.qti) by bank id,
     * tolerating both the folder and extension case so a dump Canvas exported as e.g.
     * NON_CC_ASSESSMENTS/Pool.XML.QTI still resolves on a case-sensitive filesystem (the
     * bank id strips the suffix case-insensitively). Mirrors the builders' resolver so the
     * report reads the same file the build imports.
     *
     * @param string $bankid The bank id (basename minus the .xml.qti suffix).
     * @return string|null Absolute path within the package, or null.
     */
    private function resolve_bank_dump(string $bankid): ?string {
        $direct = $this->resolve_readable('non_cc_assessments/' . $bankid . '.xml.qti');
        if ($direct !== null) {
            return $direct;
        }
        $dir = $this->resolve_bank_dir();
        if ($dir === null) {
            return null;
        }
        $target = strtolower($bankid . '.xml.qti');
        foreach ((array) @scandir($dir) as $entry) {
            if (strtolower((string) $entry) === $target && is_file($dir . '/' . $entry)) {
                return $dir . '/' . $entry;
            }
        }
        return null;
    }

    /**
     * Resolve the non_cc_assessments folder within the package, tolerating case: the exact
     * name when present, else a case-insensitive match among the package root's entries.
     *
     * @return string|null Absolute path to the folder, or null when absent.
     */
    private function resolve_bank_dir(): ?string {
        $direct = $this->resolve_within('non_cc_assessments');
        if ($direct !== null && is_dir($direct)) {
            return $direct;
        }
        $root = $this->packageroot !== null ? realpath($this->packageroot) : false;
        if ($root === false) {
            return null;
        }
        foreach ((array) @scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..' || strtolower((string) $entry) !== 'non_cc_assessments') {
                continue;
            }
            if (is_dir($root . '/' . $entry)) {
                return $root . '/' . $entry;
            }
        }
        return null;
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
        return $this->assessment_parse($modelitem, $nativefallback, false)['questions'];
    }

    /**
     * The parsed assessment the build would evaluate — the Common Cartridge QTI, or
     * its native non_cc_assessments dump when the CC file is a shell — returning the
     * full parse (questions, selections, hasassessment) so callers can read the
     * item-bank draws as well as the questions. Mirrors the adoption rule of the
     * builder that would run, so the analyse view matches what the build imports.
     *
     * @param item $modelitem The quiz/question-bank item.
     * @param bool $nativefallback Whether to fall back to the native dump.
     * @param bool $quizpath Whether the build takes the quiz_builder path (a referenced
     *                       quiz), which alone adopts the native dump for a pure bank
     *                       draw; false for the questionbank_builder (orphan/bank) path.
     * @return array The chosen parse (empty questions/selections when none resolve).
     */
    protected function assessment_parse(item $modelitem, bool $nativefallback, bool $quizpath = false): array {
        $empty = ['title' => '', 'questions' => [], 'unresolved' => 0, 'hasassessment' => false, 'selections' => []];
        $path = $this->resolve_qti($modelitem);
        if ($path === null) {
            return $empty;
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        // Mirror the builders: fall back to the native dump when the CC file has
        // no *importable* questions (not merely no questions) — a shell may carry
        // unconvertible items while the real, importable ones live in the dump.
        if ($nativefallback && !$this->any_importable($parsed['questions'])) {
            $native = $this->resolve_native_qti($modelitem, $path);
            if ($native !== null) {
                $nativeparsed = (new qti_parser())->parse((string) @file_get_contents($native));
                // Both builders adopt the native dump when it has importable questions.
                // Only quiz_builder also adopts it for a pure item-bank draw, so limit
                // selection-only adoption to the quiz path; questionbank_builder keeps
                // the CC parse (and its own unsupported rows) when the dump has neither.
                $nativeselections = ($quizpath && !empty($nativeparsed['hasassessment']))
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
                'placement' => $this->orphan_placement($modelitem),
            ];
        }
        return $orphans;
    }

    /**
     * Where the build actually places an orphan, so the report's preview matches the
     * course: the syllabus is lifted to the course top; a question bank builds into
     * section 0 as a course-bank activity (never the Additional resources section, which
     * the build won't even create for a bank-only package); everything else lands in the
     * extras section.
     *
     * @param item $modelitem The orphan resource.
     * @return string One of 'top', 'section0', 'extras'.
     */
    private function orphan_placement(item $modelitem): string {
        if ($modelitem->is_syllabus()) {
            return 'top';
        }
        if ($modelitem->kind === item::KIND_QUESTIONBANK) {
            return 'section0';
        }
        return 'extras';
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
