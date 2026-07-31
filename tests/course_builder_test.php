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
use tool_canvasuplifter\local\parser\source_detector;
use tool_canvasuplifter\local\model\course_model;

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
        $this->assertEquals(2, $resourcecm->sectionnum);
    }

    /**
     * Write a package whose section structure comes from module_meta.xml and
     * carries an unpublished page, a ContextModuleSubHeader and an inline
     * ExternalUrl with no imswl resource.
     *
     * @return string Path to the package root.
     */
    protected function build_module_meta_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        mkdir($dir . '/course_settings');
        file_put_contents($dir . '/wiki_content/welcome.html', '<p>Welcome</p>');
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
        $modulemeta = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">
  <module identifier="mod1">
    <title>Week 1</title>
    <items>
      <item identifier="mi_page">
        <content_type>WikiPage</content_type>
        <workflow_state>unpublished</workflow_state>
        <title>Welcome</title>
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
        return $dir;
    }

    /**
     * Builds the module_meta.xml fixture: the page is hidden (unpublished), the
     * subheader becomes a mod_label, and the inline ExternalUrl becomes a mod_url.
     *
     * @return void
     */
    public function test_build_honours_module_meta_visibility_subheader_and_inline_url(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_module_meta_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['page'] ?? 0);
        $this->assertSame(1, $report['createdcounts']['subheader'] ?? 0);
        $this->assertSame(1, $report['createdcounts']['url'] ?? 0);

        $modinfo = get_fast_modinfo($report['courseid']);

        // The unpublished page is built but hidden on the course page.
        $pages = $modinfo->get_instances_of('page');
        $this->assertCount(1, $pages);
        $this->assertEquals(0, reset($pages)->visible);

        // The subheader landed as a label carrying its title.
        $labels = $modinfo->get_instances_of('label');
        $this->assertCount(1, $labels);
        $label = $DB->get_record('label', ['id' => reset($labels)->instance]);
        $this->assertStringContainsString('Before Class', $label->intro);

        // The inline ExternalUrl created a mod_url pointing at YouTube.
        $urls = $modinfo->get_instances_of('url');
        $this->assertCount(1, $urls);
        $url = $DB->get_record('url', ['id' => reset($urls)->instance]);
        $this->assertSame('https://www.youtube.com/', $url->externalurl);
    }

    /**
     * Building a package with assignment_groups.xml + a weighted gradebook
     * creates a Moodle grade category per Canvas group, sets the course-level
     * aggregation to weighted mean, applies the group_weight as the category's
     * aggregationcoef, and re-parents each built assignment's grade item into
     * the matching category.
     *
     * @return void
     */
    public function test_build_creates_weighted_grade_categories(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

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
            . '<assignmentGroup identifier="g_part"><title>Participation</title>'
            . '<position>1</position><group_weight>15.0</group_weight></assignmentGroup>'
            . '<assignmentGroup identifier="g_final"><title>Final</title>'
            . '<position>2</position><group_weight>30.0</group_weight></assignmentGroup>'
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
        file_put_contents($dir . '/a1/a1.html', '<p>Essay.</p>');
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
              href="a1/a1.html">
      <file href="a1/a1.html"/>
      <file href="a1/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // Both Canvas groups became grade categories under the course, with
        // their weights stored on the category's grade_item as a custom-weight
        // override (Natural aggregation). aggregationcoef2 is a fraction of 1.0.
        $partcats = $DB->get_records(
            'grade_categories',
            ['courseid' => $report['courseid'], 'fullname' => 'Participation']
        );
        $this->assertCount(1, $partcats);
        $partcat = \grade_category::fetch(['id' => reset($partcats)->id]);
        $partitem = $partcat->load_grade_item();
        $this->assertEqualsWithDelta(0.15, (float) $partitem->aggregationcoef2, 0.0001);
        $this->assertEquals(1, $partitem->weightoverride);

        $finalcats = $DB->get_records('grade_categories', ['courseid' => $report['courseid'], 'fullname' => 'Final']);
        $this->assertCount(1, $finalcats);
        $finalcat = \grade_category::fetch(['id' => reset($finalcats)->id]);
        $finalitem = $finalcat->load_grade_item();
        $this->assertEqualsWithDelta(0.30, (float) $finalitem->aggregationcoef2, 0.0001);
        $this->assertEquals(1, $finalitem->weightoverride);

        // Course-level aggregation is Natural (custom weights honoured per child).
        $coursecat = \grade_category::fetch_course_category($report['courseid']);
        $this->assertEquals(GRADE_AGGREGATE_SUM, $coursecat->aggregation);

        // The built assignment's grade item lives under the Participation category.
        $assigns = get_fast_modinfo($report['courseid'])->get_instances_of('assign');
        $assigncm = reset($assigns);
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assigncm->instance,
            'courseid' => $report['courseid'],
        ]);
        $this->assertEquals($partcat->id, $gradeitem->categoryid);
    }

    /**
     * Building a package with an imsbasiclti cartridge creates a mod_lti
     * placeholder pointing at the cartridge's launch URL, with credentials
     * left blank for the admin to fill in.
     *
     * @return void
     */
    public function test_build_creates_lti_placeholder(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        file_put_contents(
            $dir . '/lti.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"'
            . ' xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"'
            . ' xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0">'
            . '<blti:title>Publisher Tool</blti:title>'
            . '<blti:description>External courseware</blti:description>'
            . '<blti:launch_url>https://tool.example.edu/launch</blti:launch_url>'
            . '<blti:custom>'
            . '<lticm:property name="resource_link_id">deep-link-xyz</lticm:property>'
            . '</blti:custom>'
            . '</cartridge_basiclti_link>'
        );
        file_put_contents($dir . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_lti" identifierref="r_lti"><title>Publisher Tool</title></item>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_lti" type="imsbasiclti_xmlv1p0" href="lti.xml">'
            . '<file href="lti.xml"/></resource>'
            . '</resources></manifest>');

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new \tool_canvasuplifter\local\parser\manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['lti'] ?? 0);

        $cms = get_fast_modinfo($report['courseid'])->get_instances_of('lti');
        $this->assertCount(1, $cms);
        $cm = reset($cms);
        $this->assertSame('Publisher Tool', $cm->get_name());

        $lti = $DB->get_record('lti', ['id' => $cm->instance]);
        $this->assertSame('https://tool.example.edu/launch', $lti->toolurl);
        // Per-activity tool (not a preconfigured registry entry).
        $this->assertEquals(0, $lti->typeid);
        // No credentials carried across.
        $this->assertSame('', (string) $lti->resourcekey);
        $this->assertSame('', (string) $lti->password);
        // The intro carries the per-site reminder for the admin.
        $this->assertStringContainsString('hidden placeholder', $lti->intro);
        // Custom parameters from the cartridge survive in newline-separated form.
        $this->assertStringContainsString('resource_link_id=deep-link-xyz', (string) $lti->instructorcustomparameters);
        // The activity starts hidden so a URL-matched preconfigured tool can't
        // auto-launch with privacy settings the admin hasn't reviewed.
        $this->assertEquals(0, $cm->visible);
    }

    /**
     * Building a package with a Canvas rubric attached to an assignment
     * installs a Moodle gradingform_rubric definition on the activity's
     * submissions grading area and activates rubric as the grading method
     * when <rubric_use_for_grading> is true.
     *
     * @return void
     */
    public function test_build_attaches_canvas_rubric_to_assignment(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/course_settings/rubrics.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rubrics xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric identifier="r_one">'
            . '<title>Essay Rubric</title>'
            . '<criteria><criterion>'
            . '<criterion_id>_a</criterion_id><points>5.0</points>'
            . '<description>Argument</description>'
            . '<ratings>'
            . '<rating><description>Full</description><points>5.0</points>'
            . '<long_description>Argument is clear, well supported and original.</long_description>'
            . '<criterion_id>_a</criterion_id><id>r1</id></rating>'
            . '<rating><description>None</description><points>0.0</points>'
            . '<criterion_id>_a</criterion_id><id>r2</id></rating>'
            . '</ratings></criterion></criteria></rubric></rubrics>'
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
        file_put_contents($dir . '/a1/a1.html', '<p>Write an essay.</p>');
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
              href="a1/a1.html">
      <file href="a1/a1.html"/>
      <file href="a1/assignment_settings.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // The assignment was built and rubric is the active grading method.
        $assigns = get_fast_modinfo($report['courseid'])->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assigncm = reset($assigns);

        $context = \context_module::instance($assigncm->id);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $this->assertSame('rubric', $gradingmanager->get_active_method());

        // The definition carries one criterion with two levels, scored 0 and 5.
        $controller = $gradingmanager->get_controller('rubric');
        $definition = $controller->get_definition();
        $this->assertSame('Essay Rubric', $definition->name);
        $this->assertCount(1, $definition->rubric_criteria);
        $criterion = reset($definition->rubric_criteria);
        $this->assertSame('Argument', $criterion['description']);
        $scores = array_column($criterion['levels'], 'score');
        sort($scores);
        $this->assertEqualsWithDelta([0.0, 5.0], $scores, 0.0001);
        // Canvas's per-rating <long_description> is appended onto the matching
        // level's definition so graders see the full descriptor.
        $bypoints = array_column($criterion['levels'], 'definition', 'score');
        $this->assertStringContainsString('Full', (string) $bypoints[5.0]);
        $this->assertStringContainsString('Argument is clear', (string) $bypoints[5.0]);
    }

    /**
     * Building a CC 1.3 IMS Assignment profile package (no
     * assignment_settings.xml, description in <text>, Canvas extension nested
     * under <extensions>) produces a mod_assign with the description from the
     * profile and a Canvas rubric attached via the extension's
     * <rubric_identifierref>.
     *
     * @return void
     */
    public function test_build_handles_cc13_assignment_profile_with_rubric(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        mkdir($dir . '/a1');
        file_put_contents(
            $dir . '/course_settings/rubrics.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rubrics xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric identifier="r_cc13"><title>Lab Rubric</title>'
            . '<criteria><criterion><criterion_id>_a</criterion_id>'
            . '<points>5.0</points><description>Method</description>'
            . '<ratings><rating><description>Full</description><points>5.0</points></rating>'
            . '<rating><description>None</description><points>0.0</points></rating></ratings>'
            . '</criterion></criteria></rubric></rubrics>'
        );
        file_put_contents(
            $dir . '/a1/assignment.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment identifier="ia1" xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment">'
            . '<title>Lab report</title>'
            . '<text texttype="text/html"><![CDATA[<p>Write up the experiment.</p>]]></text>'
            . '<gradable points_possible="5">true</gradable>'
            . '<submission_formats><format type="html"/><format type="file"/></submission_formats>'
            . '<extensions>'
            . '<assignment xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<rubric_identifierref>r_cc13</rubric_identifierref>'
            . '<rubric_use_for_grading>true</rubric_use_for_grading>'
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

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $assigns = get_fast_modinfo($report['courseid'])->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assigncm = reset($assigns);
        $assign = $DB->get_record('assign', ['id' => $assigncm->instance], '*', MUST_EXIST);
        $this->assertSame('Lab report', $assign->name);
        $this->assertStringContainsString('Write up the experiment.', $assign->intro);
        // CC 1.3 <gradable points_possible="5"> drives mod_assign's grade.
        $this->assertEqualsWithDelta(5.0, (float) $assign->grade, 0.0001);

        // The Canvas extension's rubric_identifierref drives grading.
        $context = \context_module::instance($assigncm->id);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $this->assertSame('rubric', $gradingmanager->get_active_method());
    }

    /**
     * For CC 1.3 IMS Assignment profile packages, the profile's <text> is the
     * authoritative prompt. Any HTML attachment sitting alongside in the
     * resource's <file> list is a handout, not the assignment instructions,
     * and must not displace <text> in the imported intro.
     *
     * Also exercises the post-build link rewrite pass over assignment intros:
     * a $WIKI_REFERENCE$ placeholder in <text> resolves to the target page's
     * pluginfile URL after every activity is built.
     *
     * @return void
     */
    public function test_build_cc13_prefers_text_and_rewrites_links_in_intro(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/a1');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/syllabus.html', '<p>Syllabus body.</p>');
        // A sibling HTML attachment that must NOT win over the profile's
        // <text>; if it does, the imported intro would carry handout text
        // rather than the prompt.
        file_put_contents($dir . '/a1/attachment.html', '<p>This is a handout, not the prompt.</p>');
        file_put_contents(
            $dir . '/a1/assignment.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<assignment identifier="ia1" xmlns="http://www.imsglobal.org/xsd/imscc_extensions/assignment">'
            . '<title>Lab</title>'
            . '<text texttype="text/html">'
            . '<![CDATA[<p>See the <a href="$WIKI_REFERENCE$/pages/syllabus">syllabus</a>.</p>]]>'
            . '</text>'
            . '<gradable points_possible="5">true</gradable>'
            . '<submission_formats><format type="html"/></submission_formats>'
            . '</assignment>'
        );
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root"><item identifier="m1"><title>Week 1</title>
        <item identifier="i_syl" identifierref="r_syl"><title>Syllabus</title></item>
        <item identifier="i_assign" identifierref="r_assign"><title>Lab</title></item>
      </item></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_syl" type="webcontent" href="wiki_content/syllabus.html">
      <file href="wiki_content/syllabus.html"/>
    </resource>
    <resource identifier="r_assign" type="assignment_xmlv1p0" href="a1/assignment.xml">
      <file href="a1/assignment.xml"/>
      <file href="a1/attachment.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $assigns = get_fast_modinfo($report['courseid'])->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assigncm = reset($assigns);
        $assign = $DB->get_record('assign', ['id' => $assigncm->instance], '*', MUST_EXIST);

        // The profile's <text> wins; the handout sibling is left out.
        $this->assertStringContainsString('See the', $assign->intro);
        $this->assertStringNotContainsString('handout, not the prompt', $assign->intro);

        // The $WIKI_REFERENCE$ placeholder is resolved to the syllabus page
        // URL by the post-build link rewriter, not stored verbatim.
        $this->assertStringNotContainsString('$WIKI_REFERENCE$', $assign->intro);
        $this->assertMatchesRegularExpression('#/mod/page/view\.php\?id=\d+#', $assign->intro);
    }

    /**
     * A CC 1.3 IMS Assignment profile embedded inline inside <resource>
     * (no <file> child) builds end-to-end: the captured inline descriptor
     * lands on item::inlinexml and assign_builder consumes it directly so
     * the activity gets its title, intro, grade and submission type.
     *
     * @return void
     */
    public function test_build_handles_inline_cc13_assignment_descriptor(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"'
            . ' xmlns:cc="http://www.imsglobal.org/xsd/imscc_extensions/assignment"'
            . ' identifier="m">'
            . '<organizations><organization identifier="org1">'
            . '<item identifier="root"><item identifier="m1"><title>Week 1</title>'
            . '<item identifier="i1" identifierref="r1"><title>Inline Lab</title></item>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r1" type="assignment_xmlv1p0">'
            . '<cc:assignment identifier="a1">'
            . '<cc:title>Inline Lab</cc:title>'
            . '<cc:text texttype="text/html">&lt;p&gt;Inline prompt.&lt;/p&gt;</cc:text>'
            . '<cc:gradable points_possible="8">true</cc:gradable>'
            . '<cc:submission_formats><cc:format type="text"/></cc:submission_formats>'
            . '</cc:assignment>'
            . '</resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        $assigns = get_fast_modinfo($report['courseid'])->get_instances_of('assign');
        $this->assertCount(1, $assigns);
        $assign = $DB->get_record('assign', ['id' => reset($assigns)->instance], '*', MUST_EXIST);
        $this->assertSame('Inline Lab', $assign->name);
        $this->assertStringContainsString('Inline prompt', $assign->intro);
        $this->assertEqualsWithDelta(8.0, (float) $assign->grade, 0.0001);
        // The `text` submission_format maps to mod_assign's online_text_entry plugin.
        $this->assertEquals(
            1,
            (int) $DB->get_field(
                'assign_plugin_config',
                'value',
                ['assignment' => $assign->id, 'plugin' => 'onlinetext', 'subtype' => 'assignsubmission', 'name' => 'enabled']
            )
        );
    }

    /**
     * Write a package whose only syllabus + a stray file are unreferenced.
     *
     * @return string Path to the package root.
     */
    protected function build_syllabus_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/welcome.html', '<p>Welcome</p>');
        file_put_contents(
            $dir . '/wiki_content/g0658_syllabus.html',
            '<html><head><title>Course Syllabus</title></head><body><p>The syllabus.</p></body></html>'
        );
        mkdir($dir . '/web_resources');
        file_put_contents($dir . '/web_resources/handout.pdf', '%PDF-1.4 test');
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
    <resource identifier="r_syllabus" type="webcontent" href="wiki_content/g0658_syllabus.html">
      <file href="wiki_content/g0658_syllabus.html"/>
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
     * The syllabus lands in the top section with its real title; other orphans
     * go to "Additional resources".
     *
     * @return void
     */
    public function test_build_places_syllabus_at_top(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_syllabus_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // Original section + the "Additional resources" section (syllabus sits in section 0).
        $this->assertSame(2, $report['sectioncount']);

        $modinfo = get_fast_modinfo($report['courseid']);
        $syllabus = null;
        foreach ($modinfo->get_instances_of('page') as $cm) {
            if ($cm->get_name() === 'Course Syllabus') {
                $syllabus = $cm;
            }
        }
        $this->assertNotNull($syllabus, 'syllabus page should be created with its HTML title');
        $this->assertEquals(0, $syllabus->sectionnum);

        // The stray file is not treated as syllabus; it goes to Additional resources.
        $resources = $modinfo->get_instances_of('resource');
        $this->assertCount(1, $resources);
        $this->assertEquals(2, reset($resources)->sectionnum);
    }

    /**
     * An eXe/IGEN-style lesson bundle should be built as a single mod_page,
     * with the sibling CSS/JS/images imported into the page's content
     * filearea and the relative URLs in the HTML rewritten to Moodle's
     * pluginfile syntax so the page actually renders.
     *
     * @return void
     */
    public function test_build_imports_lesson_bundle_assets_into_page(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/unit1');
        mkdir($dir . '/unit1/assets');
        file_put_contents(
            $dir . '/unit1/index.html',
            '<html><head><title>Unit 1</title>'
            . '<link rel="stylesheet" href="igencp.css?v=1">'
            . '<script src="./jquery.js"></script>'
            . '</head><body>'
            . '<img src="assets/head_back.gif#crop=top">'
            . '<a href="https://example.com/external.css">leave me alone</a>'
            . '</body></html>'
        );
        file_put_contents($dir . '/unit1/igencp.css', '/* skin */');
        file_put_contents($dir . '/unit1/delos_cont.css', '/* skin */');
        file_put_contents($dir . '/unit1/jquery.js', '// noise');
        file_put_contents($dir . '/unit1/assets/head_back.gif', 'GIF');

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations><organization identifier="o"><item identifier="root"/></organization></organizations>
  <resources>
    <resource identifier="r_idx" type="webcontent" href="unit1/index.html"><file href="unit1/index.html"/></resource>
    <resource identifier="r_a" type="webcontent" href="unit1/igencp.css"><file href="unit1/igencp.css"/></resource>
    <resource identifier="r_b" type="webcontent" href="unit1/delos_cont.css">
      <file href="unit1/delos_cont.css"/>
    </resource>
    <resource identifier="r_jq" type="webcontent" href="unit1/jquery.js"><file href="unit1/jquery.js"/></resource>
    <resource identifier="r_img" type="webcontent" href="unit1/assets/head_back.gif">
      <file href="unit1/assets/head_back.gif"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // One page built; no stray mod_resource activities for the framework.
        $pages = get_fast_modinfo($report['courseid'])->get_instances_of('page');
        $this->assertCount(1, $pages);
        $resourcecms = get_fast_modinfo($report['courseid'])->get_instances_of('resource');
        $this->assertCount(0, $resourcecms);

        $pagecm = reset($pages);
        $page = $DB->get_record('page', ['id' => $pagecm->instance]);
        // Relative refs got rewritten to pluginfile, including cache-busting
        // ?suffix and #fragment which must survive the rewrite. The "./"
        // relative form normalises to the bare relpath. Absolute URLs to
        // unrelated hosts are left alone.
        $this->assertStringContainsString('@@PLUGINFILE@@/igencp.css?v=1', $page->content);
        $this->assertStringContainsString('@@PLUGINFILE@@/jquery.js', $page->content);
        $this->assertStringNotContainsString('"./jquery.js"', $page->content);
        $this->assertStringContainsString('@@PLUGINFILE@@/assets/head_back.gif#crop=top', $page->content);
        $this->assertStringContainsString('https://example.com/external.css', $page->content);

        // Asset files actually landed in the page's content filearea.
        $fs = get_file_storage();
        $context = \context_module::instance($pagecm->id);
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/', 'igencp.css'));
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/', 'jquery.js'));
        $this->assertTrue($fs->file_exists($context->id, 'mod_page', 'content', 0, '/assets/', 'head_back.gif'));
    }

    /**
     * A title-less package is named after its detected source LMS, except a
     * generic (unrecognised) package, which uses the neutral default rather than
     * the "Common Cartridge" source label.
     *
     * @param string $source The detected source constant.
     * @param string $expected The expected course full name.
     * @return void
     * @dataProvider default_course_name_provider
     */
    public function test_titleless_course_named_after_source(string $source, string $expected): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $coursemodel = new course_model();
        $coursemodel->source = $source;
        $category = $this->getDataGenerator()->create_category();

        $report = (new course_builder($category->id, make_request_directory()))->build($coursemodel);

        $this->assertSame($expected, $DB->get_field('course', 'fullname', ['id' => $report['courseid']]));
    }

    /**
     * Data provider for {@see test_titleless_course_named_after_source}.
     *
     * @return array
     */
    public static function default_course_name_provider(): array {
        return [
            'd2l' => [source_detector::D2L, 'Imported D2L Brightspace course'],
            'canvas' => [source_detector::CANVAS, 'Imported Canvas course'],
            'generic' => [source_detector::GENERIC, 'Imported course'],
            'unknown' => ['', 'Imported course'],
        ];
    }
}
