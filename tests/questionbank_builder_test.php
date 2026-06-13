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
}
