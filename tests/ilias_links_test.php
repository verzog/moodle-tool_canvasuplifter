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
 * Tests importing an ILIAS-style export, where each learning module is a folded
 * lesson bundle (index.html + theme marker) and modules link to each other with
 * plain relative paths rather than Canvas placeholder tokens.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\course_builder
 * @covers     \tool_canvasuplifter\local\build\page_builder
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
}
