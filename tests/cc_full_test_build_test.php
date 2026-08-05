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
     * The whole cartridge builds into the expected module mix: 6 file resources,
     * the two discussions as forums (alongside the course's own news forum), two
     * external tools, two weblinks, the referenced quiz and its question bank.
     * There are no pages in this cartridge.
     *
     * @return void
     */
    public function test_builds_the_expected_module_mix(): void {
        [$modinfo] = $this->build_fixture();

        $counts = [];
        foreach (['page', 'resource', 'forum', 'lti', 'url', 'quiz', 'qbank'] as $mod) {
            $counts[$mod] = count($modinfo->get_instances_of($mod));
        }
        $this->assertSame([
            'page' => 0,
            'resource' => 6,
            'forum' => 3,
            'lti' => 2,
            'url' => 2,
            'quiz' => 1,
            'qbank' => 1,
        ], $counts);
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

        $this->assertSame([
            'file' => 6,
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
}
