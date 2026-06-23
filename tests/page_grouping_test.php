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
 * Tests the "combine consecutive pages into a book/lesson" build option.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\book_builder
 * @covers     \tool_canvasuplifter\local\build\lesson_builder
 * @covers     \tool_canvasuplifter\local\build\course_builder
 */
final class page_grouping_test extends \advanced_testcase {
    /**
     * Build a package with one module of three consecutive pages (the first
     * linking to the second and embedding an image) plus a second module with a
     * single page.
     *
     * @return string Path to the package root.
     */
    private function build_pages_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/wiki_content');
        mkdir($dir . '/web_resources/img', 0777, true);
        // A 1x1 PNG so the embedded image resolves to a real file.
        file_put_contents(
            $dir . '/web_resources/img/logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
        );
        file_put_contents(
            $dir . '/wiki_content/page-one.html',
            '<p>Intro <a href="$WIKI_REFERENCE$/pages/page-two">go to two</a></p>'
            . '<p><img src="$IMS-CC-FILEBASE$/img/logo.png" alt="logo"></p>'
        );
        file_put_contents($dir . '/wiki_content/page-two.html', '<p>Two</p>');
        file_put_contents($dir . '/wiki_content/page-three.html', '<p>Three</p>');
        file_put_contents($dir . '/wiki_content/page-solo.html', '<p>Solo</p>');
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="manifest" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">
  <organizations>
    <organization identifier="org1">
      <item identifier="root">
        <item identifier="m1"><title>Reading</title>
          <item identifier="i1" identifierref="r1"><title>Page One</title></item>
          <item identifier="i2" identifierref="r2"><title>Page Two</title></item>
          <item identifier="i3" identifierref="r3"><title>Page Three</title></item>
        </item>
        <item identifier="m2"><title>Solo</title>
          <item identifier="i4" identifierref="r4"><title>Page Solo</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r1" type="webcontent" href="wiki_content/page-one.html">
      <file href="wiki_content/page-one.html"/>
    </resource>
    <resource identifier="r2" type="webcontent" href="wiki_content/page-two.html">
      <file href="wiki_content/page-two.html"/>
    </resource>
    <resource identifier="r3" type="webcontent" href="wiki_content/page-three.html">
      <file href="wiki_content/page-three.html"/>
    </resource>
    <resource identifier="r4" type="webcontent" href="wiki_content/page-solo.html">
      <file href="wiki_content/page-solo.html"/>
    </resource>
  </resources>
</manifest>
XML;
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * Without the option, every page builds as its own mod_page.
     *
     * @return void
     */
    public function test_default_builds_individual_pages(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_pages_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root))->build($coursemodel);

        $this->assertSame(4, $DB->count_records('page', ['course' => $report['courseid']]));
        $this->assertSame(0, $DB->count_records('book', ['course' => $report['courseid']]));
        $this->assertSame(0, $DB->count_records('lesson', ['course' => $report['courseid']]));
    }

    /**
     * With 'book', the run of three pages collapses into a single book of three
     * chapters named after the section; the lone page stays a mod_page; embedded
     * files and internal links resolve into the chapter.
     *
     * @return void
     */
    public function test_book_grouping_combines_runs(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_pages_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root, null, 0, false, 'book'))->build($coursemodel);
        $courseid = $report['courseid'];

        $this->assertSame(1, $DB->count_records('book', ['course' => $courseid]));
        $this->assertSame(1, $DB->count_records('page', ['course' => $courseid]));
        $this->assertSame(0, $DB->count_records('lesson', ['course' => $courseid]));

        $book = $DB->get_record('book', ['course' => $courseid], '*', MUST_EXIST);
        $this->assertSame('Reading', $book->name);
        $this->assertSame(3, $DB->count_records('book_chapters', ['bookid' => $book->id]));

        $chapter = $DB->get_record('book_chapters', ['bookid' => $book->id, 'title' => 'Page One'], '*', MUST_EXIST);
        // The embedded image was imported and the reference rewritten.
        $this->assertStringContainsString('@@PLUGINFILE@@/img/logo.png', $chapter->content);
        // The internal wiki link now points at the sibling chapter.
        $this->assertStringContainsString('mod/book/view.php', $chapter->content);
        $this->assertStringContainsString('chapterid=', $chapter->content);

        $cm = get_coursemodule_from_instance('book', $book->id, $courseid, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context->id, 'mod_book', 'chapter', $chapter->id, '/img/', 'logo.png'));
    }

    /**
     * With 'lesson', the run collapses into a single lesson with one content
     * page per Canvas page (each with a navigation answer); the lone page stays
     * a mod_page and internal links resolve into the lesson page.
     *
     * @return void
     */
    public function test_lesson_grouping_combines_runs(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_pages_fixture();
        $category = $this->getDataGenerator()->create_category();
        $coursemodel = (new manifest_parser($root))->parse();

        $report = (new course_builder($category->id, $root, null, 0, false, 'lesson'))->build($coursemodel);
        $courseid = $report['courseid'];

        $this->assertSame(1, $DB->count_records('lesson', ['course' => $courseid]));
        $this->assertSame(1, $DB->count_records('page', ['course' => $courseid]));
        $this->assertSame(0, $DB->count_records('book', ['course' => $courseid]));

        $lesson = $DB->get_record('lesson', ['course' => $courseid], '*', MUST_EXIST);
        $this->assertSame('Reading', $lesson->name);
        $this->assertSame(3, $DB->count_records('lesson_pages', ['lessonid' => $lesson->id]));
        // One navigation button per content page.
        $this->assertSame(3, $DB->count_records('lesson_answers', ['lessonid' => $lesson->id]));

        $page = $DB->get_record('lesson_pages', ['lessonid' => $lesson->id, 'title' => 'Page One'], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/img/logo.png', $page->contents);
        $this->assertStringContainsString('mod/lesson/view.php', $page->contents);
        $this->assertStringContainsString('pageid=', $page->contents);
    }
}
