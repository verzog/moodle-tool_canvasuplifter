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
 * Tests for deriving a course name when the package has no embedded title.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder::name_from_filename
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 */
final class course_name_test extends \advanced_testcase {
    /**
     * Write a manifest into a fresh package dir and return that dir.
     *
     * @param string $manifest The imsmanifest.xml content.
     * @return string The package root directory.
     */
    private function package_with_manifest(string $manifest): string {
        $dir = make_request_directory();
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The filename fallback strips paths, extensions and export-tool suffixes.
     *
     * @return void
     */
    public function test_name_from_filename(): void {
        $this->assertSame(
            'AEC205 MASTER',
            course_builder::name_from_filename('AEC205_MASTER_D2LExport_45210_201581423.zip')
        );
        $this->assertSame(
            'Introduction to Wind Energy',
            course_builder::name_from_filename('Introduction to Wind Energy.imscc')
        );
        $this->assertSame(
            'my canvas course',
            course_builder::name_from_filename('my_canvas_course.imscc')
        );
        // The plugin's own placeholder names are never surfaced.
        $this->assertSame('', course_builder::name_from_filename('canvas-1718900000.imscc'));
        $this->assertSame('', course_builder::name_from_filename(''));
    }

    /**
     * A direct <organization><title> is used as the course name (the IMS CC
     * location D2L-shaped packages with no Canvas metadata may still carry).
     *
     * @return void
     */
    public function test_organization_title_used_as_course_name(): void {
        $this->resetAfterTest(true);
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="T1" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">'
            . '<organizations default="o"><organization identifier="o">'
            . '<title>Soil Mechanics 101</title>'
            . '<item identifier="i1" identifierref="r1"><title>Chapter 1</title></item>'
            . '</organization></organizations>'
            . '<resources><resource identifier="r1" type="webcontent" href="ch1.html"/></resources>'
            . '</manifest>';
        $course = (new manifest_parser($this->package_with_manifest($manifest)))->parse();
        $this->assertSame('Soil Mechanics 101', $course->fullname);
    }

    /**
     * A D2L-shaped manifest with no metadata and no organisation title yields no
     * course name from the parser, leaving the filename fallback to name it.
     *
     * @return void
     */
    public function test_no_title_yields_empty_fullname(): void {
        $this->resetAfterTest(true);
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="T2" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">'
            . '<organizations default="o"><organization identifier="o">'
            . '<item identifier="m1" identifierref="r1"><title>Content Modules</title>'
            . '<item identifier="i1" identifierref="r1"><title>Chapter 1</title></item></item>'
            . '</organization></organizations>'
            . '<resources><resource identifier="r1" type="webcontent" href="ch1.html"/></resources>'
            . '</manifest>';
        $course = (new manifest_parser($this->package_with_manifest($manifest)))->parse();
        $this->assertSame('', $course->fullname);
    }
}
