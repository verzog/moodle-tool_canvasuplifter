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
 * End-to-end test for the QTI quiz builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\quiz_builder
 */
final class quiz_builder_test extends \advanced_testcase {
    /**
     * Write a package whose QTI assessment is referenced from the course tree.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/quiz/a1');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Chapter 1 Quiz"><section ident="s1">'
            . $this->mcitem() . $this->fibitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1/qti.xml', $qti);
        // The assessment is referenced from an <item>, so it is a real quiz.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Chapter 1 Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/a1/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A multiple-choice item (answer B correct).
     *
     * @return string
     */
    private function mcitem(): string {
        return '<item ident="q1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>cc.multiple_choice.v0p1</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">&lt;p&gt;2+2?&lt;/p&gt;</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>3</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>4</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">B</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A fill-in-blank item accepting "four".
     *
     * @return string
     */
    private function fibitem(): string {
        return '<item ident="q2"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>cc.fib.v0p1</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">&lt;p&gt;Two plus two is ___&lt;/p&gt;</mattext></material>'
            . '<response_str ident="r1"><render_fib><response_label ident="A"/></render_fib></response_str></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">four</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A two-option multiple-choice item (Canvas style, no cc_profile).
     *
     * @param string $ident Item identifier.
     * @param string $correct The ident of the correct option (A or B).
     * @return string
     */
    private function mc(string $ident, string $correct): string {
        return '<item ident="' . $ident . '" title="MC ' . $ident . '"><presentation>'
            . '<material><mattext texttype="text/html">&lt;p&gt;Pick one&lt;/p&gt;</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>Alpha</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>Beta</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">' . $correct . '</varequal>'
            . '</conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A choice item with a single option. Moodle rejects multiple-choice
     * questions with fewer than two answers, which historically aborted the
     * whole import batch (and crashed the quiz build with "Invalid question type").
     *
     * @return string
     */
    private function singleoptionitem(): string {
        return '<item ident="qbad" title="Bad"><presentation>'
            . '<material><mattext texttype="text/html">&lt;p&gt;Only one&lt;/p&gt;</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>Only</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * Write a package whose referenced assessment mixes good questions with a
     * single-option choice item that Moodle cannot save.
     *
     * @return string Path to the package root.
     */
    protected function build_mixed_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/quiz/a2');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a2" title="Readiness Quiz"><section ident="s1">'
            . $this->mc('qa', 'B') . $this->singleoptionitem() . $this->mc('qc', 'A')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a2/qti.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Readiness Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/a2/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * Write a package whose referenced assessment has only unconvertible
     * questions (a lone single-option choice).
     *
     * @return string Path to the package root.
     */
    protected function build_unconvertible_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/quiz/a3');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a3" title="Readiness Quiz"><section ident="s1">'
            . $this->singleoptionitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a3/qti.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Readiness Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/a3/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A quiz with no convertible questions is skipped with a reason that names
     * the unconvertible content, not a misleading "could not find payload".
     *
     * @return void
     */
    public function test_quiz_with_no_convertible_questions_is_skipped_with_reason(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_unconvertible_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertCount(0, get_fast_modinfo($report['courseid'])->get_instances_of('quiz'));
        $joined = implode("\n", $report['skipreasons']);
        $this->assertStringContainsString('no convertible questions', $joined);
    }

    /**
     * A single unsaveable question must not abort the whole quiz: the other
     * questions still convert and the quiz is created.
     *
     * @return void
     */
    public function test_bad_question_does_not_abort_quiz(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_mixed_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $modinfo = get_fast_modinfo($report['courseid']);
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $quizcm = reset($quizzes);
        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
        // The two well-formed questions convert; the single-option one is skipped.
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
    }

    /**
     * A referenced assessment builds a mod_quiz with both questions as slots.
     *
     * @return void
     */
    public function test_referenced_assessment_builds_quiz(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $quizcm = reset($quizzes);
        $this->assertSame('Chapter 1 Quiz', $quizcm->get_name());
        // No question banks were created for a referenced assessment.
        $this->assertCount(0, $modinfo->get_instances_of('qbank'));

        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
        // Two one-mark questions give a total of 2.
        $this->assertEqualsWithDelta(2.0, (float) $quiz->sumgrades, 0.001);
    }
}
