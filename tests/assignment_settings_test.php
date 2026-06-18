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

use tool_canvasuplifter\local\parser\assignment_settings;

/**
 * Tests for the Canvas assignment settings parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\assignment_settings
 */
final class assignment_settings_test extends \basic_testcase {
    /**
     * A typical Canvas assignment_settings.xml parses into the expected values.
     *
     * @return void
     */
    public function test_parse_full(): void {
        $xml = '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Essay 1</title>'
            . '<points_possible>100.0</points_possible>'
            . '<grading_type>points</grading_type>'
            . '<submission_types>online_text_entry,online_upload</submission_types>'
            . '<allowed_extensions>pdf,docx</allowed_extensions>'
            . '<due_at>2030-09-01T23:59:00Z</due_at>'
            . '<unlock_at>2030-08-01T00:00:00Z</unlock_at>'
            . '<lock_at>2030-09-08T23:59:00Z</lock_at>'
            . '</assignment>';

        $settings = assignment_settings::parse($xml);

        $this->assertSame('Essay 1', $settings->title);
        $this->assertSame(100, $settings->points);
        $this->assertSame('points', $settings->gradingtype);
        $this->assertSame('pdf,docx', $settings->allowedextensions);
        $this->assertSame(strtotime('2030-09-01T23:59:00Z'), $settings->duedate);
        $this->assertSame(strtotime('2030-08-01T00:00:00Z'), $settings->allowfrom);
        $this->assertSame(strtotime('2030-09-08T23:59:00Z'), $settings->cutoff);
        $this->assertTrue($settings->wants_onlinetext());
        $this->assertTrue($settings->wants_fileupload());
    }

    /**
     * Missing and empty fields fall back to safe defaults.
     *
     * @return void
     */
    public function test_parse_minimal(): void {
        $xml = '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Reading</title><due_at></due_at>'
            . '</assignment>';

        $settings = assignment_settings::parse($xml);

        $this->assertSame('Reading', $settings->title);
        $this->assertSame(0, $settings->points);
        $this->assertSame(0, $settings->duedate);
        $this->assertSame([], $settings->submissiontypes);
        $this->assertFalse($settings->wants_onlinetext());
        $this->assertFalse($settings->wants_fileupload());
    }

    /**
     * A CC 1.3 IMS Assignment profile document (root <assignment> in the
     * imscc_extensions/assignment namespace) populates title, points and
     * submission types from the core profile and rubric/group references from
     * the nested Canvas <extensions> child.
     *
     * @return void
     */
    public function test_parse_cc13_ims_profile(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment" identifier="a1">'
            . '<title>Lab report</title>'
            . '<text texttype="text/html"><![CDATA[<p>Write up the experiment.</p>]]></text>'
            . '<gradable points_possible="10">true</gradable>'
            . '<submission_formats><format type="html"/><format type="file"/></submission_formats>'
            . '<extensions>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<due_at>2030-10-01T23:59:00Z</due_at>'
            . '<rubric_identifierref>r_cc13</rubric_identifierref>'
            . '<rubric_use_for_grading>false</rubric_use_for_grading>'
            . '<assignment_group_identifierref>g_part</assignment_group_identifierref>'
            . '</assignment>'
            . '</extensions>'
            . '</assignment>';

        $settings = assignment_settings::parse($xml);

        $this->assertSame('Lab report', $settings->title);
        $this->assertSame(10, $settings->points);
        $this->assertSame('points', $settings->gradingtype);
        $this->assertTrue($settings->wants_onlinetext());
        $this->assertTrue($settings->wants_fileupload());
        $this->assertSame('<p>Write up the experiment.</p>', $settings->description);
        $this->assertSame(strtotime('2030-10-01T23:59:00Z'), $settings->duedate);
        $this->assertSame('r_cc13', $settings->rubricref);
        $this->assertFalse($settings->rubricforgrading);
        $this->assertSame('g_part', $settings->gradegroupref);
    }

    /**
     * A CC 1.3 IMS Assignment profile with <gradable>false</gradable> reports
     * as not-graded so the builder doesn't put a point grade on it.
     *
     * @return void
     */
    public function test_parse_cc13_ims_profile_not_graded(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment" identifier="a1">'
            . '<title>Reflection</title>'
            . '<gradable points_possible="0">false</gradable>'
            . '</assignment>';

        $settings = assignment_settings::parse($xml);

        $this->assertSame('Reflection', $settings->title);
        $this->assertSame(0, $settings->points);
        $this->assertSame('not_graded', $settings->gradingtype);
    }

    /**
     * Malformed XML yields a default object rather than throwing.
     *
     * @return void
     */
    public function test_parse_garbage(): void {
        $settings = assignment_settings::parse('not xml at all <<<');

        $this->assertSame('', $settings->title);
        $this->assertSame(0, $settings->points);
    }
}
