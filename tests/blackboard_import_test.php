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
 * Tests importing a Blackboard Common Cartridge 1.2 export. Blackboard ships its
 * content as CC 1.2 webcontent/weblink/QTI resources and includes a
 * web_content*.log build-artifact resource that must be dropped, not imported as
 * a junk file resource.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class blackboard_import_test extends \advanced_testcase {
    /**
     * Build a package shaped like a Blackboard CC 1.2 export: a module holding a
     * real content page and an external web link, plus an unreferenced
     * web_content*.log build-artifact resource (carrying instructor-role LOM
     * metadata, as Blackboard writes it). It also ships two learner-facing logs
     * that must survive: a course-authored access.log, and a debug log that
     * happens to use the web_content<NNN>.log naming but lacks the metadata.
     *
     * @return string Path to the package root.
     */
    private function build_blackboard_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/content00001', 0777, true);
        file_put_contents($dir . '/content00001/page.html', '<html><body><h1>Lecture 1</h1></body></html>');
        mkdir($dir . '/web_content00001', 0777, true);
        file_put_contents($dir . '/web_content00001/web_content00001.log', "export build log\n");
        // A course-authored .log published as real material (must NOT be dropped).
        mkdir($dir . '/logs', 0777, true);
        file_put_contents($dir . '/logs/access.log', "127.0.0.1 - GET /\n");
        // A learner-facing resource whose file happens to share the artifact's
        // web_content<NNN>.log naming but carries no instructor-role metadata —
        // it must be preserved, not dropped on the basename alone.
        mkdir($dir . '/web_content09999', 0777, true);
        file_put_contents($dir . '/web_content09999/web_content09999.log', "sample debug output\n");
        mkdir($dir . '/weblink00001', 0777, true);
        file_put_contents(
            $dir . '/weblink00001/weblink00001.xml',
            '<?xml version="1.0"?>'
                . '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imswl_v1p2">'
                . '<title>Example</title><url href="https://example.org/"/></webLink>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="man00001" xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"
    xmlns:lom="http://ltsc.ieee.org/xsd/imsccv1p2/LOM/resource">
  <organizations>
    <organization identifier="org1" structure="rooted-hierarchy">
      <item identifier="root">
        <item identifier="m1"><title>Module 1</title>
          <item identifier="i_page" identifierref="res_page"><title>Lecture 1</title></item>
          <item identifier="i_link" identifierref="res_link"><title>Example link</title></item>
          <item identifier="i_alog" identifierref="res_accesslog"><title>Server access log</title></item>
          <item identifier="i_dbg" identifierref="res_dbglog"><title>Sample debug output</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="res_page" type="webcontent" href="content00001/page.html">
      <file href="content00001/page.html"/>
    </resource>
    <resource identifier="res_link" type="imswl_xmlv1p2" href="weblink00001/weblink00001.xml">
      <file href="weblink00001/weblink00001.xml"/>
    </resource>
    <resource identifier="web_content00001" type="webcontent">
      <metadata><lom:lom><lom:educational><lom:intendedEndUserRole>
        <lom:source>IMSGLC_CC_Rolesv1p2</lom:source><lom:value>Instructor</lom:value>
      </lom:intendedEndUserRole></lom:educational></lom:lom></metadata>
      <file href="web_content00001/web_content00001.log"/>
    </resource>
    <resource identifier="res_accesslog" type="webcontent" href="logs/access.log">
      <file href="logs/access.log"/>
    </resource>
    <resource identifier="res_dbglog" type="webcontent" href="web_content09999/web_content09999.log">
      <file href="web_content09999/web_content09999.log"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A Blackboard export builds its real content (page + web link) and drops the
     * web_content*.log build artifact rather than importing it as a file resource,
     * with no skip/warning noise from the dropped artifact.
     *
     * @return void
     */
    public function test_log_artifact_dropped_real_content_builds(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_blackboard_fixture();
        $coursemodel = (new manifest_parser($root))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // The lecture content built (as a page or a file resource) and the web
        // link became a URL.
        $haslecture = $DB->record_exists_select('page', $DB->sql_like('name', '?'), ['%Lecture 1%'])
            || $DB->record_exists_select('resource', $DB->sql_like('name', '?'), ['%Lecture 1%']);
        $this->assertTrue($haslecture, 'the Blackboard content page should build');
        $this->assertSame(1, $report['createdcounts']['url'] ?? 0);

        // The web_content*.log build artifact is dropped, not imported anywhere.
        $haslog = $DB->record_exists_select('resource', $DB->sql_like('name', '?'), ['%web_content%'])
            || $DB->record_exists_select('page', $DB->sql_like('name', '?'), ['%web_content%']);
        $this->assertFalse($haslog, 'the .log build artifact must not be imported');

        // A course-authored .log published as material (access.log) is NOT a
        // build artifact, so it still imports as a file resource.
        $this->assertTrue(
            $DB->record_exists_select('resource', $DB->sql_like('name', '?'), ['%access%']),
            'a legitimately published .log must still import'
        );

        // A learner resource whose file is named web_content09999.log but which
        // lacks the instructor-role metadata is NOT the build artifact, so the
        // basename match alone must not drop it.
        $this->assertTrue(
            $DB->record_exists_select('resource', $DB->sql_like('name', '?'), ['%debug%']),
            'a web_content<NNN>.log without instructor metadata must still import'
        );

        // Dropping the artifact is silent — it is not a skip or a warning.
        $joined = implode("\n", array_merge($report['skipreasons'], $report['warnings']));
        $this->assertStringNotContainsString('web_content', $joined);
    }
}
