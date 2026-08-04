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

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\parser\manifest_parser;
use tool_canvasuplifter\local\report\conversion_report;

/**
 * Regression fixture: a broad Canvas "kitchen sink" export (GBIRD-Sandbox).
 *
 * The fixture under tests/fixtures/gbird_sandbox is a real Canvas Common
 * Cartridge (CC 1.1) export whose large binary web resources have been replaced
 * with tiny stubs - the parser and report read only the manifest, page HTML,
 * settings XML and QTI, never the binary bytes, so the analysis is unchanged.
 * It exercises pages, files, assignments (including external-tool/SCORM),
 * discussions, a Classic quiz plus a New Quiz, a learning outcome, grade
 * categories, unreferenced duplicates, and the unsupported-question-type
 * reporting in one package, so a parser or report regression that changes any of
 * those counts is caught here.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 * @covers     \tool_canvasuplifter\local\report\conversion_report
 */
final class gbird_sandbox_test extends \advanced_testcase {
    /**
     * Parse the fixture once and return the built conversion report array.
     *
     * @return array The conversion_report::build() result.
     */
    private function fixture_report(): array {
        $root = __DIR__ . '/fixtures/gbird_sandbox';
        $course = (new manifest_parser($root))->parse();
        return (new conversion_report($course, $root, '', false))->build();
    }

    /**
     * The whole package builds now, with the expected per-kind counts.
     *
     * @return void
     */
    public function test_gbird_sandbox_builds_the_expected_activities(): void {
        $report = $this->fixture_report();

        $this->assertSame('GBIRD-Sandbox', $report['coursename']);
        $this->assertSame('canvas', $report['source']);
        $this->assertSame(4, $report['sectioncount']);
        $this->assertSame(32, $report['itemcount']);
        // Everything the analyser finds builds now; nothing is deferred/skipped.
        $this->assertSame(32, $report['buildsnowtotal']);
        $this->assertSame(0, $report['latertotal']);

        // Per-kind tally (quiz covers both the referenced quiz and the two
        // orphan question banks): 10 assignments, 2 discussions, 10 files,
        // 7 pages, 3 assessments.
        $bykind = [];
        foreach ($report['rows'] as $row) {
            $bykind[$row['kind']] = ($bykind[$row['kind']] ?? 0) + $row['count'];
        }
        $this->assertSame(10, $bykind['assignment']);
        $this->assertSame(2, $bykind['discussion']);
        $this->assertSame(10, $bykind['file']);
        $this->assertSame(7, $bykind['page']);
        $this->assertSame(3, $bykind['quiz']);

        // The referenced New Quiz builds as mod_quiz; the two unreferenced
        // assessments build as mod_qbank.
        $targets = [];
        foreach ($report['rows'] as $row) {
            if ($row['kind'] === 'quiz') {
                $targets[$row['target']] = $row['count'];
            }
        }
        $this->assertSame(1, $targets['mod_quiz']);
        $this->assertSame(2, $targets['mod_qbank']);

        // The sandbox's duplicate/copy items are unreferenced, so they collect
        // into the "extras" (Additional resources) bucket rather than a module.
        $this->assertCount(16, $report['orphans']);
        foreach ($report['orphans'] as $orphan) {
            $this->assertSame('extras', $orphan['placement']);
        }
    }

    /**
     * The question-type matrix: 7 of 12 questions convert; the 5 that do not
     * are the New-Quiz-only types, all attributed to the "New quiz engine".
     *
     * @return void
     */
    public function test_gbird_sandbox_question_matrix(): void {
        $matrix = $this->fixture_report()['questionmatrix'];

        $this->assertSame(12, $matrix['total']);
        $this->assertSame(7, $matrix['supported']);

        $supported = [];
        $unsupported = [];
        foreach ($matrix['rows'] as $row) {
            if ($row['supported']) {
                $supported[$row['label']] = $row['count'];
            } else {
                $unsupported[$row['label']] = $row['count'];
                // Every dropped question is attributed to the New Quiz.
                $this->assertSame('New quiz engine', $row['sources'][0]['name']);
            }
        }

        $this->assertSame(
            ['description' => 1, 'essay' => 1, 'matching' => 1, 'multianswer' => 3, 'multichoice' => 1],
            $supported
        );
        $this->assertSame(
            [
                'file_upload_question' => 1,
                'calculated_question' => 1,
                'fill_in_multiple_blanks_question' => 1,
                'hot_spot_question' => 1,
                'numerical_question' => 1,
            ],
            $unsupported
        );
    }

    /**
     * The single Canvas learning outcome imports cleanly (none skipped/malformed).
     *
     * @return void
     */
    public function test_gbird_sandbox_imports_the_outcome(): void {
        $outcomes = $this->fixture_report()['outcomes'];

        $this->assertSame(1, $outcomes['total']);
        $this->assertSame(1, $outcomes['importable']);
        $this->assertSame(0, $outcomes['skipped']);
        $this->assertFalse($outcomes['malformed']);
    }

    /**
     * Known limitation, asserted so it cannot regress unnoticed: the fixture's
     * unpublished "Accredible" ContextExternalTool (a Canvas external tool with
     * an inline LTI launch URL) is currently dropped. Its module_meta item
     * references a CC resource that no manifest resource provides, so the parser
     * does not yet turn it into an LTI item - unlike a cartridge-backed LTI link,
     * which builds as a hidden mod_lti placeholder. This is why the counts above
     * total 32 rather than 33.
     *
     * See issue #125: import Canvas ContextExternalTool module items that carry
     * an inline launch URL as hidden mod_lti placeholders. When that lands, this
     * test should start failing (the tool becomes an item) and be updated.
     *
     * @return void
     */
    public function test_gbird_sandbox_external_tool_is_a_known_drop(): void {
        $root = __DIR__ . '/fixtures/gbird_sandbox';

        // The fixture genuinely exercises the case: the tool, its launch URL and
        // its unpublished state are all present in the Canvas module metadata.
        $modulemeta = file_get_contents($root . '/course_settings/module_meta.xml');
        $this->assertStringContainsString('<content_type>ContextExternalTool</content_type>', $modulemeta);
        $this->assertStringContainsString('<title>Accredible</title>', $modulemeta);
        $this->assertStringContainsString('https://api.accredible.com/v1/lti/launch', $modulemeta);

        // But it is absent from the parsed model: no LTI item, and nothing named
        // after the tool. If this ever changes, revisit the counts above.
        $course = (new manifest_parser($root))->parse();
        $items = $course->orphans;
        foreach ($course->sections as $section) {
            $items = array_merge($items, $section->items);
        }
        foreach ($items as $it) {
            $this->assertNotSame(item::KIND_LTI, $it->kind);
            $this->assertStringNotContainsStringIgnoringCase('accredible', (string) $it->title);
        }
    }
}
