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

use tool_canvasuplifter\local\build\lti_builder;

/**
 * Tests for reading the launch URL out of a Common Cartridge LTI link.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\lti_builder::parse_cartridge_xml
 */
final class lti_builder_test extends \basic_testcase {
    /**
     * A plain cartridge yields its title and launch URL.
     *
     * @return void
     */
    public function test_parse_plain_cartridge(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"'
            . ' xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Tool X</blti:title>'
            . '<blti:description>Course tool</blti:description>'
            . '<blti:launch_url>https://tool.example.com/launch</blti:launch_url>'
            . '</cartridge_basiclti_link>';
        $info = lti_builder::parse_cartridge_xml($xml);
        $this->assertSame('Tool X', $info['title']);
        $this->assertSame('https://tool.example.com/launch', $info['launchurl']);
        $this->assertSame('', $info['secureurl']);
        $this->assertSame('Course tool', $info['description']);
    }

    /**
     * When only a secure_launch_url is supplied, it's promoted to launchurl so
     * the builder still has something to point mod_lti at.
     *
     * @return void
     */
    public function test_secure_url_promoted_when_launch_missing(): void {
        $xml = '<cartridge_basiclti_link xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Secure tool</blti:title>'
            . '<blti:secure_launch_url>https://tool.example.com/launch</blti:secure_launch_url>'
            . '</cartridge_basiclti_link>';
        $info = lti_builder::parse_cartridge_xml($xml);
        $this->assertSame('https://tool.example.com/launch', $info['launchurl']);
        $this->assertSame('https://tool.example.com/launch', $info['secureurl']);
    }

    /**
     * A cartridge with no URL at all is unusable as a placeholder.
     *
     * @return void
     */
    public function test_missing_urls_returns_null(): void {
        $this->assertNull(lti_builder::parse_cartridge_xml(''));
        $this->assertNull(lti_builder::parse_cartridge_xml('not xml'));
        $this->assertNull(lti_builder::parse_cartridge_xml(
            '<cartridge_basiclti_link xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>No URL</blti:title></cartridge_basiclti_link>'
        ));
    }
}
