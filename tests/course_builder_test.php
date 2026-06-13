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

    /**
     * Write a package with one assignment and one unreferenced (orphan) file.
     *
     * @return string Path to the package root.
     */
    protected function build_assignment_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/a1/assignment_settings.xml',
            '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Essay 1</title><points_possible>50</points_possible>'
            . '<grading_type>points</grading_type>'
            . '<submission_types>online_text_entry,online_upload</submission_types>'
            . '<due_at>2030-01-01T00:00:00Z</due_at></assignment>'
        );
        file_put_contents($dir . '/a1/a1.html', '<p>Write an essay.</p>');
        mkdir($dir . '/web_resources');
        file_put_contents($dir . '/web_resources/handout.pdf', '%PDF-1.4 test');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i1" identifierref="r_assign"><title>Essay 1</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_assign" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="a1/a1.html">
      <file href="a1/a1.html"/>
      <file href="a1/assignment_settings.xml"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="web_resources/handout.pdf">
      <file href="web_resources/handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The build creates an assignment and imports orphans into a spare section.
     *
     * @return void
     */
    public function test_build_creates_assignment_and_imports_orphans(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_assignment_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['assignment'] ?? 0);
        $this->assertSame(1, $report['createdcounts']['file'] ?? 0);
        // The original section plus the synthetic "Additional resources" section.
        $this->assertSame(2, $report['sectioncount']);

        $modinfo = get_fast_modinfo($report['courseid']);
        $assigns = $modinfo->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assigncm = reset($assigns);
        $assign = $DB->get_record('assign', ['id' => $assigncm->instance]);
        $this->assertSame('Essay 1', $assign->name);
        $this->assertStringContainsString('Write an essay', $assign->intro);
        $this->assertEquals(50, $assign->grade);
        $this->assertEquals(strtotime('2030-01-01T00:00:00Z'), $assign->duedate);

        // Both requested submission plugins are enabled.
        $enabled = function (string $plugin) use ($DB, $assign): string {
            return (string) $DB->get_field('assign_plugin_config', 'value', [
                'assignment' => $assign->id,
                'subtype' => 'assignsubmission',
                'plugin' => $plugin,
                'name' => 'enabled',
            ]);
        };
        $this->assertSame('1', $enabled('onlinetext'));
        $this->assertSame('1', $enabled('file'));

        // The orphan file became a resource in the "Additional resources" section.
        $resources = $modinfo->get_instances_of('resource');
        $this->assertCount(1, $resources);
        $resourcecm = reset($resources);
        $sectionname = $DB->get_field('course_sections', 'name', [
            'course' => $report['courseid'],
            'section' => 2,
        ]);
        $this->assertSame('Additional resources', $sectionname);
        $this->assertSame(2, $resourcecm->sectionnum);
    }
}
