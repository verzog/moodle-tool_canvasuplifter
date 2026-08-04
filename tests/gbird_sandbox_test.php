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
use tool_canvasuplifter\local\parser\outcomes_parser;
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
 * This suite asserts the analyse pipeline's output (manifest_parser +
 * conversion_report); it does not build the course. Along the way the fixture
 * surfaced build-fidelity gaps that are asserted as known limitations so they
 * cannot regress unnoticed and will trip when fixed: a dropped external tool
 * (issue #125), unpublished orphan activities that parse as visible (#126), and
 * empty orphan New-Quiz shells the report counts as building though the build
 * skips them (#127).
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
        return (new conversion_report($this->fixture_course(), __DIR__ . '/fixtures/gbird_sandbox', '', false))->build();
    }

    /**
     * Parse the fixture and return the neutral course model.
     *
     * @return \tool_canvasuplifter\local\model\course_model The parsed model.
     */
    private function fixture_course(): \tool_canvasuplifter\local\model\course_model {
        return (new manifest_parser(__DIR__ . '/fixtures/gbird_sandbox'))->parse();
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
        // assessments are reported as mod_qbank. Note (issue #127): those two
        // are empty New-Quiz shells, and questionbank_builder has no native-QTI
        // fallback, so the actual build skips them - the report is optimistic
        // here. When #127 is fixed these expectations change.
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

        // A supported row (importable type, no dropped-source attribution).
        $supported = static fn(string $label, int $count): array => [
            'label' => $label, 'count' => $count, 'supported' => true, 'status' => 'yes', 'sources' => [],
        ];
        // An unsupported New-Quiz row: status 'unsupported', dropped from the one
        // "New quiz engine" assessment (full sources array with its count).
        $newquiz = static fn(string $label): array => [
            'label' => $label, 'count' => 1, 'supported' => false, 'status' => 'unsupported',
            'sources' => [['name' => 'New quiz engine', 'count' => 1]],
        ];

        // Assert the whole matrix: label, count, supported flag, status and the
        // complete sources array (with counts), in order - so a status or
        // attribution regression is caught, not just a count change.
        $this->assertSame([
            $supported('description', 1),
            $supported('essay', 1),
            $supported('matching', 1),
            $supported('multianswer', 3),
            $supported('multichoice', 1),
            $newquiz('file_upload_question'),
            $newquiz('calculated_question'),
            $newquiz('fill_in_multiple_blanks_question'),
            $newquiz('hot_spot_question'),
            $newquiz('numerical_question'),
        ], $matrix['rows']);
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

        // Beyond the counts, lock down the outcome's payload so a parser that
        // dropped its name/description or scrambled the mastery ratings' points
        // would be caught (an importable count of 1 alone would not notice).
        $xml = file_get_contents(__DIR__ . '/fixtures/gbird_sandbox/course_settings/learning_outcomes.xml');
        $parsed = (new outcomes_parser())->parse($xml);
        $this->assertCount(1, $parsed);
        $this->assertSame('New outcome', $parsed[0]->fullname);
        $this->assertStringContainsString('This is a new outcome', $parsed[0]->description);
        $this->assertSame([
            ['description' => 'Exceeds Mastery', 'points' => 4.0],
            ['description' => 'Mastery', 'points' => 3.0],
            ['description' => 'Near Mastery', 'points' => 2.0],
            ['description' => 'Below Mastery', 'points' => 1.0],
            ['description' => 'No Evidence', 'points' => 0.0],
        ], $parsed[0]->ratings);
    }

    /**
     * Lock down the module structure: the four sections in order, and each
     * section's items in order (kind + title). A regression that reassigned an
     * item to the wrong module or reordered items leaves the section/item counts
     * unchanged, so only asserting the structure itself catches it.
     *
     * @return void
     */
    public function test_gbird_sandbox_section_structure_is_ordered(): void {
        $structure = [];
        foreach ($this->fixture_report()['sections'] as $section) {
            $structure[$section['title']] = array_map(
                static fn(array $it): string => $it['kind'] . '::' . $it['title'],
                $section['items']
            );
        }

        $this->assertSame([
            'My first Module' => ['assignment::Test SCORM'],
            'Canvas for document management' => [
                'file::Sample PDF.pdf',
                'file::Sample word document.docx',
                'file::Sample powerpoint.pptx',
                'page::Page with linked files',
                'page::UI elements',
                'page::Page elements',
                'page::images',
                'assignment::HIPPY Lifecycle Calendar',
                'page::ui elements',
            ],
            'This is a new module' => [
                'discussion::New discussion',
                'assignment::Assignment',
                'quiz::New quiz engine',
            ],
            'Week 1' => [
                'page::Introduction',
                'assignment::Employee Health and Wellness (Sample Course)',
                'assignment::Risk hierarchy of control',
            ],
        ], $structure);
    }

    /**
     * The two Canvas assignment groups parse into the model as grade categories,
     * in order - so this coverage is effective rather than merely advertised.
     *
     * @return void
     */
    public function test_gbird_sandbox_parses_the_grade_categories(): void {
        $categories = $this->fixture_course()->gradecategories;

        $this->assertCount(2, $categories);
        $titles = array_map(static fn(array $c): string => $c['title'], $categories);
        $this->assertSame(['Assignments', 'Imported Assignments'], $titles);
    }

    /**
     * Known limitation (issue #126), asserted so it cannot regress unnoticed:
     * an orphan activity keeps the model default isvisible=true even when its own
     * metadata marks it unpublished, because visibility is only derived from a
     * module occurrence (referenced items) or topicMeta (discussions), never from
     * an orphan's own assignment_settings.xml. These four orphan assignments carry
     * workflow_state=unpublished yet currently parse as visible; when #126 lands,
     * this test should assert isvisible=false for them instead.
     *
     * @return void
     */
    public function test_gbird_sandbox_orphan_unpublished_visibility_is_a_known_gap(): void {
        $draftidentifiers = [
            'gb33a0fe57d92fca871e56089c5077d59', // Assignment Copy.
            'g360087309c27f1c1b6535122b4b1a47f', // HIPPY Lifecycle Calendar.
            'g059f7fcba556f584d38c917eb5faae9e', // Module 1: Critically reflective practice.
            'g4bbcaba2f97bc3db1a939e82f3e07674', // The 3Cs.
        ];
        $root = __DIR__ . '/fixtures/gbird_sandbox';
        $byhrefprefix = [];
        foreach ($this->fixture_course()->orphans as $orphan) {
            $byhrefprefix[explode('/', $orphan->href)[0]] = $orphan;
        }

        foreach ($draftidentifiers as $identifier) {
            // The fixture genuinely marks each of these unpublished at source.
            $settings = file_get_contents($root . '/' . $identifier . '/assignment_settings.xml');
            $this->assertStringContainsString('<workflow_state>unpublished</workflow_state>', $settings);
            // ... but the parser currently exposes them as visible (the gap).
            $this->assertArrayHasKey($identifier, $byhrefprefix);
            $this->assertTrue($byhrefprefix[$identifier]->isvisible);
        }
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
