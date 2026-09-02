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
     * A min_score completion requirement on a prerequisite activity sets that activity's grade
     * item passing threshold (gradepass) and configures pass-grade completion, and the dependent
     * section's restriction expects a passing completion state (COMPLETION_COMPLETE_PASS) rather
     * than the generic COMPLETION_COMPLETE, which would also accept a failing grade.
     *
     * @return void
     */
    public function test_min_score_prerequisite_sets_gradepass_and_pass_state(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        mkdir($dir . '/asg');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>Page B</body></html>');
        file_put_contents($dir . '/asg/assignment.html', '<html><head><title>Task</title></head><body>Do it</body></html>');
        file_put_contents($dir . '/asg/assignment_settings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<assignment identifier="asg1" xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <title>Graded task</title>
  <grading_type>points</grading_type>
  <points_possible>100</points_possible>
  <submission_types>online_text_entry</submission_types>
  <workflow_state>published</workflow_state>
</assignment>
XML);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="asg1" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="asg/assignment.html">
      <file href="asg/assignment.html"/><file href="asg/assignment_settings.xml"/>
    </resource>
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
    <items><item identifier="mi_a"><content_type>Assignment</content_type><workflow_state>active</workflow_state>
      <title>Graded task</title><identifierref>asg1</identifierref>
      <completion_requirement><type>min_score</type><min_score>80</min_score></completion_requirement></item></items>
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

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);
        $courseid = (int) $report['courseid'];

        $modinfo = get_fast_modinfo($courseid);
        $sections = $modinfo->get_sections();
        $acmid = (int) reset($sections[1]);
        $acm = $modinfo->get_cm($acmid);
        $this->assertSame('assign', $acm->modname);

        // The assignment now requires a passing grade for completion.
        $record = $DB->get_record('course_modules', ['id' => $acmid], 'completion, completionpassgrade, completiongradeitemnumber');
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, (int) $record->completion);
        $this->assertEquals(1, (int) $record->completionpassgrade);
        $this->assertEquals(0, (int) $record->completiongradeitemnumber);

        // Its grade item carries the passing threshold scaled from Canvas's min_score.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $acm->instance,
            'itemnumber' => 0, 'courseid' => $courseid,
        ]);
        $this->assertNotFalse($gradeitem);
        $this->assertEqualsWithDelta(80.0, (float) $gradeitem->gradepass, 0.001);

        // Module B's section restriction expects a *passing* completion state, not a bare complete.
        $bsectionid = (int) $modinfo->get_section_info(2)->id;
        $tree = json_decode($DB->get_field('course_sections', 'availability', ['id' => $bsectionid]));
        $matched = false;
        foreach ($tree->c as $condition) {
            if ((int) $condition->cm === $acmid) {
                $matched = true;
                $this->assertSame(COMPLETION_COMPLETE_PASS, (int) $condition->e);
            }
        }
        $this->assertTrue($matched, 'Expected a completion condition on the assignment.');
    }

    /**
     * When a prerequisite module contains a Canvas-required item (one carrying a
     * completion_requirement) that fails to build — here an unsupported resource kind — the
     * surviving activity alone must not satisfy the gate: the whole prerequisite is reported
     * unresolved rather than writing a restriction that under-restricts the dependent section.
     *
     * @return void
     */
    public function test_dropped_required_item_marks_prerequisite_unresolved(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a.html', '<html><head><title>A</title></head><body>A</body></html>');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>B</body></html>');
        file_put_contents($dir . '/x.dat', 'binary');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a" type="webcontent" href="wiki_content/a.html"><file href="wiki_content/a.html"/></resource>
    <resource identifier="r_x" type="canvas/unsupported-widget" href="x.dat"><file href="x.dat"/></resource>
    <resource identifier="r_b" type="webcontent" href="wiki_content/b.html"><file href="wiki_content/b.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        // Module A has a normal page plus a required item of an unsupported kind (won't build).
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Module A</title><workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
        <title>Page A</title><identifierref>r_a</identifierref></item>
      <item identifier="mi_x"><content_type>Widget</content_type><workflow_state>active</workflow_state>
        <title>Required widget</title><identifierref>r_x</identifierref>
        <completion_requirement><type>must_view</type></completion_requirement></item>
    </items>
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
     * A required item that builds into a hidden activity (here a Canvas-unpublished page; the same
     * holds for an empty quiz kept as a hidden placeholder) returns a valid course module, but the
     * completion pass excludes it as non-viewable. Its module must therefore be reported unresolved
     * rather than letting a visible sibling activity alone satisfy the dependent section's gate.
     *
     * @return void
     */
    public function test_hidden_built_required_item_marks_prerequisite_unresolved(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a1.html', '<html><head><title>A1</title></head><body>A1</body></html>');
        file_put_contents($dir . '/wiki_content/a2.html', '<html><head><title>A2</title></head><body>A2</body></html>');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>B</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a1" type="webcontent" href="wiki_content/a1.html"><file href="wiki_content/a1.html"/></resource>
    <resource identifier="r_a2" type="webcontent" href="wiki_content/a2.html"><file href="wiki_content/a2.html"/></resource>
    <resource identifier="r_b" type="webcontent" href="wiki_content/b.html"><file href="wiki_content/b.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        // Module A: a visible non-required page plus an unpublished page carrying the requirement.
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Module A</title><workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_a1"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
        <title>Visible page</title><identifierref>r_a1</identifierref></item>
      <item identifier="mi_a2"><content_type>WikiPage</content_type><workflow_state>unpublished</workflow_state>
        <title>Required hidden page</title><identifierref>r_a2</identifierref>
        <completion_requirement><type>must_view</type></completion_requirement></item>
    </items>
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
     * When consecutive pages are combined into a single book, a must_view completion requirement
     * on one of the grouped pages is carried onto the resulting book activity rather than lost.
     *
     * @return void
     */
    public function test_grouped_page_completion_requirement_tracked(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

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
  <module identifier="modA"><title>Reading</title><workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
        <title>Page A</title><identifierref>r_a</identifierref></item>
      <item identifier="mi_b"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
        <title>Page B</title><identifierref>r_b</identifierref>
        <completion_requirement><type>must_view</type></completion_requirement></item>
    </items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        // The sixth constructor argument selects the "combine consecutive pages into a book" option.
        $report = (new course_builder($category->id, $dir, null, 0, false, 'book'))->build($coursemodel);
        $courseid = (int) $report['courseid'];

        $book = $DB->get_record('book', ['course' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('book', $book->id, $courseid, false, MUST_EXIST);
        $record = $DB->get_record('course_modules', ['id' => $cm->id], 'completion, completionview');
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, (int) $record->completion);
        $this->assertEquals(1, (int) $record->completionview);
    }

    /**
     * A required page that is unpublished but folded into a visible book/lesson group becomes a
     * hidden chapter; completing the visible group must not satisfy it, so its module's
     * prerequisite is reported unresolved rather than gated on the visible group alone.
     *
     * @return void
     */
    public function test_hidden_grouped_required_page_marks_prerequisite_unresolved(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/a1.html', '<html><head><title>A1</title></head><body>A1</body></html>');
        file_put_contents($dir . '/wiki_content/a2.html', '<html><head><title>A2</title></head><body>A2</body></html>');
        file_put_contents($dir . '/wiki_content/b.html', '<html><head><title>B</title></head><body>B</body></html>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_a1" type="webcontent" href="wiki_content/a1.html"><file href="wiki_content/a1.html"/></resource>
    <resource identifier="r_a2" type="webcontent" href="wiki_content/a2.html"><file href="wiki_content/a2.html"/></resource>
    <resource identifier="r_b" type="webcontent" href="wiki_content/b.html"><file href="wiki_content/b.html"/></resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        // Module A groups a visible page with an unpublished page that carries the requirement.
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Reading</title><workflow_state>active</workflow_state>
    <items>
      <item identifier="mi_a1"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
        <title>Visible page</title><identifierref>r_a1</identifierref></item>
      <item identifier="mi_a2"><content_type>WikiPage</content_type><workflow_state>unpublished</workflow_state>
        <title>Required hidden page</title><identifierref>r_a2</identifierref>
        <completion_requirement><type>must_view</type></completion_requirement></item>
    </items>
  </module>
  <module identifier="modB"><title>Module B</title><workflow_state>active</workflow_state>
    <prerequisites><prerequisite type="context_module"><title>Reading</title>
      <identifierref>modA</identifierref></prerequisite></prerequisites>
    <items><item identifier="mi_b"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page B</title><identifierref>r_b</identifierref></item></items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir, null, 0, false, 'book'))->build($coursemodel);

        $modinfo = get_fast_modinfo((int) $report['courseid']);
        $bsectionid = (int) $modinfo->get_section_info(2)->id;
        $this->assertEmpty($DB->get_field('course_sections', 'availability', ['id' => $bsectionid]));
        $this->assertStringContainsString(
            get_string('warngatingunresolved', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * A dependent section requiring several Canvas modules (an AND) is gated only when every one
     * resolves. If any prerequisite is unresolvable, no partial restriction is written — a rule on
     * the resolvable prerequisites alone would let the section unlock without the unresolved one.
     *
     * @return void
     */
    public function test_partial_prerequisites_write_no_restriction(): void {
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
        // Module B requires module A (resolvable) and module Z (never exported — unresolvable).
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA"><title>Module A</title><workflow_state>active</workflow_state>
    <items><item identifier="mi_a"><content_type>WikiPage</content_type><workflow_state>active</workflow_state>
      <title>Page A</title><identifierref>r_a</identifierref></item></items>
  </module>
  <module identifier="modB"><title>Module B</title><workflow_state>active</workflow_state>
    <prerequisites>
      <prerequisite type="context_module"><title>Module A</title><identifierref>modA</identifierref></prerequisite>
      <prerequisite type="context_module"><title>Module Z</title><identifierref>modZ</identifierref></prerequisite>
    </prerequisites>
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
        // No partial restriction: module A alone must not gate the section when module Z is missing.
        $this->assertEmpty($DB->get_field('course_sections', 'availability', ['id' => $bsectionid]));
        $this->assertStringContainsString(
            get_string('warngatingunresolved', 'tool_canvasuplifter', 1),
            implode("\n", $report['warnings'])
        );
    }

    /**
     * mod_assign stores an integer max grade, so a fractional Canvas points_possible (2.5) rounds
     * to 3. A 2.0 min_score threshold is then rescaled to keep its proportion — 80% of the rounded
     * maximum, i.e. gradepass 2.4 out of 3 — rather than a bare 2.0 out of 3 (66.7%).
     *
     * @return void
     */
    public function test_min_score_fractional_points_preserves_threshold(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/asg');
        file_put_contents($dir . '/asg/assignment.html', '<html><head><title>Task</title></head><body>Do it</body></html>');
        file_put_contents($dir . '/asg/assignment_settings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<assignment identifier="asg1" xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <title>Graded task</title>
  <grading_type>points</grading_type>
  <points_possible>2.5</points_possible>
  <submission_types>online_text_entry</submission_types>
  <workflow_state>published</workflow_state>
</assignment>
XML);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="org1"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="asg1" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="asg/assignment.html">
      <file href="asg/assignment.html"/><file href="asg/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="modA">
    <title>Module A</title><workflow_state>active</workflow_state>
    <items><item identifier="mi_a"><content_type>Assignment</content_type><workflow_state>active</workflow_state>
      <title>Graded task</title><identifierref>asg1</identifierref>
      <completion_requirement><type>min_score</type><min_score>2.0</min_score></completion_requirement></item></items>
  </module>
</modules>
XML;
        file_put_contents($dir . '/course_settings/module_meta.xml', $modulemeta);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);
        $courseid = (int) $report['courseid'];

        $modinfo = get_fast_modinfo($courseid);
        $acmid = (int) reset($modinfo->get_sections()[1]);
        $acm = $modinfo->get_cm($acmid);
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $acm->instance,
            'itemnumber' => 0, 'courseid' => $courseid,
        ]);
        $this->assertNotFalse($gradeitem);
        // The assignment's max grade is the rounded integer, and the threshold keeps its 80%.
        $this->assertEqualsWithDelta(3.0, (float) $gradeitem->grademax, 0.001);
        $this->assertEqualsWithDelta(2.4, (float) $gradeitem->gradepass, 0.001);
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
