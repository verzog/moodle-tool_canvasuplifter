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
    /** @var string|null Package root for resolving $IMS-CC-FILEBASE$ tokens, set per render. */
    private ?string $filebase = null;

    /**
     * Render questions as a Moodle XML document.
     *
     * @param array $questions Array of {@see qti_question}.
     * @param string $category Question category path, e.g. "$course$/Imported/Quiz".
     * @param string|null $imagedir Absolute folder to resolve relative images, or null to skip.
     * @param string|null $filebase Absolute package root for resolving $IMS-CC-FILEBASE$ tokens.
     * @return string Moodle XML.
     */
    public function to_moodle_xml(array $questions, string $category, ?string $imagedir = null,
            ?string $filebase = null): string {
        $this->filebase = $filebase !== null ? rtrim($filebase, '/') : null;
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
            : ($q->type === qti_question::TYPE_ESSAY ? 'essay'
            : ($q->type === qti_question::TYPE_MATCHING ? 'matching' : 'multichoice'));

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
            case 'matching':
                $body .= $this->matching_xml($q, $imagedir);
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
     * Render the matching-specific body: shuffle flag, the standard combined
     * feedback, and one <subquestion> per stem/answer pair. The stem keeps its
     * HTML (and any embedded media); the answer is the plain choice text. An
     * empty-stem subquestion is an answer-only distractor, which Moodle's match
     * type accepts.
     *
     * @param qti_question $q The question.
     * @param string|null $imagedir Image resolution folder.
     * @return string
     */
    protected function matching_xml(qti_question $q, ?string $imagedir): string {
        $out = "    <shuffleanswers>true</shuffleanswers>\n";
        $out .= "    <correctfeedback format=\"html\"><text>Your answer is correct.</text></correctfeedback>\n";
        $out .= "    <partiallycorrectfeedback format=\"html\"><text>Your answer is partially correct.</text>"
            . "</partiallycorrectfeedback>\n";
        $out .= "    <incorrectfeedback format=\"html\"><text>Your answer is incorrect.</text></incorrectfeedback>\n";
        $out .= "    <shownumcorrect/>\n";
        foreach ($q->subquestions as $sub) {
            $stem = (string) ($sub['text'] ?? '');
            $answer = (string) ($sub['answer'] ?? '');
            $out .= "    <subquestion format=\"html\">\n";
            $out .= "      " . $this->htmlblock('__text', $stem, $imagedir, true) . "\n";
            $out .= "      <answer><text>" . $this->cdata($answer) . "</text></answer>\n";
            $out .= "    </subquestion>\n";
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
        foreach ($files as $file) {
            $textandfiles .= "\n      <file name=\"" . htmlspecialchars($file['name'], ENT_QUOTES | ENT_XML1)
                . "\" path=\"" . htmlspecialchars($file['path'], ENT_QUOTES | ENT_XML1)
                . "\" encoding=\"base64\">" . $file['base64'] . "</file>";
        }
        if ($bare || $tag === '__text') {
            return $textandfiles;
        }
        return "<$tag format=\"html\">" . $textandfiles . "</$tag>";
    }

    /** @var string[] Tags whose URL attributes may point at bundled media. */
    private const MEDIA_TAGS = ['img', 'video', 'audio', 'source', 'track', 'a', 'embed', 'object', 'iframe'];

    /** @var string[] Attributes that can carry a media reference. */
    private const MEDIA_ATTRS = ['src', 'poster', 'href', 'data'];

    /**
     * Rewrite relative media references (src/poster/href/data on media-bearing
     * tags) to the Moodle pluginfile placeholder and collect the base64 bytes of
     * each referenced package file. The HTML is parsed with DOMDocument so only
     * real attributes are touched (not attribute-looking text), and quoting,
     * nesting and entities are handled by the parser. External URLs, data URIs
     * and in-page anchors are left untouched, as is anything that doesn't resolve
     * to a bundled file.
     *
     * @param string $html The HTML.
     * @param string|null $imagedir Folder to resolve referenced files, or null to skip.
     * @param array $files Collected, keyed by path+name => {path, name, base64} (modified in place).
     * @return string Rewritten HTML.
     */
    protected function embed_files(string $html, ?string $imagedir, array &$files): string {
        if ($imagedir === null || $html === '') {
            return $html;
        }
        // Fast path: nothing to do unless a media-bearing tag is present, and this
        // avoids reserialising plain text through the DOM.
        if (!preg_match('~<(?:' . implode('|', self::MEDIA_TAGS) . ')\b~i', $html)) {
            return $html;
        }

        // The HTML parser (libxml) only knows the HTML4 entity set, so HTML5
        // named entities (e.g. &rightarrow;, &sol;) would otherwise be corrupted
        // on the round-trip. Pre-convert them to numeric references, which it
        // preserves both in text and in attribute values.
        $prepared = $this->numericise_html5_entities($html);

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The meta charset forces UTF-8 parsing. A wrapper <div> keeps media-only
        // and leading-media fragments (e.g. a leading <video>/<audio>) inside the
        // body rather than being hoisted out and lost; we serialise its children.
        $loaded = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>' . $prepared . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
        $wrapper = $body !== null ? $body->getElementsByTagName('div')->item(0) : null;
        if ($wrapper === null) {
            return $html;
        }

        $changed = false;
        foreach (self::MEDIA_TAGS as $tag) {
            foreach (iterator_to_array($wrapper->getElementsByTagName($tag)) as $element) {
                foreach (self::MEDIA_ATTRS as $attr) {
                    if (!$element->hasAttribute($attr)) {
                        continue;
                    }
                    $rewritten = $this->rewrite_ref($element->getAttribute($attr), $imagedir, $files);
                    if ($rewritten !== null) {
                        $element->setAttribute($attr, $rewritten);
                        $changed = true;
                    }
                }
            }
        }

        // Nothing bundled was rewritten: return the original untouched so the
        // surrounding question HTML is never reserialised (and never altered).
        if (!$changed) {
            return $html;
        }

        $out = '';
        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /**
     * Convert HTML5 named entities to numeric character references, leaving the
     * five XML predefined entities (lt, gt, amp, quot, apos) and unknown entities
     * untouched. This lets libxml's HTML4-only parser round-trip HTML5 entities
     * faithfully, in both text and attribute values.
     *
     * @param string $html The HTML.
     * @return string
     */
    protected function numericise_html5_entities(string $html): string {
        $result = preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]{1,31});/', function (array $m): string {
            if (in_array(strtolower($m[1]), ['lt', 'gt', 'amp', 'quot', 'apos'], true)) {
                return $m[0];
            }
            $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $m[0]) {
                return $m[0];
            }
            return mb_encode_numericentity($decoded, [0x0, 0x10ffff, 0, 0x10ffff], 'UTF-8');
        }, $html);
        return $result ?? $html;
    }

    /**
     * Resolve a single media reference: if it points at a bundled file, import it
     * (collecting its base64 bytes) and return the @@PLUGINFILE@@ URL; otherwise
     * return null to leave the reference unchanged. The value is the decoded
     * attribute (DOMDocument has already resolved HTML entities).
     *
     * @param string $value The attribute value.
     * @param string $imagedir Folder to resolve referenced files.
     * @param array $files Collected files (modified in place).
     * @return string|null The rewritten URL, or null to leave it alone.
     */
    protected function rewrite_ref(string $value, string $imagedir, array &$files): ?string {
        if ($value === '') {
            return null;
        }
        // Canvas's $IMS-CC-FILEBASE$ token addresses the package's bundled files
        // (commonly under web_resources/); resolve it against the package root,
        // mirroring link_rewriter, independent of the per-question image folder.
        if (preg_match('~^(?:\$IMS-CC-FILEBASE\$|%24IMS-CC-FILEBASE%24)(.*)$~i', $value, $tm)) {
            return $this->rewrite_filebase_ref($tm[1], $files);
        }
        if (preg_match('~^(https?:|data:|mailto:|tel:|@@PLUGINFILE@@|#)~i', $value)) {
            return null;
        }
        [$path, $suffix] = $this->split_suffix($value);
        $rel = rawurldecode($path);
        // Null bytes make realpath() throw on PHP 8; refuse them.
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return null;
        }
        $abs = $this->safe_join($imagedir, $rel);
        if ($abs === null || !is_file($abs)) {
            return null;
        }
        return $this->collect_file($abs, $imagedir, $suffix, $files);
    }

    /**
     * Resolve a $IMS-CC-FILEBASE$ token reference (everything after the token)
     * against the package root. Canvas commonly stores the file under
     * web_resources/, so that location is tried as well.
     *
     * @param string $rest The path after the token (may carry ?query/#fragment).
     * @param array $files Collected files (modified in place).
     * @return string|null The rewritten @@PLUGINFILE@@ URL, or null to leave it alone.
     */
    protected function rewrite_filebase_ref(string $rest, array &$files): ?string {
        if ($this->filebase === null) {
            return null;
        }
        [$path, $suffix] = $this->split_suffix($rest);
        $rel = ltrim(rawurldecode($path), '/');
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return null;
        }
        foreach ([$rel, 'web_resources/' . $rel] as $candidate) {
            $abs = $this->safe_join($this->filebase, $candidate);
            if ($abs !== null && is_file($abs)) {
                return $this->collect_file($abs, $this->filebase, $suffix, $files);
            }
        }
        return null;
    }

    /**
     * Collect a resolved file's bytes and return its @@PLUGINFILE@@ URL. The
     * stored <file path>/name and the URL are both derived from the canonical
     * path inside $base, so dot segments resolve consistently and the two agree.
     *
     * @param string $abs Absolute path of the resolved file.
     * @param string $base Absolute base directory the file was resolved under.
     * @param string $suffix Any ?query/#fragment to re-append to the URL.
     * @param array $files Collected files (modified in place).
     * @return string The @@PLUGINFILE@@ URL.
     */
    protected function collect_file(string $abs, string $base, string $suffix, array &$files): string {
        $root = realpath($base);
        $relcanon = str_replace('\\', '/', ltrim(substr($abs, strlen((string) $root)), '/\\'));
        $name = basename($relcanon);
        $subdir = trim(dirname($relcanon), '/');
        $subdir = ($subdir === '' || $subdir === '.') ? '' : $subdir;
        $filepath = $subdir === '' ? '/' : '/' . $subdir . '/';
        $key = $filepath . $name;
        if (!isset($files[$key])) {
            $files[$key] = [
                'path' => $filepath,
                'name' => $name,
                'base64' => base64_encode((string) file_get_contents($abs)),
            ];
        }
        // Percent-encode each path segment for the URL while the stored <file
        // path> keeps the literal directory names.
        $urlpath = $subdir === ''
            ? '/'
            : '/' . implode('/', array_map('rawurlencode', explode('/', $subdir))) . '/';
        return '@@PLUGINFILE@@' . $urlpath . rawurlencode($name) . $suffix;
    }

    /**
     * Split a reference into its path and any trailing ?query/#fragment suffix.
     *
     * @param string $value The reference value.
     * @return array A two-element list: [path, suffix].
     */
    protected function split_suffix(string $value): array {
        if (preg_match('/^([^?#]*)([?#].*)$/', $value, $sm)) {
            return [$sm[1], $sm[2]];
        }
        return [$value, ''];
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
        // Require an exact match or a directory-boundary match so a sibling
        // folder sharing a name prefix (e.g. /a10 next to /a1) is not accepted.
        if ($abs !== $root && !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abs;
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
