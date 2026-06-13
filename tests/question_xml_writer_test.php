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

use tool_canvasuplifter\local\build\question_xml_writer;
use tool_canvasuplifter\local\model\qti_question;

/**
 * Tests for the Moodle question XML writer.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\question_xml_writer
 */
final class question_xml_writer_test extends \advanced_testcase {
    /**
     * Make a multiple-choice question model.
     *
     * @return qti_question
     */
    private function choice(): qti_question {
        $q = new qti_question();
        $q->type = qti_question::TYPE_MULTICHOICE;
        $q->name = 'Sample';
        $q->questiontext = '<p>Pick one</p>';
        $q->answers = [
            ['text' => 'right', 'fraction' => 100.0, 'feedback' => 'Yes'],
            ['text' => 'wrong', 'fraction' => 0.0, 'feedback' => ''],
        ];
        return $q;
    }

    /**
     * The writer emits a well-formed Moodle XML document with a category and
     * the supported question types.
     *
     * @return void
     */
    public function test_writes_wellformed_xml(): void {
        $short = new qti_question();
        $short->type = qti_question::TYPE_SHORTANSWER;
        $short->name = 'Blank';
        $short->questiontext = '<p>2+2=?</p>';
        $short->answers = [['text' => '4', 'fraction' => 100.0, 'feedback' => '']];

        $xml = (new question_xml_writer())->to_moodle_xml([$this->choice(), $short], '$course$/Imported/Bank');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output should be well-formed XML');

        $types = [];
        foreach ($dom->getElementsByTagName('question') as $q) {
            $types[] = $q->getAttribute('type');
        }
        $this->assertSame(['category', 'multichoice', 'shortanswer'], $types);
        $this->assertStringContainsString('<single>true</single>', $xml);
        $this->assertStringContainsString('<usecase>0</usecase>', $xml);
        $this->assertStringContainsString('fraction="100"', $xml);
    }

    /**
     * Unsupported questions are dropped from the output.
     *
     * @return void
     */
    public function test_skips_unsupported(): void {
        $bad = new qti_question();
        $bad->type = qti_question::TYPE_UNSUPPORTED;
        $bad->name = 'Nope';

        $xml = (new question_xml_writer())->to_moodle_xml([$bad, $this->choice()], 'cat');
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        // Only the category and the one supported question.
        $this->assertSame(2, $dom->getElementsByTagName('question')->length);
    }

    /**
     * A relative image is inlined as base64 and the src rewritten to pluginfile.
     *
     * @return void
     */
    public function test_embeds_images(): void {
        $dir = make_request_directory();
        // 1x1 transparent PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        file_put_contents($dir . '/pic.png', $png);

        $q = $this->choice();
        $q->questiontext = '<p>See <img src="pic.png" alt="x"></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        $this->assertStringContainsString('@@PLUGINFILE@@/pic.png', $xml);
        $this->assertStringContainsString('<file name="pic.png" path="/" encoding="base64">', $xml);
        $this->assertStringContainsString(base64_encode($png), $xml);
        $this->assertStringNotContainsString('src="pic.png"', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    /**
     * Bundled video/audio is inlined like images; external embeds and in-page
     * anchors are left untouched.
     *
     * @return void
     */
    public function test_embeds_media_and_leaves_external_embeds(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/clip.mp4', 'FAKEMP4BYTES');

        $q = $this->choice();
        $q->questiontext = '<p>Watch <video src="clip.mp4" poster="clip.mp4"></video></p>'
            . '<iframe src="https://www.youtube.com/embed/abc"></iframe>'
            . '<a href="#section">jump</a>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        // The bundled video is rewritten and inlined.
        $this->assertStringContainsString('src="@@PLUGINFILE@@/clip.mp4"', $xml);
        $this->assertStringContainsString('poster="@@PLUGINFILE@@/clip.mp4"', $xml);
        $this->assertStringContainsString('<file name="clip.mp4" path="/" encoding="base64">', $xml);
        $this->assertStringContainsString(base64_encode('FAKEMP4BYTES'), $xml);
        // External embeds and in-page anchors are preserved as-is.
        $this->assertStringContainsString('https://www.youtube.com/embed/abc', $xml);
        $this->assertStringContainsString('href="#section"', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    /**
     * Single-quoted/spaced attributes are matched; same-named files in different
     * folders stay distinct; URL suffixes survive; and attribute-looking text in
     * a code sample is not rewritten.
     *
     * @return void
     */
    public function test_media_edge_cases(): void {
        $dir = make_request_directory();
        mkdir($dir . '/audio');
        mkdir($dir . '/audio/en');
        mkdir($dir . '/audio/es');
        file_put_contents($dir . '/audio/en/clip.mp3', 'ENBYTES');
        file_put_contents($dir . '/audio/es/clip.mp3', 'ESBYTES');
        file_put_contents($dir . '/slides.pdf', 'PDF');
        file_put_contents($dir . '/index.html', '<html></html>');

        $q = $this->choice();
        $q->questiontext =
            "<p><audio src = 'audio/en/clip.mp3'></audio></p>"
            . '<p><audio src="audio/es/clip.mp3"></audio></p>'
            . '<p><a href="slides.pdf#page=4">slides</a></p>'
            . '<p><code>href="index.html"</code></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        // Single-quoted + spaced attribute is matched, subdir preserved.
        $this->assertStringContainsString("src='@@PLUGINFILE@@/audio/en/clip.mp3'", $xml);
        $this->assertStringContainsString('src="@@PLUGINFILE@@/audio/es/clip.mp3"', $xml);
        // Same basename in different folders -> two distinct stored files.
        $this->assertStringContainsString('<file name="clip.mp3" path="/audio/en/"', $xml);
        $this->assertStringContainsString('<file name="clip.mp3" path="/audio/es/"', $xml);
        $this->assertStringContainsString(base64_encode('ENBYTES'), $xml);
        $this->assertStringContainsString(base64_encode('ESBYTES'), $xml);
        // The #page=4 fragment is carried onto the rewritten URL.
        $this->assertStringContainsString('@@PLUGINFILE@@/slides.pdf#page=4', $xml);
        // Attribute-looking text inside a code sample is left untouched.
        $this->assertStringContainsString('href="index.html"', $xml);
        $this->assertStringNotContainsString('@@PLUGINFILE@@/index.html', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }
}
