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
     * Make a matching question model with two stem/answer pairs and one
     * answer-only distractor.
     *
     * @return qti_question
     */
    private function match(): qti_question {
        $q = new qti_question();
        $q->type = qti_question::TYPE_MATCHING;
        $q->name = 'Match terms';
        $q->questiontext = '<p>Match the term</p>';
        $q->defaultmark = 2.0;
        $q->subquestions = [
            ['text' => '<p>Wrist area</p>', 'answer' => 'carpal'],
            ['text' => '<p>Back of the knee</p>', 'answer' => 'popliteal'],
            ['text' => '', 'answer' => 'prone'],
        ];
        return $q;
    }

    /**
     * A text-only stimulus item (Canvas New Quizzes text_only_question) renders
     * as a Moodle <question type="description"> — its question text, no answers.
     *
     * @return void
     */
    public function test_writes_description(): void {
        $q = new qti_question();
        $q->type = qti_question::TYPE_DESCRIPTION;
        $q->name = 'Read this';
        $q->questiontext = '<p>Some stimulus text</p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], '$course$/Imported/Bank');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output should be well-formed XML');
        $types = [];
        foreach ($dom->getElementsByTagName('question') as $node) {
            $types[] = $node->getAttribute('type');
        }
        $this->assertSame(['category', 'description'], $types);
        $this->assertStringContainsString('Some stimulus text', $xml);
        // A description has no answers or single flag.
        $this->assertSame(0, $dom->getElementsByTagName('answer')->length);
        $this->assertStringNotContainsString('<single>', $xml);
    }

    /**
     * Matching questions render as a Moodle <question type="matching"> with one
     * <subquestion> per pair (the distractor carried as an answer-only row), the
     * shuffle flag and the combined feedback — and the document stays well-formed.
     *
     * @return void
     */
    public function test_writes_matching(): void {
        $xml = (new question_xml_writer())->to_moodle_xml([$this->match()], '$course$/Imported/Bank');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output should be well-formed XML');

        $types = [];
        foreach ($dom->getElementsByTagName('question') as $q) {
            $types[] = $q->getAttribute('type');
        }
        $this->assertSame(['category', 'matching'], $types);

        // Three subquestions: two real pairs plus the answer-only distractor.
        $this->assertSame(3, $dom->getElementsByTagName('subquestion')->length);
        $this->assertStringContainsString('<shuffleanswers>true</shuffleanswers>', $xml);
        $this->assertStringContainsString('Wrist area', $xml);
        $this->assertStringContainsString('carpal', $xml);
        $this->assertStringContainsString('popliteal', $xml);
        // The distractor is present as an answer with an empty stem.
        $this->assertStringContainsString('prone', $xml);
        // Combined feedback is emitted so the question is complete.
        $this->assertStringContainsString('<correctfeedback format="html">', $xml);
        // No multichoice scaffolding leaks into a match question.
        $this->assertStringNotContainsString('<single>', $xml);
    }

    /**
     * A true/false question renders as a native Moodle <question type="truefalse">
     * with exactly two answers whose text is 'true'/'false', the correct side
     * carrying fraction 100 — not as a multiple-choice question. The correct side
     * follows the source label, whichever option is right, and no multichoice
     * scaffolding leaks in.
     *
     * @return void
     */
    public function test_writes_truefalse(): void {
        // Source marks "False" as the correct answer.
        $q = new qti_question();
        $q->type = qti_question::TYPE_TRUEFALSE;
        $q->name = 'TF';
        $q->questiontext = '<p>The sky is green.</p>';
        $q->answers = [
            ['text' => 'True', 'fraction' => 0.0, 'feedback' => ''],
            ['text' => 'False', 'fraction' => 100.0, 'feedback' => 'Correct'],
        ];

        $xml = (new question_xml_writer())->to_moodle_xml([$q], '$course$/Imported/Bank');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output should be well-formed XML');

        $types = [];
        foreach ($dom->getElementsByTagName('question') as $node) {
            $types[] = $node->getAttribute('type');
        }
        $this->assertSame(['category', 'truefalse'], $types);

        // Exactly two answers, texts 'true'/'false', with False scored correct.
        $answers = [];
        foreach ($dom->getElementsByTagName('answer') as $node) {
            $answers[$node->getElementsByTagName('text')->item(0)->textContent] = $node->getAttribute('fraction');
        }
        $this->assertSame(['true' => '0', 'false' => '100'], $answers);

        // None of the multichoice-only scaffolding is emitted.
        $this->assertStringNotContainsString('<single>', $xml);
        $this->assertStringNotContainsString('<answernumbering>', $xml);
        $this->assertStringNotContainsString('<usecase>', $xml);
    }

    /**
     * When the source options carry no 'true'/'false' labels, the writer falls
     * back to position (first option is the true side) and still scores the
     * correct one.
     *
     * @return void
     */
    public function test_truefalse_positional_fallback(): void {
        $q = new qti_question();
        $q->type = qti_question::TYPE_TRUEFALSE;
        $q->name = 'TF2';
        $q->questiontext = '<p>Water is wet.</p>';
        // Unlabelled options; the first (true) side is correct.
        $q->answers = [
            ['text' => '', 'fraction' => 100.0, 'feedback' => ''],
            ['text' => '', 'fraction' => 0.0, 'feedback' => ''],
        ];

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $answers = [];
        foreach ($dom->getElementsByTagName('answer') as $node) {
            $answers[$node->getElementsByTagName('text')->item(0)->textContent] = $node->getAttribute('fraction');
        }
        $this->assertSame(['true' => '100', 'false' => '0'], $answers);
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
     * A Canvas $IMS-CC-FILEBASE$ token resolves against the package root — both
     * directly and via the web_resources/ location Canvas commonly uses — and the
     * referenced image is inlined; without a package root the token is untouched.
     *
     * @return void
     */
    public function test_embeds_filebase_token_images(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        mkdir($root . '/web_resources/assessment_questions', 0777, true);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        file_put_contents($root . '/web_resources/assessment_questions/fig.jpg', $png);
        // A second image that sits at the package root (no web_resources prefix).
        file_put_contents($root . '/diagram.png', 'PNGBYTES');

        $q = $this->choice();
        $q->questiontext = '<p>See <img src="$IMS-CC-FILEBASE$/assessment_questions/fig.jpg" alt="x">'
            . ' and <img src="$IMS-CC-FILEBASE$/diagram.png"></p>';

        // Image dir is the (question-local) folder; the token resolves via filebase.
        $imagedir = make_request_directory();
        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $imagedir, $root);

        // Resolved through the web_resources/ fallback and inlined.
        $this->assertStringContainsString('@@PLUGINFILE@@/web_resources/assessment_questions/fig.jpg', $xml);
        $this->assertStringContainsString(
            '<file name="fig.jpg" path="/web_resources/assessment_questions/" encoding="base64">',
            $xml
        );
        $this->assertStringContainsString(base64_encode($png), $xml);
        // Resolved directly at the package root.
        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.png', $xml);
        $this->assertStringContainsString(base64_encode('PNGBYTES'), $xml);
        // The raw token never survives into the output.
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'output should be well-formed XML');

        // Without a package root, the token is left untouched (no crash, no embed).
        $xmlnobase = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $imagedir);
        $this->assertStringContainsString('IMS-CC-FILEBASE', $xmlnobase);
        $this->assertStringNotContainsString('@@PLUGINFILE@@/web_resources', $xmlnobase);
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
     * A fragment that starts with a media element (which libxml would otherwise
     * hoist out of the body) keeps the element and still embeds the file.
     *
     * @return void
     */
    public function test_leading_media_is_preserved_and_embedded(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/clip.mp4', 'FAKEMP4');

        $q = $this->choice();
        $q->questiontext = '<video src="clip.mp4"></video><p>watch</p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        $this->assertStringContainsString('src="@@PLUGINFILE@@/clip.mp4"', $xml);
        $this->assertStringContainsString('<file name="clip.mp4" path="/" encoding="base64">', $xml);
        $this->assertStringContainsString('watch', $xml);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    /**
     * HTML5 named entities survive the media round-trip: in text they are kept
     * faithfully, and in a bundled URL they are decoded so the file resolves.
     *
     * @return void
     */
    public function test_html5_entities_survive_media_roundtrip(): void {
        $dir = make_request_directory();
        mkdir($dir . '/sub');
        file_put_contents($dir . '/sub/pic.png', 'PNG');

        $q = $this->choice();
        // Uses &rightarrow; in the text and &sol; (HTML5 "/") inside the bundled URL.
        $q->questiontext = '<p>A &rightarrow; B <img src="sub&sol;pic.png"></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        // The arrow is preserved (as the character or a numeric ref), never as
        // the broken "&amp;rightarrow;".
        $this->assertStringNotContainsString('&amp;rightarrow;', $xml);
        // The &sol; entity resolved to "/" so the subfolder file was found.
        $this->assertStringContainsString('@@PLUGINFILE@@/sub/pic.png', $xml);
        $this->assertStringContainsString('<file name="pic.png" path="/sub/" encoding="base64">', $xml);
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
        $this->assertStringContainsString('src="@@PLUGINFILE@@/audio/en/clip.mp3"', $xml);
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

    /**
     * Reserved characters in preserved subdirectories are percent-encoded in the
     * pluginfile URL (but stored literally), and a sibling folder sharing a name
     * prefix is not treated as inside the assessment folder.
     *
     * @return void
     */
    public function test_media_url_encoding_and_traversal(): void {
        $base = make_request_directory();
        // The assessment folder and a sibling that shares its name prefix.
        mkdir($base . '/a1');
        mkdir($base . '/a1/sub dir');
        mkdir($base . '/a10');
        file_put_contents($base . '/a1/sub dir/clip.mp3', 'OK');
        file_put_contents($base . '/a10/secret.pdf', 'SECRET');

        $q = $this->choice();
        $q->questiontext = '<p><audio src="sub%20dir/clip.mp3"></audio>'
            . '<a href="../a10/secret.pdf">leak</a></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $base . '/a1');

        // The space is encoded in the URL but the stored path keeps it literal.
        $this->assertStringContainsString('src="@@PLUGINFILE@@/sub%20dir/clip.mp3"', $xml);
        $this->assertStringContainsString('<file name="clip.mp3" path="/sub dir/"', $xml);
        // The sibling-folder file is NOT imported; the link is left untouched.
        $this->assertStringContainsString('href="../a10/secret.pdf"', $xml);
        $this->assertStringNotContainsString('secret.pdf" path', $xml);
        $this->assertStringNotContainsString(base64_encode('SECRET'), $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    /**
     * Dot segments resolve to the canonical file; HTML entities and quotes in
     * filenames are handled; and a quoted '>' in an earlier attribute doesn't
     * hide a later media reference.
     *
     * @return void
     */
    public function test_media_canonical_paths_and_entities(): void {
        $dir = make_request_directory();
        mkdir($dir . '/sub');
        file_put_contents($dir . '/clip.mp3', 'CLIP');
        file_put_contents($dir . '/Tom & Jerry.pdf', 'TJ');
        file_put_contents($dir . '/a"b.pdf', 'QUOTE');
        file_put_contents($dir . '/slides.pdf', 'SLIDES');

        $q = $this->choice();
        $q->questiontext =
            '<p><audio src="sub/../clip.mp3"></audio></p>'
            . '<p><a href="Tom%20&amp;%20Jerry.pdf">tj</a></p>'
            . '<p><a href="a%22b.pdf">q</a></p>'
            . '<p><a title="2 > 1" href="slides.pdf">s</a></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        // Dot segments collapse to the canonical root-level file.
        $this->assertStringContainsString('src="@@PLUGINFILE@@/clip.mp3"', $xml);
        $this->assertStringContainsString('<file name="clip.mp3" path="/"', $xml);
        $this->assertStringNotContainsString('/sub/../', $xml);
        // An HTML entity in the filename is decoded for lookup and the file imported.
        $this->assertStringContainsString('@@PLUGINFILE@@/Tom%20%26%20Jerry.pdf', $xml);
        $this->assertStringContainsString(base64_encode('TJ'), $xml);
        // A quote in the stored name is XML-escaped, keeping the document well-formed.
        $this->assertStringContainsString('a&quot;b.pdf', $xml);
        $this->assertStringContainsString(base64_encode('QUOTE'), $xml);
        // A quoted '>' earlier in the tag doesn't hide the later href.
        $this->assertStringContainsString('@@PLUGINFILE@@/slides.pdf', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }

    /**
     * DOM parsing handles bundled iframes, attribute-looking text inside another
     * attribute's value, and null-byte references without aborting.
     *
     * @return void
     */
    public function test_media_dom_edge_cases(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/worksheet.html', '<p>hi</p>');
        file_put_contents($dir . '/index.html', 'x');
        file_put_contents($dir . '/slides.pdf', 'PDF');

        $q = $this->choice();
        $q->questiontext =
            '<p><iframe src="worksheet.html"></iframe></p>'
            . '<p><iframe src="https://example.com/embed"></iframe></p>'
            . '<p><a title=\'x href="index.html"\' href="slides.pdf">s</a></p>'
            . '<p><a href="bad%00.pdf">nul</a></p>';

        $xml = (new question_xml_writer())->to_moodle_xml([$q], 'cat', $dir);

        // A bundled iframe is imported; an external one is preserved.
        $this->assertStringContainsString('src="@@PLUGINFILE@@/worksheet.html"', $xml);
        $this->assertStringContainsString('<file name="worksheet.html" path="/"', $xml);
        $this->assertStringContainsString('https://example.com/embed', $xml);
        // Only the real href is rewritten, not attribute-looking text in the title.
        $this->assertStringContainsString('@@PLUGINFILE@@/slides.pdf', $xml);
        $this->assertStringNotContainsString('@@PLUGINFILE@@/index.html', $xml);
        // A null byte in a reference is left alone and doesn't abort generation.
        $this->assertStringContainsString('bad%00.pdf', $xml);
        $this->assertStringNotContainsString('@@PLUGINFILE@@/bad', $xml);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
    }
}
