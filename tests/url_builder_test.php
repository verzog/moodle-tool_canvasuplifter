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

use tool_canvasuplifter\local\build\url_builder;

/**
 * Tests for reading the target URL out of a Common Cartridge web-link file.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\url_builder::url_from_weblink_xml
 */
final class url_builder_test extends \basic_testcase {
    /**
     * A web link with no XML namespace yields its href.
     *
     * @return void
     */
    public function test_plain_namespace_url(): void {
        $xml = '<webLink><title>X</title><url href="https://a.edu/x"/></webLink>';
        $this->assertSame('https://a.edu/x', url_builder::url_from_weblink_xml($xml));
    }

    /**
     * A web link in a default namespace yields its href.
     *
     * @return void
     */
    public function test_default_namespace_url(): void {
        $xml = '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p2">'
            . '<title>X</title><url href="https://a.edu/x"/></webLink>';
        $this->assertSame('https://a.edu/x', url_builder::url_from_weblink_xml($xml));
    }

    /**
     * A web link in a prefixed namespace yields its href. This is the real-world
     * Canvas shape (<wl:webLink>/<wl:url>) that the previous SimpleXML reader
     * silently missed, leaving every URL un-built.
     *
     * @return void
     */
    public function test_prefixed_namespace_url(): void {
        $xml = '<wl:webLink xmlns:wl="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p2">'
            . '<wl:title>X</wl:title>'
            . '<wl:url href="https://youtube.com/playlist?list=ABC&amp;si=DEF" target="_blank"/>'
            . '</wl:webLink>';
        $this->assertSame(
            'https://youtube.com/playlist?list=ABC&si=DEF',
            url_builder::url_from_weblink_xml($xml)
        );
    }

    /**
     * A URL carried as element text content (rather than an href attribute) is read.
     *
     * @return void
     */
    public function test_text_content_url(): void {
        $xml = '<webLink><url>https://a.edu/text</url></webLink>';
        $this->assertSame('https://a.edu/text', url_builder::url_from_weblink_xml($xml));
    }

    /**
     * Non-http(s) targets and empty/invalid documents yield null.
     *
     * @return void
     */
    public function test_unusable_targets_return_null(): void {
        $this->assertNull(url_builder::url_from_weblink_xml(''));
        $this->assertNull(url_builder::url_from_weblink_xml('<webLink><url href=""/></webLink>'));
        $this->assertNull(url_builder::url_from_weblink_xml('<webLink><url href="mailto:a@b.c"/></webLink>'));
        $this->assertNull(url_builder::url_from_weblink_xml('<webLink><title>no url here</title></webLink>'));
        $this->assertNull(url_builder::url_from_weblink_xml('not xml at all'));
    }
}
