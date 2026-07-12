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

/**
 * Tests dropping Canvas platform boilerplate: help-guide web-links to Canvas's
 * own docs and ANGEL migration objects, while leaving real content untouched.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 */
final class canvas_cleanup_test extends \advanced_testcase {
    /**
     * Titles of every built (non-suppressed) item in a parsed course.
     *
     * @param \tool_canvasuplifter\local\model\course_model $course The parsed course.
     * @return string[]
     */
    private function built_titles($course): array {
        $titles = [];
        foreach ($course->sections as $section) {
            foreach ($section->items as $it) {
                $titles[] = $it->title;
            }
        }
        foreach ($course->orphans as $orphan) {
            $titles[] = $orphan->title;
        }
        return $titles;
    }

    /**
     * A Canvas package drops its guides.instructure.com help link and its ANGEL
     * migration objects, keeps a genuine external link, and counts the drops.
     *
     * @return void
     */
    public function test_drops_canvas_boilerplate_only(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings', 0777, true);
        mkdir($dir . '/web_resources/32036CA31E6B4353B3E5D28F9198710D', 0777, true);
        // The canvas_export.txt file makes the source detector classify as Canvas.
        file_put_contents($dir . '/course_settings/canvas_export.txt', 'Canvas');
        file_put_contents(
            $dir . '/guides.xml',
            '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1"><title>Browsers</title>'
            . '<url href="http://guides.instructure.com/s/2204/m/4214/l/41056-which-browsers"/></webLink>'
        );
        file_put_contents(
            $dir . '/real.xml',
            '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1"><title>Real Link</title>'
            . '<url href="https://example.com/reading"/></webLink>'
        );
        file_put_contents($dir . '/web_resources/32036CA31E6B4353B3E5D28F9198710D/AngelObj[Assessment].xml', '<x/>');

        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="i_g" identifierref="r_guides"><title>Browsers</title></item>'
            . '<item identifier="i_r" identifierref="r_real"><title>Real Link</title></item>'
            . '<item identifier="i_a" identifierref="r_angel"><title>AngelObj</title></item>'
            . '</item></organization></organizations><resources>'
            . '<resource identifier="r_guides" type="imswl_xmlv1p1"><file href="guides.xml"/></resource>'
            . '<resource identifier="r_real" type="imswl_xmlv1p1"><file href="real.xml"/></resource>'
            . '<resource identifier="r_angel" type="webcontent" '
            . 'href="web_resources/32036CA31E6B4353B3E5D28F9198710D/AngelObj[Assessment].xml">'
            . '<file href="web_resources/32036CA31E6B4353B3E5D28F9198710D/AngelObj[Assessment].xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(2, $course->canvasboilerplatedropped);
        $titles = $this->built_titles($course);
        $this->assertContains('Real Link', $titles);
        $this->assertNotContains('Browsers', $titles);
        $this->assertNotContains('AngelObj', $titles);
    }

    /**
     * The drop is gated on the Canvas source: an identical guides link in a
     * package with no Canvas fingerprint is kept (nothing marks it as Canvas).
     *
     * @return void
     */
    public function test_guides_link_kept_without_canvas_source(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/guides.xml',
            '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1"><title>Browsers</title>'
            . '<url href="http://guides.instructure.com/s/2204/m/4214/l/41056-which-browsers"/></webLink>'
        );
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="i_g" identifierref="r_guides"><title>Browsers</title></item>'
            . '</item></organization></organizations><resources>'
            . '<resource identifier="r_guides" type="imswl_xmlv1p1"><file href="guides.xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(0, $course->canvasboilerplatedropped);
        $this->assertContains('Browsers', $this->built_titles($course));
    }
}
