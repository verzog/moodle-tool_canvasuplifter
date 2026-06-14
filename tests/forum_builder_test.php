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
 * End-to-end test for the discussion-topic forum builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\forum_builder
 */
final class forum_builder_test extends \advanced_testcase {
    /**
     * Write a package with one discussion topic referenced in the course tree.
     *
     * @return string Path to the package root.
     */
    private function build_discussion_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        $topic = '<?xml version="1.0" encoding="utf-8"?>'
            . '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Class Discussion</title>'
            . '<text texttype="text/html">&lt;p&gt;Introduce yourself here.&lt;/p&gt;</text>'
            . '</topic>';
        file_put_contents($dir . '/discussion/d1.xml', $topic);
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Module 1</title>
          <item identifier="i_disc" identifierref="r_disc"><title>Class Discussion</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r_disc" type="imsdt_xmlv1p1">
      <file href="discussion/d1.xml"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * A referenced discussion becomes a forum whose opening post carries the prompt.
     *
     * @return void
     */
    public function test_discussion_builds_forum_with_opening_post(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_discussion_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(1, $report['createdcounts']['discussion'] ?? 0);

        // The course also has the auto-created Announcements forum (type "news"),
        // so scope to the general discussion forum we built.
        $forums = $DB->get_records('forum', ['course' => $report['courseid'], 'type' => 'general']);
        $this->assertCount(1, $forums);
        $forum = reset($forums);
        $this->assertSame('Class Discussion', $forum->name);

        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id]);
        $this->assertCount(1, $discussions);
        $discussion = reset($discussions);

        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);
        $this->assertNotEmpty($post);
        $this->assertStringContainsString('Introduce yourself here.', $post->message);
    }
}
