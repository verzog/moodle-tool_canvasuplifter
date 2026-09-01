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
 * discussions, New Quizzes (whose questions are recovered from the native QTI
 * dump - every CC assessment_qti.xml here is an empty shell), a learning
 * outcome, grade categories, unreferenced duplicates, and the
 * unsupported-question-type reporting in one package, so a parser or report
 * regression that changes any of those counts is caught here.
 *
 * This suite asserts the analyse pipeline's output (manifest_parser +
 * conversion_report); it does not build the course. Along the way the fixture
 * surfaced a batch of parser/build-fidelity issues that are now fixed and guarded
 * here so they cannot regress: an inline ContextExternalTool synthesised into an
 * LTI placeholder instead of being dropped (issue #125), orphan activities whose
 * unpublished state is derived from their own metadata (#126), external-tool
 * assignments re-homed as LTI placeholders (#128), quiz grade items routed to
 * their Canvas assignment group (#130), ordering questions marked unsupported
 * rather than mis-counted (#129; categorization now converts to a match, #169),
 * and files_meta hidden files imported
 * hidden (#131). questionbank_builder's native-QTI fallback (#127) is exercised by
 * questionbank_builder_test; here the two orphan banks still report as mod_qbank.
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
        // 33 items: the 32 previously found plus the inline ContextExternalTool
        // "Accredible", which is now surfaced as an LTI placeholder (#125) instead
        // of being dropped.
        $this->assertSame(33, $report['itemcount']);
        // Everything the analyser finds builds now; nothing is deferred/skipped.
        $this->assertSame(33, $report['buildsnowtotal']);
        $this->assertSame(0, $report['latertotal']);

        // Per-kind tally. 7 of the original 10 assignments are external-tool
        // assignments re-homed as LTI placeholders (#128), leaving 3 assignments;
        // the 8 LTI items are those 7 plus the inline "Accredible" tool (#125).
        // quiz still covers the referenced quiz and the two orphan banks.
        $bykind = [];
        foreach ($report['rows'] as $row) {
            $bykind[$row['kind']] = ($bykind[$row['kind']] ?? 0) + $row['count'];
        }
        $this->assertSame(3, $bykind['assignment']);
        $this->assertSame(2, $bykind['discussion']);
        $this->assertSame(10, $bykind['file']);
        $this->assertSame(8, $bykind['lti']);
        $this->assertSame(7, $bykind['page']);
        $this->assertSame(3, $bykind['quiz']);

        // The referenced New Quiz builds as mod_quiz; the two unreferenced
        // assessments are reported as mod_qbank. questionbank_builder now has the
        // same native-QTI fallback as quiz_builder (#127), so the orphan bank whose
        // native dump carries questions genuinely builds; the other orphan's dump
        // is also empty, so it remains a shell the build skips.
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
     * The question-type matrix: 11 of 16 questions convert; the rest are
     * New-Quiz-only types, all attributed to the "New quiz engine".
     *
     * @return void
     */
    public function test_gbird_sandbox_question_matrix(): void {
        $matrix = $this->fixture_report()['questionmatrix'];

        // 16 questions across the referenced "New quiz engine" (12) and the orphan
        // "New quiz engine (question bank)" (4) - both read through the native-QTI
        // fallback now that questionbank_builder has it too (#127), so the report
        // mirrors what builds. Since #169, categorization_question converts to a Moodle
        // match and file_upload_question to an essay; ordering_question and
        // hot_spot_question remain unsupported (no faithful core equivalent).
        $this->assertSame(16, $matrix['total']);
        $this->assertSame(14, $matrix['supported']);

        // A supported row (importable type, no dropped-source attribution).
        $supported = static fn(string $label, int $count): array => [
            'label' => $label, 'count' => $count, 'supported' => true, 'status' => 'yes', 'sources' => [],
        ];
        // An unsupported New-Quiz row: status 'unsupported', dropped from the named
        // assessments (full sources array with per-assessment counts).
        $newquiz = static fn(string $label, int $count, array $sources): array => [
            'label' => $label, 'count' => $count, 'supported' => false, 'status' => 'unsupported',
            'sources' => $sources,
        ];
        $engine = [['name' => 'New quiz engine', 'count' => 1]];

        // Assert the whole matrix: label, count, supported flag, status and the
        // complete sources array (with counts), in order - so a status or
        // attribution regression is caught, not just a count change.
        $this->assertSame([
            $supported('calculated', 1),
            $supported('cloze', 1),
            $supported('description', 2),
            // The referenced quiz's essay plus the file_upload_question, now imported as
            // an essay requiring a file attachment (#169).
            $supported('essay', 2),
            // The two matching_question items plus the two categorization items, now
            // imported as matches (item -> category) (#169).
            $supported('matching', 4),
            $supported('multianswer', 3),
            $supported('numerical', 1),
            $newquiz('ordering_question', 1, $engine),
            $newquiz('hot_spot_question', 1, $engine),
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

        // The external-tool assignments (Test SCORM, HIPPY, Employee Health, Risk
        // hierarchy) build as LTI placeholders (#128), and the inline
        // ContextExternalTool "Accredible" now appears as an LTI item (#125) rather
        // than being dropped; the plain assignment and the quiz are unchanged.
        $this->assertSame([
            'My first Module' => ['lti::Test SCORM'],
            'Canvas for document management' => [
                'file::Sample PDF.pdf',
                'file::Sample word document.docx',
                'file::Sample powerpoint.pptx',
                'page::Page with linked files',
                'page::UI elements',
                'page::Page elements',
                'page::images',
                'lti::HIPPY Lifecycle Calendar',
                'page::ui elements',
                'lti::Accredible',
            ],
            'This is a new module' => [
                'discussion::New discussion',
                'assignment::Assignment',
                'quiz::New quiz engine',
            ],
            'Week 1' => [
                'page::Introduction',
                'lti::Employee Health and Wellness (Sample Course)',
                'lti::Risk hierarchy of control',
            ],
        ], $structure);
    }

    /**
     * Lock down which resources are unreferenced (not just how many): a
     * suppression regression (e.g. suppress_embedded_page_assets leaving an
     * embedded asset as an orphan while a different one vanishes) would keep the
     * count and placements unchanged, so assert the ordered orphan identities.
     * Files are identified by href (unique per directory); the discussion and
     * the two empty New-Quiz shells have no href, so use their resource id.
     *
     * @return void
     */
    public function test_gbird_sandbox_orphan_identities_are_locked_down(): void {
        $identities = array_map(
            static fn(item $o): string => $o->kind . '::' . ($o->href !== '' ? $o->href : 'id=' . $o->identifier),
            $this->fixture_course()->orphans
        );

        // Three of the orphan assignments are external-tool assignments, so they
        // are re-homed as LTI placeholders (#128); the two plain assignment copies
        // stay assignments.
        $this->assertSame([
            'page::wiki_content/kitchen-sink.html',
            'assignment::gb33a0fe57d92fca871e56089c5077d59/assignment-copy.html',
            'assignment::g6a3ca58aba8fd36264c8732aa3a34541/assignment-test.html',
            'lti::g360087309c27f1c1b6535122b4b1a47f/hippy-lifecycle-calendar.html',
            'lti::g059f7fcba556f584d38c917eb5faae9e/module-1-critically-reflective-practice.html',
            'lti::g4bbcaba2f97bc3db1a939e82f3e07674/the-3cs.html',
            'discussion::id=geed05fe2eecad7f1697a643011326699',
            'quiz::id=g51585833ff292ade97dca48bd6f5325f',
            'quiz::id=g78c27639db700ae8ce95dd4a0e414918',
            'file::web_resources/Uploaded Media/755728c4-f4bf-4893-8893-68393414f6cc',
            'file::web_resources/ABC123/Sample PDF.pdf',
            'file::web_resources/ABC123/Sample word document.docx',
            'file::web_resources/ABC123/Sample powerpoint.pptx',
            'file::web_resources/XYZ345/Sample powerpoint.pptx',
            'file::web_resources/XYZ345/Sample word document.docx',
            'file::web_resources/XYZ345/Sample PDF.pdf',
        ], $identities);
    }

    /**
     * Regression guard for issue #131: course_settings/files_meta.xml marks the
     * "Uploaded Media" folder hidden (it holds a QTI-internal image). That file is
     * now imported hidden rather than as a visible standalone resource, while files
     * in non-hidden folders stay visible - so the hidden state is honoured without
     * blanket-hiding every uploaded file.
     *
     * @return void
     */
    public function test_gbird_sandbox_files_meta_hidden_is_honoured(): void {
        $meta = file_get_contents(__DIR__ . '/fixtures/gbird_sandbox/course_settings/files_meta.xml');
        // The fixture genuinely marks the "Uploaded Media" folder hidden.
        $this->assertMatchesRegularExpression(
            '#<folder path="Uploaded Media">\s*<hidden>true</hidden>#',
            $meta
        );

        $byhref = [];
        foreach ($this->fixture_course()->orphans as $orphan) {
            if ($orphan->kind === item::KIND_FILE && $orphan->href !== '') {
                $byhref[$orphan->href] = $orphan;
            }
        }

        // The QTI-internal image under the hidden folder imports hidden ...
        $hidden = 'web_resources/Uploaded Media/755728c4-f4bf-4893-8893-68393414f6cc';
        $this->assertArrayHasKey($hidden, $byhref);
        $this->assertFalse($byhref[$hidden]->isvisible);

        // ... while ordinary uploaded files (not under a hidden folder) stay visible.
        foreach (['web_resources/ABC123/Sample PDF.pdf', 'web_resources/XYZ345/Sample PDF.pdf'] as $visible) {
            $this->assertArrayHasKey($visible, $byhref);
            $this->assertTrue($byhref[$visible]->isvisible);
        }
    }

    /**
     * Regression guard for issue #128: seven of the ten assignments are Canvas
     * external-tool assignments (submission_types=external_tool with an
     * external_tool_url - Quizzes.Next / SCORM). These are re-homed as LTI items
     * carrying the launch URL, so they build as hidden mod_lti placeholders rather
     * than as near-empty mod_assign activities that drop the tool.
     *
     * @return void
     */
    public function test_gbird_sandbox_external_tool_assignments_become_lti(): void {
        $root = __DIR__ . '/fixtures/gbird_sandbox';
        // The fixture really contains seven external-tool assignments at source.
        $externaltool = 0;
        foreach (glob($root . '/*/assignment_settings.xml') as $settings) {
            $xml = file_get_contents($settings);
            if (str_contains($xml, '<submission_types>external_tool') && str_contains($xml, '<external_tool_url>')) {
                $externaltool++;
            }
        }
        $this->assertSame(7, $externaltool);

        // Each parses into an LTI item that carries an http(s) launch URL, and no
        // assignment retains an external-tool submission.
        $items = $this->fixture_course()->orphans;
        foreach ($this->fixture_course()->sections as $section) {
            $items = array_merge($items, $section->items);
        }
        $ltilaunches = 0;
        foreach ($items as $it) {
            if ($it->kind === item::KIND_LTI && $it->launchurl !== '') {
                $this->assertMatchesRegularExpression('#^https?://#', $it->launchurl);
                $ltilaunches++;
            }
        }
        // The seven ex-assignments plus the inline "Accredible" ContextExternalTool.
        $this->assertSame(8, $ltilaunches);
    }

    /**
     * The two Canvas assignment groups parse into the model as grade categories,
     * in order - so this coverage is effective rather than merely advertised.
     *
     * @return void
     */
    public function test_gbird_sandbox_parses_the_grade_categories(): void {
        $course = $this->fixture_course();

        // Full category specs (identifier + title + position), so a dropped or
        // changed identifier - which would stop course_builder matching
        // assignments to categories - is caught, not just a renamed title.
        $this->assertSame(
            [
                ['g91781b5190987f195cb5692a92a91322', 'Assignments', 1],
                ['gd419b3a4c35c4fc26ea774f481e86ffc', 'Imported Assignments', 2],
            ],
            array_map(
                static fn(array $c): array => [$c['identifier'], $c['title'], $c['position']],
                $course->gradecategories
            )
        );

        // Every graded activity must carry its gradegroupref into the "Assignments"
        // category so course_builder can route its grade item there. The three
        // plain assignments still do; and since #130, the three quiz/assessment
        // items do too (their group ref lives on the assessment_meta.xml). The
        // seven external-tool assignments are now LTI placeholders (#128) with no
        // grade item, so they correctly carry no ref.
        $items = $course->orphans;
        foreach ($course->sections as $section) {
            $items = array_merge($items, $section->items);
        }
        $assignmentrefs = [];
        $quizrefs = [];
        foreach ($items as $it) {
            if ($it->kind === item::KIND_ASSIGNMENT) {
                $assignmentrefs[] = $it->gradegroupref;
            } else if (in_array($it->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true)) {
                $quizrefs[] = $it->gradegroupref;
            }
        }
        $this->assertCount(3, $assignmentrefs);
        $this->assertSame(['g91781b5190987f195cb5692a92a91322'], array_values(array_unique($assignmentrefs)));
        $this->assertCount(3, $quizrefs);
        $this->assertSame(['g91781b5190987f195cb5692a92a91322'], array_values(array_unique($quizrefs)));
    }

    /**
     * Regression guard for issue #126: an orphan activity (no module places it) has
     * no module occurrence to inherit visibility from, so its draft state must be
     * read from its own companion metadata. Assignments carry workflow_state in
     * assignment_settings.xml, New-Quiz shells in assessment_meta.xml (on the
     * embedded <assignment>), and the discussion in its separately-named topicMeta.
     * Every one of these is unpublished at source and now imports hidden.
     *
     * @return void
     */
    public function test_gbird_sandbox_orphan_unpublished_visibility_is_derived(): void {
        $root = __DIR__ . '/fixtures/gbird_sandbox';
        // Every unpublished orphan activity, keyed by the orphan's g-id, with the
        // source metadata file that marks it unpublished.
        $drafts = [
            'gb33a0fe57d92fca871e56089c5077d59' => 'gb33a0fe57d92fca871e56089c5077d59/assignment_settings.xml',
            'g360087309c27f1c1b6535122b4b1a47f' => 'g360087309c27f1c1b6535122b4b1a47f/assignment_settings.xml',
            'g059f7fcba556f584d38c917eb5faae9e' => 'g059f7fcba556f584d38c917eb5faae9e/assignment_settings.xml',
            'g4bbcaba2f97bc3db1a939e82f3e07674' => 'g4bbcaba2f97bc3db1a939e82f3e07674/assignment_settings.xml',
            'g51585833ff292ade97dca48bd6f5325f' => 'g51585833ff292ade97dca48bd6f5325f/assessment_meta.xml',
            'g78c27639db700ae8ce95dd4a0e414918' => 'g78c27639db700ae8ce95dd4a0e414918/assessment_meta.xml',
            'geed05fe2eecad7f1697a643011326699' => 'gc26eeb2556ed538c7d57d0ce227a90e0.xml',
        ];

        // Key orphans by resource id and (for the file-backed ones) href prefix.
        $byid = [];
        foreach ($this->fixture_course()->orphans as $orphan) {
            $byid[$orphan->identifier] = $orphan;
            if ($orphan->href !== '') {
                $byid[explode('/', $orphan->href)[0]] = $orphan;
            }
        }

        foreach ($drafts as $identifier => $sourcefile) {
            // The fixture genuinely marks each of these unpublished at source ...
            $source = file_get_contents($root . '/' . $sourcefile);
            $this->assertStringContainsString('<workflow_state>unpublished</workflow_state>', $source);
            // ... and the parser now derives that draft state onto the orphan.
            $this->assertArrayHasKey($identifier, $byid);
            $this->assertFalse($byid[$identifier]->isvisible, "$identifier should import hidden");
        }

        // A published orphan is untouched: the "Assignment test" copy carries
        // workflow_state=published and stays visible, proving the derivation only
        // ever hides drafts rather than blanket-hiding every orphan.
        $publishedid = 'g6a3ca58aba8fd36264c8732aa3a34541';
        $this->assertStringContainsString(
            '<workflow_state>published</workflow_state>',
            file_get_contents($root . '/' . $publishedid . '/assignment_settings.xml')
        );
        $this->assertArrayHasKey($publishedid, $byid);
        $this->assertTrue($byid[$publishedid]->isvisible);
    }

    /**
     * The native QTI carries a categorization_question and an ordering_question, which
     * qti_parser::map_type() once left to the response-cardinality heuristic - silently
     * mis-counting them as multi-answer / multiple-choice questions (issue #129). Since
     * #169 categorization converts to a Moodle match, so it no longer appears as its own
     * unsupported row (it is folded into the supported "matching" row); ordering has no
     * faithful core equivalent, so it stays TYPE_UNSUPPORTED as its own named row - the
     * guard that neither is mis-read as a choice question.
     *
     * @return void
     */
    public function test_gbird_sandbox_categorization_converts_ordering_unsupported(): void {
        $qti = '';
        foreach (glob(__DIR__ . '/fixtures/gbird_sandbox/non_cc_assessments/*.qti') as $file) {
            $qti .= file_get_contents($file);
        }
        $this->assertStringContainsString('categorization_question', $qti);
        $this->assertStringContainsString('ordering_question', $qti);

        $rows = $this->fixture_report()['questionmatrix']['rows'];
        $byid = [];
        foreach ($rows as $row) {
            $byid[$row['label']] = $row;
        }
        // Categorization is no longer an unsupported row - it converts to a match.
        $this->assertArrayNotHasKey('categorization_question', $byid);
        // Ordering has no faithful core equivalent, so it stays its own unsupported row,
        // attributed to the referenced quiz it came from (not mis-read as a choice type).
        $this->assertArrayHasKey('ordering_question', $byid);
        $this->assertFalse($byid['ordering_question']['supported']);
        $this->assertSame('unsupported', $byid['ordering_question']['status']);
        $this->assertSame([['name' => 'New quiz engine', 'count' => 1]], $byid['ordering_question']['sources']);
    }

    /**
     * The fixture's categorization questions use Canvas all-or-nothing scoring, which imports
     * as a partial-credit Moodle match; the report raises the warnreportcategorization warning
     * so a grader knows to review those questions' grading (#169).
     *
     * @return void
     */
    public function test_gbird_sandbox_warns_about_categorization_scoring(): void {
        $this->assertContains('warnreportcategorization', $this->fixture_report()['warnings']);
    }

    /**
     * Regression guard for issue #125: the fixture's unpublished "Accredible"
     * ContextExternalTool (a Canvas external tool with an inline LTI launch URL,
     * whose module_meta item references a CC resource no manifest resource
     * provides) is now synthesised into a hidden LTI placeholder from its inline
     * URL rather than being dropped - just like a cartridge-backed LTI link.
     *
     * @return void
     */
    public function test_gbird_sandbox_inline_external_tool_becomes_lti(): void {
        $root = __DIR__ . '/fixtures/gbird_sandbox';

        // The fixture genuinely exercises the case: the tool, its launch URL and
        // its unpublished state are all present in the Canvas module metadata.
        $modulemeta = file_get_contents($root . '/course_settings/module_meta.xml');
        $this->assertStringContainsString('<content_type>ContextExternalTool</content_type>', $modulemeta);
        $this->assertStringContainsString('<title>Accredible</title>', $modulemeta);
        $this->assertStringContainsString('https://api.accredible.com/v1/lti/launch', $modulemeta);

        // It now appears in the parsed model as an LTI item carrying that launch
        // URL, and - being unpublished in Canvas - imports hidden.
        $course = (new manifest_parser($root))->parse();
        $items = $course->orphans;
        foreach ($course->sections as $section) {
            $items = array_merge($items, $section->items);
        }
        $accredible = null;
        foreach ($items as $it) {
            if ($it->title === 'Accredible') {
                $accredible = $it;
            }
        }
        $this->assertNotNull($accredible, 'the Accredible tool should be an item');
        $this->assertSame(item::KIND_LTI, $accredible->kind);
        $this->assertSame('https://api.accredible.com/v1/lti/launch', $accredible->launchurl);
        $this->assertFalse($accredible->isvisible, 'the unpublished tool should import hidden');

        // The course_settings.xml tab_configuration lists two course-navigation
        // tools: the Accredible tool (also this module item, so deduped) and a second
        // one (context_external_tool_g7e72e4...) that appears only as a nav tab with
        // no definition in the package. Only the nav-only one is flagged for the admin.
        $this->assertSame(1, $course->navtoolsunimported);
    }
}
