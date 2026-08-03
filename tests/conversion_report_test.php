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

        // Per-section drill-down: subheader appears with its label target.
        $sectionrow = $report['sections'][0]['items'][0];
        $this->assertSame('subheader', $sectionrow['kind']);
        $this->assertTrue($sectionrow['buildsnow']);
        $this->assertSame('mod_label', $sectionrow['target']);
        $this->assertSame(conversion_report::CONFIDENCE_FULL, $sectionrow['confidence']);

        // Aggregate row carries the note (per-section rows intentionally don't).
        $bykind = [];
        foreach ($report['rows'] as $row) {
            $bykind[$row['kind']] = $row;
        }
        $this->assertArrayHasKey('subheader', $bykind);
        $this->assertSame('mod_label', $bykind['subheader']['target']);
        $this->assertSame('note_subheader', $bykind['subheader']['note']);
        $this->assertTrue($bykind['subheader']['buildsnow']);
        $this->assertSame(1, $report['buildsnowtotal']);
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
     * When an unreferenced quiz with importable questions would import as a bank
     * only, the report nudges the user toward the quiz-from-bank toggle — but only
     * while that toggle is off.
     *
     * @return void
     */
    public function test_orphan_quiz_nudges_toward_quizfrombank(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Homework"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/good.xml', $qti);

        $course = new course_model();
        $orphan = new item('q_orphan', 'Homework');
        $orphan->kind = item::KIND_QUIZ;
        $orphan->files = ['quiz/good.xml'];
        $course->orphans[] = $orphan;

        // Toggle off (default): the nudge is present.
        $off = (new conversion_report($course, $dir))->build();
        $this->assertContains('warnreportquizfrombank', $off['warnings']);

        // Toggle on: the standalone quiz will be built, so no nudge.
        $on = (new conversion_report($course, $dir, '', true))->build();
        $this->assertNotContains('warnreportquizfrombank', $on['warnings']);

        // A course whose only quiz is referenced already builds a runnable quiz,
        // so there is nothing to nudge about even with the toggle off.
        $linkedcourse = new course_model();
        $section = new section_model('Week 1');
        $linked = new item('q_ref', 'Chapter Quiz');
        $linked->kind = item::KIND_QUIZ;
        $linked->files = ['quiz/good.xml'];
        $section->add_item($linked);
        $linkedcourse->add_section($section);
        $linkedonly = (new conversion_report($linkedcourse, $dir))->build();
        $this->assertNotContains('warnreportquizfrombank', $linkedonly['warnings']);
    }

    /**
     * An orphan quiz with no importable questions (an empty shell, or only
     * unsupported types) builds neither a bank nor a quiz, so the nudge must not
     * fire for it — enabling the toggle would deliver nothing.
     *
     * @return void
     */
    public function test_no_nudge_for_orphan_quiz_without_importable_questions(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Empty"><section ident="s1">'
            . $this->unsupporteditem('cc.numeric.v0p1')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/shell.xml', $qti);

        $course = new course_model();
        $orphan = new item('q_shell', 'Empty');
        $orphan->kind = item::KIND_QUIZ;
        $orphan->files = ['quiz/shell.xml'];
        $course->orphans[] = $orphan;

        $report = (new conversion_report($course, $dir))->build();
        $this->assertNotContains('warnreportquizfrombank', $report['warnings']);
    }

    /**
     * A native Canvas assessment supplied as a .xml.qti dump also builds, so its
     * eligibility for the nudge must be recognised — matching the builders, which
     * accept .xml.qti as well as .xml.
     *
     * @return void
     */
    public function test_orphan_quiz_nudge_recognises_xmlqti(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $qti = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Homework"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/a1.xml.qti', $qti);

        $course = new course_model();
        $orphan = new item('q_native', 'Homework');
        $orphan->kind = item::KIND_QUIZ;
        $orphan->files = ['quiz/a1.xml.qti'];
        $course->orphans[] = $orphan;

        $report = (new conversion_report($course, $dir))->build();
        $this->assertContains('warnreportquizfrombank', $report['warnings']);
    }

    /**
     * Build a course of one section holding the given ordered item kinds, so the
     * page-grouping reflection can be probed.
     *
     * @param array $kinds Ordered item::KIND_* values for the section.
     * @return course_model
     */
    private function course_with_kinds(array $kinds): course_model {
        $course = new course_model();
        $section = new section_model('Unit');
        foreach ($kinds as $i => $kind) {
            $modelitem = new item('i' . $i, 'Item ' . $i);
            $modelitem->kind = $kind;
            $section->add_item($modelitem);
        }
        $course->add_section($section);
        return $course;
    }

    /**
     * Map the aggregate rows by "kind|target" for convenient assertions.
     *
     * @param array $report A built report.
     * @return array<string, array>
     */
    private function rows_by_target(array $report): array {
        $bykey = [];
        foreach ($report['rows'] as $row) {
            $bykey[$row['kind'] . '|' . $row['target']] = $row;
        }
        return $bykey;
    }

    /**
     * With the page-grouping option set, a run of consecutive pages is reported
     * as building into a single book (or lesson), so the analysis reflects the
     * choice the admin made before analysing.
     *
     * @return void
     */
    public function test_pagegrouping_reflects_consecutive_pages(): void {
        $course = $this->course_with_kinds([item::KIND_PAGE, item::KIND_PAGE, item::KIND_PAGE]);

        $book = $this->rows_by_target((new conversion_report($course, null, 'book'))->build());
        $this->assertArrayHasKey('page|mod_book', $book);
        $this->assertSame(3, $book['page|mod_book']['count']);
        $this->assertArrayNotHasKey('page|mod_page', $book);
        $this->assertSame('note_page_grouped_book', $book['page|mod_book']['note']);
        $this->assertTrue($book['page|mod_book']['buildsnow']);

        $lesson = $this->rows_by_target((new conversion_report($course, null, 'lesson'))->build());
        $this->assertArrayHasKey('page|mod_lesson', $lesson);
        $this->assertSame('note_page_grouped_lesson', $lesson['page|mod_lesson']['note']);
    }

    /**
     * The section drill-down and builds-now total reflect grouping too: grouped
     * pages target the book and still count as building now.
     *
     * @return void
     */
    public function test_pagegrouping_reflects_in_section_detail(): void {
        $course = $this->course_with_kinds([item::KIND_PAGE, item::KIND_PAGE]);

        $report = (new conversion_report($course, null, 'book'))->build();

        $this->assertSame(2, $report['buildsnowtotal']);
        foreach ($report['sections'][0]['items'] as $detailitem) {
            $this->assertSame('mod_book', $detailitem['target']);
        }
    }

    /**
     * Grouping is tracked per occurrence, not per object: the manifest parser
     * shares one resource object across sections, so the same page can sit in a
     * 2+ page run in one section and as a lone page in another. Each occurrence
     * must report the target the builder would actually create there.
     *
     * @return void
     */
    public function test_pagegrouping_is_per_occurrence_not_per_object(): void {
        $course = new course_model();

        $shared = new item('shared', 'Shared');
        $shared->kind = item::KIND_PAGE;

        // Section one: a run of two pages (the shared page groups here).
        $runsection = new section_model('Run');
        $first = new item('p1', 'First');
        $first->kind = item::KIND_PAGE;
        $runsection->add_item($first);
        $runsection->add_item($shared);
        $course->add_section($runsection);

        // Section two: the SAME shared object, alone before an assignment.
        $lonesection = new section_model('Lone');
        $lonesection->add_item($shared);
        $assign = new item('a1', 'Essay');
        $assign->kind = item::KIND_ASSIGNMENT;
        $lonesection->add_item($assign);
        $course->add_section($lonesection);

        $report = (new conversion_report($course, null, 'book'))->build();

        // The run occurrence folds into the book; the lone occurrence stays a page.
        $this->assertSame('mod_book', $report['sections'][0]['items'][1]['target']);
        $this->assertSame('mod_page', $report['sections'][1]['items'][0]['target']);

        // The aggregate splits the page kind across both targets, not all-book.
        $bykey = $this->rows_by_target($report);
        $this->assertSame(2, $bykey['page|mod_book']['count']);
        $this->assertSame(1, $bykey['page|mod_page']['count']);
    }

    /**
     * Without the option, the same consecutive pages are reported individually
     * as mod_page, and a lone page broken by another activity never groups even
     * when the option is on.
     *
     * @return void
     */
    public function test_pagegrouping_off_and_lone_pages_stay_pages(): void {
        $course = $this->course_with_kinds([item::KIND_PAGE, item::KIND_PAGE]);
        $off = $this->rows_by_target((new conversion_report($course, null, ''))->build());
        $this->assertSame(2, $off['page|mod_page']['count']);
        $this->assertArrayNotHasKey('page|mod_book', $off);

        // Page, assignment, page: two runs of a single page each, so neither groups.
        $broken = $this->course_with_kinds([item::KIND_PAGE, item::KIND_ASSIGNMENT, item::KIND_PAGE]);
        $on = $this->rows_by_target((new conversion_report($broken, null, 'book'))->build());
        $this->assertSame(2, $on['page|mod_page']['count']);
        $this->assertArrayNotHasKey('page|mod_book', $on);
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
        // The dropped question is attributed to the assessment it came from.
        $this->assertSame([['name' => 'Quiz', 'count' => 1]], $bylabel['cc.numeric.v0p1']['sources']);
        // A converting row carries no source attribution.
        $this->assertSame([], $bylabel['multichoice']['sources']);
    }

    /**
     * When the Common Cartridge assessment is an empty shell (Canvas exports New
     * Quizzes with the questions only in the native dump), the matrix falls back
     * to non_cc_assessments/<id>.xml.qti — as the builder does — so New Quizzes
     * questions are counted instead of showing an empty matrix.
     *
     * @return void
     */
    public function test_matrix_falls_back_to_native_dump_for_new_quizzes(): void {
        $dir = make_request_directory();
        mkdir($dir . '/a1');
        mkdir($dir . '/non_cc_assessments');
        // The CC file is a valid but question-less shell.
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1"></section>'
            . '</assessment></questestinterop>';
        file_put_contents($dir . '/a1/assessment_qti.xml', $shell);
        // The real questions live in the native dump.
        $native = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/a1.xml.qti', $native);

        // A referenced quiz: only these build through quiz_builder's native
        // fallback (orphan quizzes/banks use questionbank_builder, no fallback).
        $course = new course_model();
        $section = new section_model('Week 1');
        $quiz = new item('q1', 'New quiz engine');
        $quiz->kind = item::KIND_QUIZ;
        $quiz->files = ['a1/assessment_qti.xml', 'non_cc_assessments/a1.xml.qti'];
        $section->add_item($quiz);
        $course->add_section($section);

        $matrix = (new conversion_report($course, $dir))->build()['questionmatrix'];

        // Without the native-dump fallback this would be an empty matrix.
        $this->assertSame(1, $matrix['total']);
        $this->assertSame(1, $matrix['supported']);
        $this->assertSame('multichoice', $matrix['rows'][0]['label']);

        // The same shell as an unreferenced orphan builds through
        // questionbank_builder (no native fallback), so the matrix must not count
        // its native questions as convertible.
        $orphancourse = new course_model();
        $orphan = new item('q2', 'New quiz engine');
        $orphan->kind = item::KIND_QUIZ;
        $orphan->files = ['a1/assessment_qti.xml', 'non_cc_assessments/a1.xml.qti'];
        $orphancourse->orphans[] = $orphan;
        $this->assertSame([], (new conversion_report($orphancourse, $dir))->build()['questionmatrix']);
    }

    /**
     * A referenced New Quiz whose native dump holds only unsupported questions
     * still surfaces them in the matrix (as unsupported rows) — these are the
     * quizzes most at risk of silent question loss, so the report must not fall
     * through to the empty CC shell and show nothing.
     *
     * @return void
     */
    public function test_matrix_shows_unsupported_only_native_dump(): void {
        $dir = make_request_directory();
        mkdir($dir . '/a1');
        mkdir($dir . '/non_cc_assessments');
        $shell = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1"></section>'
            . '</assessment></questestinterop>';
        file_put_contents($dir . '/a1/assessment_qti.xml', $shell);
        // The native dump holds one unsupported (unconvertible) question.
        $native = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New quiz engine"><section ident="s1">'
            . $this->unsupporteditem('cc.numeric.v0p1')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/a1.xml.qti', $native);

        $course = new course_model();
        $section = new section_model('Week 1');
        $quiz = new item('q1', 'New quiz engine');
        $quiz->kind = item::KIND_QUIZ;
        $quiz->files = ['a1/assessment_qti.xml', 'non_cc_assessments/a1.xml.qti'];
        $section->add_item($quiz);
        $course->add_section($section);

        $matrix = (new conversion_report($course, $dir))->build()['questionmatrix'];

        // The unsupported native question is surfaced, not hidden behind the shell.
        $this->assertSame(1, $matrix['total']);
        $this->assertSame(0, $matrix['supported']);
        $this->assertSame('unsupported', $matrix['rows'][0]['status']);
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
        // The skipped question names the assessment it came from.
        $this->assertSame([['name' => 'Quiz', 'count' => 1]], reset($notconverting)['sources']);
    }

    /**
     * When one question type is dropped across several assessments, the matrix
     * attributes the losses per assessment, most-affected first, so a shortened
     * quiz is visible rather than hidden behind a bare total.
     *
     * @return void
     */
    public function test_matrix_attributes_skipped_questions_per_assessment(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        $final = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Final Exam"><section ident="s1">'
            . $this->profileitem('cc.multiple_choice.v0p1', 'B')
            . $this->oneoptionitem('cc.multiple_choice.v0p1', 'f1')
            . $this->oneoptionitem('cc.multiple_choice.v0p1', 'f2')
            . '</section></assessment></questestinterop>';
        $practice = '<?xml version="1.0" encoding="utf-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a2" title="Practice"><section ident="s1">'
            . $this->oneoptionitem('cc.multiple_choice.v0p1', 'p1')
            . '</section></assessment></questestinterop>';
        file_put_contents($dir . '/quiz/final.xml', $final);
        file_put_contents($dir . '/quiz/practice.xml', $practice);

        $course = new course_model();
        $finalquiz = new item('q1', 'Final Exam');
        $finalquiz->kind = item::KIND_QUIZ;
        $finalquiz->files = ['quiz/final.xml'];
        $practicequiz = new item('q2', 'Practice');
        $practicequiz->kind = item::KIND_QUIZ;
        $practicequiz->files = ['quiz/practice.xml'];
        $section = new section_model('Week 1');
        $section->add_item($finalquiz);
        $section->add_item($practicequiz);
        $course->sections[] = $section;

        $matrix = (new conversion_report($course, $dir))->build()['questionmatrix'];

        $notconverting = array_filter($matrix['rows'], fn($r) => $r['label'] === 'multichoice' && !$r['supported']);
        $row = reset($notconverting);
        $this->assertSame('incomplete', $row['status']);
        $this->assertSame(3, $row['count']);
        // Most-affected assessment first, each with its own dropped-question count.
        $this->assertSame(
            [['name' => 'Final Exam', 'count' => 2], ['name' => 'Practice', 'count' => 1]],
            $row['sources']
        );
    }

    /**
     * A Flash (.swf) resource is reported as a distinct row with an honest note
     * and lowered confidence, and raises the obsolete-format warning, rather than
     * being counted as a clean file conversion.
     *
     * @return void
     */
    public function test_report_flags_obsolete_flash_resources(): void {
        $course = new course_model();
        $flash = new item('f1', 'slide1.swf');
        $flash->kind = item::KIND_FILE;
        $flash->files = ['presentation/slide1.swf'];
        $pdf = new item('f2', 'notes.pdf');
        $pdf->kind = item::KIND_FILE;
        $pdf->files = ['docs/notes.pdf'];
        $course->orphans[] = $flash;
        $course->orphans[] = $pdf;

        $report = (new conversion_report($course))->build();

        $this->assertContains('warnreportobsolete', $report['warnings']);
        $notes = array_column($report['rows'], 'note');
        $this->assertContains('note_file_obsolete', $notes);
        $this->assertContains('note_file', $notes);
        $obsolete = array_values(array_filter($report['rows'], fn($r) => $r['note'] === 'note_file_obsolete'));
        $this->assertCount(1, $obsolete);
        $this->assertSame(1, $obsolete[0]['count']);
        $this->assertSame('mod_resource', $obsolete[0]['target']);
        $this->assertSame(conversion_report::CONFIDENCE_PARTIAL, $obsolete[0]['confidence']);
    }

    /**
     * A resource that builds from its href (an HTML page) but lists a secondary
     * Flash asset first must be judged on the href Moodle imports, so it is not
     * misreported as an obsolete Flash resource.
     *
     * @return void
     */
    public function test_report_judges_file_on_href_not_secondary_asset(): void {
        $course = new course_model();
        $page = new item('h1', 'Interactive exercise');
        $page->kind = item::KIND_FILE;
        $page->href = 'ex/index.html';
        $page->files = ['ex/movie.swf', 'ex/index.html'];
        $course->orphans[] = $page;

        $report = (new conversion_report($course))->build();

        $this->assertNotContains('warnreportobsolete', $report['warnings']);
        $notes = array_column($report['rows'], 'note');
        $this->assertContains('note_file', $notes);
        $this->assertNotContains('note_file_obsolete', $notes);
    }

    /**
     * With package access, a resource whose manifest href is unreadable but whose
     * file list holds a readable Flash payload is judged on the file the builder
     * would import, so it is flagged obsolete.
     *
     * @return void
     */
    public function test_report_flags_flash_when_href_unreadable(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/slides.swf', 'flash');

        $course = new course_model();
        $stale = new item('s1', 'Presentation');
        $stale->kind = item::KIND_FILE;
        $stale->href = 'missing.html';
        $stale->files = ['slides.swf'];
        $course->orphans[] = $stale;

        $report = (new conversion_report($course, $dir))->build();

        $this->assertContains('warnreportobsolete', $report['warnings']);
        $this->assertContains('note_file_obsolete', array_column($report['rows'], 'note'));
    }

    /**
     * With package access, a readable href (an HTML page) is honoured over a
     * secondary Flash file, so the resource is not flagged obsolete.
     *
     * @return void
     */
    public function test_report_honours_readable_href_over_secondary_flash(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/index.html', '<p>hi</p>');
        file_put_contents($dir . '/movie.swf', 'flash');

        $course = new course_model();
        $page = new item('p1', 'Interactive exercise');
        $page->kind = item::KIND_FILE;
        $page->href = 'index.html';
        $page->files = ['movie.swf', 'index.html'];
        $course->orphans[] = $page;

        $report = (new conversion_report($course, $dir))->build();

        $this->assertNotContains('warnreportobsolete', $report['warnings']);
        $this->assertNotContains('note_file_obsolete', array_column($report['rows'], 'note'));
    }

    /**
     * A file whose name carries a copy marker of an original also present in the
     * package (for example "name (2)" or "name-1") raises the duplicates warning.
     *
     * @return void
     */
    public function test_report_flags_duplicate_copies(): void {
        $course = new course_model();
        $paths = ['docs/syllabus.pdf', 'docs/syllabus (2).pdf', 'wk/worksheet.docx', 'wk/worksheet-1.docx'];
        foreach ($paths as $i => $path) {
            $file = new item('d' . $i, basename($path));
            $file->kind = item::KIND_FILE;
            $file->files = [$path];
            $course->orphans[] = $file;
        }

        $report = (new conversion_report($course))->build();

        $this->assertContains('warnreportduplicates', $report['warnings']);
    }

    /**
     * Distinct files that merely share a trailing digit (Lesson1 vs Lesson2, with
     * no bare original) must not be mistaken for duplicate copies.
     *
     * @return void
     */
    public function test_report_does_not_flag_distinct_numbered_files(): void {
        $course = new course_model();
        foreach (['lesson1.pdf', 'lesson2.pdf'] as $i => $name) {
            $file = new item('n' . $i, $name);
            $file->kind = item::KIND_FILE;
            $file->files = ['x/' . $name];
            $course->orphans[] = $file;
        }

        $report = (new conversion_report($course))->build();

        $this->assertNotContains('warnreportduplicates', $report['warnings']);
    }

    /**
     * A four-digit year suffix marks a distinct edition, not a copy, so a
     * "name-2024" file alongside "name" must not raise the duplicates warning.
     *
     * @return void
     */
    public function test_report_does_not_flag_year_suffixed_editions(): void {
        $course = new course_model();
        foreach (['docs/syllabus.pdf', 'docs/syllabus-2024.pdf'] as $i => $path) {
            $file = new item('y' . $i, basename($path));
            $file->kind = item::KIND_FILE;
            $file->files = [$path];
            $course->orphans[] = $file;
        }

        $report = (new conversion_report($course))->build();

        $this->assertNotContains('warnreportduplicates', $report['warnings']);
    }

    /**
     * A single-option choice item: a recognised multichoice type, but Moodle
     * needs at least two answers, so it cannot actually be saved.
     *
     * @param string $profile The cc_profile value.
     * @param string $suffix Distinguishes items sharing a profile (unique idents).
     * @return string
     */
    private function oneoptionitem(string $profile, string $suffix = ''): string {
        return '<item ident="i_one_' . md5($profile . $suffix) . '"><itemmetadata><qtimetadata>'
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
