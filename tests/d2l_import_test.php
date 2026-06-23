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
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * Tests importing a D2L (Brightspace) Common Cartridge export, whose modules are
 * empty "contentmodule" resources and whose syllabus/news are D2L metadata.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class d2l_import_test extends \advanced_testcase {
    /**
     * Build a package shaped like a D2L export: two content "modules"
     * (contentmodule resources with no payload) holding real files, plus
     * D2L syllabus/news metadata resources that aren't linked anywhere.
     *
     * @return string Path to the package root.
     */
    private function build_d2l_fixture(): string {
        $dir = make_request_directory();
        file_put_contents($dir . '/map.docx', 'map');
        file_put_contents($dir . '/syllabus.docx', 'syllabus');
        file_put_contents($dir . '/slides.pptx', 'slides');
        file_put_contents($dir . '/syllabus_d2l.xml', '<syllabus description="" homepage_file_id="" />');
        file_put_contents($dir . '/news_d2l.xml', '<news><item id="1"><headline>Hi</headline></item></news>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="D2L_1"
    xmlns:d2l_2p0="http://desire2learn.com/xsd/d2lcp_v2p0"
    xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">
  <organizations default="d2l_orgs">
    <organization identifier="d2l_org">
      <item identifier="m1" identifierref="RES_m1">
        <title>Course Information</title>
        <item identifier="i1" identifierref="RES_i1"><title>Course Map</title></item>
        <item identifier="i2" identifierref="RES_i2"><title>Syllabus</title></item>
      </item>
      <item identifier="m2" identifierref="RES_m2">
        <title>1. Properties of Soils</title>
        <item identifier="i3" identifierref="RES_i3"><title>Chapter 1</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES_m1" type="webcontent" d2l_2p0:material_type="contentmodule" href="" />
    <resource identifier="RES_i1" type="webcontent" d2l_2p0:material_type="content" href="map.docx" />
    <resource identifier="RES_i2" type="webcontent" d2l_2p0:material_type="content" href="syllabus.docx" />
    <resource identifier="RES_m2" type="webcontent" d2l_2p0:material_type="contentmodule" href="" />
    <resource identifier="RES_i3" type="webcontent" d2l_2p0:material_type="content" href="slides.pptx" />
    <resource identifier="res_syllabus" type="webcontent" d2l_2p0:material_type="d2lsyllabus" href="syllabus_d2l.xml" />
    <resource identifier="res_news" type="webcontent" d2l_2p0:material_type="d2lnews" href="news_d2l.xml" />
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The empty contentmodule resources and the d2l* metadata resources are
     * suppressed by the parser: modules become sections, their files attach,
     * and nothing is left over as an orphan.
     *
     * @return void
     */
    public function test_modules_become_sections_metadata_suppressed(): void {
        $root = $this->build_d2l_fixture();
        $course = (new manifest_parser($root))->parse();

        $this->assertCount(2, $course->sections);
        $this->assertSame('Course Information', $course->sections[0]->title);
        $this->assertSame('1. Properties of Soils', $course->sections[1]->title);
        // The module's own contentmodule ref must not attach as a phantom item.
        $this->assertCount(2, $course->sections[0]->items);
        $this->assertCount(1, $course->sections[1]->items);
        // Syllabus/news metadata and the empty modules are suppressed, not orphaned.
        $this->assertCount(0, $course->orphans);
    }

    /**
     * Building the D2L package creates one file resource per content item in the
     * right section, with no failed-payload skips and no "Additional resources"
     * dumping ground for the D2L metadata XML.
     *
     * @return void
     */
    public function test_build_creates_files_without_skips(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_d2l_fixture();
        $category = $this->getDataGenerator()->create_category();
        $course = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($course);
        $courseid = $report['courseid'];

        // Three content files, nothing skipped, no orphan metadata downloads.
        $this->assertSame(3, $DB->count_records('resource', ['course' => $courseid]));
        $this->assertSame(0, $report['skipped']);

        // Module titles became section names.
        $names = $DB->get_fieldset_select('course_sections', 'name', 'course = ? AND name IS NOT NULL', [$courseid]);
        $this->assertContains('Course Information', $names);
        $this->assertContains('1. Properties of Soils', $names);
    }

    /**
     * D2L assessment exports (material_type d2lquiz / d2lquestionlibrary) are
     * real content, not metadata, so they are preserved (here as an orphan
     * resource) rather than suppressed along with news/syllabus/links.
     *
     * @return void
     */
    public function test_d2l_quiz_resources_are_preserved(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/slides.pptx', 'slides');
        file_put_contents($dir . '/quiz_d2l_1.xml', '<quiz><question/></quiz>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="D2L_2"
    xmlns:d2l_2p0="http://desire2learn.com/xsd/d2lcp_v2p0"
    xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">
  <organizations default="d2l_orgs">
    <organization identifier="d2l_org">
      <item identifier="m1" identifierref="RES_m1">
        <title>Unit</title>
        <item identifier="i1" identifierref="RES_i1"><title>Slides</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES_m1" type="webcontent" d2l_2p0:material_type="contentmodule" href="" />
    <resource identifier="RES_i1" type="webcontent" d2l_2p0:material_type="content" href="slides.pptx" />
    <resource identifier="RES_quiz" type="webcontent" d2l_2p0:material_type="d2lquiz" href="quiz_d2l_1.xml" />
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $quiz = $course->orphans[0];
        $this->assertSame('RES_quiz', $quiz->identifier);
        $this->assertSame(item::KIND_FILE, $quiz->kind);
        $this->assertFalse($quiz->suppressed);
    }

    /**
     * A referenced buildable resource whose payload is missing from the manifest
     * (a malformed export) must still be placed in its section — so it reaches
     * the builder and is reported as skipped — rather than being silently
     * suppressed along with the D2L placeholders.
     *
     * @return void
     */
    public function test_missing_payload_resource_is_still_reported(): void {
        $dir = make_request_directory();
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="cc1" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i1" identifierref="r1"><title>Broken Assignment</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r1" type="assignment_xmlv1p0"/>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Not suppressed: it lands in its section, so the builder sees it and
        // reports a missing-payload skip instead of it vanishing from the report.
        $this->assertCount(1, $course->sections);
        $this->assertCount(1, $course->sections[0]->items);
        $placed = $course->sections[0]->items[0];
        $this->assertSame('r1', $placed->identifier);
        $this->assertSame(item::KIND_ASSIGNMENT, $placed->kind);
        $this->assertFalse($placed->suppressed);
    }
}
