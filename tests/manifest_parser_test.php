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
     * Discussion (imsdt) resources are XML, but their <title> element should be
     * recovered as the title rather than falling back to the file name.
     *
     * @return void
     */
    public function test_derives_discussion_title_from_xml(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/disc1.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Introduce Yourself</title><text texttype="text/html">Say hi.</text></topic>'
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
    <resource identifier="r_disc" type="imsdt_xmlv1p1" href="disc1.xml">
      <file href="disc1.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('discussion', $course->orphans[0]->kind);
        $this->assertSame('Introduce Yourself', $course->orphans[0]->title);
    }

    /**
     * An LTI cartridge names the tool in a namespaced <blti:title>, which the
     * title extractor should read rather than falling back to the filename.
     *
     * @return void
     */
    public function test_derives_lti_title_from_blti_title(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/i96b51e0093f6f84b4eed4e674c2d6aec.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0" '
            . 'xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Publisher Courseware</blti:title>'
            . '<blti:launch_url>https://example.com/lti</blti:launch_url>'
            . '</cartridge_basiclti_link>'
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
    <resource identifier="r_lti" type="imsbasiclti_xmlv1p0" href="i96b51e0093f6f84b4eed4e674c2d6aec.xml">
      <file href="i96b51e0093f6f84b4eed4e674c2d6aec.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('lti', $course->orphans[0]->kind);
        $this->assertSame('Publisher Courseware', $course->orphans[0]->title);
    }

    /**
     * A QTI assessment names itself in an <assessment title="..."> attribute, so
     * an untitled quiz/bank resource takes that name instead of its filename.
     *
     * @return void
     */
    public function test_derives_quiz_title_from_qti_assessment(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        file_put_contents(
            $dir . '/quiz/qti_0eec1982.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Section 1.1 Homework"><section ident="s1"/></assessment>'
            . '</questestinterop>'
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
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment" href="quiz/qti_0eec1982.xml">
      <file href="quiz/qti_0eec1982.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('quiz', $course->orphans[0]->kind);
        $this->assertSame('Section 1.1 Homework', $course->orphans[0]->title);
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

    /**
     * When course_settings/module_meta.xml is present, its modules drive the
     * section structure (overriding the manifest's <organization>), per-item
     * workflow_state propagates as isvisible, ContextModuleSubHeader rows become
     * synthetic subheader items, and ExternalUrl items with an inline <url>
     * become URL items even without an imswl resource.
     *
     * @return void
     */
    public function test_module_meta_drives_sections_and_workflow_state(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/welcome.html', '<html><title>W</title></html>');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="wrong"><title>Wrong Section From Manifest</title>
          <item identifier="i1" identifierref="r_page"><title>Ignored Title</title></item>
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

        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="mod1">
    <title>Week 1</title>
    <workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_page">
        <content_type>WikiPage</content_type>
        <workflow_state>unpublished</workflow_state>
        <title>Welcome (overridden)</title>
        <identifierref>r_page</identifierref>
      </item>
      <item identifier="mi_sub">
        <content_type>ContextModuleSubHeader</content_type>
        <workflow_state>active</workflow_state>
        <title>Before Class</title>
      </item>
      <item identifier="mi_url">
        <content_type>ExternalUrl</content_type>
        <workflow_state>active</workflow_state>
        <title>Lecture Videos</title>
        <identifierref>mi_url</identifierref>
        <url>https://www.youtube.com/</url>
      </item>
    </items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->sections);
        $section = $course->sections[0];
        $this->assertSame('Week 1', $section->title);
        $this->assertCount(3, $section->items);

        // The page took the title from module_meta and the unpublished state.
        $this->assertSame('Welcome (overridden)', $section->items[0]->title);
        $this->assertSame(item::KIND_PAGE, $section->items[0]->kind);
        $this->assertFalse($section->items[0]->isvisible);

        // The subheader is synthesised as a label-like item with no resource.
        $this->assertSame(item::KIND_SUBHEADER, $section->items[1]->kind);
        $this->assertSame('Before Class', $section->items[1]->title);
        $this->assertTrue($section->items[1]->isvisible);

        // The inline ExternalUrl becomes a URL item carrying the href.
        $this->assertSame(item::KIND_URL, $section->items[2]->kind);
        $this->assertSame('Lecture Videos', $section->items[2]->title);
        $this->assertSame('https://www.youtube.com/', $section->items[2]->url);

        // The synthesised URL is counted as placed, so it's not also orphaned.
        $this->assertSame([], $course->orphans);
    }

    /**
     * A module-level workflow_state="unpublished" hides every item inside it,
     * even items whose own workflow_state is "active". Canvas lets teachers
     * unpublish a whole module without having to flip each item, so the inherited
     * state must AND with the per-item one.
     *
     * @return void
     */
    public function test_module_meta_module_workflow_state_propagates_to_items(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/welcome.html', '<p>Hi</p>');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_page1" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
    <resource identifier="r_page2" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="mod_hidden">
    <title>Draft Module</title>
    <workflow_state>unpublished</workflow_state>
    <items>
      <item identifier="mi_a">
        <content_type>WikiPage</content_type>
        <workflow_state>active</workflow_state>
        <title>Active item in hidden module</title>
        <identifierref>r_page1</identifierref>
      </item>
      <item identifier="mi_sub">
        <content_type>ContextModuleSubHeader</content_type>
        <workflow_state>active</workflow_state>
        <title>Header</title>
      </item>
    </items>
  </module>
  <module identifier="mod_visible">
    <title>Live Module</title>
    <workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_b">
        <content_type>WikiPage</content_type>
        <workflow_state>active</workflow_state>
        <title>Plain active item</title>
        <identifierref>r_page2</identifierref>
      </item>
    </items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(2, $course->sections);
        // Every item under the unpublished module is hidden, including the subheader.
        foreach ($course->sections[0]->items as $childitem) {
            $this->assertFalse($childitem->isvisible, "child {$childitem->title} should inherit hidden state");
        }
        // The other module's items stay visible.
        $this->assertTrue($course->sections[1]->items[0]->isvisible);
    }

    /**
     * Canvas can place the same identifierref in two modules with different
     * per-module visibility (or titles). Visibility must be tracked per module
     * occurrence, so writing the hidden state for one module must not flip the
     * other — the previous shared-instance approach made the later occurrence
     * win for both sections.
     *
     * @return void
     */
    public function test_module_meta_shared_identifierref_keeps_per_module_visibility(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/welcome.html', '<p>Hi</p>');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/welcome.html">
      <file href="wiki_content/welcome.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        // The page is reused in a hidden module first and a visible module second;
        // the previous "last write wins" bug hid both, while reversing the order
        // showed both. Each occurrence also gets its own title.
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="mod_hidden">
    <title>Draft</title>
    <workflow_state>unpublished</workflow_state>
    <items>
      <item identifier="mi_a">
        <content_type>WikiPage</content_type>
        <workflow_state>active</workflow_state>
        <title>Welcome (draft copy)</title>
        <identifierref>r_page</identifierref>
      </item>
    </items>
  </module>
  <module identifier="mod_live">
    <title>Live</title>
    <workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_b">
        <content_type>WikiPage</content_type>
        <workflow_state>active</workflow_state>
        <title>Welcome (live copy)</title>
        <identifierref>r_page</identifierref>
      </item>
    </items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(2, $course->sections);
        $hidden = $course->sections[0]->items[0];
        $live = $course->sections[1]->items[0];

        $this->assertFalse($hidden->isvisible, 'hidden module copy stays hidden');
        $this->assertTrue($live->isvisible, 'live module copy stays visible');

        // Per-module title overrides are also independent.
        $this->assertSame('Welcome (draft copy)', $hidden->title);
        $this->assertSame('Welcome (live copy)', $live->title);

        // The two section items are distinct objects, not the same shared reference.
        $this->assertNotSame($hidden, $live);
    }

    /**
     * When module_meta.xml omits the per-item <title>, the cloned section item
     * should inherit the title derived from the resource's HTML <title> instead
     * of falling back to the file slug. Title recovery has to happen before the
     * clone, otherwise it only updates the unused canonical resource.
     *
     * @return void
     */
    public function test_module_meta_clones_inherit_derived_title(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents(
            $dir . '/wiki_content/g1234_welcome.html',
            '<html><head><title>Welcome to the Course</title></head><body><p>Hi</p></body></html>'
        );

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_page" type="webcontent" href="wiki_content/g1234_welcome.html">
      <file href="wiki_content/g1234_welcome.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        // The module item has no <title>, so the parser must reach the HTML title.
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="mod1">
    <title>Week 1</title>
    <items>
      <item identifier="mi_page">
        <content_type>WikiPage</content_type>
        <workflow_state>active</workflow_state>
        <identifierref>r_page</identifierref>
      </item>
    </items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->sections);
        $this->assertSame('Welcome to the Course', $course->sections[0]->items[0]->title);
    }

    /**
     * Canvas marks an announcement by emitting a topicMeta companion XML with
     * <type>announcement</type> and a <topic_id> pointing back at the imsdt
     * discussion resource. The parser should set isannouncement on the matching
     * discussion item and leave ordinary topics alone.
     *
     * @return void
     */
    public function test_topic_meta_marks_discussions_as_announcements(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/announce.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Welcome to the course</title>'
            . '<text texttype="text/html">Classes start Monday.</text></topic>'
        );
        file_put_contents(
            $dir . '/announce_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_announce</topic_id><type>announcement</type></topicMeta>'
        );
        file_put_contents(
            $dir . '/topic.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Discussion</title>'
            . '<text texttype="text/html">Say hi.</text></topic>'
        );
        file_put_contents(
            $dir . '/topic_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_topic</topic_id><type>topic</type></topicMeta>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_a" identifierref="r_announce"><title>Welcome</title></item>
        <item identifier="i_t" identifierref="r_topic"><title>Discussion</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_announce" type="imsdt_xmlv1p1">
      <file href="announce.xml"/>
    </resource>
    <resource identifier="r_announce_meta"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource">
      <file href="announce_meta.xml"/>
    </resource>
    <resource identifier="r_topic" type="imsdt_xmlv1p1">
      <file href="topic.xml"/>
    </resource>
    <resource identifier="r_topic_meta"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource">
      <file href="topic_meta.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $items = [];
        foreach ($course->sections[0]->items as $sectionitem) {
            $items[$sectionitem->identifier] = $sectionitem;
        }
        $this->assertSame(item::KIND_DISCUSSION, $items['r_announce']->kind);
        $this->assertTrue($items['r_announce']->isannouncement);
        $this->assertSame(item::KIND_DISCUSSION, $items['r_topic']->kind);
        $this->assertFalse($items['r_topic']->isannouncement);
    }

    /**
     * topicMeta carries its own <workflow_state>. When it says "unpublished",
     * the announcement's isvisible must flip to false even when module_meta.xml
     * doesn't list the announcement at all (Canvas commonly omits them from
     * the module structure entirely).
     *
     * @return void
     */
    public function test_topic_meta_unpublished_propagates_to_isvisible(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/announce.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Draft</title><text texttype="text/html">Not ready.</text></topic>'
        );
        file_put_contents(
            $dir . '/announce_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_announce</topic_id>'
            . '<type>announcement</type>'
            . '<workflow_state>unpublished</workflow_state>'
            . '</topicMeta>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_a" identifierref="r_announce"/>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_announce" type="imsdt_xmlv1p1">
      <file href="announce.xml"/>
    </resource>
    <resource identifier="r_announce_meta"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource">
      <file href="announce_meta.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $announce = $course->sections[0]->items[0];
        $this->assertTrue($announce->isannouncement);
        $this->assertFalse($announce->isvisible);
    }

    /**
     * Canvas's assignment_groups.xml becomes course_model::gradecategories, in
     * position order, and course_settings.xml's <group_weighting_scheme> drives
     * course_model::weightingscheme. Per-assignment
     * <assignment_group_identifierref> is captured into item::gradegroupref so
     * the builder can route each assignment into its category.
     *
     * @return void
     */
    public function test_assignment_groups_become_grade_categories(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/course_settings/course_settings.xml',
            '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>C</title><group_weighting_scheme>percent</group_weighting_scheme>'
            . '</course>'
        );
        file_put_contents(
            $dir . '/course_settings/assignment_groups.xml',
            '<assignmentGroups xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<assignmentGroup identifier="g_quizzes"><title>Weekly Quizzes</title>'
            . '<position>3</position><group_weight>10.0</group_weight></assignmentGroup>'
            . '<assignmentGroup identifier="g_part"><title>Participation</title>'
            . '<position>1</position><group_weight>15.0</group_weight></assignmentGroup>'
            . '</assignmentGroups>'
        );
        file_put_contents(
            $dir . '/a1/assignment_settings.xml',
            '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Essay</title><points_possible>50</points_possible>'
            . '<grading_type>points</grading_type>'
            . '<submission_types>online_upload</submission_types>'
            . '<assignment_group_identifierref>g_part</assignment_group_identifierref>'
            . '</assignment>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_assign" identifierref="r_assign"><title>Essay</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_assign"
              type="associatedcontent/imscc_xmlv1p1/learning-application-resource"
              href="a1/assignment_settings.xml">
      <file href="a1/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame('percent', $course->weightingscheme);
        // Sorted by position: Participation (1) before Weekly Quizzes (3).
        $this->assertCount(2, $course->gradecategories);
        $this->assertSame('g_part', $course->gradecategories[0]['identifier']);
        $this->assertSame('Participation', $course->gradecategories[0]['title']);
        $this->assertSame(15.0, $course->gradecategories[0]['weight']);
        $this->assertSame('g_quizzes', $course->gradecategories[1]['identifier']);
        $this->assertSame(10.0, $course->gradecategories[1]['weight']);

        $assign = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_ASSIGNMENT, $assign->kind);
        $this->assertSame('g_part', $assign->gradegroupref);
    }

    /**
     * Webcontent assets under quiz/ (QTI question images) are skipped, while a
     * genuine course file is kept.
     *
     * @return void
     */
    public function test_skips_quiz_assets(): void {
        $dir = make_request_directory();
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_asset" type="webcontent">
      <file href="quiz/q1/diagram.png"/>
    </resource>
    <resource identifier="r_doc" type="webcontent" href="web_resources/handout.pdf">
      <file href="web_resources/handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Only the real file survives; the quiz asset is skipped.
        $this->assertCount(1, $course->orphans);
        $this->assertSame('r_doc', $course->orphans[0]->identifier);
        $this->assertSame(item::KIND_FILE, $course->orphans[0]->kind);
    }
}
