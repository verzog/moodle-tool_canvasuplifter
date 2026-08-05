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

use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * End-to-end build of the IMS "Validation Cartridge 1" (cc_full_test) fixture: a
 * broad, standards-conformant Common Cartridge with pages, files, discussions,
 * external tools, weblinks, a question bank and a quiz. Complements gbird_sandbox
 * (analyse-only) with a full course build across many activity kinds at once.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class cc_full_test_build_test extends \advanced_testcase {
    /**
     * Build the whole fixture into a fresh course and return the modinfo plus the
     * conversion report, so the individual assertions can inspect what was built.
     *
     * @return array [\course_modinfo modinfo, array report].
     */
    private function build_fixture(): array {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = __DIR__ . '/fixtures/cc_full_test';
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        return [get_fast_modinfo($report['courseid']), $report];
    }

    /**
     * The whole cartridge builds into the expected module mix: the four learner
     * placed file resources only, the two imported discussions as forums, two
     * external tools, two weblinks, the referenced quiz and its question bank.
     * There are no pages in this cartridge, and the quiz/discussion dependency
     * media is embedded into that content (see test_embedded_media_is_embedded)
     * rather than surfaced as standalone downloads.
     *
     * @return void
     */
    public function test_builds_the_expected_module_mix(): void {
        global $DB;
        [$modinfo] = $this->build_fixture();

        $resourcenames = [];
        foreach ($modinfo->get_instances_of('resource') as $cm) {
            $resourcenames[] = $cm->name;
        }
        sort($resourcenames);
        // Only the four files the cartridge's organization places as activities.
        // The quiz's image dependency and the discussion's media are embedded into
        // their owning content, so they must NOT also appear here as 'img1' /
        // 'smiling_dog' downloads - that leak is what this pins against.
        $this->assertSame([
            'Assignment 1',
            'Assignment 2',
            'Learning Objectives',
            'Super exciting!',
        ], $resourcenames);

        // The two imported discussions build as general forums. Moodle's
        // auto-created Announcements (type=news) forum is optional - a site whose
        // course defaults disable announcements never creates it - so filter it
        // out rather than counting it, keeping the assertion configuration-robust.
        $importedforums = 0;
        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if ($DB->get_field('forum', 'type', ['id' => $cm->instance]) !== 'news') {
                $importedforums++;
            }
        }
        $this->assertSame(2, $importedforums, 'two imported discussion forums');

        $this->assertCount(0, $modinfo->get_instances_of('page'), 'no pages in this cartridge');
        $this->assertCount(2, $modinfo->get_instances_of('lti'), 'two external tools');
        $this->assertCount(2, $modinfo->get_instances_of('url'), 'two weblinks');
        $this->assertCount(1, $modinfo->get_instances_of('quiz'), 'one quiz');
        $this->assertCount(1, $modinfo->get_instances_of('qbank'), 'one question bank');
    }

    /**
     * The per-kind created counts match the builders, and the only thing skipped
     * is the cartridge's deliberately broken "Non-existent reference" weblink,
     * which has no resolvable URL - so a real, non-fatal skip is reported rather
     * than the build aborting or silently inventing an activity.
     *
     * @return void
     */
    public function test_created_counts_and_only_broken_weblink_is_skipped(): void {
        [, $report] = $this->build_fixture();

        // The file=4 count is the organization-placed files only; the quiz and
        // discussion dependency media is embedded into that content, not built as
        // file activities (see test_embedded_media_is_embedded).
        $this->assertSame([
            'file' => 4,
            'quiz' => 1,
            'url' => 2,
            'discussion' => 2,
            'lti' => 2,
            'questionbank' => 1,
        ], $report['createdcounts']);
        // Exactly one skip: the broken weblink (imswl resource pointing at a file
        // the package never ships).
        $this->assertSame(['url' => 1], $report['skippedcounts']);
    }

    /**
     * External tools build as hidden mod_lti placeholders (they need their
     * credentials reconfigured before use), and every question in the cartridge's
     * bank and quiz is imported.
     *
     * @return void
     */
    public function test_external_tools_hidden_and_all_questions_imported(): void {
        global $DB;
        [$modinfo] = $this->build_fixture();

        foreach ($modinfo->get_instances_of('lti') as $cm) {
            $this->assertSame(0, (int) $cm->visible, 'LTI placeholders import hidden');
        }
        // All 22 questions from the bank and the quiz are imported.
        $this->assertSame(22, $DB->count_records('question_bank_entries'));
    }

    /**
     * The cartridge's dependency media embeds into the content that owns it: the
     * quiz question's stem/answer/feedback images land in the question file area,
     * and the discussion's inline image and attachment land on the seeded forum
     * post - each referenced with a Canvas $IMS-CC-FILEBASE$ path or a ../ climb
     * that resolves relative to the owning resource's folder rather than the
     * package root. Nothing is silently dropped and nothing leaks as a download.
     *
     * @return void
     */
    public function test_embedded_media_is_embedded(): void {
        global $DB;
        $this->build_fixture();

        // Quiz question media: img1/img2/img3.png (stem, answer, feedback) imported
        // into the 'question' component rather than left as unresolved placeholders.
        $questionfiles = $DB->get_fieldset_select(
            'files',
            'filename',
            "component = :component AND filename <> '.' AND filename <> ''",
            ['component' => 'question']
        );
        sort($questionfiles);
        $this->assertSame(['img1.png', 'img2.png', 'img3.png'], array_values(array_unique($questionfiles)));

        // Discussion media: the inline image embeds into the forum post body and
        // the declared attachment imports as a real post attachment.
        $postmedia = $DB->get_fieldset_select(
            'files',
            'filename',
            "component = :component AND filearea = :filearea AND filename <> '.' AND filename <> ''",
            ['component' => 'mod_forum', 'filearea' => 'post']
        );
        $this->assertSame(['smiling_dog.jpg'], array_values(array_unique($postmedia)));

        $attachments = $DB->get_fieldset_select(
            'files',
            'filename',
            "component = :component AND filearea = :filearea AND filename <> '.' AND filename <> ''",
            ['component' => 'mod_forum', 'filearea' => 'attachment']
        );
        $this->assertSame(['angry_person.jpg'], array_values(array_unique($attachments)));
    }
}
