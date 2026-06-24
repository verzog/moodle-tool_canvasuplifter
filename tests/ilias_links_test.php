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
use tool_canvasuplifter\local\build\page_payload;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * Tests importing an ILIAS-style export, where each learning module is a folded
 * lesson bundle (index.html + theme marker) and modules link to each other with
 * plain relative paths rather than Canvas placeholder tokens.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder
 * @covers     \tool_canvasuplifter\local\build\page_builder
 * @covers     \tool_canvasuplifter\local\build\book_builder
 * @covers     \tool_canvasuplifter\local\build\page_payload
 * @covers     \tool_canvasuplifter\local\build\link_rewriter
 */
final class ilias_links_test extends \advanced_testcase {
    /**
     * Build a package shaped like an ILIAS export: two learning-module folders,
     * each with an index.html and a delos_cont.css theme marker, where module A's
     * page links to module B with a relative ../ path and embeds its own asset.
     *
     * @return string Path to the package root.
     */
    private function build_ilias_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/lm_a');
        mkdir($dir . '/lm_b');
        file_put_contents($dir . '/lm_a/delos_cont.css', '.ilc {}');
        file_put_contents($dir . '/lm_b/delos_cont.css', '.ilc {}');
        file_put_contents(
            $dir . '/lm_a/index.html',
            '<html><head><link rel="stylesheet" href="delos_cont.css"></head>'
            . '<body><p>Introduction.</p>'
            . '<a href="../lm_b/index.html">Continue to Module B</a></body></html>'
        );
        file_put_contents(
            $dir . '/lm_b/index.html',
            '<html><head><link rel="stylesheet" href="delos_cont.css"></head>'
            . '<body><p>Module B content.</p></body></html>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="ilias_1" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Course</title>
          <item identifier="i1" identifierref="r_a"><title>Module A</title></item>
          <item identifier="i2" identifierref="r_b"><title>Module B</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_a" type="webcontent" href="lm_a/index.html">
      <file href="lm_a/index.html"/>
      <file href="lm_a/delos_cont.css"/>
    </resource>
    <resource identifier="r_b" type="webcontent" href="lm_b/index.html">
      <file href="lm_b/index.html"/>
      <file href="lm_b/delos_cont.css"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The cross-module relative link is rewritten to module B's activity URL,
     * while module A's own theme asset is still folded in as a pluginfile.
     *
     * @return void
     */
    public function test_relative_cross_module_link_resolves(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_ilias_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(2, $report['createdcounts']['page'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);
        $acm = null;
        $bcm = null;
        foreach ($modinfo->get_instances_of('page') as $cm) {
            if ($cm->get_name() === 'Module A') {
                $acm = $cm;
            }
            if ($cm->get_name() === 'Module B') {
                $bcm = $cm;
            }
        }
        $this->assertNotNull($acm);
        $this->assertNotNull($bcm);

        $pagea = $DB->get_record('page', ['id' => $acm->instance]);
        // The relative cross-module link now points at module B's page activity.
        $this->assertStringContainsString('/mod/page/view.php?id=' . $bcm->id, $pagea->content);
        $this->assertStringNotContainsString('../lm_b/index.html', $pagea->content);
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $pagea->content);
        // The bundle's own asset still resolves through pluginfile.
        $this->assertStringContainsString('@@PLUGINFILE@@/delos_cont.css', $pagea->content);

        $fs = get_file_storage();
        $context = \context_module::instance($acm->id);
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/', 'delos_cont.css'));
    }

    /**
     * With page grouping set to "book", the two modules fold into one book and
     * module A's relative cross-module link resolves to module B's chapter URL,
     * rather than being left as a dead ../lm_b/index.html link.
     *
     * @return void
     */
    public function test_relative_link_resolves_in_grouped_book(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_ilias_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root, null, 0, false, 'book'))->build($coursemodel);

        $books = get_fast_modinfo($report['courseid'])->get_instances_of('book');
        $this->assertCount(1, $books);
        $book = reset($books);

        $chapters = $DB->get_records('book_chapters', ['bookid' => $book->instance], 'pagenum ASC');
        $this->assertCount(2, $chapters);
        $chapters = array_values($chapters);
        $intro = $chapters[0];
        $target = $chapters[1];

        // The link resolves to module B's chapter inside the same book, not the
        // raw relative path.
        $this->assertStringContainsString(
            '/mod/book/view.php?id=' . $book->id . '&chapterid=' . $target->id,
            $intro->content
        );
        $this->assertStringNotContainsString('../lm_b/index.html', $intro->content);
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $intro->content);
    }

    /**
     * A page that links by relative path to a CC variant fallback's HTML
     * resolves to the preferred activity (the assignment the variant swaps in),
     * even though the fallback resource itself is suppressed and never built.
     *
     * @return void
     */
    public function test_relative_link_to_variant_fallback_resolves(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        mkdir($dir . '/fb');
        mkdir($dir . '/asg');
        file_put_contents($dir . '/wiki_content/p.html', '<p>See <a href="../fb/a.html">the lab</a>.</p>');
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
            . ' xmlns:cpx="http://www.imsglobal.org/xsd/imsccv1p2/imscpext_v1p0" identifier="m">'
            . '<organizations><organization identifier="org" structure="rooted-hierarchy">'
            . '<item identifier="root">'
            . '<item identifier="i_p" identifierref="r_p"><title>Intro</title></item>'
            . '<item identifier="i_lab" identifierref="r_fb"><title>Lab Report</title></item>'
            . '</item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_p" type="webcontent" href="wiki_content/p.html">'
            . '<file href="wiki_content/p.html"/></resource>'
            . '<resource identifier="r_fb" type="webcontent" href="fb/a.html">'
            . '<cpx:variant identifierref="r_asg"/><file href="fb/a.html"/></resource>'
            . '<resource identifier="r_asg" type="assignment_xmlv1p0" href="asg/a.xml"><file href="asg/a.xml"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo($report['courseid']);
        $assigns = $modinfo->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assigncm = reset($assigns);
        $pages = $modinfo->get_instances_of('page');
        $pagecm = reset($pages);

        $page = $DB->get_record('page', ['id' => $pagecm->instance]);
        // The relative link to the suppressed fallback path resolves to the
        // assignment the variant swapped in.
        $this->assertStringContainsString('/mod/assign/view.php?id=' . $assigncm->id, $page->content);
        $this->assertStringNotContainsString('../fb/a.html', $page->content);
    }

    /**
     * page_payload::basedir() resolves against the file actually read: when a
     * page item lists a readable file in one folder and an href in another,
     * locate() uses the file list first, so the base directory must match the
     * folder of that file (not the href).
     *
     * @return void
     */
    public function test_basedir_uses_file_actually_read(): void {
        $root = make_request_directory();
        mkdir($root . '/real');
        file_put_contents($root . '/real/page.html', '<p>Body.</p>');

        $modelitem = new item('r1', 'Page');
        // The locate() order tries files[] before href; only the file is readable.
        $modelitem->files = ['real/page.html'];
        $modelitem->href = 'ghost/page.html';

        $this->assertSame('real', page_payload::basedir($root, $modelitem));
    }

    /**
     * A bundle asset whose filename contains a space still resolves after the
     * ILIAS cleaner's DOM pass: the cleaner percent-encodes the path in the
     * stored HTML, but bundle rewriting decodes the candidate so it still maps
     * to @@PLUGINFILE@@ and the file is imported, rather than 404ing.
     *
     * @return void
     */
    public function test_bundle_asset_with_space_resolves_after_cleaning(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $dir = make_request_directory();
        mkdir($dir . '/lm/data', 0777, true);
        file_put_contents($dir . '/lm/delos_cont.css', '.ilc {}');
        // Two assets whose names carry characters that must be URL-encoded: a
        // space and a reserved "#" (the latter would otherwise read as a URL
        // fragment).
        file_put_contents($dir . '/lm/data/my pic.png', $png);
        file_put_contents($dir . '/lm/data/a#b.png', $png);
        file_put_contents(
            $dir . '/lm/index.html',
            '<html><head><link rel="stylesheet" href="delos_cont.css"></head><body>'
            . '<table class="ilc_page_cont_PageContainer"><tr><td>'
            . '<table class="ilc_table_MainTable"><tr>'
            . '<td width="275"><table class="ilc_table_Navigation"><tr><td>Activities</td></tr></table></td>'
            . '<td><table class="ilc_table_TextTable"><tr><td class="ilc_table_cell_Cell2">'
            . '<p>Body</p><img src="data/my pic.png" alt="pic"><img src="data/a%23b.png" alt="hash">'
            . '</td></tr></table></td>'
            . '</tr></table></td></tr></table></body></html>'
        );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org"><item identifier="root">'
            . '<item identifier="i1" identifierref="r_lm"><title>Module</title></item>'
            . '</item></organization></organizations>'
            . '<resources><resource identifier="r_lm" type="webcontent" href="lm/index.html">'
            . '<file href="lm/index.html"/><file href="lm/delos_cont.css"/>'
            . '<file href="lm/data/my pic.png"/><file href="lm/data/a#b.png"/>'
            . '</resource></resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $pages = get_fast_modinfo($report['courseid'])->get_instances_of('page');
        $pagecm = reset($pages);
        $page = $DB->get_record('page', ['id' => $pagecm->instance], '*', MUST_EXIST);

        // The cleaner removed the navigation but kept the content images, and
        // bundle rewriting matched the encoded paths and re-emitted them encoded
        // so the reserved characters survive as a valid URL.
        $this->assertStringNotContainsString('Activities', $page->content);
        $this->assertStringContainsString('@@PLUGINFILE@@/data/my%20pic.png', $page->content);
        $this->assertStringContainsString('@@PLUGINFILE@@/data/a%23b.png', $page->content);
        // The decoded (broken) forms must not be emitted.
        $this->assertStringNotContainsString('@@PLUGINFILE@@/data/my pic.png', $page->content);
        $this->assertStringNotContainsString('@@PLUGINFILE@@/data/a#b.png', $page->content);

        $context = \context_module::instance($pagecm->id);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/data/', 'my pic.png'));
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/data/', 'a#b.png'));
    }
}
