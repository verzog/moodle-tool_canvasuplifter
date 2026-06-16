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
        $this->assertEquals(FORMAT_HTML, $post->messageformat);
    }

    /**
     * Build a package containing one referenced discussion whose topic XML is
     * supplied, plus (optionally) extra resource/organisation XML.
     *
     * @param string $topicxml The <topic>…</topic> body.
     * @param string $extraresources Extra <resource> entries.
     * @param string $extraitems Extra organisation <item> entries under Module 1.
     * @return string Package root path.
     */
    private function package_with_topic(string $topicxml, string $extraresources = '', string $extraitems = ''): string {
        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        file_put_contents($dir . '/discussion/d1.xml', '<?xml version="1.0" encoding="utf-8"?>' . $topicxml);
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="org1"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_disc" identifierref="r_disc"><title>Class Discussion</title></item>'
            . $extraitems
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_disc" type="imsdt_xmlv1p1"><file href="discussion/d1.xml"/></resource>'
            . $extraresources
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The first post of the built forum.
     *
     * @param int $courseid The course id.
     * @return \stdClass
     */
    private function first_post(int $courseid): \stdClass {
        global $DB;
        $forum = $DB->get_record('forum', ['course' => $courseid, 'type' => 'general'], '*', MUST_EXIST);
        $discussion = $DB->get_record('forum_discussions', ['forum' => $forum->id], '*', MUST_EXIST);
        return $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
    }

    /**
     * A plain-text prompt (texttype="text/plain") is stored as plain text, not HTML.
     *
     * @return void
     */
    public function test_plain_text_prompt_kept_as_plain(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $topic = '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Plain</title><text texttype="text/plain">Use &lt; and &gt; freely.</text></topic>';
        $root = $this->package_with_topic($topic);
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($this->getDataGenerator()->create_category()->id, $root))->build($coursemodel);

        $post = $this->first_post($report['courseid']);
        $this->assertEquals(FORMAT_PLAIN, $post->messageformat);
        $this->assertStringContainsString('Use < and > freely.', $post->message);
    }

    /**
     * A topic attachment is imported as a forum post attachment.
     *
     * @return void
     */
    public function test_topic_attachment_is_imported(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = make_request_directory();
        mkdir($root . '/discussion');
        file_put_contents($root . '/discussion/brief.pdf', 'PDFBYTES');
        $topic = '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>With file</title><text texttype="text/html">&lt;p&gt;See brief&lt;/p&gt;</text>'
            . '<attachments><attachment href="discussion/brief.pdf"/></attachments></topic>';
        file_put_contents($root . '/discussion/d1.xml', '<?xml version="1.0" encoding="utf-8"?>' . $topic);
        file_put_contents($root . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>M1</title>'
            . '<item identifier="i_disc" identifierref="r_disc"><title>With file</title></item>'
            . '</item></item></organization></organizations>'
            . '<resources><resource identifier="r_disc" type="imsdt_xmlv1p1">'
            . '<file href="discussion/d1.xml"/><file href="discussion/brief.pdf"/></resource></resources></manifest>');

        $coursemodel = (new manifest_parser($root))->parse();
        $report = (new course_builder($this->getDataGenerator()->create_category()->id, $root))->build($coursemodel);

        $post = $this->first_post($report['courseid']);
        $this->assertSame('1', (string) $post->attachment);
        $context = \context_module::instance(get_coursemodule_from_instance(
            'forum',
            $DB->get_field('forum_discussions', 'forum', ['firstpost' => $post->id])
        )->id);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_forum',
            'attachment',
            $post->id,
            'itemid',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('brief.pdf', reset($files)->get_filename());
    }

    /**
     * Canvas internal-link placeholders in a prompt are resolved to the built
     * activity's URL in the second pass, like page links.
     *
     * @return void
     */
    public function test_prompt_internal_links_are_rewritten(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        mkdir($dir . '/wiki_content');
        file_put_contents($dir . '/wiki_content/intro.html', '<html><head><title>Intro</title></head><body>Hi</body></html>');
        $topic = '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Linky</title><text texttype="text/html">'
            . '&lt;p&gt;&lt;a href="$CANVAS_OBJECT_REFERENCE$/pages/r_page"&gt;go&lt;/a&gt;&lt;/p&gt;</text></topic>';
        file_put_contents($dir . '/discussion/d1.xml', '<?xml version="1.0" encoding="utf-8"?>' . $topic);
        file_put_contents($dir . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>M1</title>'
            . '<item identifier="i_page" identifierref="r_page"><title>Intro</title></item>'
            . '<item identifier="i_disc" identifierref="r_disc"><title>Linky</title></item>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_page" type="webcontent" href="wiki_content/intro.html">'
            . '<file href="wiki_content/intro.html"/></resource>'
            . '<resource identifier="r_disc" type="imsdt_xmlv1p1"><file href="discussion/d1.xml"/></resource>'
            . '</resources></manifest>');

        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($this->getDataGenerator()->create_category()->id, $dir))->build($coursemodel);

        $post = $this->first_post($report['courseid']);
        $this->assertStringContainsString('/mod/page/view.php', $post->message);
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $post->message);
    }

    /**
     * A Canvas announcement (imsdt with a topicMeta type="announcement" sibling)
     * gets posted to the course's auto-created Announcements forum (type="news")
     * as a discussion thread, rather than building a new forum activity per
     * announcement. No extra general forum is created.
     *
     * @return void
     */
    public function test_announcement_posts_to_news_forum(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        file_put_contents(
            $dir . '/discussion/a1.xml',
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Welcome to the course</title>'
            . '<text texttype="text/html">&lt;p&gt;Classes start Monday.&lt;/p&gt;</text>'
            . '</topic>'
        );
        // topicMeta declaring this discussion as an announcement.
        file_put_contents(
            $dir . '/discussion/a1_meta.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_announce</topic_id>'
            . '<title>Welcome to the course</title>'
            . '<type>announcement</type>'
            . '<workflow_state>active</workflow_state>'
            . '</topicMeta>'
        );
        file_put_contents($dir . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_announce" identifierref="r_announce"/>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_announce" type="imsdt_xmlv1p1">'
            . '<file href="discussion/a1.xml"/></resource>'
            . '<resource identifier="r_announce_meta" '
            . 'type="associatedcontent/imscc_xmlv1p1/learning-application-resource" '
            . 'href="discussion/a1_meta.xml"><file href="discussion/a1_meta.xml"/></resource>'
            . '</resources></manifest>');

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $dir))->build($coursemodel);

        // The announcement counts as a built discussion, but no extra general forum exists.
        $this->assertSame(1, $report['createdcounts']['discussion'] ?? 0);
        $generals = $DB->get_records('forum', ['course' => $report['courseid'], 'type' => 'general']);
        $this->assertCount(0, $generals);

        // The news forum picked up a new discussion thread with the announcement prompt.
        $news = $DB->get_record('forum', ['course' => $report['courseid'], 'type' => 'news'], '*', MUST_EXIST);
        $discussions = $DB->get_records('forum_discussions', ['forum' => $news->id]);
        $this->assertCount(1, $discussions);
        $discussion = reset($discussions);
        $this->assertSame('Welcome to the course', $discussion->name);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);
        $this->assertStringContainsString('Classes start Monday.', $post->message);
    }

    /**
     * An announcement whose topicMeta says workflow_state="unpublished" is not
     * posted to the news forum (skipped), even when module_meta doesn't list it.
     *
     * @return void
     */
    public function test_unpublished_announcement_is_skipped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        file_put_contents(
            $dir . '/discussion/a1.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>Draft</title><text texttype="text/html">Not ready.</text></topic>'
        );
        file_put_contents(
            $dir . '/discussion/a1_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_announce</topic_id>'
            . '<type>announcement</type>'
            . '<workflow_state>unpublished</workflow_state>'
            . '</topicMeta>'
        );
        file_put_contents($dir . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>M1</title>'
            . '<item identifier="i_announce" identifierref="r_announce"/>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_announce" type="imsdt_xmlv1p1">'
            . '<file href="discussion/a1.xml"/></resource>'
            . '<resource identifier="r_announce_meta" '
            . 'type="associatedcontent/imscc_xmlv1p1/learning-application-resource" '
            . 'href="discussion/a1_meta.xml"><file href="discussion/a1_meta.xml"/></resource>'
            . '</resources></manifest>');

        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($this->getDataGenerator()->create_category()->id, $dir))->build($coursemodel);

        $this->assertSame(0, $report['createdcounts']['discussion'] ?? 0);
        $this->assertSame(1, $report['skippedcounts']['discussion'] ?? 0);
        $news = $DB->get_record('forum', ['course' => $report['courseid'], 'type' => 'news'], '*', MUST_EXIST);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $news->id]));
    }

    /**
     * Internal Canvas links to a specific announcement should resolve to that
     * announcement's discussion thread, not the shared news forum index, even
     * when multiple announcements live in the same news forum. The page's
     * $CANVAS_OBJECT_REFERENCE$ link gets rewritten to /mod/forum/discuss.php?d=…
     *
     * @return void
     */
    public function test_announcement_link_rewriting_targets_discussion(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/discussion');
        mkdir($dir . '/wiki_content');
        file_put_contents(
            $dir . '/discussion/a1.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>News one</title><text texttype="text/html">First.</text></topic>'
        );
        file_put_contents(
            $dir . '/discussion/a1_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_a1</topic_id><type>announcement</type></topicMeta>'
        );
        file_put_contents(
            $dir . '/discussion/a2.xml',
            '<topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1">'
            . '<title>News two</title><text texttype="text/html">Second.</text></topic>'
        );
        file_put_contents(
            $dir . '/discussion/a2_meta.xml',
            '<topicMeta xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<topic_id>r_a2</topic_id><type>announcement</type></topicMeta>'
        );
        // A page linking to the second announcement via $CANVAS_OBJECT_REFERENCE$.
        file_put_contents(
            $dir . '/wiki_content/intro.html',
            '<p><a href="$CANVAS_OBJECT_REFERENCE$/announcements/r_a2">See announcement</a></p>'
        );
        file_put_contents($dir . '/imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>M1</title>'
            . '<item identifier="i_page" identifierref="r_page"><title>Intro</title></item>'
            . '<item identifier="i_a1" identifierref="r_a1"/>'
            . '<item identifier="i_a2" identifierref="r_a2"/>'
            . '</item></item></organization></organizations>'
            . '<resources>'
            . '<resource identifier="r_page" type="webcontent" href="wiki_content/intro.html">'
            . '<file href="wiki_content/intro.html"/></resource>'
            . '<resource identifier="r_a1" type="imsdt_xmlv1p1">'
            . '<file href="discussion/a1.xml"/></resource>'
            . '<resource identifier="r_a1_meta" '
            . 'type="associatedcontent/imscc_xmlv1p1/learning-application-resource" '
            . 'href="discussion/a1_meta.xml"><file href="discussion/a1_meta.xml"/></resource>'
            . '<resource identifier="r_a2" type="imsdt_xmlv1p1">'
            . '<file href="discussion/a2.xml"/></resource>'
            . '<resource identifier="r_a2_meta" '
            . 'type="associatedcontent/imscc_xmlv1p1/learning-application-resource" '
            . 'href="discussion/a2_meta.xml"><file href="discussion/a2_meta.xml"/></resource>'
            . '</resources></manifest>');

        $coursemodel = (new manifest_parser($dir))->parse();
        $report = (new course_builder($this->getDataGenerator()->create_category()->id, $dir))->build($coursemodel);

        $news = $DB->get_record('forum', ['course' => $report['courseid'], 'type' => 'news'], '*', MUST_EXIST);
        $secondannouncement = $DB->get_record('forum_discussions', ['forum' => $news->id, 'name' => 'News two'], '*', MUST_EXIST);

        $page = $DB->get_record_sql(
            'SELECT p.* FROM {page} p JOIN {course_modules} cm ON cm.instance = p.id
             JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = :course AND m.name = :name',
            ['course' => $report['courseid'], 'name' => 'page']
        );
        // The page link landed on the announcement's thread, not the forum index.
        $this->assertStringContainsString('/mod/forum/discuss.php?d=' . $secondannouncement->id, $page->content);
        $this->assertStringNotContainsString('CANVAS_OBJECT_REFERENCE', $page->content);
    }
}
