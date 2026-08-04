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
 * End-to-end test that a Canvas external-tool assignment is built as a hidden
 * mod_lti placeholder that keeps its launch URL and its instructions (#128).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\lti_builder
 */
final class lti_launch_build_test extends \advanced_testcase {
    /**
     * Write a package with a single external-tool assignment: an
     * assignment_settings.xml declaring an external_tool submission with a launch
     * URL, plus a sibling HTML file holding the assignment instructions.
     *
     * @return string Path to the package root.
     */
    protected function build_external_tool_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/a1', 0777, true);
        $settings = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment identifier="ra" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Publisher Tool</title>'
            . '<submission_types>external_tool</submission_types>'
            . '<external_tool_url>https://tool.example.com/launch</external_tool_url>'
            . '<workflow_state>published</workflow_state>'
            . '</assignment>';
        file_put_contents($dir . '/a1/assignment_settings.xml', $settings);
        file_put_contents(
            $dir . '/a1/instructions.html',
            '<html><body><p>Complete the publisher activity before Friday.</p></body></html>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i1" identifierref="ra"><title>Publisher Tool</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="ra" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="a1/instructions.html">
      <file href="a1/instructions.html"/>
      <file href="a1/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The external-tool assignment builds as a single hidden mod_lti whose launch
     * URL is preserved and whose intro carries the assignment instructions rather
     * than only the credentials-needed placeholder note.
     *
     * @return void
     */
    public function test_external_tool_assignment_builds_lti_with_instructions(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_external_tool_fixture();
        $category = $this->getDataGenerator()->create_category();

        // The parser re-homes the external-tool assignment to an LTI item.
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // It builds as one mod_lti and no mod_assign.
        $modinfo = get_fast_modinfo($report['courseid']);
        $ltis = $modinfo->get_instances_of('lti');
        $this->assertCount(1, $ltis);
        $this->assertEmpty($modinfo->get_instances_of('assign'));

        $lti = reset($ltis);
        // Built hidden (the LTI placeholder needs admin credential review).
        $this->assertSame(0, (int) $lti->visible);

        $instance = $DB->get_record('lti', ['id' => $lti->instance], '*', MUST_EXIST);
        // The launch URL is preserved ...
        $this->assertSame('https://tool.example.com/launch', $instance->toolurl);
        // ... and the assignment instructions survive in the intro, not just the
        // credentials-needed placeholder note.
        $this->assertStringContainsString('Complete the publisher activity before Friday.', $instance->intro);
    }
}
