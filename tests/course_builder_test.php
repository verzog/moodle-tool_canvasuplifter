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
 * Tests for the course builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class course_builder_test extends \advanced_testcase {
    /**
     * Write a minimal one-page Canvas package to a temporary directory.
     *
     * @return string Path to the package root.
     */
    protected function build_page_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/welcome.html', '<h1>Welcome</h1><p>Hello world.</p>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i1" identifierref="r_page"><title>Welcome</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * Building a one-page package creates a course with a single page activity.
     *
     * @return void
     */
    public function test_build_creates_course_with_page(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_page_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertGreaterThan(0, $report['courseid']);
        $this->assertSame(1, $report['itemcount']);
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(1, $report['createdcounts']['page'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $pages = $modinfo->get_instances_of('page');
        $this->assertCount(1, $pages);
        $page = reset($pages);
        $this->assertSame('Welcome', $page->get_name());
    }

    /**
     * Write a two-page package: a welcome page embedding an image and linking
     * to a syllabus page.
     *
     * @return string Path to the package root.
     */
    protected function build_linked_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/web_resources');
        file_put_contents($dir . '/web_resources/logo.png', 'PNG');
        mkdir($dir . '/wiki_content');
        file_put_contents(
            $dir . '/wiki_content/welcome.html',
            '<p><img src="$IMS-CC-FILEBASE$/logo.png"></p>'
            . '<p><a href="$WIKI_REFERENCE$/pages/syllabus">Syllabus</a></p>'
        );
        file_put_contents($dir . '/wiki_content/syllabus.html', '<p>The syllabus.</p>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i1" identifierref="r_welcome"><title>Welcome</title></item>
          <item identifier="i2" identifierref="r_syllabus"><title>Syllabus</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_welcome" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
    <resource identifier="r_syllabus" type="webcontent" href="wiki_content/syllabus.html">
      <file href="wiki_content/syllabus.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The build embeds referenced files and rewrites internal page links.
     *
     * @return void
     */
    public function test_build_embeds_files_and_rewrites_internal_links(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_linked_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(2, $report['createdcounts']['page'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $welcomecm = null;
        $syllabuscm = null;
        foreach ($modinfo->get_instances_of('page') as $cm) {
            if ($cm->get_name() === 'Welcome') {
                $welcomecm = $cm;
            }
            if ($cm->get_name() === 'Syllabus') {
                $syllabuscm = $cm;
            }
        }
        $this->assertNotNull($welcomecm);
        $this->assertNotNull($syllabuscm);

        $welcome = $DB->get_record('page', ['id' => $welcomecm->instance]);
        // The embedded image reference was rewritten to pluginfile.
        $this->assertStringContainsString('@@PLUGINFILE@@/logo.png', $welcome->content);
        // The internal link now points at the syllabus page.
        $this->assertStringContainsString('/mod/page/view.php?id=' . $syllabuscm->id, $welcome->content);
        $this->assertStringNotContainsString('WIKI_REFERENCE', $welcome->content);

        // The image file is stored in the page's own content file area.
        $fs = get_file_storage();
        $context = \context_module::instance($welcomecm->id);
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/', 'logo.png'));
    }
}
