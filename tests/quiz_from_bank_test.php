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
 * Tests the "also build a quiz from each standalone question bank" toggle.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class quiz_from_bank_test extends \advanced_testcase {
    /**
     * Write a package whose single assessment is unreferenced (an orphan), so it
     * builds as a question bank by default.
     *
     * @return string Path to the package root.
     */
    private function build_orphan_assessment_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Standalone Bank"><section ident="s1">'
            . $this->mcitem('i1', 'A') . $this->mcitem('i2', 'B')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/a1.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A two-option multiple-choice item.
     *
     * @param string $ident Item identifier.
     * @param string $correct Correct response label.
     * @return string
     */
    private function mcitem(string $ident, string $correct): string {
        return '<item ident="' . $ident . '"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel>'
            . '<fieldentry>cc.multiple_choice.v0p1</fieldentry></qtimetadatafield>'
            . '</qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Pick one</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>a</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>b</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">' . $correct . '</varequal>'
            . '</conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition>'
            . '</resprocessing></item>';
    }

    /**
     * By default a standalone assessment builds only a question bank, no quiz.
     *
     * @return void
     */
    public function test_default_builds_only_bank(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_orphan_assessment_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $DB->count_records('qbank', ['course' => $report['courseid']]));
        $this->assertSame(0, $DB->count_records('quiz', ['course' => $report['courseid']]));
        $this->assertSame(0, $report['extraquizzes'] ?? -1);
    }

    /**
     * With the toggle on and a linked quiz already carrying the same title
     * as the orphan assessment, the bank picks up a "(question bank)" suffix
     * but the runnable quiz built from the same orphan model item keeps the
     * unsuffixed title. Pins the contract that disambiguation lives on
     * item::banktitle (used only for the bank build), not on item::title.
     *
     * @return void
     */
    public function test_toggle_does_not_suffix_runnable_quiz_when_disambiguating(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Foo"><section ident="s1">'
            . $this->mcitem('i1', 'A') . $this->mcitem('i2', 'B')
            . '</section></assessment></questestinterop>';
        // Two assessments with the same QTI title; one is linked from the
        // organisation tree, the other is orphan and builds as a bank.
        file_put_contents($dir . '/quiz/r_linked.xml', $qti);
        file_put_contents($dir . '/quiz/r_orphan.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_linked" identifierref="r_linked"><title>Foo</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_linked" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/r_linked.xml"/>
    </resource>
    <resource identifier="r_orphan" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/r_orphan.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir, null, 0, true))->build($coursemodel);

        $courseid = $report['courseid'];
        $quiznames = $DB->get_fieldset_select('quiz', 'name', 'course = ?', [$courseid]);
        $banknames = $DB->get_fieldset_select('qbank', 'name', 'course = ?', [$courseid]);
        sort($quiznames);

        // Two quizzes — the linked one and the extra runnable one — both
        // called "Foo"; the orphan-built bank carries the suffix.
        $this->assertSame(['Foo', 'Foo'], $quiznames);
        $this->assertSame(['Foo (question bank)'], $banknames);
    }

    /**
     * With the toggle on, a standalone assessment builds both the bank and a quiz.
     *
     * @return void
     */
    public function test_toggle_also_builds_quiz(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_orphan_assessment_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root, null, 0, true))->build($coursemodel);

        $this->assertSame(1, $DB->count_records('qbank', ['course' => $report['courseid']]));
        $this->assertSame(1, $DB->count_records('quiz', ['course' => $report['courseid']]));
        $this->assertSame(1, $report['extraquizzes'] ?? 0);
    }
}
