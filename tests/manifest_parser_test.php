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
use tool_canvasuplifter\local\model\item;

/**
 * Tests for the manifest parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 */
final class manifest_parser_test extends \advanced_testcase {
    /**
     * Write a minimal Canvas-style package to a temporary directory.
     *
     * @return string Path to the package root.
     */
    protected function build_fixture(): string {
        $dir = make_request_directory();
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i1" identifierref="r_page"><title>Welcome</title></item>
          <item identifier="i2" identifierref="r_assign"><title>Essay</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
    <resource identifier="r_assign"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource"
              href="a1/assignment_settings.xml">
      <file href="a1/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The parser should build sections and classify resources correctly.
     *
     * @return void
     */
    public function test_parse_builds_sections_and_classifies(): void {
        $course = (new manifest_parser($this->build_fixture()))->parse();

        $this->assertCount(1, $course->sections);
        $section = $course->sections[0];
        $this->assertSame('Week 1', $section->title);
        $this->assertCount(2, $section->items);

        $this->assertSame('Welcome', $section->items[0]->title);
        $this->assertSame(item::KIND_PAGE, $section->items[0]->kind);

        $this->assertSame('Essay', $section->items[1]->title);
        $this->assertSame(item::KIND_ASSIGNMENT, $section->items[1]->kind);
    }

    /**
     * A missing manifest should throw.
     *
     * @return void
     */
    public function test_missing_manifest_throws(): void {
        $dir = make_request_directory();
        $this->expectException(\RuntimeException::class);
        (new manifest_parser($dir))->parse();
    }

    /**
     * An unreferenced page with no manifest title gets one from its HTML <title>.
     *
     * @return void
     */
    public function test_derives_orphan_title_from_html(): void {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        file_put_contents(
            $dir . '/wiki_content/g0658_syllabus.html',
            '<html><head><title>Course Syllabus</title></head><body><p>Body</p></body></html>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_syllabus" type="webcontent" href="wiki_content/g0658_syllabus.html">
      <file href="wiki_content/g0658_syllabus.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('Course Syllabus', $course->orphans[0]->title);
    }

    /**
     * Learning-application resources without an HTML payload (Canvas discussion
     * topicMeta, quiz meta, canvas_export.txt) are not treated as pages; the
     * syllabus (HTML, intendeduse="syllabus") is, and carries its hint.
     *
     * @return void
     */
    public function test_skips_metadata_only_resources(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        file_put_contents(
            $dir . '/course_settings/syllabus.html',
            '<html><head><title>Syllabus</title></head><body>Hi</body></html>'
        );
        file_put_contents($dir . '/topic.xml', '<topicMeta><title>Discussion 1</title></topicMeta>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_syllabus"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource"
              href="course_settings/syllabus.html" intendeduse="syllabus">
      <file href="course_settings/syllabus.html"/>
    </resource>
    <resource identifier="r_topicmeta"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource"
              href="topic.xml">
      <file href="topic.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Only the syllabus survives as an (orphan) page; the topicMeta is skipped.
        $this->assertCount(1, $course->orphans);
        $orphan = $course->orphans[0];
        $this->assertSame(item::KIND_PAGE, $orphan->kind);
        $this->assertSame('syllabus', $orphan->intendeduse);
        $this->assertSame('Syllabus', $orphan->title);
    }
}
