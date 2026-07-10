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

    /**
     * Write a package whose QTI assessment has a sibling assessment_meta.xml
     * carrying real Canvas quiz configuration.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_with_meta(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/am');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="am" title="Chapter 1 Quiz"><section ident="s1">'
            . $this->mcitem() . $this->fibitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/am/qti.xml', $qti);
        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="am" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Chapter 1 Quiz</title>'
            . '<description>&lt;p&gt;Read carefully.&lt;/p&gt;</description>'
            . '<shuffle_answers>false</shuffle_answers>'
            . '<scoring_policy>keep_latest</scoring_policy>'
            . '<quiz_type>assignment</quiz_type>'
            . '<points_possible>50.0</points_possible>'
            . '<show_correct_answers>false</show_correct_answers>'
            . '<allowed_attempts>2</allowed_attempts>'
            . '<one_question_at_a_time>true</one_question_at_a_time>'
            . '<cant_go_back>true</cant_go_back>'
            . '<access_code>letmein</access_code>'
            . '<ip_filter>192.168.0.0/24</ip_filter>'
            . '<time_limit>30</time_limit>'
            . '<assignment identifier="am_a">'
            . '<unlock_at>2030-08-01T00:00:00Z</unlock_at>'
            . '<lock_at>2030-09-08T23:59:00Z</lock_at>'
            . '</assignment>'
            . '</quiz>';
        file_put_contents($dir . '/quiz/am/assessment_meta.xml', $meta);
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
      <file href="quiz/am/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The real Canvas quiz configuration in assessment_meta.xml is carried onto
     * the built mod_quiz instead of leaving it on generic defaults.
     *
     * @return void
     */
    public function test_settings_carried_over_from_meta(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_with_meta();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $modinfo = get_fast_modinfo($report['courseid']);
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $quizcm = reset($quizzes);
        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);

        $this->assertEquals(30 * 60, (int) $quiz->timelimit);
        $this->assertEquals(2, (int) $quiz->attempts);
        $this->assertEquals(QUIZ_ATTEMPTLAST, (int) $quiz->grademethod);
        $this->assertEqualsWithDelta(50.0, (float) $quiz->grade, 0.001);
        $this->assertEquals(0, (int) $quiz->shuffleanswers);
        $this->assertEquals(1, (int) $quiz->questionsperpage);
        $this->assertSame(QUIZ_NAVMETHOD_SEQ, $quiz->navmethod);
        $this->assertSame('letmein', $quiz->password);
        $this->assertSame('192.168.0.0/24', $quiz->subnet);
        $this->assertEquals(strtotime('2030-08-01T00:00:00Z'), (int) $quiz->timeopen);
        $this->assertEquals(strtotime('2030-09-08T23:59:00Z'), (int) $quiz->timeclose);
        // With show_correct_answers=false, the right-answer review bit clears everywhere.
        $this->assertEquals(0, (int) $quiz->reviewrightanswer);
        // The description carries over as the quiz intro.
        $this->assertStringContainsString('Read carefully.', $quiz->intro);
        // Questions still import.
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
    }

    /**
     * Write a package whose quiz resource lists assessment_meta.xml *before* the
     * QTI file, with an explicit zero-point survey that hides results. Exercises
     * three edge cases at once: the meta file must not be read as the QTI doc,
     * an explicit 0 points must give a 0 max grade (not the 100-point default),
     * and hide_results=always must clear the result-revealing review options.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_zero_points_hidden(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/z');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="z" title="Survey"><section ident="s1">'
            . $this->mcitem() . $this->fibitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/z/qti.xml', $qti);
        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="z" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Survey</title>'
            . '<quiz_type>survey</quiz_type>'
            . '<points_possible>0.0</points_possible>'
            . '<hide_results>always</hide_results>'
            . '</quiz>';
        file_put_contents($dir . '/quiz/z/assessment_meta.xml', $meta);
        // The meta file is listed before the QTI file on purpose.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Survey</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/z/assessment_meta.xml"/>
      <file href="quiz/z/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * An explicit zero-point survey that hides results builds with a 0 maximum
     * grade and the result-revealing review options cleared, and its questions
     * still import even though the manifest lists assessment_meta.xml first.
     *
     * @return void
     */
    public function test_zero_points_and_hidden_results(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_zero_points_hidden();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $modinfo = get_fast_modinfo($report['courseid']);
        $quizinstances = $modinfo->get_instances_of('quiz');
        $quizcm = reset($quizinstances);
        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);

        // Explicit zero points -> ungraded (0) max, not the 100-point default.
        $this->assertEqualsWithDelta(0.0, (float) $quiz->grade, 0.001);
        // With hide_results=always, every review bit clears everywhere —
        // including the attempt review, so responses aren't visible after
        // submission. mod_quiz always forces the DURING bit back on (a student
        // must see their attempt while taking it), so assert only that the
        // post-submission phases of the attempt review are clear.
        $postsubmission = ~\mod_quiz\question\display_options::DURING;
        $this->assertSame(0, (int) $quiz->reviewattempt & $postsubmission);
        $this->assertEquals(0, (int) $quiz->reviewcorrectness);
        $this->assertEquals(0, (int) $quiz->reviewrightanswer);
        $this->assertEquals(0, (int) $quiz->reviewoverallfeedback);
        // The QTI file was found despite the meta being listed first.
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
    }

    /**
     * Write a package whose quiz hides results until after the last attempt,
     * with the given number of allowed attempts.
     *
     * @param string $dirname Unique sub-folder name under quiz/.
     * @param int $attempts Canvas allowed_attempts (-1 for unlimited).
     * @return string Path to the package root.
     */
    protected function build_fixture_until_last(string $dirname, int $attempts): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/' . $dirname);
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="' . $dirname . '" title="Quiz"><section ident="s1">'
            . $this->mcitem() . $this->fibitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/' . $dirname . '/qti.xml', $qti);
        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="' . $dirname . '" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Quiz</title>'
            . '<hide_results>until_after_last_attempt</hide_results>'
            . '<allowed_attempts>' . $attempts . '</allowed_attempts>'
            . '</quiz>';
        file_put_contents($dir . '/quiz/' . $dirname . '/assessment_meta.xml', $meta);
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org1"><item identifier="root">'
            . '<item identifier="m1"><title>Week 1</title>'
            . '<item identifier="i_q" identifierref="r_quiz"><title>Quiz</title></item></item>'
            . '</item></organization></organizations>'
            . '<resources><resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">'
            . '<file href="quiz/' . $dirname . '/qti.xml"/></resource></resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * hide_results=until_after_last_attempt maps by attempt count: a
     * multiple-attempt quiz keeps results hidden in the "later while open"
     * phase (so a non-final attempt can't reveal them before the last), while a
     * single-attempt quiz reveals them then (its first attempt is its last, and
     * clearing that phase would hide them forever with no close date).
     *
     * @return void
     */
    public function test_until_after_last_attempt_respects_attempt_count(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $open = \mod_quiz\question\display_options::LATER_WHILE_OPEN;

        // Multiple attempts: the open phase must be hidden.
        $rootmulti = $this->build_fixture_until_last('multi', 3);
        $category = $this->getDataGenerator()->create_category();
        $modelmulti = (new manifest_parser($rootmulti))->parse();
        $reportmulti = (new course_builder($category->id, $rootmulti))->build($modelmulti);
        $cmsmulti = get_fast_modinfo($reportmulti['courseid'])->get_instances_of('quiz');
        $cmmulti = reset($cmsmulti);
        $quizmulti = $DB->get_record('quiz', ['id' => $cmmulti->instance], '*', MUST_EXIST);
        $this->assertSame(0, (int) $quizmulti->reviewmarks & $open);
        $this->assertSame(0, (int) $quizmulti->reviewrightanswer & $open);

        // Single attempt: the open phase reveals results (not hidden forever).
        $rootsingle = $this->build_fixture_until_last('single', 1);
        $modelsingle = (new manifest_parser($rootsingle))->parse();
        $reportsingle = (new course_builder($category->id, $rootsingle))->build($modelsingle);
        $cmssingle = get_fast_modinfo($reportsingle['courseid'])->get_instances_of('quiz');
        $cmsingle = reset($cmssingle);
        $quizsingle = $DB->get_record('quiz', ['id' => $cmsingle->instance], '*', MUST_EXIST);
        $this->assertSame($open, (int) $quizsingle->reviewmarks & $open);
    }

    /**
     * Write a package whose quiz is a Canvas exam/New-Quiz shell: a valid
     * assessment with an empty <section/> (the questions live in an item bank
     * Canvas didn't export) plus an assessment_meta.xml with real settings.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_empty_shell(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/shell');
        $qti = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="shell" title="Patient Safety Quiz">'
            . '<qtimetadata><qtimetadatafield><fieldlabel>cc_profile</fieldlabel>'
            . '<fieldentry>cc.exam.v0p1</fieldentry></qtimetadatafield></qtimetadata>'
            . '<section ident="root_section"/>'
            . '</assessment></questestinterop>';
        file_put_contents($dir . '/quiz/shell/assessment_qti.xml', $qti);
        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="shell" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Patient Safety Quiz</title>'
            . '<time_limit>20</time_limit>'
            . '<allowed_attempts>2</allowed_attempts>'
            . '</quiz>';
        file_put_contents($dir . '/quiz/shell/assessment_meta.xml', $meta);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Patient Safety Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/shell/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A Canvas quiz shell with no questions in the package is imported as a
     * hidden placeholder carrying its settings, with a teacher-facing note,
     * rather than being dropped — and the build report warns about it.
     *
     * @return void
     */
    public function test_question_less_shell_becomes_hidden_placeholder(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_empty_shell();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // Built (not skipped), and hidden until a teacher adds questions.
        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $modinfo = get_fast_modinfo($report['courseid']);
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $quizcm = reset($quizzes);
        $this->assertEquals(0, (int) $quizcm->visible);

        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
        // No questions, but the Canvas settings carried over.
        $this->assertEquals(0, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
        $this->assertEquals(20 * 60, (int) $quiz->timelimit);
        $this->assertEquals(2, (int) $quiz->attempts);
        // The intro explains what happened, and the report warns about it.
        $this->assertStringContainsString('without its questions', $quiz->intro);
        $this->assertStringContainsString('hidden placeholders', implode("\n", $report['warnings']));
    }

    /**
     * Write a package whose quiz file is a QTI 2.1 assessment (no QTI 1.2
     * <assessment>/<section>), which the parser cannot read and which must not
     * be mistaken for a recoverable Canvas shell.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_non_qti12(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/v2');
        $qti = '<?xml version="1.0"?>'
            . '<assessmentTest xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="t1" title="QTI 2.1 Quiz">'
            . '<testPart identifier="p1"><assessmentSection identifier="s1" title="S"/></testPart>'
            . '</assessmentTest>';
        file_put_contents($dir . '/quiz/v2/assessment_qti.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>QTI 2.1 Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/v2/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A quiz file that isn't a readable QTI 1.2 assessment (here, QTI 2.1) is
     * reported and skipped, NOT turned into a hidden placeholder — masking the
     * conversion failure would hide real data loss.
     *
     * @return void
     */
    public function test_non_qti12_assessment_is_skipped_not_placeholdered(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_non_qti12();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertCount(0, get_fast_modinfo($report['courseid'])->get_instances_of('quiz'));
        // It is reported as a skip, not silently created.
        $this->assertNotEmpty($report['skipreasons']);
        $this->assertStringNotContainsString('hidden placeholders', implode("\n", $report['warnings']));
    }

    /**
     * A broken file with a stray bare <item> but no <assessment>/<section> is a
     * conversion failure, not a Canvas shell: it must be skipped, not turned
     * into a hidden placeholder just because it bumps the unresolved count.
     *
     * @return void
     */
    public function test_bare_item_without_assessment_is_skipped(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/stray');
        $qti = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<item ident="orphan"/></questestinterop>';
        file_put_contents($dir . '/quiz/stray/assessment_qti.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Broken</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/stray/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertCount(0, get_fast_modinfo($report['courseid'])->get_instances_of('quiz'));
        $this->assertStringNotContainsString('hidden placeholders', implode("\n", $report['warnings']));
    }

    /**
     * A native Canvas matching item (question_type, no cc_profile) with two
     * scored pairs and one unused choice carried as a distractor.
     *
     * @return string
     */
    private function nativematchitem(): string {
        $row = function (string $ident, string $stem): string {
            return '<response_lid ident="' . $ident . '"><material><mattext texttype="text/html">' . $stem
                . '</mattext></material><render_choice>'
                . '<response_label ident="o1"><material><mattext>carpal</mattext></material></response_label>'
                . '<response_label ident="o2"><material><mattext>popliteal</mattext></material></response_label>'
                . '<response_label ident="o3"><material><mattext>prone</mattext></material></response_label>'
                . '</render_choice></response_lid>';
        };
        return '<item ident="m1" title="Anatomical terms"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>matching_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">Match the term.</mattext></material>'
            . $row('rA', 'Wrist area') . $row('rB', 'Back of the knee') . '</presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="rA">o1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50.00</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="rB">o2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50.00</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A native Canvas true/false item (question_type, no cc_profile).
     *
     * @return string
     */
    private function nativetfitem(): string {
        return '<item ident="tf1" title="Cells"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>true_false_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">&lt;p&gt;Cells form tissues.&lt;/p&gt;</mattext>'
            . '</material><response_lid ident="response1" rcardinality="Single"><render_choice>'
            . '<response_label ident="t"><material><mattext texttype="text/plain">True</mattext></material></response_label>'
            . '<response_label ident="f"><material><mattext texttype="text/plain">False</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="response1">t</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * Write a package whose Common Cartridge assessment_qti.xml is an empty shell
     * but whose real questions live in non_cc_assessments/<id>.xml.qti (Canvas's
     * native dump), keyed by the same id as the quiz folder.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_native_fallback(): string {
        $dir = make_request_directory();
        mkdir($dir . '/gnative', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        // The CC wrapper is a valid but empty QTI 1.2 shell.
        $shell = '<?xml version="1.0"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnative" title="Week 1 Quiz"><section ident="root_section"/>'
            . '</assessment></questestinterop>';
        file_put_contents($dir . '/gnative/assessment_qti.xml', $shell);
        $meta = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="gnative" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Week 1 Quiz</title>'
            . '<time_limit>20</time_limit>'
            . '<allowed_attempts>2</allowed_attempts>'
            . '</quiz>';
        file_put_contents($dir . '/gnative/assessment_meta.xml', $meta);
        // The native dump holds the actual questions.
        $native = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnative" title="Week 1 Quiz"><section ident="root_section">'
            . $this->nativematchitem() . $this->nativetfitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/gnative.xml.qti', $native);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_q" identifierref="r_quiz"><title>Week 1 Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="gnative/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * Write a package with a page and a quiz whose question prompt links to that
     * page via a Canvas object-reference placeholder.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture_question_link(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz', 0777, true);
        mkdir($dir . '/quiz/ql');
        mkdir($dir . '/wiki_content', 0777, true);
        file_put_contents($dir . '/wiki_content/target.html', '<html><body><h1>Target Page</h1></body></html>');
        $linkhtml = '&lt;p&gt;See &lt;a href="$CANVAS_OBJECT_REFERENCE$/pages/r_page"&gt;the page&lt;/a&gt;&lt;/p&gt;';
        $mc = '<item ident="qlink" title="Linked"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>cc.multiple_choice.v0p1</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">' . $linkhtml . '</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>Alpha</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>Beta</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
        // A matching question whose first row stem carries a Canvas object link,
        // so its subquestion text must be rewritten in the same pass.
        $stemlink = '&lt;p&gt;Match &lt;a href="$CANVAS_OBJECT_REFERENCE$/pages/r_page"&gt;the page&lt;/a&gt;&lt;/p&gt;';
        $choices = '<render_choice>'
            . '<response_label ident="o1"><material><mattext>One</mattext></material></response_label>'
            . '<response_label ident="o2"><material><mattext>Two</mattext></material></response_label></render_choice>';
        $match = '<item ident="qmatch" title="Match"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>matching_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">Match these.</mattext></material>'
            . '<response_lid ident="rA"><material><mattext texttype="text/html">' . $stemlink . '</mattext></material>'
            . $choices . '</response_lid>'
            . '<response_lid ident="rB"><material><mattext>Second</mattext></material>' . $choices . '</response_lid>'
            . '</presentation>'
            . '<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>'
            . '<respcondition><conditionvar><varequal respident="rA">o1</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition>'
            . '<respcondition><conditionvar><varequal respident="rB">o2</varequal></conditionvar>'
            . '<setvar varname="SCORE" action="Add">50</setvar></respcondition></resprocessing></item>';
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="ql" title="Linked Quiz"><section ident="s1">' . $mc . $match
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/ql/qti.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_page" identifierref="r_page"><title>Target Page</title></item>
          <item identifier="i_q" identifierref="r_quiz"><title>Linked Quiz</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/target.html">
      <file href="wiki_content/target.html"/>
    </resource>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="quiz/ql/qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A $CANVAS_OBJECT_REFERENCE$ link inside imported question text is resolved
     * to the real Moodle activity URL by the second-pass rewrite, once every
     * target exists — mirroring the page/forum/intro passes.
     *
     * @return void
     */
    public function test_question_internal_links_rewritten(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_question_link();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $question = $DB->get_record_select(
            'question',
            $DB->sql_like('questiontext', ':needle'),
            ['needle' => '%the page%'],
            'qtype, questiontext',
            MUST_EXIST
        );
        // The Canvas object-reference token resolved to the page's Moodle URL.
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $question->questiontext);
        $this->assertStringContainsString('/mod/page/view.php', $question->questiontext);

        // The same token in a match question's row stem (stored in
        // qtype_match_subquestions) is resolved too.
        $sub = $DB->get_record_select(
            'qtype_match_subquestions',
            $DB->sql_like('questiontext', ':needle'),
            ['needle' => '%Match%'],
            'questiontext',
            MUST_EXIST
        );
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $sub->questiontext);
        $this->assertStringContainsString('/mod/page/view.php', $sub->questiontext);
    }

    /**
     * When the Common Cartridge shell is empty, the builder recovers the real
     * questions from non_cc_assessments/<id>.xml.qti: it builds a visible quiz
     * (not a hidden placeholder), imports the matching and true/false questions,
     * and still carries the settings from assessment_meta.xml.
     *
     * @return void
     */
    public function test_empty_shell_recovered_from_native_dump(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture_native_fallback();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $modinfo = get_fast_modinfo($report['courseid']);
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $quizcm = reset($quizzes);
        // A real quiz, not a hidden placeholder.
        $this->assertEquals(1, (int) $quizcm->visible);
        $this->assertStringNotContainsString('hidden placeholders', implode("\n", $report['warnings']));

        $quiz = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
        // Both native questions imported as slots.
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
        // The settings from assessment_meta.xml still carried over.
        $this->assertEquals(20 * 60, (int) $quiz->timelimit);
        $this->assertEquals(2, (int) $quiz->attempts);

        // The matching question became a Moodle match with three subquestions
        // (two scored pairs plus the carried distractor).
        $matchq = $DB->get_record('question', ['qtype' => 'match'], '*', MUST_EXIST);
        $subqs = $DB->get_records('qtype_match_subquestions', ['questionid' => $matchq->id]);
        $this->assertCount(3, $subqs);
        $answers = array_map(fn($s) => $s->answertext, array_values($subqs));
        sort($answers);
        $this->assertSame(['carpal', 'popliteal', 'prone'], $answers);
    }
}
