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
use tool_canvasuplifter\local\parser\source_detector;
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
     * A manifest with a locally-malformed resource (Canvas ships unsupported
     * resource types with a broken <filehref=...> tag) is recovered rather than
     * rejected wholesale: the good resources still parse, and the broken one is
     * simply not classified as a buildable activity.
     *
     * @return void
     */
    public function test_malformed_resource_is_recovered_not_fatal(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/syllabus.html', '<html><head><title>Syllabus</title></head><body>hi</body></html>');
        // The second resource has a malformed <filehref=...> tag (no space), as in
        // Canvas's cc_unsupported_resources fixture; libxml would reject the whole
        // document without recovery, losing the valid page too.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="O1" structure="rooted-hierarchy">
      <item identifier="root">
        <item identifier="I1" identifierref="R1"><title>Syllabus</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="R1" type="webcontent" href="syllabus.html">
      <file href="syllabus.html"/>
    </resource>
    <resource identifier="R2" type="imsapip_zipv1p0">
      <filehref="doesntexist.zip" />
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        // Does not throw, and the good page survives the recovery.
        $course = (new manifest_parser($dir))->parse();
        $titles = [];
        foreach ($course->sections as $section) {
            foreach ($section->items as $it) {
                $titles[] = $it->title;
            }
        }
        $this->assertContains('Syllabus', $titles);
    }

    /**
     * Recovery must not make a genuinely broken manifest look importable: a file
     * truncated at the opening tag recovers to an empty stub root, which should
     * still raise rather than parse as an empty course.
     *
     * @return void
     */
    public function test_truncated_manifest_still_throws(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/imsmanifest.xml', '<manifest');
        $this->expectException(\RuntimeException::class);
        (new manifest_parser($dir))->parse();
    }

    /**
     * A manifest truncated partway through its structure (after real child nodes)
     * must also raise: recovery would otherwise hand back a silently partial
     * course. libxml flags this as "Premature end of data", which a merely
     * malformed-but-complete manifest never triggers.
     *
     * @return void
     */
    public function test_truncated_after_children_still_throws(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/imsmanifest.xml',
            '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
                . '<resources><resource identifier="R1" type="webcontent" href="a.html">'
                . '<file href="a.html"/>'
        );
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
     * A Windows-authored package (notably a native D2L export) writes backslash
     * path separators in hrefs; the parser must normalise them to forward slashes
     * so the payload resolves against the forward-slash paths inside the zip
     * instead of being skipped as unreadable.
     *
     * @return void
     */
    public function test_backslash_hrefs_are_normalised(): void {
        $dir = make_request_directory();
        mkdir($dir . '/Module 2');
        file_put_contents($dir . '/Module 2/Notes.pdf', '%PDF-1.4 test');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_pdf" type="webcontent" href="Module 2\Notes.pdf">
      <file href="Module 2\Notes.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $orphan = $course->orphans[0];
        // Backslashes are converted to forward slashes on both href and files.
        $this->assertSame('Module 2/Notes.pdf', $orphan->href);
        $this->assertSame(['Module 2/Notes.pdf'], $orphan->files);
        // The normalised path resolves to the real file, so it is a file resource
        // (an unreadable payload would be dropped instead).
        $this->assertSame(item::KIND_FILE, $orphan->kind);
    }

    /**
     * A resource whose href is an absolute URL — notably a native D2L
     * "contentlink" exported as webcontent with an http href — is an external
     * link, so it maps to mod_url with the href as the target, not a file the
     * builder would fail to read.
     *
     * @return void
     */
    public function test_external_url_href_maps_to_url(): void {
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
    <resource identifier="r_link" type="webcontent" href="https://www.softchalkcloud.com/lesson/serve/abc/html"/>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame(item::KIND_URL, $course->orphans[0]->kind);
        $this->assertSame('https://www.softchalkcloud.com/lesson/serve/abc/html', $course->orphans[0]->url);
    }

    /**
     * Separator normalisation happens on the whole DOM before classification, so
     * a backslash href is classified on its normalised form — a wiki_content page
     * stays a page (not a file), which a raw backslash href would have misclassed.
     *
     * @return void
     */
    public function test_backslash_href_classifies_on_normalised_path(): void {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/syllabus.html', '<html><body>hi</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_pg" type="webcontent" href="wiki_content\syllabus.html">
      <file href="wiki_content\syllabus.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame(item::KIND_PAGE, $course->orphans[0]->kind);
    }

    /**
     * Source detection runs on the same DOM after normalisation, so a Windows
     * exporter's backslash fingerprint (e.g. eXe's js\yahoo\...) is recognised
     * rather than being read as a generic package.
     *
     * @return void
     */
    public function test_backslash_fingerprint_is_detected_as_source(): void {
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
    <resource identifier="r_js" type="webcontent" href="index.html">
      <file href="index.html"/>
      <file href="js\yahoo\yahoo.js"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(source_detector::EXE, $course->source);
    }

    /**
     * A Blackboard Learn native export declares proprietary x-bb-* resource types
     * (not Common Cartridge), so it is detected as blackboard_native and its content
     * stays unclassified — the report then leads with a clear message rather than
     * silently building zero items.
     *
     * @return void
     */
    public function test_blackboard_native_export_is_detected_from_resource_types(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/res00001.dat', '<content/>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="man00001" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="toc00001"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="res00001" type="resource/x-bb-document" href="res00001.dat">
      <file href="res00001.dat"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(source_detector::BLACKBOARD_NATIVE, $course->source);
        // The x-bb-document content is unclassified, so nothing builds from it.
        $this->assertSame([], $course->orphans);
    }

    /**
     * The .bb-package-info marker file alone (even with an otherwise unremarkable
     * manifest) identifies a Blackboard-native package.
     *
     * @return void
     */
    public function test_blackboard_native_detected_by_marker_file(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/.bb-package-info', "version=1\n");
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="man00001" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="toc00001"><item identifier="root"/></organization></organizations>
  <resources/>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(source_detector::BLACKBOARD_NATIVE, $course->source);
    }

    /**
     * A package that mixes x-bb-* settings with a resource whose href is an absolute
     * URL is not native: classify() builds that resource as a mod_url regardless of
     * its (unrecognised) type, so the package has importable content.
     *
     * @return void
     */
    public function test_blackboard_native_not_flagged_when_a_weblink_builds(): void {
        $dir = make_request_directory();
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="man00001" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="toc00001">
      <item identifier="root"><item identifier="i_link" identifierref="res_link"><title>Handbook</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="res_settings" type="course/x-bb-coursesetting"/>
    <resource identifier="res_link" type="resource/x-bb-link" href="https://example.org/handbook"/>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The absolute-href resource is importable content, so the package is not
        // declared native, and that resource builds as a URL.
        $this->assertNotSame(source_detector::BLACKBOARD_NATIVE, $course->source);
        $kinds = array_map(fn($i) => $i->kind, $course->all_items());
        $this->assertContains(item::KIND_URL, $kinds);
    }

    /**
     * A file embedded inside a page body via a $IMS-CC-FILEBASE$ token (Canvas
     * stores these under web_resources/) is inlined into the page at build time,
     * so it must not also surface as a standalone orphan resource — while a file
     * no page references still does.
     *
     * @return void
     */
    public function test_page_embedded_assets_are_not_orphans(): void {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        mkdir($dir . '/web_resources/Uploaded Media', 0777, true);
        // A page that embeds two web_resources images via the filebase token: one
        // is only embedded (tile), the other is also placed in the course menu.
        file_put_contents(
            $dir . '/wiki_content/home.html',
            '<html><head><title>Home</title></head><body>'
            . '<img src="$IMS-CC-FILEBASE$/Uploaded%20Media/tile.png">'
            . '<img src="$IMS-CC-FILEBASE$/Uploaded%20Media/placed.png"></body></html>'
        );
        file_put_contents($dir . '/web_resources/Uploaded Media/tile.png', 'PNG');
        file_put_contents($dir . '/web_resources/Uploaded Media/placed.png', 'PNG2');
        file_put_contents($dir . '/handout.pdf', 'PDF');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_home" identifierref="r_home"><title>Home</title></item>
        <item identifier="i_placed" identifierref="r_placed"><title>Placed image</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_home" type="webcontent" href="wiki_content/home.html">
      <file href="wiki_content/home.html"/>
    </resource>
    <resource identifier="r_tile" type="webcontent" href="web_resources/Uploaded Media/tile.png">
      <file href="web_resources/Uploaded Media/tile.png"/>
    </resource>
    <resource identifier="r_placed" type="webcontent" href="web_resources/Uploaded Media/placed.png">
      <file href="web_resources/Uploaded Media/placed.png"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="handout.pdf">
      <file href="handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The embedded-only tile is suppressed; the truly unreferenced handout
        // still becomes an orphan.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('handout.pdf', $orphanhrefs);
        $this->assertNotContains('web_resources/Uploaded Media/tile.png', $orphanhrefs);

        // The image that is also placed in the course menu survives as its own
        // activity — being embedded in a page must not drop a placed resource.
        $placedhrefs = [];
        foreach ($course->sections as $section) {
            foreach ($section->items as $sectionitem) {
                $placedhrefs[] = $sectionitem->href;
            }
        }
        $this->assertContains('web_resources/Uploaded Media/placed.png', $placedhrefs);
    }

    /**
     * Media embedded only in a quiz description (Canvas stores it in the sibling
     * assessment_meta.xml, referenced with a $IMS-CC-FILEBASE$ token) is inlined
     * into the quiz intro at build time, so it must not also surface as a standalone
     * orphan — mirroring the page suppression. A file the quiz never references is
     * still surfaced as an orphan.
     *
     * @return void
     */
    public function test_quiz_description_embedded_assets_are_not_orphans(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/web_resources/Banners', 0777, true);
        file_put_contents(
            $dir . '/quiz/qti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Pre-Emergency Survey"><section ident="s1"/></assessment>'
            . '</questestinterop>'
        );
        // The Canvas quiz configuration; its description embeds a banner image.
        file_put_contents(
            $dir . '/quiz/assessment_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>Pre-Emergency Survey</title>'
            . '<description>&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$/Banners/EmergencyContinuity.jpg"&gt;&lt;/p&gt;</description>'
            . '</quiz>'
        );
        file_put_contents($dir . '/web_resources/Banners/EmergencyContinuity.jpg', 'JPG');
        file_put_contents($dir . '/handout.pdf', 'PDF');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_quiz" identifierref="r_quiz"><title>Pre-Emergency Survey</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment" href="quiz/qti.xml">
      <file href="quiz/qti.xml"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="web_resources/Banners/EmergencyContinuity.jpg">
      <file href="web_resources/Banners/EmergencyContinuity.jpg"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="handout.pdf">
      <file href="handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        // The banner embedded only in the quiz description is suppressed.
        $this->assertNotContains('web_resources/Banners/EmergencyContinuity.jpg', $orphanhrefs);
        // The unreferenced handout still becomes an orphan.
        $this->assertContains('handout.pdf', $orphanhrefs);
    }

    /**
     * An orphan assessment (absent from the organisation tree) is built as a
     * question bank, whose activity has an empty intro and embeds nothing, so its
     * assessment_meta.xml description media must be left as a standalone download
     * rather than suppressed — only a placed quiz routes through quiz_builder.
     *
     * @return void
     */
    public function test_orphan_quiz_description_media_is_kept(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/web_resources/Banners', 0777, true);
        file_put_contents(
            $dir . '/quiz/qti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Survey"><section ident="s1"/></assessment>'
            . '</questestinterop>'
        );
        file_put_contents(
            $dir . '/quiz/assessment_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>Survey</title>'
            . '<description>&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$/Banners/Banner.jpg"&gt;&lt;/p&gt;</description>'
            . '</quiz>'
        );
        file_put_contents($dir . '/web_resources/Banners/Banner.jpg', 'JPG');
        // The org tree references no resource, so the quiz is an orphan (bank).
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment" href="quiz/qti.xml">
      <file href="quiz/qti.xml"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="web_resources/Banners/Banner.jpg">
      <file href="web_resources/Banners/Banner.jpg"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The banner is not suppressed: an orphan assessment builds as a bank that
        // embeds nothing, so the media stays available as a standalone download.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('web_resources/Banners/Banner.jpg', $orphanhrefs);
    }

    /**
     * A placed assessment whose questions are all unsupported (so quiz_builder
     * skips it and builds nothing) embeds nothing, so its description media must be
     * left as a standalone download rather than suppressed and lost.
     *
     * @return void
     */
    public function test_unbuildable_quiz_description_media_is_kept(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/web_resources/Banners', 0777, true);
        // A QTI 1.2 assessment carrying one item of an unsupported question type:
        // parsed as a question but not importable, and not an empty shell, so
        // quiz_builder returns null without creating (or embedding) anything.
        file_put_contents(
            $dir . '/quiz/qti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="Ordering"><section ident="s1">'
            . '<item ident="q1" title="Order these">'
            . '<itemmetadata><qtimetadata><qtimetadatafield>'
            . '<fieldlabel>question_type</fieldlabel><fieldentry>ordering_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Put in order.</mattext></material></presentation>'
            . '</item></section></assessment>'
            . '</questestinterop>'
        );
        file_put_contents(
            $dir . '/quiz/assessment_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>Ordering</title>'
            . '<description>&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$/Banners/Banner.jpg"&gt;&lt;/p&gt;</description>'
            . '</quiz>'
        );
        file_put_contents($dir . '/web_resources/Banners/Banner.jpg', 'JPG');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_quiz" identifierref="r_quiz"><title>Ordering</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment" href="quiz/qti.xml">
      <file href="quiz/qti.xml"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="web_resources/Banners/Banner.jpg">
      <file href="web_resources/Banners/Banner.jpg"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The quiz will not build, so its description media must stay a standalone
        // download rather than being suppressed and lost.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('web_resources/Banners/Banner.jpg', $orphanhrefs);
    }

    /**
     * Media embedded only in an assignment's instructions (a CC 1.3 profile's
     * <text>, here with an owner-relative $IMS-CC-FILEBASE$ token) is inlined into
     * the assignment intro at build time, so it must not also surface as a standalone
     * orphan. A file the assignment never references still does.
     *
     * @return void
     */
    public function test_assignment_description_embedded_assets_are_not_orphans(): void {
        $dir = make_request_directory();
        mkdir($dir . '/asg');
        // A CC 1.3 IMS Assignment profile whose HTML instructions embed a sibling
        // banner by bare name (resolved against the profile's own folder).
        file_put_contents(
            $dir . '/asg/lab.xml',
            '<?xml version="1.0"?>'
            . '<assignment xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment" identifier="a1">'
            . '<title>Lab Report</title>'
            . '<text texttype="text/html">&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$banner.jpg"&gt;&lt;/p&gt;</text>'
            . '<gradable points_possible="5">true</gradable>'
            . '<submission_formats><format type="html"/></submission_formats>'
            . '</assignment>'
        );
        file_put_contents($dir . '/asg/banner.jpg', 'JPG');
        file_put_contents($dir . '/handout.pdf', 'PDF');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_asg" identifierref="r_asg"><title>Lab Report</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_asg" type="assignment_xmlv1p0" href="asg/lab.xml">
      <file href="asg/lab.xml"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="asg/banner.jpg">
      <file href="asg/banner.jpg"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="handout.pdf">
      <file href="handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        // The banner embedded only in the assignment instructions is suppressed.
        $this->assertNotContains('asg/banner.jpg', $orphanhrefs);
        // The unreferenced handout still becomes an orphan.
        $this->assertContains('handout.pdf', $orphanhrefs);
    }

    /**
     * Media embedded only in a discussion body (an owner-relative $IMS-CC-FILEBASE$
     * token in the imsdt topic HTML) is inlined into the first forum post at build
     * time, so it must not also surface as a standalone orphan. A file the discussion
     * never references still does.
     *
     * @return void
     */
    public function test_discussion_body_embedded_assets_are_not_orphans(): void {
        $dir = make_request_directory();
        mkdir($dir . '/disc');
        file_put_contents(
            $dir . '/disc/topic.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Week 1 Discussion</title>'
            . '<text texttype="text/html">&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$media.png"&gt;&lt;/p&gt;</text>'
            . '</topic>'
        );
        file_put_contents($dir . '/disc/media.png', 'PNG');
        file_put_contents($dir . '/handout.pdf', 'PDF');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_disc" identifierref="r_disc"><title>Week 1 Discussion</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_disc" type="imsdt_xmlv1p1" href="disc/topic.xml">
      <file href="disc/topic.xml"/>
    </resource>
    <resource identifier="r_media" type="webcontent" href="disc/media.png">
      <file href="disc/media.png"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="handout.pdf">
      <file href="handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        // The image embedded only in the discussion body is suppressed.
        $this->assertNotContains('disc/media.png', $orphanhrefs);
        // The unreferenced handout still becomes an orphan.
        $this->assertContains('handout.pdf', $orphanhrefs);
    }

    /**
     * An unpublished announcement is skipped by forum_builder before it embeds
     * anything, so media referenced only by its body must be left as a standalone
     * download rather than suppressed and lost.
     *
     * @return void
     */
    public function test_unpublished_announcement_body_media_is_kept(): void {
        $dir = make_request_directory();
        mkdir($dir . '/disc');
        file_put_contents(
            $dir . '/disc/topic.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Draft Announcement</title>'
            . '<text texttype="text/html">&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$media.png"&gt;&lt;/p&gt;</text>'
            . '</topic>'
        );
        // The topicMeta marks the discussion an announcement and unpublished.
        file_put_contents(
            $dir . '/disc/topicMeta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="r_meta">'
            . '<topic_id>r_disc</topic_id><type>announcement</type>'
            . '<workflow_state>unpublished</workflow_state></topicMeta>'
        );
        file_put_contents($dir . '/disc/media.png', 'PNG');
        // The announcement is not placed in the org tree (unpublished draft).
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title></item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_disc" type="imsdt_xmlv1p1" href="disc/topic.xml">
      <file href="disc/topic.xml"/>
    </resource>
    <resource identifier="r_meta" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="disc/topicMeta.xml">
      <file href="disc/topicMeta.xml"/>
    </resource>
    <resource identifier="r_media" type="webcontent" href="disc/media.png">
      <file href="disc/media.png"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The body media of an unpublished announcement (which never builds) is kept.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('disc/media.png', $orphanhrefs);
    }

    /**
     * A flat Canvas external-tool assignment re-homed to a hidden mod_lti has no
     * inline description, so lti_builder reads its instructions from a sibling HTML
     * file and embeds that file's media into the LTI intro. Media referenced only
     * there must not also surface as a standalone orphan.
     *
     * @return void
     */
    public function test_lti_sibling_html_embedded_assets_are_not_orphans(): void {
        $dir = make_request_directory();
        mkdir($dir . '/lti');
        // A flat Canvas external-tool assignment: settings name the launch URL but
        // carry no inline description; the prompt lives in a sibling HTML file.
        file_put_contents(
            $dir . '/lti/assignment_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>Publisher Tool</title>'
            . '<submission_types>external_tool</submission_types>'
            . '<external_tool_url>https://tool.example/launch</external_tool_url>'
            . '</assignment>'
        );
        file_put_contents(
            $dir . '/lti/instructions.html',
            '<p><img src="$IMS-CC-FILEBASE$banner.jpg"></p>'
        );
        file_put_contents($dir . '/lti/banner.jpg', 'JPG');
        file_put_contents($dir . '/handout.pdf', 'PDF');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_lti" identifierref="r_lti"><title>Publisher Tool</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_lti" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="lti/instructions.html">
      <file href="lti/assignment_settings.xml"/>
      <file href="lti/instructions.html"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="lti/banner.jpg">
      <file href="lti/banner.jpg"/>
    </resource>
    <resource identifier="r_handout" type="webcontent" href="handout.pdf">
      <file href="handout.pdf"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The re-homed LTI item embeds the sibling HTML's banner into its intro.
        $lti = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_LTI, $lti->kind);
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        // The banner embedded only in the LTI instructions is suppressed.
        $this->assertNotContains('lti/banner.jpg', $orphanhrefs);
        // The unreferenced handout still becomes an orphan.
        $this->assertContains('handout.pdf', $orphanhrefs);
    }

    /**
     * A re-homed external-tool assignment whose launch URL is not http(s) (a
     * javascript:/data:/file: scheme) is rejected by lti_builder, which creates no
     * activity, so media referenced by its instructions must stay a standalone
     * download rather than being suppressed and lost.
     *
     * @return void
     */
    public function test_lti_non_http_launch_url_media_is_kept(): void {
        $dir = make_request_directory();
        mkdir($dir . '/lti');
        file_put_contents(
            $dir . '/lti/assignment_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>Bad Tool</title>'
            . '<submission_types>external_tool</submission_types>'
            . '<external_tool_url>javascript:alert(1)</external_tool_url>'
            . '</assignment>'
        );
        file_put_contents(
            $dir . '/lti/instructions.html',
            '<p><img src="$IMS-CC-FILEBASE$banner.jpg"></p>'
        );
        file_put_contents($dir . '/lti/banner.jpg', 'JPG');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_lti" identifierref="r_lti"><title>Bad Tool</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_lti" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="lti/instructions.html">
      <file href="lti/assignment_settings.xml"/>
      <file href="lti/instructions.html"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="lti/banner.jpg">
      <file href="lti/banner.jpg"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The tool is rejected (non-http URL), so its instructions never build; the
        // banner they reference stays available as a standalone download.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('lti/banner.jpg', $orphanhrefs);
    }

    /**
     * When a discussion lists several topic XMLs, forum_builder uses the first that
     * parses as a topic and never reads a later one. If that first topic has an
     * empty/plain body (embeds nothing), media referenced only by a later topic file
     * must not be suppressed — the builder never embeds it.
     *
     * @return void
     */
    public function test_discussion_later_topic_media_is_kept(): void {
        $dir = make_request_directory();
        mkdir($dir . '/disc');
        // The first valid topic has an empty HTML body: forum_builder accepts it and
        // embeds nothing, never reading topic_b.
        file_put_contents(
            $dir . '/disc/topic_a.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Empty</title><text texttype="text/html"></text></topic>'
        );
        file_put_contents(
            $dir . '/disc/topic_b.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Later</title>'
            . '<text texttype="text/html">&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$media.png"&gt;&lt;/p&gt;</text>'
            . '</topic>'
        );
        file_put_contents($dir . '/disc/media.png', 'PNG');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_disc" identifierref="r_disc"><title>Discussion</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_disc" type="imsdt_xmlv1p1" href="disc/topic_a.xml">
      <file href="disc/topic_a.xml"/>
      <file href="disc/topic_b.xml"/>
    </resource>
    <resource identifier="r_media" type="webcontent" href="disc/media.png">
      <file href="disc/media.png"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The builder embeds nothing (first topic empty), so the later topic's media
        // is not suppressed and stays a standalone download.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertContains('disc/media.png', $orphanhrefs);
    }

    /**
     * A placed quiz whose Common Cartridge QTI has only unsupported questions but
     * whose native non_cc_assessments dump carries importable questions still builds
     * (quiz_builder adopts the dump) and embeds its description, so media embedded
     * only in that description must be suppressed rather than left as a duplicate.
     *
     * @return void
     */
    public function test_native_qti_quiz_description_media_is_suppressed(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        mkdir($dir . '/non_cc_assessments', 0777, true);
        mkdir($dir . '/web_resources/Banners', 0777, true);
        // The Common Cartridge shell carries only an unsupported (ordering) question.
        file_put_contents(
            $dir . '/quiz/qti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New Quiz"><section ident="s1">'
            . '<item ident="q1" title="Order"><itemmetadata><qtimetadata><qtimetadatafield>'
            . '<fieldlabel>question_type</fieldlabel><fieldentry>ordering_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Order.</mattext></material></presentation>'
            . '</item></section></assessment>'
            . '</questestinterop>'
        );
        // The native dump keyed by the CC folder name carries an importable question.
        file_put_contents(
            $dir . '/non_cc_assessments/quiz.xml.qti',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a1" title="New Quiz"><section ident="s1">'
            . '<item ident="q2" title="Essay"><itemmetadata><qtimetadata><qtimetadatafield>'
            . '<fieldlabel>question_type</fieldlabel><fieldentry>essay_question</fieldentry>'
            . '</qtimetadatafield></qtimetadata></itemmetadata>'
            . '<presentation><material><mattext>Write an essay.</mattext></material></presentation>'
            . '</item></section></assessment>'
            . '</questestinterop>'
        );
        file_put_contents(
            $dir . '/quiz/assessment_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="a1">'
            . '<title>New Quiz</title>'
            . '<description>&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$/Banners/Banner.jpg"&gt;&lt;/p&gt;</description>'
            . '</quiz>'
        );
        file_put_contents($dir . '/web_resources/Banners/Banner.jpg', 'JPG');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="i_quiz" identifierref="r_quiz"><title>New Quiz</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_quiz" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment" href="quiz/qti.xml">
      <file href="quiz/qti.xml"/>
    </resource>
    <resource identifier="r_banner" type="webcontent" href="web_resources/Banners/Banner.jpg">
      <file href="web_resources/Banners/Banner.jpg"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The quiz builds via the native dump and embeds its description, so the
        // banner referenced only there is suppressed from the orphan list.
        $orphanhrefs = array_map(fn($o) => $o->href, $course->orphans);
        $this->assertNotContains('web_resources/Banners/Banner.jpg', $orphanhrefs);
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
     * An IMS web-link (imswl) names its target inside the XML; an orphan URL
     * with no manifest <title> should pick up the <webLink>/<title>, not fall
     * back to the file slug like "weblink00003".
     *
     * @return void
     */
    public function test_derives_orphan_url_title_from_weblink_xml(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/weblink00003.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p2">'
            . '<title>Open Textbook Library</title>'
            . '<url href="https://example.edu/textbooks"/>'
            . '</webLink>'
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
    <resource identifier="r_link" type="imswl_xmlv1p2" href="weblink00003.xml">
      <file href="weblink00003.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame(item::KIND_URL, $course->orphans[0]->kind);
        $this->assertSame('Open Textbook Library', $course->orphans[0]->title);
    }

    /**
     * Canvas's course_settings/rubrics.xml becomes course_model::rubrics, with
     * each rubric's criteria and ratings preserved. Ratings are sorted ascending
     * by points so gradingform_rubric renders them left-to-right low→high. The
     * per-assignment <rubric_identifierref> + <rubric_use_for_grading> in
     * assignment_settings.xml lands on item::rubricref / item::rubricforgrading.
     *
     * @return void
     */
    public function test_rubrics_xml_becomes_model_and_assignment_ref(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/course_settings/rubrics.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rubrics xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric identifier="r_one">'
            . '<title>Essay Rubric</title>'
            . '<free_form_criterion_comments>false</free_form_criterion_comments>'
            . '<hide_score_total>false</hide_score_total>'
            . '<criteria><criterion>'
            . '<criterion_id>_4743</criterion_id>'
            . '<points>5.0</points>'
            . '<description>Argument</description>'
            . '<ratings>'
            . '<rating><description>Full Marks</description><points>5.0</points>'
            . '<long_description>Argument is clear, well supported and original.</long_description>'
            . '<criterion_id>_4743</criterion_id><id>blank</id></rating>'
            . '<rating><description>No Marks</description><points>0.0</points>'
            . '<criterion_id>_4743</criterion_id><id>blank_2</id></rating>'
            . '</ratings>'
            . '</criterion></criteria>'
            . '</rubric>'
            . '</rubrics>'
        );
        file_put_contents(
            $dir . '/a1/assignment_settings.xml',
            '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Essay</title><points_possible>5</points_possible>'
            . '<grading_type>points</grading_type>'
            . '<submission_types>online_text_entry</submission_types>'
            . '<rubric_identifierref>r_one</rubric_identifierref>'
            . '<rubric_use_for_grading>true</rubric_use_for_grading>'
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

        $this->assertArrayHasKey('r_one', $course->rubrics);
        $rubric = $course->rubrics['r_one'];
        $this->assertSame('Essay Rubric', $rubric['title']);
        $this->assertFalse($rubric['hide_score_total']);
        $this->assertCount(1, $rubric['criteria']);
        $this->assertSame('Argument', $rubric['criteria'][0]['description']);
        // Ratings sorted low→high to match gradingform_rubric's sortlevelsasc=1.
        $this->assertSame([0.0, 5.0], array_column($rubric['criteria'][0]['levels'], 'points'));
        // Per-rating long_descriptions are preserved on the model (and surface as
        // a second paragraph on the gradingform_rubric level definition at build).
        $levels = $rubric['criteria'][0]['levels'];
        $bypoints = array_column($levels, 'long_description', 'points');
        $this->assertSame('', $bypoints[0.0]);
        $this->assertSame('Argument is clear, well supported and original.', $bypoints[5.0]);

        $assign = $course->sections[0]->items[0];
        $this->assertSame('r_one', $assign->rubricref);
        $this->assertTrue($assign->rubricforgrading);
    }

    /**
     * A CC 1.3 IMS Assignment profile resource (root <assignment xmlns="imscc_extensions/
     * assignment">) has its <rubric_identifierref> and <assignment_group_identifierref>
     * picked up from the nested Canvas <extensions> element, so non-Canvas exporters
     * still wire rubrics and grade categories onto the model.
     *
     * @return void
     */
    public function test_cc13_assignment_profile_picks_up_rubric_ref(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/course_settings/rubrics.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rubrics xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric identifier="r_cc13"><title>R</title>'
            . '<criteria><criterion><criterion_id>_a</criterion_id>'
            . '<points>5.0</points><description>D</description>'
            . '<ratings><rating><description>F</description><points>5.0</points></rating></ratings>'
            . '</criterion></criteria></rubric></rubrics>'
        );
        file_put_contents(
            $dir . '/a1/assignment.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment identifier="ia1" xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment">'
            . '<title>Lab report</title>'
            . '<text texttype="text/html">&lt;p&gt;Write up the experiment.&lt;/p&gt;</text>'
            . '<gradable points_possible="10">true</gradable>'
            . '<submission_formats><format type="html"/><format type="file"/></submission_formats>'
            . '<extensions>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric_identifierref>r_cc13</rubric_identifierref>'
            . '<rubric_use_for_grading>false</rubric_use_for_grading>'
            . '<assignment_group_identifierref>g_part</assignment_group_identifierref>'
            . '</assignment>'
            . '</extensions>'
            . '</assignment>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_assign" identifierref="r_assign"><title>Lab report</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_assign" type="assignment_xmlv1p0" href="a1/assignment.xml">
      <file href="a1/assignment.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $assign = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_ASSIGNMENT, $assign->kind);
        $this->assertSame('r_cc13', $assign->rubricref);
        $this->assertFalse($assign->rubricforgrading);
        $this->assertSame('g_part', $assign->gradegroupref);
    }

    /**
     * When the manifest embeds the CC 1.3 IMS Assignment profile descriptor
     * inline inside <resource> (no <file> child), the parser captures the
     * serialized inline XML on item::inlinexml so the assignment is buildable
     * without needing a path on disk.
     *
     * @return void
     */
    public function test_inline_cc13_assignment_descriptor_is_captured(): void {
        $dir = make_request_directory();
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"'
            . ' xmlns:cc="http://www.imsglobal.org/xsd/imscc_extensions/assignment"'
            . ' identifier="m">'
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i1" identifierref="r1"><title>Inline Lab</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="assignment_xmlv1p0">'
            . '<cc:assignment identifier="a1">'
            . '<cc:title>Inline Lab</cc:title>'
            . '<cc:text texttype="text/html">&lt;p&gt;Inline body&lt;/p&gt;</cc:text>'
            . '<cc:gradable points_possible="7">true</cc:gradable>'
            . '<cc:submission_formats><cc:format type="html"/></cc:submission_formats>'
            . '</cc:assignment>'
            . '</resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $assign = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_ASSIGNMENT, $assign->kind);
        $this->assertNotSame('', $assign->inlinexml);
        $this->assertStringContainsString('Inline body', $assign->inlinexml);
        // The captured snippet parses through assignment_settings as a
        // full CC 1.3 profile document.
        $settings = \tool_canvasuplifter\local\parser\assignment_settings::parse($assign->inlinexml);
        $this->assertSame('Inline Lab', $settings->title);
        $this->assertSame(7, $settings->points);
    }

    /**
     * When a CC <variant identifierref="..."> on a fallback resource points
     * at a richer preferred resource of KIND_ASSIGNMENT, the section attach
     * follows the variant: the assignment lands in the section while the
     * fallback is suppressed and never appears in orphans.
     *
     * @return void
     */
    public function test_section_attach_follows_cc_variant_to_assignment(): void {
        $dir = make_request_directory();
        mkdir($dir . '/fb');
        mkdir($dir . '/asg');
        file_put_contents($dir . '/fb/a.html', '<p>Fallback handout.</p>');
        file_put_contents(
            $dir . '/asg/a.xml',
            '<?xml version="1.0"?>'
            . '<assignment xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment" identifier="a1">'
            . '<title>Real Lab</title>'
            . '<gradable points_possible="5">true</gradable>'
            . '<submission_formats><format type="html"/></submission_formats>'
            . '</assignment>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"'
            . ' xmlns:cpx="http://www.imsglobal.org/xsd/imsccv1p2/imscpext_v1p0"'
            . ' identifier="m">'
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i1" identifierref="r_fb"><title>Lab Report</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_fb" type="webcontent" href="fb/a.html">'
            . '<cpx:variant identifierref="r_asg"/>'
            . '<file href="fb/a.html"/>'
            . '</resource>'
            . '<resource identifier="r_asg" type="assignment_xmlv1p0" href="asg/a.xml">'
            . '<file href="asg/a.xml"/>'
            . '</resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->sections);
        $this->assertCount(1, $course->sections[0]->items);
        $attached = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_ASSIGNMENT, $attached->kind);
        $this->assertSame('r_asg', $attached->identifier);
        // The org-tree title overrides the underlying assignment's derived title.
        $this->assertSame('Lab Report', $attached->title);
        // The fallback resource is suppressed: it must not surface as an orphan.
        $this->assertSame([], $course->orphans);
        // The preferred item carries the fallback identifier as an alias so
        // $CANVAS_OBJECT_REFERENCE$ links targeting the fallback still resolve
        // to the built assignment once course_builder publishes the URL map.
        $this->assertSame(['r_fb'], $attached->aliasids);
        // It also carries the fallback's source path, so a relative link to the
        // fallback HTML resolves to the assignment via the path map.
        $this->assertSame(['fb/a.html'], $attached->aliaspaths);
    }

    /**
     * Inline CC 1.3 assignment descriptors carry their <extensions>'
     * assignment_group_identifierref and rubric_identifierref on the
     * captured inlinexml. mark_assignment_groups() must parse them out of
     * the inline XML when no on-disk settings file resolves so the imported
     * assignment still picks up its grade-category placement and rubric
     * attachment.
     *
     * @return void
     */
    public function test_inline_cc13_extensions_populate_grade_and_rubric_refs(): void {
        $dir = make_request_directory();
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"'
            . ' xmlns:cc="http://www.imsglobal.org/xsd/imscc_extensions/assignment"'
            . ' identifier="m">'
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i1" identifierref="r1"><title>Inline Lab</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="assignment_xmlv1p0">'
            . '<cc:assignment identifier="a1">'
            . '<cc:title>Inline Lab</cc:title>'
            . '<cc:gradable points_possible="5">true</cc:gradable>'
            . '<cc:submission_formats><cc:format type="html"/></cc:submission_formats>'
            . '<cc:extensions>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<assignment_group_identifierref>g_inline</assignment_group_identifierref>'
            . '<rubric_identifierref>r_inline</rubric_identifierref>'
            . '<rubric_use_for_grading>false</rubric_use_for_grading>'
            . '</assignment>'
            . '</cc:extensions>'
            . '</cc:assignment>'
            . '</resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $assign = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_ASSIGNMENT, $assign->kind);
        $this->assertSame('g_inline', $assign->gradegroupref);
        $this->assertSame('r_inline', $assign->rubricref);
        $this->assertFalse($assign->rubricforgrading);
    }

    /**
     * When the package ships no course_settings/course_settings.xml (e.g. a
     * question-bank-only Canvas export, or any non-Canvas CC package), the
     * course title is recovered from the IMS LOMIMSCC metadata block on the
     * manifest. The single untitled root <item> in such a package picks up the
     * same title as its section name so the report doesn't show a blank.
     *
     * @return void
     */
    public function test_recovers_course_title_from_manifest_lom_metadata(): void {
        $dir = make_request_directory();
        mkdir($dir . '/qb');
        file_put_contents(
            $dir . '/qb/qb.xml',
            '<questestinterop><objectbank ident="ob"/></questestinterop>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"'
            . ' xmlns:lomimscc="http://ltsc.ieee.org/xsd/imsccv1p2/LOM/manifest"'
            . ' identifier="m">'
            . '<metadata><lomimscc:lom><lomimscc:general>'
            . '<lomimscc:title><lomimscc:string>Lab Manuals : CH 1 Intro</lomimscc:string></lomimscc:title>'
            . '</lomimscc:general></lomimscc:lom></metadata>'
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i1" identifierref="r1"><title>Q Bank One</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="imsqti_xmlv1p2/imscc_xmlv1p2/question-bank">'
            . '<file href="qb/qb.xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame('Lab Manuals : CH 1 Intro', $course->fullname);
        // The untitled <item identifier="root"> wraps the one activity leaf;
        // the section inherits the course name rather than coming out blank.
        $this->assertCount(1, $course->sections);
        $this->assertSame('Lab Manuals : CH 1 Intro', $course->sections[0]->title);
    }

    /**
     * When a Canvas package ships a question bank alongside its twin quiz
     * assessment carrying the same human-readable title, the question bank
     * gets a "(question bank)" suffix so the two activities don't appear with
     * identical names. Standalone question banks (no title twin) stay as-is.
     *
     * @return void
     */
    public function test_disambiguates_questionbank_titles_against_twin_quizzes(): void {
        $dir = make_request_directory();
        mkdir($dir . '/r1');
        mkdir($dir . '/r2');
        mkdir($dir . '/r3');
        $qti = '<questestinterop><assessment ident="a"/></questestinterop>';
        $bank = '<questestinterop><objectbank ident="ob"/></questestinterop>';
        file_put_contents($dir . '/r1/r1.xml', $qti);
        file_put_contents($dir . '/r2/r2.xml', $bank);
        file_put_contents($dir . '/r3/r3.xml', $bank);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1">
  <organizations>
    <organization identifier="org" structure="rooted-hierarchy">
      <item identifier="root">
        <item identifier="i1" identifierref="r1"><title>CH 1.1 Overview</title></item>
        <item identifier="i2" identifierref="r2"><title>CH 1.1 Overview</title></item>
        <item identifier="i3" identifierref="r3"><title>Standalone Bank</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r1" type="imsqti_xmlv1p2/imscc_xmlv1p2/assessment"><file href="r1/r1.xml"/></resource>
    <resource identifier="r2" type="imsqti_xmlv1p2/imscc_xmlv1p2/question-bank"><file href="r2/r2.xml"/></resource>
    <resource identifier="r3" type="imsqti_xmlv1p2/imscc_xmlv1p2/question-bank"><file href="r3/r3.xml"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $byid = [];
        foreach ($course->sections[0]->items as $modelitem) {
            $byid[$modelitem->identifier] = $modelitem;
        }
        $quiz = $byid['r1'];
        $bank = $byid['r2'];
        $standalone = $byid['r3'];

        // The model titles stay as the manifest provides them; the disambiguated
        // bank name lives separately on item::banktitle so a quiz_from_bank
        // second build of the same orphan model item keeps the unsuffixed name.
        $this->assertSame('CH 1.1 Overview', $quiz->title);
        $this->assertSame('', $quiz->banktitle);
        $this->assertSame('CH 1.1 Overview', $bank->title);
        $this->assertSame('CH 1.1 Overview (question bank)', $bank->banktitle);
        // Standalone bank (no twin quiz title) gets no banktitle.
        $this->assertSame('Standalone Bank', $standalone->title);
        $this->assertSame('', $standalone->banktitle);
    }

    /**
     * Issue #146: an unplaced standalone objectbank resource whose only file is a
     * non_cc_assessments/<id>.xml.qti dump (no organisation <title>, no <assessment>
     * title, no HTML) takes its model title from the objectbank's <bank_title>, so the
     * analysis report, the built activity, and duplicate-title disambiguation all use the
     * same name rather than falling back to the file slug.
     *
     * @return void
     */
    public function test_standalone_objectbank_title_from_bank_title(): void {
        $dir = make_request_directory();
        mkdir($dir . '/non_cc_assessments');
        $bank = '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<objectbank ident="ob"><qtimetadata><qtimetadatafield>'
            . '<fieldlabel>bank_title</fieldlabel><fieldentry>Unfiled Questions</fieldentry>'
            . '</qtimetadatafield></qtimetadata>'
            . '<item ident="q1"/></objectbank></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/pool.xml.qti', $bank);
        // The resource is declared but never placed in the organisation tree (an orphan).
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org"><item identifier="root">'
            . '<item identifier="m1"><title>Week 1</title></item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_bank" type="associatedcontent/imscc_xmlv1p1/learning-application-resource">'
            . '<file href="non_cc_assessments/pool.xml.qti"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $orphan = $course->orphans[0];
        $this->assertSame(item::KIND_QUESTIONBANK, $orphan->kind);
        $this->assertSame('pool', $orphan->objectbankid);
        // The title comes from <bank_title>, not the "pool.xml.qti" file slug.
        $this->assertSame('Unfiled Questions', $orphan->title);
    }

    /**
     * Issue #146: a learning-application-resource whose primary payload is an HTML page but
     * that also lists an objectbank dump as an auxiliary file is a page, not a bank — the
     * page must not be dropped and replaced by a section-0 question bank.
     *
     * @return void
     */
    public function test_html_learning_app_resource_with_aux_bank_is_page(): void {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content', 0777, true);
        mkdir($dir . '/non_cc_assessments', 0777, true);
        file_put_contents($dir . '/wiki_content/page.html', '<html><head><title>Intro</title></head><body>Hi</body></html>');
        file_put_contents(
            $dir . '/non_cc_assessments/pool.xml.qti',
            '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<objectbank ident="ob"><item ident="q1"/></objectbank></questestinterop>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org"><item identifier="root">'
            . '<item identifier="m1"><title>Week 1</title></item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="associatedcontent/imscc_xmlv1p1/learning-application-resource"'
            . ' href="wiki_content/page.html">'
            . '<file href="wiki_content/page.html"/><file href="non_cc_assessments/pool.xml.qti"/>'
            . '</resource></resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame(item::KIND_PAGE, $course->orphans[0]->kind);
        $this->assertSame('', $course->orphans[0]->objectbankid);
    }

    /**
     * Issue #146: a QTI file rooted at <questestinterop> that carries both an <assessment>
     * and a sibling <objectbank> is an assessment dump (owned by the quiz path), not a
     * standalone bank, so classification must not treat it as a mod_qbank resource.
     *
     * @return void
     */
    public function test_mixed_assessment_objectbank_not_standalone_bank(): void {
        $dir = make_request_directory();
        mkdir($dir . '/non_cc_assessments', 0777, true);
        $mixed = '<questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2">'
            . '<assessment ident="a" title="Quiz"><section ident="s"/></assessment>'
            . '<objectbank ident="ob"><item ident="q1"/></objectbank></questestinterop>';
        file_put_contents($dir . '/non_cc_assessments/mixed.xml.qti', $mixed);
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org"><item identifier="root">'
            . '<item identifier="m1"><title>Week 1</title></item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="associatedcontent/imscc_xmlv1p1/learning-application-resource">'
            . '<file href="non_cc_assessments/mixed.xml.qti"/></resource></resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Not classified as a standalone bank: it surfaces no question-bank orphan (it would
        // have become one — and been counted here — without the mixed-payload guard).
        foreach ($course->orphans as $orphan) {
            $this->assertNotSame(item::KIND_QUESTIONBANK, $orphan->kind);
            $this->assertSame('', $orphan->objectbankid);
        }
        $banks = array_filter($course->orphans, fn($o) => $o->kind === item::KIND_QUESTIONBANK);
        $this->assertCount(0, $banks);
    }

    /**
     * Issue #149: the quiz classifier must require a real QTI/CC assessment shape, not any
     * resource type containing the word "assessment". Blackboard's course/x-bb-* settings
     * and its x-bb-qti-test resources carry no Canvas QTI, so they must not become phantom
     * quizzes; a genuine imsqti CC assessment must still classify as a quiz.
     *
     * @return void
     */
    public function test_blackboard_assessment_types_are_not_quizzes(): void {
        $dir = make_request_directory();
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/a1/assessment_qti.xml',
            '<questestinterop><assessment ident="a" title="Real Quiz"><section ident="s"/></assessment></questestinterop>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org"><item identifier="root">'
            . '<item identifier="i_q" identifierref="r_qti"><title>Real Quiz</title></item>'
            . '<item identifier="i_a" identifierref="r_bbassess"><title>BB settings</title></item>'
            . '<item identifier="i_c" identifierref="r_bbcreate"><title>BB create settings</title></item>'
            . '<item identifier="i_t" identifierref="r_bbtest"><title>BB test</title></item>'
            . '</item></organization></organizations><resources>'
            . '<resource identifier="r_qti" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment">'
            . '<file href="a1/assessment_qti.xml"/></resource>'
            . '<resource identifier="r_bbassess" type="course/x-bb-courseassessment"/>'
            . '<resource identifier="r_bbcreate" type="course/x-bb-courseassessmentcreationsettings"/>'
            . '<resource identifier="r_bbtest" type="assessment/x-bb-qti-test"/>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $bykind = [];
        foreach ($course->all_items() as $modelitem) {
            $bykind[$modelitem->identifier] = $modelitem->kind;
        }
        // The genuine Canvas QTI assessment is still a quiz.
        $this->assertSame(item::KIND_QUIZ, $bykind['r_qti'] ?? null);
        // None of the Blackboard *assessment* types are classified as quizzes.
        foreach (['r_bbassess', 'r_bbcreate', 'r_bbtest'] as $id) {
            $this->assertNotSame(item::KIND_QUIZ, $bykind[$id] ?? null, "Blackboard $id must not be a quiz");
        }
        // A package that mixes a few x-bb-* settings resources with genuine importable
        // CC content (the real Canvas QTI quiz here) is NOT a wholly-native package, so
        // it must not be flagged blackboard_native and told nothing can be imported.
        $this->assertNotSame(source_detector::BLACKBOARD_NATIVE, $course->source);
    }

    /**
     * Course title fallback only consults the manifest's direct <metadata>
     * child. CC resources may carry their own LOM <metadata> blocks; the
     * lookup must not borrow a resource title as the course full name when
     * the top-level <metadata> is absent.
     *
     * @return void
     */
    public function test_course_title_ignores_per_resource_metadata(): void {
        $dir = make_request_directory();
        mkdir($dir . '/qb');
        file_put_contents(
            $dir . '/qb/qb.xml',
            '<questestinterop><objectbank ident="ob"/></questestinterop>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1"'
            . ' xmlns:lomimscc="http://ltsc.ieee.org/xsd/imsccv1p2/LOM/manifest"'
            . ' identifier="m">'
            // No top-level <metadata>: the only LOM block lives inside the
            // resource, which describes the bank, not the course.
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i1" identifierref="r1"><title>Q Bank One</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="imsqti_xmlv1p2/imscc_xmlv1p2/question-bank">'
            . '<metadata><lomimscc:lom><lomimscc:general>'
            . '<lomimscc:title><lomimscc:string>Per-Resource LOM Title</lomimscc:string></lomimscc:title>'
            . '</lomimscc:general></lomimscc:lom></metadata>'
            . '<file href="qb/qb.xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame('', $course->fullname);
    }

    /**
     * An unreferenced (orphan) KIND_QUIZ that course_builder converts to a
     * question bank also gets disambiguated when its title matches a linked
     * quiz, so the resulting mod_quiz and mod_qbank activities don't share a
     * name.
     *
     * @return void
     */
    public function test_disambiguates_orphan_quiz_built_as_bank(): void {
        $dir = make_request_directory();
        mkdir($dir . '/r1');
        mkdir($dir . '/r2');
        // Both assessments carry the same title in their QTI XML so
        // derive_title produces the colliding "Foo" name without any org-tree
        // reference for the orphan.
        $qti = '<questestinterop><assessment ident="a" title="Foo"/></questestinterop>';
        file_put_contents($dir . '/r1/r1.xml', $qti);
        file_put_contents($dir . '/r2/r2.xml', $qti);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p2/imscp_v1p1">
  <organizations>
    <organization identifier="org" structure="rooted-hierarchy">
      <item identifier="root">
        <item identifier="i1" identifierref="r_linked"><title>Foo</title></item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_linked" type="imsqti_xmlv1p2/imscc_xmlv1p2/assessment"><file href="r1/r1.xml"/></resource>
    <resource identifier="r_orphan" type="imsqti_xmlv1p2/imscc_xmlv1p2/assessment"><file href="r2/r2.xml"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $orphan = $course->orphans[0];
        // The orphan KIND_QUIZ that will build as a bank gets banktitle set
        // for the bank build; title stays unsuffixed so a subsequent
        // quiz_from_bank build using the same model item still creates a
        // runnable mod_quiz called "Foo".
        $this->assertSame(item::KIND_QUIZ, $orphan->kind);
        $this->assertSame('Foo', $orphan->title);
        $this->assertSame('Foo (question bank)', $orphan->banktitle);
        $linked = $course->sections[0]->items[0];
        $this->assertSame(item::KIND_QUIZ, $linked->kind);
        $this->assertSame('Foo', $linked->title);
        $this->assertSame('', $linked->banktitle);
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

    /**
     * eXe/IGEN/DELOS-style lesson bundles (folders carrying igencp.css +
     * delos_cont.css + index.html) get folded into a single mod_page anchored
     * at the folder's index.html. The skeleton siblings (jquery, accordion,
     * theme images, audio players, …) vanish from the orphan list instead of
     * surfacing as hundreds of pointless mod_resource entries.
     *
     * @return void
     */
    public function test_folds_lesson_bundle_into_single_page(): void {
        $dir = make_request_directory();
        mkdir($dir . '/unit1');
        mkdir($dir . '/unit1/assets');
        // The bundle markers + the entry page + the skeleton noise.
        file_put_contents($dir . '/unit1/index.html', '<html><head><title>Unit 1</title></head><body>Hi</body></html>');
        file_put_contents($dir . '/unit1/igencp.css', '/* skin */');
        file_put_contents($dir . '/unit1/delos_cont.css', '/* skin */');
        file_put_contents($dir . '/unit1/jquery.js', '// noise');
        file_put_contents($dir . '/unit1/accordion.css', '/* noise */');
        file_put_contents($dir . '/unit1/assets/head_back.gif', 'GIF');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_index" type="webcontent" href="unit1/index.html"><file href="unit1/index.html"/></resource>
    <resource identifier="r_skin1" type="webcontent" href="unit1/igencp.css"><file href="unit1/igencp.css"/></resource>
    <resource identifier="r_skin2" type="webcontent" href="unit1/delos_cont.css"><file href="unit1/delos_cont.css"/></resource>
    <resource identifier="r_jq" type="webcontent" href="unit1/jquery.js"><file href="unit1/jquery.js"/></resource>
    <resource identifier="r_acc" type="webcontent" href="unit1/accordion.css"><file href="unit1/accordion.css"/></resource>
    <resource identifier="r_img" type="webcontent" href="unit1/assets/head_back.gif">
      <file href="unit1/assets/head_back.gif"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Only the bundle's index.html survives as a single page; everything
        // else (skin CSS, jQuery, theme image in a subfolder) is suppressed.
        $this->assertCount(1, $course->orphans);
        $this->assertSame('r_index', $course->orphans[0]->identifier);
        $this->assertSame(item::KIND_PAGE, $course->orphans[0]->kind);
        $this->assertSame('Unit 1', $course->orphans[0]->title);

        // The promoted page must point at index.html, not whatever <file> the
        // manifest happens to list first. And it must carry the sibling files
        // as bundle assets so page_builder can re-host them under pluginfile.
        $page = $course->orphans[0];
        $this->assertSame('unit1/index.html', $page->href);
        $this->assertSame('unit1/index.html', $page->files[0]);
        $assetrels = array_column($page->bundleassets, 'relpath');
        sort($assetrels);
        $this->assertContains('assets/head_back.gif', $assetrels);
        $this->assertContains('jquery.js', $assetrels);
        $this->assertNotContains('index.html', $assetrels);
    }

    /**
     * Exporters that ship "Index.html" or "INDEX.HTML" should still produce
     * the page; case-insensitive match recovers the actual basename instead
     * of falling through to KIND_UNKNOWN and losing the whole bundle.
     *
     * @return void
     */
    public function test_folds_lesson_bundle_case_insensitively(): void {
        $dir = make_request_directory();
        mkdir($dir . '/unit2');
        file_put_contents($dir . '/unit2/Index.html', '<html><head><title>Unit 2</title></head></html>');
        file_put_contents($dir . '/unit2/igencp.css', '');
        file_put_contents($dir . '/unit2/delos_cont.css', '');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_idx" type="webcontent" href="unit2/Index.html">
      <file href="unit2/Index.html"/>
    </resource>
    <resource identifier="r_a" type="webcontent" href="unit2/igencp.css">
      <file href="unit2/igencp.css"/>
    </resource>
    <resource identifier="r_b" type="webcontent" href="unit2/delos_cont.css">
      <file href="unit2/delos_cont.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('r_idx', $course->orphans[0]->identifier);
        $this->assertSame(item::KIND_PAGE, $course->orphans[0]->kind);
    }

    /**
     * Bundle assets that the manifest organisation references must not
     * surface as section items either — only the orphan pass filters
     * KIND_UNKNOWN otherwise, so referenced framework files would still
     * appear in the report as unbuildable rows.
     *
     * @return void
     */
    public function test_folded_bundle_assets_are_dropped_from_sections(): void {
        $dir = make_request_directory();
        mkdir($dir . '/unit3');
        file_put_contents($dir . '/unit3/index.html', '<html><title>Unit 3</title></html>');
        file_put_contents($dir . '/unit3/igencp.css', '');
        file_put_contents($dir . '/unit3/delos_cont.css', '');
        file_put_contents($dir . '/unit3/jquery.js', '');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="o">
      <item identifier="root">
        <item identifier="m1"><title>Week 1</title>
          <item identifier="i_idx" identifierref="r_idx"><title>Lesson</title></item>
          <item identifier="i_jq" identifierref="r_jq"><title>Player</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_idx" type="webcontent" href="unit3/index.html">
      <file href="unit3/index.html"/>
    </resource>
    <resource identifier="r_a" type="webcontent" href="unit3/igencp.css">
      <file href="unit3/igencp.css"/>
    </resource>
    <resource identifier="r_b" type="webcontent" href="unit3/delos_cont.css">
      <file href="unit3/delos_cont.css"/>
    </resource>
    <resource identifier="r_jq" type="webcontent" href="unit3/jquery.js">
      <file href="unit3/jquery.js"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Only the index page reaches the section; the referenced jQuery asset
        // is suppressed instead of surfacing as an unbuildable unknown item.
        $this->assertCount(1, $course->sections);
        $this->assertCount(1, $course->sections[0]->items);
        $this->assertSame('r_idx', $course->sections[0]->items[0]->identifier);
        $this->assertSame(item::KIND_PAGE, $course->sections[0]->items[0]->kind);
    }

    /**
     * A root-level bundle (markers in the package root) should claim every
     * resource, including those under subfolders like assets/ or images/,
     * to match the same folder-tree semantics nested bundles use.
     *
     * @return void
     */
    public function test_root_level_bundle_includes_subfolders(): void {
        $dir = make_request_directory();
        mkdir($dir . '/assets');
        file_put_contents($dir . '/index.html', '<html><title>Course</title></html>');
        file_put_contents($dir . '/igencp.css', '');
        file_put_contents($dir . '/delos_cont.css', '');
        file_put_contents($dir . '/assets/head_back.gif', 'GIF');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_idx" type="webcontent" href="index.html"><file href="index.html"/></resource>
    <resource identifier="r_a" type="webcontent" href="igencp.css"><file href="igencp.css"/></resource>
    <resource identifier="r_b" type="webcontent" href="delos_cont.css"><file href="delos_cont.css"/></resource>
    <resource identifier="r_sub" type="webcontent" href="assets/head_back.gif">
      <file href="assets/head_back.gif"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The subfolder image is folded too, not left as a stray orphan.
        $this->assertCount(1, $course->orphans);
        $this->assertSame('r_idx', $course->orphans[0]->identifier);
        $assetrels = array_column($course->orphans[0]->bundleassets, 'relpath');
        $this->assertContains('assets/head_back.gif', $assetrels);
    }

    /**
     * When the package root carries the marker triple AND a subfolder also
     * carries it, the outer (root) bundle should claim the subfolder; the
     * inner detection must not later promote the subfolder's index.html
     * back to KIND_PAGE.
     *
     * @return void
     */
    public function test_root_bundle_swallows_nested_bundle(): void {
        $dir = make_request_directory();
        mkdir($dir . '/inner');
        file_put_contents($dir . '/index.html', '<html><title>Course</title></html>');
        file_put_contents($dir . '/igencp.css', '');
        file_put_contents($dir . '/delos_cont.css', '');
        file_put_contents($dir . '/inner/index.html', '<html><title>Inner</title></html>');
        file_put_contents($dir . '/inner/igencp.css', '');
        file_put_contents($dir . '/inner/delos_cont.css', '');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_root" type="webcontent" href="index.html"><file href="index.html"/></resource>
    <resource identifier="r_a" type="webcontent" href="igencp.css"><file href="igencp.css"/></resource>
    <resource identifier="r_b" type="webcontent" href="delos_cont.css"><file href="delos_cont.css"/></resource>
    <resource identifier="r_inner" type="webcontent" href="inner/index.html">
      <file href="inner/index.html"/>
    </resource>
    <resource identifier="r_c" type="webcontent" href="inner/igencp.css"><file href="inner/igencp.css"/></resource>
    <resource identifier="r_d" type="webcontent" href="inner/delos_cont.css">
      <file href="inner/delos_cont.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Only the root index becomes a page; the nested one is just an asset.
        $this->assertCount(1, $course->orphans);
        $this->assertSame('r_root', $course->orphans[0]->identifier);
        $assetrels = array_column($course->orphans[0]->bundleassets, 'relpath');
        $this->assertContains('inner/index.html', $assetrels);
    }

    /**
     * When a bundle is expressed as one <resource> with index.html as href
     * and the framework siblings as additional <file> children, those
     * non-anchor files must still land in $bundleassets — page_builder needs
     * them to rewrite and import the relative refs.
     *
     * @return void
     */
    public function test_anchor_resource_siblings_collected_as_assets(): void {
        $dir = make_request_directory();
        mkdir($dir . '/lesson');
        file_put_contents($dir . '/lesson/index.html', '<html><title>L</title></html>');
        file_put_contents($dir . '/lesson/igencp.css', '');
        file_put_contents($dir . '/lesson/delos_cont.css', '');
        file_put_contents($dir . '/lesson/jquery.js', '');

        // Single resource bundles everything together.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_bundle" type="webcontent" href="lesson/index.html">
      <file href="lesson/index.html"/>
      <file href="lesson/igencp.css"/>
      <file href="lesson/delos_cont.css"/>
      <file href="lesson/jquery.js"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame(item::KIND_PAGE, $course->orphans[0]->kind);
        $assetrels = array_column($course->orphans[0]->bundleassets, 'relpath');
        sort($assetrels);
        $this->assertSame(['delos_cont.css', 'igencp.css', 'jquery.js'], $assetrels);
    }

    /**
     * A KIND_UNKNOWN resource that *isn't* a bundle asset (an unsupported
     * resource type the organisation references) must still surface in the
     * section so the report can flag it as unmappable.
     *
     * @return void
     */
    public function test_non_bundle_unknown_still_surfaces_in_section(): void {
        $dir = make_request_directory();
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="o"><item identifier="root">
      <item identifier="m1"><title>Week 1</title>
        <item identifier="i_x" identifierref="r_x"><title>Mystery</title></item>
      </item>
    </item></organization>
  </organizations>
  <resources>
    <resource identifier="r_x" type="some-vendor-specific-bundle" href="x.bin">
      <file href="x.bin"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Section still carries the unknown so the report can warn about it.
        $this->assertCount(1, $course->sections);
        $this->assertCount(1, $course->sections[0]->items);
        $this->assertSame(item::KIND_UNKNOWN, $course->sections[0]->items[0]->kind);
        $this->assertSame('Mystery', $course->sections[0]->items[0]->title);
    }

    /**
     * Resources the classifier deliberately marks KIND_UNKNOWN (quiz/ assets,
     * metadata-only learning-application companions) stay suppressed even
     * when the organisation explicitly references them. Genuinely unknown
     * resource types must still appear so the report can flag them.
     *
     * @return void
     */
    public function test_deliberate_unknown_assets_stay_suppressed_in_sections(): void {
        $dir = make_request_directory();
        mkdir($dir . '/quiz');
        file_put_contents($dir . '/quiz/diagram.png', 'PNG');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="o"><item identifier="root">
      <item identifier="m1"><title>Week 1</title>
        <item identifier="i_qa" identifierref="r_quizasset"><title>QuizAsset</title></item>
      </item>
    </item></organization>
  </organizations>
  <resources>
    <resource identifier="r_quizasset" type="webcontent">
      <file href="quiz/diagram.png"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The quiz asset doesn't reach the section even though the
        // organisation references it — same behaviour as the original parser.
        $this->assertCount(1, $course->sections);
        $this->assertCount(0, $course->sections[0]->items);
    }

    /**
     * If the marker filenames appear inside a folder only as secondary <file>
     * entries (no resource's primary href is that folder's index.html), no
     * anchor is found and nothing should be claimed: a valid nested bundle
     * below must still be allowed to fold on its own.
     *
     * @return void
     */
    public function test_unanchored_outer_lets_inner_bundle_fold(): void {
        $dir = make_request_directory();
        mkdir($dir . '/outer');
        mkdir($dir . '/outer/lesson');
        // Markers in outer/ — but no resource will have outer/index.html as
        // its primary path; the index file is bundled into the loose carrier.
        file_put_contents($dir . '/outer/index.html', '<html><title>Outer</title></html>');
        file_put_contents($dir . '/outer/igencp.css', '');
        file_put_contents($dir . '/outer/delos_cont.css', '');
        // Valid nested bundle.
        file_put_contents($dir . '/outer/lesson/index.html', '<html><title>Lesson</title></html>');
        file_put_contents($dir . '/outer/lesson/igencp.css', '');
        file_put_contents($dir . '/outer/lesson/delos_cont.css', '');

        // The outer carrier resource has the marker triple only as <file> entries;
        // its primary href is the unrelated zip below it, so no anchor exists at outer/.
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_carrier" type="webcontent" href="outer/igencp.css">
      <file href="outer/igencp.css"/>
      <file href="outer/delos_cont.css"/>
      <file href="outer/index.html"/>
    </resource>
    <resource identifier="r_inner" type="webcontent" href="outer/lesson/index.html">
      <file href="outer/lesson/index.html"/>
    </resource>
    <resource identifier="r_inner_a" type="webcontent" href="outer/lesson/igencp.css">
      <file href="outer/lesson/igencp.css"/>
    </resource>
    <resource identifier="r_inner_b" type="webcontent" href="outer/lesson/delos_cont.css">
      <file href="outer/lesson/delos_cont.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The inner bundle still folds: lesson/index.html becomes a page.
        $byid = [];
        foreach ($course->orphans as $orphan) {
            $byid[$orphan->identifier] = $orphan;
        }
        $this->assertArrayHasKey('r_inner', $byid);
        $this->assertSame(item::KIND_PAGE, $byid['r_inner']->kind);
    }

    /**
     * Folders containing an index.html but no ILIAS theme marker stay as
     * ordinary mod_resource entries — Canvas exports with miscellaneous
     * HTML/CSS in subfolders must not be folded by accident. Likewise a
     * folder carrying the theme marker but no index.html at its root.
     *
     * @return void
     */
    public function test_bundle_detector_does_not_fire_without_markers(): void {
        $dir = make_request_directory();
        mkdir($dir . '/random');
        mkdir($dir . '/themed');
        file_put_contents($dir . '/random/index.html', '<html><title>X</title></html>');
        file_put_contents($dir . '/random/site.css', '/* not a theme marker */');
        file_put_contents($dir . '/themed/igencp.css', '/* theme marker but no index.html at root */');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_index" type="webcontent" href="random/index.html">
      <file href="random/index.html"/>
    </resource>
    <resource identifier="r_css" type="webcontent" href="random/site.css"><file href="random/site.css"/></resource>
    <resource identifier="r_theme" type="webcontent" href="themed/igencp.css">
      <file href="themed/igencp.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // All three still surface as plain orphans; no bundle fold.
        $this->assertCount(3, $course->orphans);
        $kinds = array_column($course->orphans, 'kind', 'identifier');
        $this->assertSame(item::KIND_FILE, $kinds['r_index']);
        $this->assertSame(item::KIND_FILE, $kinds['r_css']);
        $this->assertSame(item::KIND_FILE, $kinds['r_theme']);
    }

    /**
     * A parent landing index.html without its own theme marker must NOT be
     * promoted just because a child lesson bundle has one. Otherwise the
     * shortest-first fold would claim the whole parent tree and demote every
     * real lesson bundle inside it.
     *
     * @return void
     */
    public function test_child_theme_marker_does_not_promote_parent_anchor(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course');
        mkdir($dir . '/course/lesson');
        // Parent landing page with NO theme marker anywhere outside the child.
        file_put_contents($dir . '/course/index.html', '<html><title>Course</title></html>');
        // Real lesson bundle below it.
        file_put_contents($dir . '/course/lesson/index.html', '<html><title>Lesson</title></html>');
        file_put_contents($dir . '/course/lesson/igencp.css', '');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_parent" type="webcontent" href="course/index.html">
      <file href="course/index.html"/>
    </resource>
    <resource identifier="r_child" type="webcontent" href="course/lesson/index.html">
      <file href="course/lesson/index.html"/>
    </resource>
    <resource identifier="r_theme" type="webcontent" href="course/lesson/igencp.css">
      <file href="course/lesson/igencp.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Parent stays as an ordinary file resource; only the child lesson
        // folder folds to a page.
        $byid = [];
        foreach ($course->orphans as $orphan) {
            $byid[$orphan->identifier] = $orphan;
        }
        $this->assertArrayHasKey('r_parent', $byid);
        $this->assertSame(item::KIND_FILE, $byid['r_parent']->kind);
        $this->assertArrayHasKey('r_child', $byid);
        $this->assertSame(item::KIND_PAGE, $byid['r_child']->kind);
        // The theme marker is folded as a child asset, not surfaced separately.
        $this->assertArrayNotHasKey('r_theme', $byid);
    }

    /**
     * A nested folder whose index.html only appears as a secondary <file>
     * entry (no resource lists it as the primary href) must not steal a
     * theme marker from a real parent bundle. Without this guard the
     * marker would be attributed to the unanchored nested folder,
     * fold_one_bundle() would return false there, and the real parent
     * lesson folder would never be folded — every bundle asset would then
     * surface as a separate file.
     *
     * @return void
     */
    public function test_unanchored_nested_index_does_not_steal_parent_marker(): void {
        $dir = make_request_directory();
        mkdir($dir . '/lesson');
        mkdir($dir . '/lesson/style');
        file_put_contents($dir . '/lesson/index.html', '<html><title>Lesson</title></html>');
        // Nested asset folder also has an index.html, but only as a secondary
        // file entry below — no resource has it as a primary href.
        file_put_contents($dir . '/lesson/style/index.html', '<html><title>fragment</title></html>');
        file_put_contents($dir . '/lesson/style/igencp.css', '');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_lesson" type="webcontent" href="lesson/index.html">
      <file href="lesson/index.html"/>
    </resource>
    <resource identifier="r_skin" type="webcontent" href="lesson/style/igencp.css">
      <file href="lesson/style/igencp.css"/>
      <file href="lesson/style/index.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The real lesson folder folds; the unanchored nested folder is
        // suppressed as a bundle asset, not surfaced separately.
        $byid = [];
        foreach ($course->orphans as $orphan) {
            $byid[$orphan->identifier] = $orphan;
        }
        $this->assertArrayHasKey('r_lesson', $byid);
        $this->assertSame(item::KIND_PAGE, $byid['r_lesson']->kind);
        $this->assertArrayNotHasKey('r_skin', $byid);
        $assetrels = array_column($byid['r_lesson']->bundleassets, 'relpath');
        $this->assertContains('style/igencp.css', $assetrels);
    }

    /**
     * Common Cartridge organisations can nest module folders inside a course
     * wrapper, and each module can in turn wrap its lessons inside an
     * untitled identifierref-less folder <item>. The walker must flatten
     * those wrappers into the section's items so descendant lessons don't
     * surface as orphans.
     *
     * @return void
     */
    public function test_org_tree_recurses_into_folder_wrappers(): void {
        $dir = make_request_directory();
        mkdir($dir . '/intro');
        mkdir($dir . '/lesson1');
        mkdir($dir . '/lesson2');
        file_put_contents($dir . '/intro/intro.html', '<html><title>Intro</title></html>');
        file_put_contents($dir . '/lesson1/page.html', '<html><title>L1</title></html>');
        file_put_contents($dir . '/lesson2/page.html', '<html><title>L2</title></html>');

        // Item_1 → CourseWrapper → [Intro leaf, Unit 1 folder → [Lesson1, Lesson2]].
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="Org_1" structure="rooted-hierarchy">
      <item identifier="Item_1">
        <item identifier="course_wrapper">
          <title>Course Title</title>
          <item identifierref="r_intro" identifier="i_intro"><title>Intro</title></item>
          <item identifier="folder_unit1">
            <title>Unit 1</title>
            <item identifierref="r_lesson1" identifier="i_l1"><title>Lesson 1</title></item>
            <item identifierref="r_lesson2" identifier="i_l2"><title>Lesson 2</title></item>
          </item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_intro" type="webcontent" href="intro/intro.html">
      <file href="intro/intro.html"/>
    </resource>
    <resource identifier="r_lesson1" type="webcontent" href="lesson1/page.html">
      <file href="lesson1/page.html"/>
    </resource>
    <resource identifier="r_lesson2" type="webcontent" href="lesson2/page.html">
      <file href="lesson2/page.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Two sections: the leaf-only "Intro" wrapper and the "Unit 1" folder.
        $this->assertCount(2, $course->sections);
        $sectionsbytitle = [];
        foreach ($course->sections as $section) {
            $sectionsbytitle[$section->title] = $section;
        }
        $this->assertArrayHasKey('Intro', $sectionsbytitle);
        $this->assertCount(1, $sectionsbytitle['Intro']->items);
        $this->assertArrayHasKey('Unit 1', $sectionsbytitle);
        // Both lesson leaves attached inside Unit 1 with their org-tree titles.
        $titles = array_map(fn($i) => $i->title, $sectionsbytitle['Unit 1']->items);
        $this->assertSame(['Lesson 1', 'Lesson 2'], $titles);
        // No orphans: every resource is reachable from the org tree.
        $this->assertSame([], $course->orphans);
    }

    /**
     * Real ILIAS / IGEN / DELOS exports nest the theme marker many levels
     * below the lesson folder (style/igencp.css,
     * Customizing/global/skin/igencp/igencp.css,
     * templates/default/delos_cont.css). The detector should still fold each
     * lesson folder into a single page even though the markers don't sit at
     * the same level as the anchor index.html.
     *
     * @return void
     */
    public function test_folds_bundle_with_nested_theme_marker(): void {
        $dir = make_request_directory();
        mkdir($dir . '/NTERID_LM_00008599_R');
        mkdir($dir . '/NTERID_LM_00008599_R/style');
        mkdir($dir . '/NTERID_LM_00008599_R/style/images');
        file_put_contents(
            $dir . '/NTERID_LM_00008599_R/index.html',
            '<html><head><title>Fall Protection</title></head><body>Hi</body></html>'
        );
        file_put_contents($dir . '/NTERID_LM_00008599_R/lm_pg_5859.html', '<p>Lesson page.</p>');
        file_put_contents($dir . '/NTERID_LM_00008599_R/syntaxhighlight.css', '');
        file_put_contents($dir . '/NTERID_LM_00008599_R/style/igencp.css', '');
        file_put_contents($dir . '/NTERID_LM_00008599_R/style/images/head_back.gif', 'GIF');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_idx" type="webcontent" href="NTERID_LM_00008599_R/index.html">
      <file href="NTERID_LM_00008599_R/index.html"/>
    </resource>
    <resource identifier="r_lmpg" type="webcontent" href="NTERID_LM_00008599_R/lm_pg_5859.html">
      <file href="NTERID_LM_00008599_R/lm_pg_5859.html"/>
    </resource>
    <resource identifier="r_sx" type="webcontent" href="NTERID_LM_00008599_R/syntaxhighlight.css">
      <file href="NTERID_LM_00008599_R/syntaxhighlight.css"/>
    </resource>
    <resource identifier="r_theme" type="webcontent" href="NTERID_LM_00008599_R/style/igencp.css">
      <file href="NTERID_LM_00008599_R/style/igencp.css"/>
    </resource>
    <resource identifier="r_img" type="webcontent" href="NTERID_LM_00008599_R/style/images/head_back.gif">
      <file href="NTERID_LM_00008599_R/style/images/head_back.gif"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // Single page from the bundle; siblings folded as assets.
        $this->assertCount(1, $course->orphans);
        $page = $course->orphans[0];
        $this->assertSame('r_idx', $page->identifier);
        $this->assertSame(item::KIND_PAGE, $page->kind);
        $assetrels = array_column($page->bundleassets, 'relpath');
        sort($assetrels);
        $this->assertSame(
            ['lm_pg_5859.html', 'style/igencp.css', 'style/images/head_back.gif', 'syntaxhighlight.css'],
            $assetrels
        );
    }

    /**
     * An HTML <title> like " - Audio Visual" loses its leading separator so
     * the report and the built activity don't show the dash on its own.
     *
     * @return void
     */
    public function test_strips_leading_separator_from_derived_title(): void {
        $dir = make_request_directory();
        mkdir($dir . '/lessons');
        file_put_contents(
            $dir . '/lessons/audio.html',
            '<html><head><title> - Audio Visual</title></head><body/></html>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1"><item identifier="root"/></organization>
  </organizations>
  <resources>
    <resource identifier="r_audio" type="webcontent" href="lessons/audio.html">
      <file href="lessons/audio.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertCount(1, $course->orphans);
        $this->assertSame('Audio Visual', $course->orphans[0]->title);
    }

    /**
     * A Canvas course-navigation external tool that is also placed as a module item
     * is already imported as a hidden mod_lti, so it is deduped; a nav-only tool that
     * appears only in course_settings.xml's tab_configuration carries no launch
     * configuration in the package, so it is counted for the report to surface.
     *
     * @return void
     */
    public function test_reports_unimported_course_navigation_tools(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        // The Accredible tool is placed as a module item (built as a hidden mod_lti);
        // "navonly" appears only as a navigation tab, with no definition anywhere.
        file_put_contents(
            $dir . '/course_settings/module_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<module identifier="m1"><title>Tools</title><workflow_state>active</workflow_state>'
            . '<items><item identifier="mi1"><content_type>ContextExternalTool</content_type>'
            . '<workflow_state>active</workflow_state><title>Accredible</title>'
            . '<identifierref>toolA</identifierref><url>https://tool.example/a</url></item></items>'
            . '</module></modules>'
        );
        file_put_contents(
            $dir . '/course_settings/course_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="c1">'
            . '<title>Nav Tools Course</title>'
            . '<tab_configuration>[{"id":0},{"id":"context_external_tool_toolA"},'
            . '{"id":"context_external_tool_navonly","hidden":true}]</tab_configuration>'
            . '</course>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources/>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The placed Accredible tool is already a hidden mod_lti (deduped); only the
        // nav-only tool is counted as unimported.
        $this->assertSame(1, $course->navtoolsunimported);
        // The placed tool built as a KIND_LTI item; the nav-only tool did not.
        $ltis = array_filter($course->all_items(), fn($i) => $i->kind === item::KIND_LTI);
        $this->assertCount(1, $ltis);
        $this->assertSame('Accredible', array_values($ltis)[0]->title);
    }

    /**
     * A package whose tab_configuration has no external-tool tabs (or no
     * course_settings.xml at all) records no unimported navigation tools.
     *
     * @return void
     */
    public function test_no_navigation_tools_when_tab_configuration_has_none(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        file_put_contents(
            $dir . '/course_settings/course_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="c1">'
            . '<title>Plain Course</title>'
            . '<tab_configuration>[{"id":0},{"id":14},{"id":3}]</tab_configuration>'
            . '</course>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources/>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        $this->assertSame(0, $course->navtoolsunimported);
    }

    /**
     * A ContextExternalTool module item with a missing or non-http(s) launch URL is
     * not built (no mod_lti is created), so a navigation tab pointing at it must
     * still be flagged rather than deduped away as "already imported".
     *
     * @return void
     */
    public function test_navigation_tool_for_unbuilt_module_item_is_flagged(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        // The tool's <url> is not http(s), so item_from_module_meta drops it.
        file_put_contents(
            $dir . '/course_settings/module_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<module identifier="m1"><title>Tools</title><workflow_state>active</workflow_state>'
            . '<items><item identifier="mi1"><content_type>ContextExternalTool</content_type>'
            . '<workflow_state>active</workflow_state><title>Broken</title>'
            . '<identifierref>toolB</identifierref><url>javascript:alert(1)</url></item></items>'
            . '</module></modules>'
        );
        file_put_contents(
            $dir . '/course_settings/course_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="c1">'
            . '<title>Broken Tool Course</title>'
            . '<tab_configuration>[{"id":0},{"id":"context_external_tool_toolB"}]</tab_configuration>'
            . '</course>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources/>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // No LTI was built for toolB, and the nav tab is not deduped away.
        $this->assertCount(0, array_filter($course->all_items(), fn($i) => $i->kind === item::KIND_LTI));
        $this->assertSame(1, $course->navtoolsunimported);
    }

    /**
     * A nav tab that resolves to a manifest LTI resource is deduped (that tool is
     * imported as an orphan mod_lti and, either way, surfaced to the admin); a tab
     * that resolves to a resource which will not build (KIND_UNKNOWN) is not deduped
     * and is flagged, as is one that resolves to no resource at all.
     *
     * @return void
     */
    public function test_navigation_tool_dedup_requires_a_buildable_resource(): void {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/tool');
        file_put_contents(
            $dir . '/tool/basiclti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"'
            . ' xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0">'
            . '<blti:title>Publisher Tool</blti:title>'
            . '<blti:launch_url>https://tool.example/launch</blti:launch_url>'
            . '</cartridge_basiclti_link>'
        );
        // A learning-application-resource whose only payload is a non-HTML companion
        // file classifies as KIND_UNKNOWN and never builds.
        file_put_contents($dir . '/tool/meta.txt', 'metadata');
        file_put_contents(
            $dir . '/course_settings/course_settings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<course xmlns="http://canvas.instructure.com/xsd/cccv1p0" identifier="c1">'
            . '<title>Mixed Nav Tools</title>'
            . '<tab_configuration>[{"id":0},{"id":"context_external_tool_navL"},'
            . '{"id":"context_external_tool_navU"},{"id":"context_external_tool_navonly"}]</tab_configuration>'
            . '</course>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="navL" type="imsbasiclti_xmlv1p0" href="tool/basiclti.xml">
      <file href="tool/basiclti.xml"/>
    </resource>
    <resource identifier="navU" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="tool/meta.txt">
      <file href="tool/meta.txt"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $course = (new manifest_parser($dir))->parse();

        // The navL resource (a real LTI) is deduped; navU (won't build) and navonly
        // (no resource) are both flagged.
        $this->assertSame(2, $course->navtoolsunimported);
        // The LTI resource did build as an orphan mod_lti.
        $ltis = array_filter($course->all_items(), fn($i) => $i->kind === item::KIND_LTI);
        $this->assertCount(1, $ltis);
        $this->assertSame('Publisher Tool', array_values($ltis)[0]->title);
    }
}
