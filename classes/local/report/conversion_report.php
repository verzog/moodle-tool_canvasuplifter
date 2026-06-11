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

/**
 * Builds a read-only "what is in this package" report from a course model.
 *
 * In Phase 0 this is the plugin's only output: it tells an administrator what
 * the package contains and how cleanly each part will map to Moodle, without
 * creating anything.
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
     * @return array<string, array{target: string, confidence: string}>
     */
    public static function mapping_plan(): array {
        return [
            item::KIND_PAGE => ['target' => 'mod_page', 'confidence' => self::CONFIDENCE_FULL],
            item::KIND_FILE => ['target' => 'mod_resource', 'confidence' => self::CONFIDENCE_FULL],
            item::KIND_URL => ['target' => 'mod_url', 'confidence' => self::CONFIDENCE_FULL],
            item::KIND_ASSIGNMENT => ['target' => 'mod_assign', 'confidence' => self::CONFIDENCE_PARTIAL],
            item::KIND_DISCUSSION => ['target' => 'mod_forum', 'confidence' => self::CONFIDENCE_PARTIAL],
            item::KIND_QUIZ => ['target' => 'mod_quiz', 'confidence' => self::CONFIDENCE_PARTIAL],
            item::KIND_QUESTIONBANK => ['target' => 'mod_qbank', 'confidence' => self::CONFIDENCE_PARTIAL],
            item::KIND_LTI => ['target' => 'mod_lti', 'confidence' => self::CONFIDENCE_MANUAL],
            item::KIND_UNKNOWN => ['target' => '-', 'confidence' => self::CONFIDENCE_NONE],
        ];
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
     * Produce the full report as a structured array, ready to render.
     *
     * @return array{
     *     coursename: string,
     *     sectioncount: int,
     *     itemcount: int,
     *     rows: array<int, array{kind: string, count: int, target: string, confidence: string}>,
     *     warnings: string[]
     * }
     */
    public function build(): array {
        $plan = self::mapping_plan();
        $counts = $this->counts_by_kind();

        $rows = [];
        foreach ($counts as $kind => $count) {
            $entry = $plan[$kind] ?? ['target' => '-', 'confidence' => self::CONFIDENCE_NONE];
            $rows[] = [
                'kind' => $kind,
                'count' => $count,
                'target' => $entry['target'],
                'confidence' => $entry['confidence'],
            ];
        }

        $warnings = [];
        if (($counts[item::KIND_UNKNOWN] ?? 0) > 0) {
            $warnings[] = 'Some resources could not be classified and will be skipped.';
        }
        if (($counts[item::KIND_LTI] ?? 0) > 0) {
            $warnings[] = 'External (LTI) tools need their keys reconfigured by hand after import.';
        }
        if (($counts[item::KIND_QUIZ] ?? 0) > 0 || ($counts[item::KIND_QUESTIONBANK] ?? 0) > 0) {
            $warnings[] = 'Quiz questions depend on type support; check the question-type matrix.';
        }

        return [
            'coursename' => $this->course->fullname,
            'sectioncount' => count($this->course->sections),
            'itemcount' => count($this->course->all_items()),
            'rows' => $rows,
            'warnings' => $warnings,
        ];
    }
}
