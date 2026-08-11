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
     * A native Canvas item of an unsupported type (ordering_question), which parses into
     * the model as a question but never becomes importable.
     *
     * @return string
     */
    private function unsupporteditem(): string {
        return '<item ident="uq1"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>ordering_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext texttype="text/html">&lt;p&gt;Order these&lt;/p&gt;</mattext></material>'
            . '</presentation></item>';
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
     * @param int $selnumber The selection_number (questions drawn from the bank).
     * @return string
     */
    private function bankdrawshell(string $ident, string $title, int $selnumber = 2): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="' . $ident . '" title="' . $title . '"><section ident="root">'
            . '<section ident="grp"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref><selection_number>' . $selnumber . '</selection_number>'
            . '</selection></selection_ordering></section>'
            . '</section></assessment></questestinterop>';
    }

    /**
     * Write a package with a single unlinked (orphan) New Quiz that draws from bank1
     * (a two-question objectbank titled "Question Pool").
     *
     * @param int $selnumber The selection_number (questions drawn from the bank).
     * @return string Path to the package root.
     */
    private function write_orphan_bank_package(int $selnumber = 2): string {
        return $this->write_orphan_shell_package($this->bankdrawshell('gnq', 'Final Evaluation', $selnumber));
    }

    /**
     * Write a package with a single unlinked (orphan) New Quiz whose assessment is the
     * given shell, alongside bank1 (a two-question objectbank titled "Question Pool").
     * Optional extra resources/files let a caller declare a second orphan after the quiz.
     *
     * @param string $shell The assessment_qti.xml contents for the orphan quiz.
     * @param string $extraresources Extra manifest <resource> XML appended after the quiz.
     * @param array $extrafiles Map of package-relative path to file contents to also write.
     * @return string Path to the package root.
     */
    private function write_orphan_shell_package(string $shell, string $extraresources = '', array $extrafiles = []): string {
        $dir = make_request_directory();
        mkdir($dir . '/gnq', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        file_put_contents($dir . '/gnq/assessment_qti.xml', $shell);
        file_put_contents($dir . '/non_cc_assessments/bank1.xml.qti', $this->objectbank('Question Pool'));
        foreach ($extrafiles as $path => $content) {
            file_put_contents($dir . '/' . $path, $content);
        }
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org1">'
            . '<item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>'
            . '</organization></organizations><resources>'
            . '<resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">'
            . '<file href="gnq/assessment_qti.xml"/></resource>'
            . $extraresources
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * Write a package with an orphan New Quiz whose draws are short: it references
     * bank1 (a two-question objectbank, present) and missingbank (absent from the
     * package). Optional extra resources/files let a caller declare a second orphan
     * after the quiz.
     *
     * @param string $extraresources Extra manifest <resource> XML appended after the quiz.
     * @param array $extrafiles Map of package-relative path to file contents to also write.
     * @return string Path to the package root.
     */
    private function write_incomplete_orphan_bank_package(string $extraresources = '', array $extrafiles = []): string {
        // The quiz draws from bank1 (present, written by the shared writer) and
        // missingbank (never written, so it can't resolve).
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnq" title="Final Evaluation"><section ident="root">'
            . '<section ident="g1"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>'
            . '<section ident="g2"><selection_ordering><selection>'
            . '<sourcebank_ref>missingbank</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>'
            . '</section></assessment></questestinterop>';
        return $this->write_orphan_shell_package($shell, $extraresources, $extrafiles);
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

        $dir = $this->write_orphan_bank_package();

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
        // Mark the orphan quiz unpublished: it references the same bank as the
        // published linked quiz, so the shared bank must not inherit its hidden state.
        foreach ($coursemodel->orphans as $orphan) {
            $orphan->isvisible = false;
        }
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // The linked assessment built a runnable quiz; the orphan resolved to the same
        // bank — imported once and shared — so there is a single mod_qbank.
        $this->assertCount(1, $modinfo->get_instances_of('quiz'));
        $qbanks = $modinfo->get_instances_of('qbank');
        $this->assertCount(1, $qbanks);
        $bank = reset($qbanks);
        $this->assertSame('Shared Pool', $bank->get_name());
        // The shared bank stays visible; the unpublished orphan didn't hide it (the
        // registry bank is shared infrastructure, never keyed to the orphan quiz item).
        $this->assertEquals(1, (int) $bank->visible);
    }

    /**
     * Issue #144: with the quiz-from-bank toggle on, a pure bank-backed orphan New Quiz
     * (no inline questions, only <selection_ordering> draws) still gets a runnable quiz
     * drawing from the shared bank — the toggle isn't limited to orphans that built a
     * bank of their own.
     *
     * @return void
     */
    public function test_orphan_bank_backed_quiz_gets_runnable_quiz_with_toggle(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = $this->write_orphan_bank_package();

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        // Build with the quiz-from-bank toggle enabled (5th constructor argument).
        $report = (new course_builder($category->id, $dir, null, 0, true))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // A runnable quiz was built (drawing two random questions from the shared bank),
        // alongside the imported bank.
        $quizzes = $modinfo->get_instances_of('quiz');
        $this->assertCount(1, $quizzes);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        $quiz = reset($quizzes);
        $this->assertSame(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->instance]));
        $this->assertGreaterThanOrEqual(1, $report['extraquizzes'] ?? 0);
    }

    /**
     * Issue #144: an orphan New Quiz whose draws are short — a referenced bank absent
     * from the package while another resolves — is flagged with the incomplete-bank
     * warning even when no runnable quiz is built for it.
     *
     * @return void
     */
    public function test_orphan_bank_backed_incomplete_draw_warns(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = $this->write_incomplete_orphan_bank_package();

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // The present bank's questions were still imported (not lost)...
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // ...and the short draw (missingbank) is flagged with the incomplete warning.
        $this->assertStringContainsString('missing part of their questions', implode("\n", $report['warnings']));
    }

    /**
     * Issue #144 review: the incomplete-draw counter must reflect only the orphan quiz
     * itself. questionbank_builder's public lastbankincomplete state is reset per build,
     * but a non-quiz orphan (here a web link) processed after the short quiz doesn't run
     * that builder, so reading its stale flag once per following orphan would inflate the
     * warning. The warning must still report exactly one bank-backed quiz.
     *
     * @return void
     */
    public function test_incomplete_orphan_bank_counted_once_across_later_orphans(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Declare the short orphan quiz first, then two unrelated non-quiz orphans (web
        // links) that are processed after it in the same single-item orphan loop.
        $link = '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1">'
            . '<title>Reading</title><url href="https://example.com/reading"/></webLink>';
        $extraresources =
            '<resource identifier="r_l1" type="imswl_xmlv1p1"><file href="l1.xml"/></resource>'
            . '<resource identifier="r_l2" type="imswl_xmlv1p1"><file href="l2.xml"/></resource>';
        $dir = $this->write_incomplete_orphan_bank_package($extraresources, ['l1.xml' => $link, 'l2.xml' => $link]);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $warnings = implode("\n", $report['warnings']);
        // Exactly one quiz is short — not one plus a phantom for each trailing orphan.
        $this->assertStringContainsString('1 bank-backed quiz(zes) are missing part of their questions', $warnings);
        $this->assertStringNotContainsString('2 bank-backed quiz(zes)', $warnings);
        $this->assertStringNotContainsString('3 bank-backed quiz(zes)', $warnings);
    }

    /**
     * Issue #144 review: a pure bank-backed orphan New Quiz builds no bank module of its
     * own (build() returns null) but its questions are imported into a shared bank, so it
     * must be reported as handled — counted as created and absent from the skip list —
     * not tallied as an unconvertible/skipped item.
     *
     * @return void
     */
    public function test_handled_bank_backed_orphan_not_reported_as_skipped(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = $this->write_orphan_bank_package();

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        // Toggle off: no runnable quiz is built, so the item's questions live only in the
        // shared bank — the pure "handled via shared bank" case.
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // The shared bank holds the imported questions.
        $modinfo = get_fast_modinfo($report['courseid']);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // The orphan quiz is the package's only item; it's counted created (handled),
        // not skipped, and leaves no skip note behind.
        $this->assertSame(1, $report['createdcounts']['quiz'] ?? 0);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(0, $report['skippedcounts']['quiz'] ?? 0);
        $this->assertEmpty($report['skipreasons']);
    }

    /**
     * Issue #144 review: an orphan New Quiz whose draw asks for more questions than the
     * referenced bank holds is short even though every source question imported
     * (full === true). A selection_number of 5 against a two-question bank must still
     * raise the incomplete-bank warning.
     *
     * @return void
     */
    public function test_orphan_bank_draw_exceeding_pool_warns(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // The quiz draws 5 questions from bank1, which only holds 2.
        $dir = $this->write_orphan_bank_package(5);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        // The bank's two questions were still imported...
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // ...but the over-sized draw (5 > 2) is flagged as short.
        $this->assertStringContainsString('missing part of their questions', implode("\n", $report['warnings']));
    }

    /**
     * Issue #144 review: an orphan New Quiz that draws the whole bank twice (two groups
     * both omitting selection_number) can't satisfy the second group from a bank the
     * first already drained, so it must still be reported incomplete even though the bank
     * imported fully.
     *
     * @return void
     */
    public function test_orphan_repeated_draw_all_groups_warn(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Two <selection> groups reference bank1 and both omit selection_number, so each
        // asks for the whole (two-question) bank; the second can't be filled.
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnq" title="Final Evaluation"><section ident="root">'
            . '<section ident="g1"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref>'
            . '</selection></selection_ordering></section>'
            . '<section ident="g2"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref>'
            . '</selection></selection_ordering></section>'
            . '</section></assessment></questestinterop>';
        $dir = $this->write_orphan_shell_package($shell);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        $this->assertStringContainsString('missing part of their questions', implode("\n", $report['warnings']));
    }

    /**
     * Issue #144 review: an orphan New Quiz that has bank draws plus inline questions of
     * its own that are all unconvertible (unsupported) must not be marked handled-via-bank
     * — those inline questions would be lost silently. The item keeps its unconvertible
     * skip reason and is counted skipped, even though the referenced bank imported.
     *
     * @return void
     */
    public function test_unconvertible_inline_questions_not_masked_by_bank(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // The quiz draws two questions from bank1 and also carries one inline question of
        // an unsupported type (its own content, which cannot convert).
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnq" title="Final Evaluation"><section ident="root">'
            . '<section ident="g1"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>'
            . $this->unsupporteditem()
            . '</section></assessment></questestinterop>';
        $dir = $this->write_orphan_shell_package($shell);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // The referenced bank was still imported as a side effect...
        $modinfo = get_fast_modinfo($report['courseid']);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // ...but the item is NOT masked as created: its unconvertible inline question
        // keeps it in the skipped tally with an honest skip reason.
        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertSame(1, $report['skippedcounts']['quiz'] ?? 0);
        $this->assertNotEmpty($report['skipreasons']);
    }

    /**
     * Issue #144 review: when the Common Cartridge QTI holds unconvertible inline
     * questions and the native dump for the same assessment holds only bank draws, the
     * native fallback must not discard the CC inline questions. Adopting the questionless
     * native parse wholesale would make the orphan look like a pure bank-backed shell and
     * silently drop the inline questions; the CC conversion failure has to be preserved.
     *
     * @return void
     */
    public function test_native_selection_fallback_keeps_cc_inline_failures(): void {
        // The CC assessment carries one inline unsupported question and no draws of its
        // own; the native dump for the same id carries only a bank draw (no questions).
        $report = $this->build_cc_plus_native_draw($this->unsupporteditem());

        // The native dump's bank draw was still imported as a side effect...
        $modinfo = get_fast_modinfo($report['courseid']);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // ...but the CC inline question isn't masked away: the item is counted skipped
        // with an honest skip reason rather than silently marked handled-via-bank.
        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertSame(1, $report['skippedcounts']['quiz'] ?? 0);
        $this->assertNotEmpty($report['skipreasons']);
    }

    /**
     * Issue #144 review: an orphan New Quiz whose Common Cartridge QTI holds convertible
     * inline questions but whose item-bank draws live only in the native dump must still
     * import those draws. The native dump has to be inspected for selections even when the
     * CC questions convert; otherwise every referenced bank question is silently omitted.
     *
     * @return void
     */
    public function test_native_selections_imported_when_cc_questions_convert(): void {
        // The CC assessment carries two convertible inline questions and no draws; the
        // native dump for the same id carries only a bank1 draw.
        $report = $this->build_cc_plus_native_draw($this->mcitem() . $this->fibitem());

        // Two banks exist: the item's own inline bank (its convertible CC questions) and
        // the referenced item bank drawn from the native dump — the draw wasn't dropped.
        $modinfo = get_fast_modinfo($report['courseid']);
        $banks = $modinfo->get_instances_of('qbank');
        $this->assertCount(2, $banks);
        $names = array_map(fn($b) => $b->get_name(), $banks);
        $this->assertContains('Final Evaluation', $names);
        $this->assertContains('Question Pool', $names);
    }

    /**
     * Issue #144 review: an orphan New Quiz whose Common Cartridge QTI lists bare item
     * references (questions Canvas didn't export the bodies of) plus native bank draws
     * must not be marked handled-via-bank. Bare references are tracked in the parser's
     * unresolved count rather than the questions array, so the missing content still has
     * to be reported as skipped rather than silently absorbed by the bank import.
     *
     * @return void
     */
    public function test_unresolved_references_not_masked_by_bank(): void {
        // The CC assessment lists one bare item reference (no body) and no draws of its own.
        $report = $this->build_cc_plus_native_draw('<item ident="missingq"/>');

        // The native dump's bank draw was still imported...
        $modinfo = get_fast_modinfo($report['courseid']);
        $this->assertCount(1, $modinfo->get_instances_of('qbank'));
        // ...but the bare reference isn't masked: the item is counted skipped and its
        // missing content is reported.
        $this->assertSame(0, $report['createdcounts']['quiz'] ?? 0);
        $this->assertSame(1, $report['skippedcounts']['quiz'] ?? 0);
        $this->assertStringContainsString('not present in the package', implode("\n", $report['skipreasons']));
    }

    /**
     * Build an orphan New Quiz whose Common Cartridge assessment holds the given item
     * body and whose native dump (gnq.xml.qti) carries only a bank1 draw, then run the
     * course builder. Shared by the native-fallback tests.
     *
     * @param string $ccbody The <item>/section markup for the CC assessment's root section.
     * @return array The build report.
     */
    private function build_cc_plus_native_draw(string $ccbody): array {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $wrap = fn($body) => '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="gnq" title="Final Evaluation"><section ident="root">'
            . $body . '</section></assessment></questestinterop>';
        $nativedraw = '<section ident="grp"><selection_ordering><selection>'
            . '<sourcebank_ref>bank1</sourcebank_ref><selection_number>2</selection_number>'
            . '</selection></selection_ordering></section>';
        $dir = $this->write_orphan_shell_package(
            $wrap($ccbody),
            '',
            ['non_cc_assessments/gnq.xml.qti' => $wrap($nativedraw)]
        );

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        return (new course_builder($category->id, $dir))->build($coursemodel);
    }
}
