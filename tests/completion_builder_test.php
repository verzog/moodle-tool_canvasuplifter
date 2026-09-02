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
 * End-to-end test for Canvas module prerequisites -> Moodle availability + completion.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\completion_builder
 */
final class completion_builder_test extends \advanced_testcase {
    /**
     * Write a two-module package where module B lists module A as a prerequisite, and module A's
     * page carries a must_view completion requirement.
     *
     * @return string Path to the package root.
     */
    private function two_module_package(): string {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a.html', '<html><head><title>A</title></head><body>Page A</body></html>');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>Page B</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a" type="webcontent" href="wiki_content/a.html"><file href="wiki_content/a.html"/></resource>
    <resource identifier="r_b" type="webcontent" href="wiki_content/b.html"><file href="wiki_content/b.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA">
    <title>Module A</title><workflow_state>active</workflow_state>
    <items><item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page A</title><identifierref>r_a</identifierref></item></items>
  </module>
  <module identifier="modB">
    <title>Module B</title><workflow_state>active</workflow_state>
    <prerequisites><prerequisite type="context_module"><title>Module A</title>
      <identifierref>modA</identifierref></prerequisite></prerequisites>
    <items><item identifier="mi_b"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page B</title><identifierref>r_b</identifierref></item></items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);
        return $dir;
    }

    /**
     * Module B's Canvas prerequisite on module A becomes a section-level "Restrict access" rule
     * on B requiring completion of A's activity, A's activity gets automatic view-completion, and
     * course completion is enabled.
     *
     * @return void
     */
    public function test_prerequisite_becomes_section_availability_and_completion(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = $this->two_module_package();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $courseid = (int) $report['courseid'];
        // Course completion is enabled.
        $this->assertEquals(1, $DB->get_field('course', 'enablecompletion', ['id' => $courseid]));

        $modinfo = get_fast_modinfo($courseid);
        $sections = $modinfo->get_sections();
        // Section 1 = Module A, section 2 = Module B (built in module order).
        $acmid = (int) reset($sections[1]);
        $bcmid = (int) reset($sections[2]);

        // Module A's activity now tracks completion automatically by view.
        $acm = $DB->get_record('course_modules', ['id' => $acmid], 'id, completion, completionview');
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, (int) $acm->completion);
        $this->assertEquals(1, (int) $acm->completionview);

        // Module B's section carries an availability restriction requiring A's completion.
        $bsectionid = (int) $modinfo->get_section_info(2)->id;
        $availability = $DB->get_field('course_sections', 'availability', ['id' => $bsectionid]);
        $this->assertNotEmpty($availability);
        $tree = json_decode($availability);
        $this->assertSame('&', $tree->op);
        $cms = array_map(static fn($c) => (int) $c->cm, $tree->c);
        $this->assertContains($acmid, $cms);
        $this->assertNotContains($bcmid, $cms);
        foreach ($tree->c as $condition) {
            $this->assertSame('completion', $condition->type);
            $this->assertSame(COMPLETION_COMPLETE, (int) $condition->e);
        }

        // The build report notes the gating.
        $this->assertStringContainsString(
            get_string('notegatingimported', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * A prerequisite module whose only activity is unpublished (hidden) cannot be gated on —
     * students could never complete a hidden activity — so no restriction is written and the
     * prerequisite is reported unresolved rather than locking the dependent section forever.
     *
     * @return void
     */
    public function test_hidden_prerequisite_activity_is_not_gated(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a.html', '<html><head><title>A</title></head><body>A</body></html>');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>B</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a" type="webcontent" href="wiki_content/a.html"><file href="wiki_content/a.html"/></resource>
    <resource identifier="r_b" type="webcontent" href="wiki_content/b.html"><file href="wiki_content/b.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        // Module A's page is unpublished (hidden); module B requires module A.
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Module A</title><workflow_state>active</workflow_state>
    <items><item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>unpublished</workflow_state>
      <title>Page A</title><identifierref>r_a</identifierref></item></items>
  </module>
  <module identifier="modB"><title>Module B</title><workflow_state>active</workflow_state>
    <prerequisites><prerequisite type="context_module"><title>Module A</title>
      <identifierref>modA</identifierref></prerequisite></prerequisites>
    <items><item identifier="mi_b"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page B</title><identifierref>r_b</identifierref></item></items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $modinfo = get_fast_modinfo((int) $report['courseid']);
        $bsectionid = (int) $modinfo->get_section_info(2)->id;
        $this->assertEmpty($DB->get_field('course_sections', 'availability', ['id' => $bsectionid]));
        $this->assertStringContainsString(
            get_string('warngatingunresolved', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * When completion tracking is disabled site-wide, gating is skipped (a rule could never be
     * met) and reported, rather than enabling the course flag and writing dead restrictions.
     *
     * @return void
     */
    public function test_site_completion_disabled_skips_gating(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 0);

        $dir = $this->two_module_package();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $courseid = (int) $report['courseid'];
        $this->assertEquals(0, $DB->get_field('course', 'enablecompletion', ['id' => $courseid]));
        $bsectionid = (int) get_fast_modinfo($courseid)->get_section_info(2)->id;
        $this->assertEmpty($DB->get_field('course_sections', 'availability', ['id' => $bsectionid]));
        $this->assertStringContainsString(
            get_string('warngatingsitecompletion', 'tool_canvasuplifter'),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * A package with no module prerequisites gates nothing and does not enable course completion.
     *
     * @return void
     */
    public function test_no_prerequisites_leaves_course_ungated(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a.html', '<html><head><title>A</title></head><body>A</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a" type="webcontent" href="wiki_content/a.html"><file href="wiki_content/a.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Module A</title><workflow_state>active</workflow_state>
    <items><item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page A</title><identifierref>r_a</identifierref></item></items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $courseid = (int) $report['courseid'];
        $this->assertEquals(0, $DB->get_field('course', 'enablecompletion', ['id' => $courseid]));
        $this->assertStringNotContainsString('Restrict access', implode("\n", $report['warnings']));
    }
}
