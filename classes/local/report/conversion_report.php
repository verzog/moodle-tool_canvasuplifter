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

    /** @var course_model The parsed course. */
    protected course_model $course;

    /** @var string|null Extracted package root, for reading QTI files; null if unavailable. */
    protected ?string $packageroot;

    /** @var string Page-grouping choice to reflect: '' (off), 'book' or 'lesson'. */
    protected string $pagegrouping;

    /**
     * Constructor.
     *
     * @param course_model $course The parsed course model.
     * @param string|null $packageroot Extracted package root, enabling the question-type matrix.
     * @param string $pagegrouping Page-grouping choice to reflect: '' (off), 'book' or 'lesson'.
     */
    public function __construct(course_model $course, ?string $packageroot = null, string $pagegrouping = '') {
        $this->course = $course;
        $this->packageroot = $packageroot !== null ? rtrim($packageroot, '/') : null;
        $this->pagegrouping = in_array($pagegrouping, ['book', 'lesson'], true) ? $pagegrouping : '';
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
        $key = $modelitem->kind . '|' . $plan['target'];
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

        return [
            'coursename' => $this->course->fullname,
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
        ];
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
     *               {label:string, count:int, supported:bool, status:string}.
     *               status is 'yes' (imports), 'incomplete' (a supported type
     *               missing data Moodle needs, e.g. a single-option choice) or
     *               'unsupported' (a type we cannot map).
     */
    public function question_type_matrix(): array {
        if ($this->packageroot === null) {
            return [];
        }
        $supported = [];     // Importable, keyed by Moodle question type.
        $incomplete = [];    // Supported type Moodle would reject, keyed by type.
        $unsupported = [];   // Unrecognised, keyed by Canvas cc_profile.
        $total = 0;
        foreach ($this->course->all_items() as $modelitem) {
            if (!in_array($modelitem->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true)) {
                continue;
            }
            $path = $this->resolve_qti($modelitem);
            if ($path === null) {
                continue;
            }
            $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
            foreach ($parsed['questions'] as $question) {
                $total++;
                if ($question->is_importable()) {
                    $supported[$question->type] = ($supported[$question->type] ?? 0) + 1;
                } else if ($question->type === qti_question::TYPE_UNSUPPORTED) {
                    $label = $question->profile !== '' ? $question->profile : '(unknown)';
                    $unsupported[$label] = ($unsupported[$label] ?? 0) + 1;
                } else {
                    // A recognised type that Moodle can't actually save (e.g. a
                    // choice question with fewer than two answers).
                    $incomplete[$question->type] = ($incomplete[$question->type] ?? 0) + 1;
                }
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
            $rows[] = ['label' => $type, 'count' => $count, 'supported' => true, 'status' => 'yes'];
            $supportedtotal += $count;
        }
        foreach ($incomplete as $type => $count) {
            $rows[] = ['label' => $type, 'count' => $count, 'supported' => false, 'status' => 'incomplete'];
        }
        foreach ($unsupported as $profile => $count) {
            $rows[] = ['label' => $profile, 'count' => $count, 'supported' => false, 'status' => 'unsupported'];
        }
        return ['total' => $total, 'supported' => $supportedtotal, 'rows' => $rows];
    }

    /**
     * Resolve the QTI assessment XML for a quiz/question-bank item, safely
     * within the package root.
     *
     * @param item $modelitem The item.
     * @return string|null Absolute path, or null.
     */
    protected function resolve_qti(item $modelitem): ?string {
        if ($this->packageroot === null) {
            return null;
        }
        $root = realpath($this->packageroot);
        if ($root === false) {
            return null;
        }
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml$/i', $relative)) {
                continue;
            }
            $absolute = realpath($this->packageroot . '/' . ltrim($relative, '/'));
            if ($absolute === false || !is_readable($absolute)) {
                continue;
            }
            // Require a directory boundary, not a bare string prefix, so a sibling
            // directory sharing the root's name (e.g. "<root>-x") cannot pass.
            if ($absolute === $root || str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
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
