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

    /**
     * A Canvas external-tool assignment and its lti_resource_links/ twin (matched by lookup
     * uuid) build one mod_lti, not two, and the twin's custom parameters (the publisher's
     * assignment id), secure launch URL and description reach the placeholder.
     *
     * @return void
     */
    public function test_external_tool_assignment_and_link_twin_build_once(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_twin_fixture();
        $category = $this->getDataGenerator()->create_category();

        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $ltis = get_fast_modinfo($report['courseid'])->get_instances_of('lti');
        $this->assertCount(1, $ltis);
        $instance = $DB->get_record('lti', ['id' => reset($ltis)->instance], '*', MUST_EXIST);
        $this->assertSame('Publisher Tool', $instance->name);
        $this->assertStringContainsString('assignment_xid=xid-1', $instance->instructorcustomparameters);
        $this->assertSame('https://secure.example.com/launch', $instance->securetoolurl);
        $this->assertStringContainsString('Read chapter 5 first.', $instance->intro);
    }

    /**
     * When the external-tool assignment fails at build time, its suppressed twin cartridge is
     * built instead, so the link is not lost.
     *
     * @return void
     */
    public function test_twin_builds_when_its_assignment_fails(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_twin_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $this->assertArrayHasKey('twin', $coursemodel->ltitwins);
        // The assignment can no longer build (as when its tool URL fails to load).
        $coursemodel->sections[0]->items[0]->launchurl = 'javascript:bad';

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $ltis = get_fast_modinfo($report['courseid'])->get_instances_of('lti');
        $this->assertCount(1, $ltis);
        $this->assertSame('McGraw Hill Connect LTIA', reset($ltis)->name);
    }

    /**
     * The external-tool fixture plus its lti_resource_links/ twin (lookup uuid "uuid-1") and no
     * assignment instructions.
     *
     * @return string Path to the package root.
     */
    protected function build_twin_fixture(): string {
        $root = $this->build_external_tool_fixture();
        $settings = str_replace(
            '</assignment>',
            '<resource_link_lookup_uuid>uuid-1</resource_link_lookup_uuid></assignment>',
            (string) file_get_contents($root . '/a1/assignment_settings.xml')
        );
        file_put_contents($root . '/a1/assignment_settings.xml', $settings);
        mkdir($root . '/lti_resource_links');
        file_put_contents(
            $root . '/lti_resource_links/twin.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p3"'
            . ' xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"'
            . ' xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0">'
            . '<blti:title>McGraw Hill Connect LTIA</blti:title>'
            . '<blti:description>Read chapter 5 first.</blti:description>'
            . '<blti:secure_launch_url>https://secure.example.com/launch</blti:secure_launch_url>'
            . '<blti:custom><lticm:property name="assignment_xid">xid-1</lticm:property></blti:custom>'
            . '<blti:extensions platform="canvas.instructure.com">'
            . '<lticm:property name="lookup_uuid">uuid-1</lticm:property></blti:extensions>'
            . '</cartridge_basiclti_link>'
        );
        $manifest = str_replace(
            '</resources>',
            '<resource identifier="twin" type="imsbasiclti_xmlv1p3">'
            . '<file href="lti_resource_links/twin.xml"/></resource></resources>',
            (string) file_get_contents($root . '/imsmanifest.xml')
        );
        file_put_contents($root . '/imsmanifest.xml', $manifest);
        // No assignment instructions, so the twin's description fills the intro.
        file_put_contents($root . '/a1/instructions.html', '<html><body></body></html>');
        return $root;
    }

    /**
     * Write a package with a single LTI cartridge link carrying the given launch URL and title.
     *
     * @param string $launchurl The cartridge launch URL.
     * @param string $title The organization item title (the activity name).
     * @param string|null $cartridgetitle The cartridge <blti:title>; defaults to $title.
     * @return string Path to the package root.
     */
    protected function build_lti_cartridge_fixture(string $launchurl, string $title, ?string $cartridgetitle = null): string {
        $dir = make_request_directory();
        mkdir($dir . '/lti', 0777, true);
        $cartridge = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"'
            . ' xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"'
            . ' xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0">'
            . '<blti:title>' . ($cartridgetitle ?? $title) . '</blti:title>'
            . '<blti:launch_url>' . $launchurl . '</blti:launch_url>'
            . '</cartridge_basiclti_link>';
        file_put_contents($dir . '/lti/tool.xml', $cartridge);
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org1"><item identifier="root">'
            . '<item identifier="m1"><title>Week 1</title>'
            . '<item identifier="i1" identifierref="r_lti"><title>' . $title . '</title></item>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_lti" type="imsbasiclti_xmlv1p0" href="lti/tool.xml">'
            . '<file href="lti/tool.xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * An LTI link whose launch URL points at a Moodle enrol_lti endpoint builds as a normal
     * mod_lti placeholder, and the conversion report flags it as Moodle-hosted content.
     *
     * @return void
     */
    public function test_moodle_hosted_lti_is_flagged_in_report(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_lti_cartridge_fixture(
            'https://moodle.example.edu/enrol/lti/launch.php?id=3',
            'Weekly activity'
        );
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertCount(1, get_fast_modinfo($report['courseid'])->get_instances_of('lti'));
        $this->assertStringContainsString(
            get_string('notemoodleltidetected', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * An LTI link whose title marks it as a STACK question is flagged as a STACK delivery in the
     * conversion report, even though it still builds as a plain tool placeholder.
     *
     * @return void
     */
    public function test_stack_lti_is_flagged_in_report(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_lti_cartridge_fixture('https://tool.example.com/launch', 'STACK: derivatives');
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertCount(1, get_fast_modinfo($report['courseid'])->get_instances_of('lti'));
        $this->assertStringContainsString(
            get_string('notestackltidetected', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * A STACK signal in the cartridge's own title is honoured even when the Canvas item was
     * renamed to something generic (which overwrites the model title).
     *
     * @return void
     */
    public function test_stack_detected_from_cartridge_title_when_item_renamed(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_lti_cartridge_fixture('https://tool.example.com/launch', 'Exercise 1', 'STACK Question');
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertStringContainsString(
            get_string('notestackltidetected', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }
}
