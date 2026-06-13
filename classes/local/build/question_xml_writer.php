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

namespace tool_canvasuplifter\local\build;

use tool_canvasuplifter\local\model\qti_question;

/**
 * Renders parsed QTI questions as a Moodle XML import document.
 *
 * The output is consumed by Moodle's own qformat_xml importer, so we reuse
 * Moodle's battle-tested question creation rather than building questions by
 * hand. Media referenced by a question (images, video, audio and attachments
 * stored as relative files in the assessment folder) are inlined as base64
 * <file> elements and the reference rewritten to a pluginfile placeholder.
 * External URLs (e.g. YouTube embeds) are left untouched. No Moodle
 * dependencies, so it's unit-testable.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_xml_writer {
    /**
     * Render questions as a Moodle XML document.
     *
     * @param array $questions Array of {@see qti_question}.
     * @param string $category Question category path, e.g. "$course$/Imported/Quiz".
     * @param string|null $imagedir Absolute folder to resolve relative images, or null to skip.
     * @return string Moodle XML.
     */
    public function to_moodle_xml(array $questions, string $category, ?string $imagedir = null): string {
        $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n";
        $out .= "  <question type=\"category\">\n    <category><text>"
            . htmlspecialchars($category, ENT_XML1) . "</text></category>\n  </question>\n";
        foreach ($questions as $question) {
            $xml = $this->question_xml($question, $imagedir);
            if ($xml !== '') {
                $out .= $xml;
            }
        }
        $out .= "</quiz>\n";
        return $out;
    }

    /**
     * Render a single question, or '' for unsupported ones.
     *
     * @param qti_question $q The question.
     * @param string|null $imagedir Image resolution folder.
     * @return string
     */
    protected function question_xml(qti_question $q, ?string $imagedir): string {
        if ($q->type === qti_question::TYPE_UNSUPPORTED) {
            return '';
        }
        $mtype = $q->type === qti_question::TYPE_SHORTANSWER ? 'shortanswer'
            : ($q->type === qti_question::TYPE_ESSAY ? 'essay' : 'multichoice');

        $body = "  <question type=\"$mtype\">\n";
        $body .= "    <name><text>" . htmlspecialchars($this->plain($q->name), ENT_XML1) . "</text></name>\n";
        $body .= "    " . $this->htmlblock('questiontext', $q->questiontext, $imagedir) . "\n";
        $body .= "    " . $this->htmlblock('generalfeedback', $q->generalfeedback, $imagedir) . "\n";
        $body .= "    <defaultgrade>" . number_format(max(0.0, $q->defaultmark), 7, '.', '') . "</defaultgrade>\n";
        $body .= "    <penalty>0.3333333</penalty>\n    <hidden>0</hidden>\n";

        switch ($mtype) {
            case 'shortanswer':
                $body .= "    <usecase>0</usecase>\n";
                $body .= $this->answers_xml($q, $imagedir, 'moodle_auto_format');
                break;
            case 'essay':
                $body .= $this->essay_xml();
                break;
            default:
                $body .= "    <single>" . ($q->type === qti_question::TYPE_MULTIANSWER ? 'false' : 'true') . "</single>\n";
                $body .= "    <shuffleanswers>true</shuffleanswers>\n    <answernumbering>abc</answernumbering>\n";
                $body .= $this->answers_xml($q, $imagedir, 'html');
        }
        return $body . "  </question>\n";
    }

    /**
     * Render the <answer> elements.
     *
     * @param qti_question $q The question.
     * @param string|null $imagedir Image resolution folder.
     * @param string $format The answer text format attribute.
     * @return string
     */
    protected function answers_xml(qti_question $q, ?string $imagedir, string $format): string {
        $out = '';
        foreach ($q->answers as $answer) {
            $fraction = rtrim(rtrim(number_format((float) $answer['fraction'], 5, '.', ''), '0'), '.');
            $fraction = $fraction === '' ? '0' : $fraction;
            $out .= "    <answer fraction=\"$fraction\" format=\"$format\">\n";
            if ($format === 'html') {
                $out .= "      " . $this->htmlblock('__text', $answer['text'], $imagedir, true) . "\n";
            } else {
                $out .= "      <text>" . $this->cdata($answer['text']) . "</text>\n";
            }
            $out .= "      " . $this->htmlblock('feedback', $answer['feedback'] ?? '', $imagedir) . "\n";
            $out .= "    </answer>\n";
        }
        return $out;
    }

    /**
     * Essay-specific fields.
     *
     * @return string
     */
    protected function essay_xml(): string {
        return "    <responseformat>editor</responseformat>\n    <responserequired>1</responserequired>\n"
            . "    <responsefieldlines>10</responsefieldlines>\n    <attachments>0</attachments>\n"
            . "    <attachmentsrequired>0</attachmentsrequired>\n"
            . "    <graderinfo format=\"html\"><text></text></graderinfo>\n"
            . "    <responsetemplate format=\"html\"><text></text></responsetemplate>\n";
    }

    /**
     * Render an HTML-bearing element (questiontext/feedback/answer text) with any
     * referenced media (images, video, audio, attachments) inlined as base64
     * <file> siblings.
     *
     * @param string $tag Element name; "__text" emits a bare <text> (for answers).
     * @param string $html The HTML.
     * @param string|null $imagedir Image resolution folder.
     * @param bool $bare Whether to emit just <text> (no wrapper, no format attr).
     * @return string
     */
    protected function htmlblock(string $tag, string $html, ?string $imagedir, bool $bare = false): string {
        $files = [];
        $rewritten = $this->embed_files($html, $imagedir, $files);
        $textandfiles = "<text>" . $this->cdata($rewritten) . "</text>";
        foreach ($files as $name => $base64) {
            $textandfiles .= "\n      <file name=\"" . htmlspecialchars($name, ENT_XML1)
                . "\" path=\"/\" encoding=\"base64\">" . $base64 . "</file>";
        }
        if ($bare || $tag === '__text') {
            return $textandfiles;
        }
        return "<$tag format=\"html\">" . $textandfiles . "</$tag>";
    }

    /**
     * Rewrite relative media references (src/poster/href on img, video, audio,
     * source, track, anchors, etc.) to @@PLUGINFILE@@ and collect the base64
     * bytes of each referenced package file. External URLs and in-page anchors
     * are left untouched, as is any reference that doesn't resolve to a real
     * bundled file.
     *
     * @param string $html The HTML.
     * @param string|null $imagedir Folder to resolve referenced files, or null to skip.
     * @param array $files Collected name => base64 (modified in place).
     * @return string Rewritten HTML.
     */
    protected function embed_files(string $html, ?string $imagedir, array &$files): string {
        if ($imagedir === null || $html === '') {
            return $html;
        }
        return preg_replace_callback('/\b(src|poster|href)="([^"]+)"/i', function ($m) use ($imagedir, &$files) {
            $raw = $m[2];
            if (preg_match('~^(https?:|data:|mailto:|tel:|@@PLUGINFILE@@|#)~i', $raw)) {
                return $m[0];
            }
            $rel = rawurldecode(preg_replace('/[?#].*$/', '', $raw));
            $abs = $this->safe_join($imagedir, $rel);
            if ($abs === null || !is_file($abs)) {
                return $m[0];
            }
            $name = basename($rel);
            if (!isset($files[$name])) {
                $files[$name] = base64_encode((string) file_get_contents($abs));
            }
            return $m[1] . '="@@PLUGINFILE@@/' . rawurlencode($name) . '"';
        }, $html);
    }

    /**
     * Resolve a relative path under a base dir, refusing traversal.
     *
     * @param string $base The base directory.
     * @param string $rel The relative path.
     * @return string|null Absolute path, or null.
     */
    protected function safe_join(string $base, string $rel): ?string {
        $abs = realpath($base . '/' . ltrim($rel, '/'));
        $root = realpath($base);
        if ($abs === false || $root === false) {
            return null;
        }
        return strpos($abs, $root) === 0 ? $abs : null;
    }

    /**
     * Wrap text in CDATA, escaping any embedded terminator.
     *
     * @param string $text The text.
     * @return string
     */
    protected function cdata(string $text): string {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]>';
    }

    /**
     * Plain-text version of an HTML string for use in names.
     *
     * @param string $html The HTML.
     * @return string
     */
    protected function plain(string $html): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))));
        return $text !== '' ? $text : 'Question';
    }
}
