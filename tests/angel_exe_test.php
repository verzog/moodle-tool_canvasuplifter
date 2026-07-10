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
use tool_canvasuplifter\local\parser\source_detector;

/**
 * Tests source detection and the ANGEL/eXe cleanup: dropping _UNREFERENCED_
 * artifacts and titling an empty-<title> page from its first heading.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\source_detector
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 */
final class angel_exe_test extends \advanced_testcase {
    /**
     * Parse a manifest string into a DOMDocument.
     *
     * @param string $xml The manifest XML.
     * @return \DOMDocument
     */
    private function dom(string $xml): \DOMDocument {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        return $dom;
    }

    /**
     * ANGEL identifiers (…ID_LM_/GLO_/… and the _UNREFERENCED_ marker) are
     * recognised as an ANGEL export.
     *
     * @return void
     */
    public function test_detects_angel(): void {
        $m = '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"><resources>'
            . '<resource identifier="NTERID_LM_00000001_R" type="webcontent" href="a/index.html">'
            . '<file href="a/index.html"/></resource></resources></manifest>';
        $this->assertSame(source_detector::ANGEL, source_detector::detect('/does/not/exist', $this->dom($m)));
    }

    /**
     * A D2L material_type attribute wins over other signals.
     *
     * @return void
     */
    public function test_detects_d2l(): void {
        $m = '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1" '
            . 'xmlns:d2l="http://desire2learn.com/xsd/d2lcp_v2p0"><resources>'
            . '<resource identifier="r1" type="webcontent" href="a.html" d2l:material_type="content">'
            . '<file href="a.html"/></resource></resources></manifest>';
        $this->assertSame(source_detector::D2L, source_detector::detect('/does/not/exist', $this->dom($m)));
    }

    /**
     * A plain package with no recognised fingerprint is generic.
     *
     * @return void
     */
    public function test_detects_generic(): void {
        $m = '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"><resources>'
            . '<resource identifier="r1" type="webcontent" href="a.html"><file href="a.html"/></resource>'
            . '</resources></manifest>';
        $this->assertSame(source_detector::GENERIC, source_detector::detect('/does/not/exist', $this->dom($m)));
    }

    /**
     * A recognised ANGEL package drops its _UNREFERENCED_ artifacts entirely and
     * titles an empty-<title> learning-module page from its first heading; the
     * detected source is recorded on the course model.
     *
     * @return void
     */
    public function test_angel_package_cleanup(): void {
        $this->resetAfterTest(true);
        $dir = make_request_directory();
        mkdir($dir . '/lm', 0777, true);
        mkdir($dir . '/glo', 0777, true);
        // A real page whose <title> is empty — the title must come from <h1>.
        file_put_contents(
            $dir . '/lm/index.html',
            '<html><head><title></title></head><body><h1>Worksheet 1</h1><p>Body</p></body></html>'
        );
        // A leftover glossary fragment with the broken "NTER" title, marked
        // _UNREFERENCED_ by the exporter — must be dropped.
        file_put_contents(
            $dir . '/glo/term.html',
            '<html><head><title>NTER</title></head><body>Anthropocentrism</body></html>'
        );
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Unit 1</title>'
            . '<item identifier="i_page" identifierref="NTERID_LM_00000001_R"><title></title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource identifier="NTERID_LM_00000001_R" type="webcontent" href="lm/index.html">'
            . '<file href="lm/index.html"/></resource>'
            . '<resource identifier="NTERID_GLO_00000002_UNREFERENCED_R" type="webcontent" href="glo/term.html">'
            . '<file href="glo/term.html"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(source_detector::ANGEL, $course->source);

        $titles = [];
        foreach ($course->sections as $section) {
            foreach ($section->items as $it) {
                $titles[] = $it->title;
            }
        }
        foreach ($course->orphans as $orphan) {
            $titles[] = $orphan->title;
        }
        // The _UNREFERENCED_ glossary fragment is gone entirely.
        $this->assertNotContains('NTER', $titles);
        // The empty-<title> page is titled from its heading.
        $this->assertContains('Worksheet 1', $titles);
    }
}
