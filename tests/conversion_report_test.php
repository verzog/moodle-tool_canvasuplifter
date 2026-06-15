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

use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\model\section_model;
use tool_canvasuplifter\local\report\conversion_report;

/**
 * Tests for the conversion report.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\report\conversion_report
 */
final class conversion_report_test extends \advanced_testcase {
    /**
     * The report should split builds-now from later, and surface detail/orphans.
     *
     * @return void
     */
    public function test_build_reports_detail_and_orphans(): void {
        $course = new course_model();
        $course->fullname = 'Demo course';

        $section = new section_model('Week 1');
        $page = new item('i1', 'Welcome');
        $page->kind = item::KIND_PAGE;
        $assign = new item('i2', 'Essay');
        $assign->kind = item::KIND_ASSIGNMENT;
        $section->add_item($page);
        $section->add_item($assign);
        $course->add_section($section);

        $orphan = new item('r9', '');
        $orphan->kind = item::KIND_FILE;
        $orphan->resourcetype = 'webcontent';
        $course->orphans[] = $orphan;

        $report = (new conversion_report($course))->build();

        // Page, the orphan file and the assignment all build now (3).
        $this->assertSame(3, $report['buildsnowtotal']);
        $this->assertSame(0, $report['latertotal']);

        $bykind = [];
        foreach ($report['rows'] as $row) {
            $bykind[$row['kind']] = $row;
        }
        $this->assertTrue($bykind['page']['buildsnow']);
        $this->assertTrue($bykind['assignment']['buildsnow']);
        $this->assertSame('note_assignment', $bykind['assignment']['note']);

        // Per-section drill-down.
        $this->assertCount(1, $report['sections']);
        $this->assertSame('Week 1', $report['sections'][0]['title']);
        $this->assertCount(2, $report['sections'][0]['items']);

        // The orphan is surfaced and falls back to its identifier for a title.
        $this->assertCount(1, $report['orphans']);
        $this->assertSame('r9', $report['orphans'][0]['title']);
        $this->assertSame('file', $report['orphans'][0]['kind']);
        // A non-syllabus orphan is destined for the extras section.
        $this->assertSame('extras', $report['orphans'][0]['placement']);
        // Without a package root, there is no question-type matrix.
        $this->assertSame([], $report['questionmatrix']);
    }

    /**
     * Canvas ContextModuleSubHeader items count towards "builds now" (they're in
     * course_builder::BUILDS_NOW), and the mapping plan reports them as mod_label
     * rather than as unknown items the admin would assume aren't going anywhere.
     *
     * @return void
     */
    public function test_subheader_reports_as_label(): void {
        $course = new course_model();
        $section = new section_model('Week 1');
        $sub = new item('s1', 'Before Class');
        $sub->kind = item::KIND_SUBHEADER;
        $section->add_item($sub);
        $course->add_section($section);

        $report = (new conversion_report($course))->build();

        $row = $report['sections'][0]['items'][0];
        $this->assertSame('subheader', $row['kind']);
        $this->assertTrue($row['buildsnow']);
        $this->assertSame('mod_label', $row['target']);
        $this->assertSame(conversion_report::CONFIDENCE_FULL, $row['confidence']);
        $this->assertSame('note_subheader', $row['note']);
    }

    /**
     * The syllabus orphan is reported as going to the top of the course.
     *
     * @return void
     */
    public function test_syllabus_orphan_placement(): void {
        $course = new course_model();
        $syllabus = new item('isyll', 'Syllabus');
        $syllabus->kind = item::KIND_PAGE;
        $syllabus->intendeduse = 'syllabus';
        $course->orphans[] = $syllabus;

        $report = (new conversion_report($course))->build();

        $this->assertSame('top', $report['orphans'][0]['placement']);
    }

    /**
     * An orphan assessment is reported as a question bank (mod_qbank), matching
     * the builder, while a referenced one stays a quiz (mod_quiz).
     *
     * @return void
     */
    public function test_orphan_quiz_reports_as_question_bank(): void {
        $course = new course_model();
        $section = new section_model('Week 1');
        $linked = new item('q_ref', 'Chapter Quiz');
        $linked->kind = item::KIND_QUIZ;
        $section->add_item($linked);
        $course->add_section($section);
        $orphan = new item('q_orphan', 'Homework Bank');
        $orphan->kind = item::KIND_QUIZ;
        $course->orphans[] = $orphan;

        $report = (new conversion_report($course))->build();

        // The unreferenced assessment is reported as a question bank.
        $this->assertSame('mod_qbank', $report['orphans'][0]['target']);
        // The referenced one keeps the quiz target in the section drill-down.
        $this->assertSame('mod_quiz', $report['sections'][0]['items'][0]['target']);

        // The aggregate splits the quiz content type by its real targets.
        $targets = [];
        foreach ($report['rows'] as $row) {
            if ($row['kind'] === 'quiz') {
                $targets[$row['target']] = $row['count'];
            }
        }
        $this->assertSame(1, $targets['mod_quiz'] ?? 0);
        $this->assertSame(1, $targets['mod_qbank'] ?? 0);
    }

    /**
     * Given a package root, the matrix tallies supported question types and
     * lists unsupported ones by their Canvas profile.
     *
     * @return void
     */
    public function test_question_type_matrix(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Quiz"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . $this->unsupporteditem('cc.numeric.v0p1')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1.xml', $qti);

        $course = new course_model();
        $quiz = new item('q1', 'Quiz');
        $quiz->kind = item::KIND_QUIZ;
        $quiz->files = ['quiz/a1.xml'];
        $course->orphans[] = $quiz;

        $matrix = (new conversion_report($course, $dir))->build()['questionmatrix'];

        $this->assertSame(2, $matrix['total']);
        $this->assertSame(1, $matrix['supported']);
        $bylabel = [];
        foreach ($matrix['rows'] as $row) {
            $bylabel[$row['label']] = $row;
        }
        $this->assertTrue($bylabel['multichoice']['supported']);
        $this->assertSame('yes', $bylabel['multichoice']['status']);
        $this->assertFalse($bylabel['cc.numeric.v0p1']['supported']);
        $this->assertSame('unsupported', $bylabel['cc.numeric.v0p1']['status']);
        $this->assertSame(1, $bylabel['cc.numeric.v0p1']['count']);
    }

    /**
     * A recognised question type Moodle would reject (a single-option choice) is
     * counted as not converting, so the "will convert" total stays honest.
     *
     * @return void
     */
    public function test_matrix_counts_unsaveable_question_as_not_converting(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Quiz"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . $this->oneoptionitem('cc.multiple_choice.v0p1')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1.xml', $qti);

        $course = new course_model();
        $quiz = new item('q1', 'Quiz');
        $quiz->kind = item::KIND_QUIZ;
        $quiz->files = ['quiz/a1.xml'];
        $course->orphans[] = $quiz;

        $matrix = (new conversion_report($course, $dir))->build()['questionmatrix'];

        $this->assertSame(2, $matrix['total']);
        // Only the well-formed multichoice converts.
        $this->assertSame(1, $matrix['supported']);
        $converting = array_filter($matrix['rows'], fn($r) => $r['label'] === 'multichoice' && $r['supported']);
        $notconverting = array_filter($matrix['rows'], fn($r) => $r['label'] === 'multichoice' && !$r['supported']);
        $this->assertSame(1, (int) (reset($converting)['count'] ?? 0));
        $this->assertSame(1, (int) (reset($notconverting)['count'] ?? 0));
        // The single-option ("only") choice is a recognised type we can't
        // complete, so it is flagged incomplete rather than unsupported.
        $this->assertSame('incomplete', reset($notconverting)['status']);
    }

    /**
     * A single-option choice item: a recognised multichoice type, but Moodle
     * needs at least two answers, so it cannot actually be saved.
     *
     * @param string $profile The cc_profile value.
     * @return string
     */
    private function oneoptionitem(string $profile): string {
        return '<item ident="i_one_' . md5($profile) . '"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>' . $profile . '</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Q?</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>only</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">A</varequal></conditionvar>'
            . '<setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A minimal single-choice QTI item carrying the given profile and correct id.
     *
     * @param string $profile The cc_profile value.
     * @param string $correct The correct response label id.
     * @return string
     */
    private function profileitem(string $profile, string $correct): string {
        return '<item ident="i_' . md5($profile) . '"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>' . $profile . '</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Q?</mattext></material>'
            . '<response_lid ident="r1" rcardinality="Single"><render_choice>'
            . '<response_label ident="A"><material><mattext>a</mattext></material></response_label>'
            . '<response_label ident="B"><material><mattext>b</mattext></material></response_label>'
            . '</render_choice></response_lid></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes>'
            . '<respcondition continue="No"><conditionvar><varequal respident="r1">' . $correct . '</varequal>'
            . '</conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition></resprocessing></item>';
    }

    /**
     * A QTI item with a presentation but a numeric response (no response_lid),
     * which the parser cannot map and reports as unsupported.
     *
     * @param string $profile The cc_profile value.
     * @return string
     */
    private function unsupporteditem(string $profile): string {
        return '<item ident="i_' . md5($profile) . '"><itemmetadata><qtimetadata>'
            . '<qtimetadatafield><fieldlabel>cc_profile</fieldlabel><fieldentry>' . $profile . '</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Enter a number</mattext></material>'
            . '<response_num ident="r1"><render_fib fibtype="Decimal"/></response_num></presentation>'
            . '<resprocessing><outcomes><decvar varname="SCORE"/></outcomes></resprocessing></item>';
    }
}
