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
 * End-to-end test for the QTI question bank builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\questionbank_builder
 */
final class questionbank_builder_test extends \advanced_testcase {
    /**
     * Write a package with a single QTI assessment holding two questions.
     *
     * @return string Path to the package root.
     */
    protected function build_qti_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/quiz/a1');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Chapter 1 Quiz"><section ident="s1">'
            . $this->mcitem() . $this->fibitem()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1/qti.xml', $qti);
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
     * Write a package whose Common Cartridge assessment_qti.xml is an empty New
     * Quizzes shell, with the real questions living only in the native
     * non_cc_assessments/<id>.xml.qti dump (as Canvas exports them). The
     * assessment is unreferenced, so course_builder routes it to the question
     * bank builder.
     *
     * @return string Path to the package root.
     */
    protected function build_shell_with_native_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/a1', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        // The CC QTI is a genuine but empty shell: an <assessment>/<section> with
        // no items, exactly what New Quizzes exports.
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1"></section>'
            . '</assessment></questestinterop>';
        file_put_contents($dir . '/a1/assessment_qti.xml', $shell);
        // The native dump carries the real questions, keyed by canvas question_type.
        $native = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1">'
            . $this->nativemcitem() . $this->nativemcitem2()
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/a1.xml.qti', $native);
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
      <file href="a1/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A native Canvas multiple-choice item (question_type metadata, answer B).
     *
     * @return string
     */
    private function nativemcitem(): string {
        return '<item ident="nq1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_choice_question</fieldentry>'
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
     * A second native Canvas multiple-choice item (answer A).
     *
     * @return string
     */
    private function nativemcitem2(): string {
        return '<item ident="nq2"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>multiple_choice_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">&lt;p&gt;Pick A&lt;/p&gt;</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>Aye</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>Bee</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * Issue #127: when the CC assessment_qti.xml is an empty shell, the builder
     * falls back to the native non_cc_assessments dump (as quiz_builder does) and
     * builds a bank holding the recovered questions rather than skipping it.
     *
     * @return void
     */
    public function test_build_recovers_questions_from_native_qti_dump(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_shell_with_native_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $banks = $modinfo->get_instances_of('qbank');
        $this->assertCount(1, $banks);
        $bank = reset($banks);
        $this->assertSame('New quiz engine', $bank->get_name());

        // Both native questions were recovered into the bank.
        $context = \context_module::instance($bank->id);
        $cat = question_get_default_category($context->id);
        $this->assertNotEmpty($cat);
        $count = $DB->count_records('question_bank_entries', ['questioncategoryid' => $cat->id]);
        $this->assertSame(2, $count);
    }

    /**
     * Building the package creates a qbank in section 0 with both questions.
     *
     * @return void
     */
    public function test_build_imports_question_bank(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_qti_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $banks = $modinfo->get_instances_of('qbank');
        $this->assertCount(1, $banks);
        $bank = reset($banks);
        $this->assertSame('Chapter 1 Quiz', $bank->get_name());
        $this->assertEquals(0, $bank->sectionnum);

        // Both questions were imported into the bank's default category.
        $context = \context_module::instance($bank->id);
        $cat = question_get_default_category($context->id);
        $this->assertNotEmpty($cat);
        $count = $DB->count_records('question_bank_entries', ['questioncategoryid' => $cat->id]);
        $this->assertSame(2, $count);
    }

    /**
     * An objectbank item bank holding two multiple-choice questions.
     *
     * @param string $title The bank_title.
     * @return string
     */
    private function objectbank(string $title): string {
        return '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<objectbank ident="bank1"><qtimetadata><qtimetadatafield>'
            . '<fieldlabel>bank_title</fieldlabel><fieldentry>' . $title . '</fieldentry>'
            . '</qtimetadatafield></qtimetadata>'
            . $this->nativemcitem() . $this->nativemcitem2()
            . '</objectbank></questestinterop>';
    }

    /**
     * A Canvas New Quiz shell that draws two questions from bank1 via a
     * <selection_ordering>/<sourcebank_ref>, with the given assessment id/title.
     *
     * @param string $ident The assessment ident.
     * @param string $title The assessment title.
     * @return string
     */
    private function bankdrawshell(string $ident, string $title): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="' . $ident . '" title="' . $title . '"><section ident="root">'
            . '<section ident="grp"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>'
            . '</section></assessment></questestinterop>';
    }

    /**
     * Issue #144: a bank-backed New Quiz that isn't linked from any module is routed
     * to questionbank_builder; it now resolves its <selection_ordering> draws and
     * imports the referenced item bank instead of dropping the questions.
     *
     * @return void
     */
    public function test_orphan_new_quiz_imports_referenced_bank(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/gnq', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        file_put_contents($dir . '/gnq/assessment_qti.xml', $this->bankdrawshell('gnq', 'Final Evaluation'));
        file_put_contents($dir . '/non_cc_assessments/bank1.xml.qti', $this->objectbank('Question Pool'));
        // The assessment resource is declared but not linked in any module (orphan).
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
      <file href="gnq/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // The referenced bank was imported (its two questions kept), not dropped,
        // even though the orphan New Quiz carried no inline questions of its own.
        $banks = $modinfo->get_instances_of('qbank');
        $this->assertCount(1, $banks);
        $bank = reset($banks);
        $this->assertSame('Question Pool', $bank->get_name());
        $this->assertEquals(0, $bank->sectionnum);
        $context = \context_module::instance($bank->id);
        $cat = question_get_default_category($context->id);
        $this->assertSame(2, $DB->count_records('question_bank_entries', ['questioncategoryid' => $cat->id]));
    }

    /**
     * Issue #144: a linked quiz (quiz_builder) and an orphan quiz (questionbank_builder)
     * that both draw from the same item bank share one imported mod_qbank via the
     * cross-builder registry, rather than importing it twice.
     *
     * @return void
     */
    public function test_shared_bank_imported_once_across_builders(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/q1', 0777, true);
        mkdir($dir . '/q2', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        file_put_contents($dir . '/q1/assessment_qti.xml', $this->bankdrawshell('q1', 'Linked Quiz'));
        file_put_contents($dir . '/q2/assessment_qti.xml', $this->bankdrawshell('q2', 'Orphan Quiz'));
        file_put_contents($dir . '/non_cc_assessments/bank1.xml.qti', $this->objectbank('Shared Pool'));
        // Link q1 in the module; leave q2 declared but unlinked (orphan). Both draw
        // from bank1.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_q1" identifierref="r_q1"><title>Linked Quiz</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_q1" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="q1/assessment_qti.xml"/>
    </resource>
    <resource identifier="r_q2" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">
      <file href="q2/assessment_qti.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // The linked assessment built a runnable quiz; the orphan built as a bank —
        // but bank1 was imported once and shared, so there is a single mod_qbank.
        $this->assertCount(1, $modinfo->get_instances_of('quiz'));
        $qbanks = $modinfo->get_instances_of('qbank');
        $this->assertCount(1, $qbanks);
        $bank = reset($qbanks);
        $this->assertSame('Shared Pool', $bank->get_name());
    }
}
