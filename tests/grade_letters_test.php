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

use tool_canvasuplifter\local\parser\manifest_parser;
use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\model\course_model;

/**
 * Tests importing a Canvas grading standard (grading_standards.xml) as Moodle
 * course grade letters.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class grade_letters_test extends \advanced_testcase {
    /**
     * Write a minimal Canvas package with the given course_settings and
     * grading_standards XML into a fresh request directory.
     *
     * @param string $settings The <course> course_settings.xml body.
     * @param string $standards The <gradingStandards> grading_standards.xml body.
     * @return string The package root directory.
     */
    private function package(string $settings, string $standards): string {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings', 0777, true);
        file_put_contents($dir . '/course_settings/course_settings.xml', $settings);
        file_put_contents($dir . '/course_settings/grading_standards.xml', $standards);
        file_put_contents(
            $dir . '/imsmanifest.xml',
            '<?xml version="1.0"?><manifest identifier="m" '
            . 'xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root"/></organization>'
            . '</organizations><resources/></manifest>'
        );
        return $dir;
    }

    /** The standard grading_standards.xml body used across tests. */
    private const STANDARDS = '<?xml version="1.0"?>'
        . '<gradingStandards xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
        . '<gradingStandard identifier="gs1" version="2"><title>IN2001</title>'
        . '<data>[["A",0.895],["B",0.795],["C",0.6949],["D",0.595],["F",0.0]]</data>'
        . '</gradingStandard></gradingStandards>';

    /**
     * An enabled Canvas grading standard parses to grade letters, highest
     * boundary first, with fractions expressed as percentages.
     *
     * @return void
     */
    public function test_reads_canvas_letter_grades(): void {
        $settings = '<?xml version="1.0"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>T</title>'
            . '<grading_standard_enabled>true</grading_standard_enabled>'
            . '<grading_standard_identifier_ref>gs1</grading_standard_identifier_ref></course>';
        $course = (new manifest_parser($this->package($settings, self::STANDARDS)))->parse();

        $this->assertSame(
            [
                ['letter' => 'A', 'lowerboundary' => 89.5],
                ['letter' => 'B', 'lowerboundary' => 79.5],
                ['letter' => 'C', 'lowerboundary' => 69.49],
                ['letter' => 'D', 'lowerboundary' => 59.5],
                ['letter' => 'F', 'lowerboundary' => 0.0],
            ],
            $course->gradeletters
        );
    }

    /**
     * grading_standard_enabled is an XML boolean, so the numeric "1"
     * serialisation counts as enabled too.
     *
     * @return void
     */
    public function test_accepts_numeric_boolean(): void {
        $settings = '<?xml version="1.0"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>T</title>'
            . '<grading_standard_enabled>1</grading_standard_enabled>'
            . '<grading_standard_identifier_ref>gs1</grading_standard_identifier_ref></course>';
        $course = (new manifest_parser($this->package($settings, self::STANDARDS)))->parse();

        $this->assertCount(5, $course->gradeletters);
        $this->assertSame('A', $course->gradeletters[0]['letter']);
    }

    /**
     * With no identifier ref and several standards present, decline rather than
     * guess which scheme applies (installing the wrong letters).
     *
     * @return void
     */
    public function test_declines_multiple_standards_without_ref(): void {
        $settings = '<?xml version="1.0"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>T</title>'
            . '<grading_standard_enabled>true</grading_standard_enabled></course>';
        $two = '<?xml version="1.0"?>'
            . '<gradingStandards xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<gradingStandard identifier="gs1"><title>One</title><data>[["A",0.9],["F",0.0]]</data></gradingStandard>'
            . '<gradingStandard identifier="gs2"><title>Two</title><data>[["P",0.5],["F",0.0]]</data></gradingStandard>'
            . '</gradingStandards>';
        $course = (new manifest_parser($this->package($settings, $two)))->parse();

        $this->assertSame([], $course->gradeletters);
    }

    /**
     * A course whose grading standard is switched off imports no letters, so
     * Moodle's site-default grade letters keep applying.
     *
     * @return void
     */
    public function test_disabled_scheme_imports_nothing(): void {
        $settings = '<?xml version="1.0"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>T</title>'
            . '<grading_standard_enabled>false</grading_standard_enabled>'
            . '<grading_standard_identifier_ref>gs1</grading_standard_identifier_ref></course>';
        $course = (new manifest_parser($this->package($settings, self::STANDARDS)))->parse();

        $this->assertSame([], $course->gradeletters);
    }

    /**
     * Building a course with a letter-grade scheme installs the grade letters on
     * the course context.
     *
     * @return void
     */
    public function test_build_installs_grade_letters(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $model = new course_model();
        $model->fullname = 'Letter Grade Course';
        $model->gradeletters = [
            ['letter' => 'A', 'lowerboundary' => 89.5],
            ['letter' => 'B', 'lowerboundary' => 79.5],
            ['letter' => 'F', 'lowerboundary' => 0.0],
        ];
        $category = $this->getDataGenerator()->create_category();

        $report = (new course_builder($category->id, make_request_directory()))->build($model);

        $context = \context_course::instance($report['courseid']);
        $letters = $DB->get_records('grade_letters', ['contextid' => $context->id], 'lowerboundary DESC');
        $pairs = array_values(array_map(
            fn($r) => [$r->letter, round((float) $r->lowerboundary, 2)],
            $letters
        ));
        $this->assertSame([['A', 89.5], ['B', 79.5], ['F', 0.0]], $pairs);

        // The course grade display is switched to letters so the scheme shows.
        $this->assertEquals(
            GRADE_DISPLAY_TYPE_LETTER,
            (int) grade_get_setting($report['courseid'], 'displaytype', '')
        );
    }
}
