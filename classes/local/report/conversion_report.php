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

use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;

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

    /**
     * Constructor.
     *
     * @param course_model $course The parsed course model.
     */
    public function __construct(course_model $course) {
        $this->course = $course;
    }

    /**
     * The planned Moodle target for each item kind.
     *
     * The 'note' value is a language string key describing conversion caveats.
     *
     * @return array<string, array{target: string, confidence: string, note: string}>
     */
    public static function mapping_plan(): array {
        return [
            item::KIND_PAGE => ['target' => 'mod_page', 'confidence' => self::CONFIDENCE_FULL, 'note' => 'note_page'],
            item::KIND_FILE => ['target' => 'mod_resource', 'confidence' => self::CONFIDENCE_FULL, 'note' => 'note_file'],
            item::KIND_URL => ['target' => 'mod_url', 'confidence' => self::CONFIDENCE_FULL, 'note' => 'note_url'],
            item::KIND_ASSIGNMENT => ['target' => 'mod_assign', 'confidence' => self::CONFIDENCE_PARTIAL, 'note' => 'note_assignment'],
            item::KIND_DISCUSSION => ['target' => 'mod_forum', 'confidence' => self::CONFIDENCE_PARTIAL, 'note' => 'note_discussion'],
            item::KIND_QUIZ => ['target' => 'mod_quiz', 'confidence' => self::CONFIDENCE_PARTIAL, 'note' => 'note_quiz'],
            item::KIND_QUESTIONBANK => ['target' => 'mod_qbank', 'confidence' => self::CONFIDENCE_PARTIAL, 'note' => 'note_questionbank'],
            item::KIND_LTI => ['target' => 'mod_lti', 'confidence' => self::CONFIDENCE_MANUAL, 'note' => 'note_lti'],
            item::KIND_UNKNOWN => ['target' => '-', 'confidence' => self::CONFIDENCE_NONE, 'note' => 'note_unknown'],
        ];
    }

    /**
     * Whether the builder can create this item kind in the current phase.
     *
     * @param string $kind One of the item::KIND_* constants.
     * @return bool
     */
    public static function builds_now(string $kind): bool {
        return in_array($kind, course_builder::BUILDS_NOW, true);
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

        $rows = [];
        $buildsnowtotal = 0;
        $latertotal = 0;
        foreach ($counts as $kind => $count) {
            $entry = $this->plan_for($kind);
            $buildsnow = self::builds_now($kind);
            $rows[] = [
                'kind' => $kind,
                'count' => $count,
                'target' => $entry['target'],
                'confidence' => $entry['confidence'],
                'note' => $entry['note'],
                'buildsnow' => $buildsnow,
            ];
            if ($buildsnow) {
                $buildsnowtotal += $count;
            } else {
                $latertotal += $count;
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
        ];
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
            foreach ($sectionmodel->items as $modelitem) {
                $entry = $this->plan_for($modelitem->kind);
                $items[] = [
                    'title' => $modelitem->title,
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
        foreach ($this->course->orphans as $modelitem) {
            $entry = $this->plan_for($modelitem->kind);
            $orphans[] = [
                'title' => $modelitem->title !== '' ? $modelitem->title : $modelitem->identifier,
                'kind' => $modelitem->kind,
                'target' => $entry['target'],
                'resourcetype' => $modelitem->resourcetype,
            ];
        }
        return $orphans;
    }
}
