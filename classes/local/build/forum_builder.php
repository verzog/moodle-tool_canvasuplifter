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

use DOMDocument;
use DOMElement;
use stdClass;
use tool_canvasuplifter\local\model\item;

/**
 * Creates a mod_forum activity from a Canvas discussion-topic resource.
 *
 * Common Cartridge discussion topics (imsdt) carry only the prompt — a title and
 * HTML body — not the replies, which Canvas does not export. This builder creates
 * a standard forum and seeds the prompt as the opening discussion thread, so
 * learners can reply to it. Any media the prompt embeds is imported so it resolves
 * through pluginfile.php.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_builder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
    }

    /**
     * Create a mod_forum activity and seed the discussion prompt.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The discussion item from the parsed model.
     * @return int|null Created course module id, or null if the topic could not be read.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $topic = $this->read_topic($modelitem);
        if ($topic === null) {
            return null;
        }

        $module = $DB->get_record('modules', ['name' => 'forum']);
        if (!$module) {
            return null;
        }

        $name = $modelitem->title !== '' ? $modelitem->title
            : ($topic['title'] !== '' ? $topic['title'] : 'Forum');

        $moduleinfo = $this->moduleinfo($course, $sectionnum, (int) $module->id, $name);
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        $this->seed_discussion($course, (int) $created->instance, $cmid, $name, $topic);

        return $cmid;
    }

    /**
     * Create the opening discussion thread from the topic prompt and import any
     * media it embeds into the first post.
     *
     * @param stdClass $course Course record.
     * @param int $forumid The created forum instance id.
     * @param int $cmid The forum course module id.
     * @param string $name The thread subject.
     * @param array $topic Parsed topic: text, plain (bool), attachments (string[]).
     * @return void
     */
    private function seed_discussion(stdClass $course, int $forumid, int $cmid, string $name, array $topic): void {
        global $DB;

        $prompt = (string) ($topic['text'] ?? '');
        $plain = !empty($topic['plain']);
        // Honour a plain-text prompt so angle brackets and line breaks survive;
        // an empty prompt falls back to the title as HTML.
        if (trim($prompt) !== '') {
            $message = $prompt;
            $format = $plain ? FORMAT_PLAIN : FORMAT_HTML;
        } else {
            $message = '<p>' . s($name) . '</p>';
            $format = FORMAT_HTML;
        }

        $discussion = (object) [
            'course' => $course->id,
            'forum' => $forumid,
            'name' => shorten_text($name, 255),
            'message' => $message,
            'messageformat' => $format,
            'messagetrust' => 0,
            'mailnow' => 0,
            'groupid' => -1,
        ];
        $discussionid = forum_add_discussion($discussion, null, null, get_admin()->id);
        if (!$discussionid) {
            return;
        }

        $firstpostid = (int) $DB->get_field('forum_discussions', 'firstpost', ['id' => $discussionid]);
        if ($firstpostid <= 0) {
            return;
        }
        $context = \context_module::instance($cmid);

        // Import prompt media (HTML only) into the first post and rewrite refs.
        if ($format === FORMAT_HTML) {
            $newmessage = (new file_embedder($this->packageroot))
                ->embed($context->id, 'mod_forum', 'post', $message, $firstpostid);
            if ($newmessage !== $message) {
                $DB->set_field('forum_posts', 'message', $newmessage, ['id' => $firstpostid]);
            }
        }

        $this->import_attachments($context->id, $firstpostid, (array) ($topic['attachments'] ?? []));
    }

    /**
     * Import the topic's declared attachment files as forum post attachments.
     *
     * @param int $contextid The forum module context id.
     * @param int $postid The first post's id.
     * @param string[] $hrefs Package-relative attachment paths.
     * @return void
     */
    private function import_attachments(int $contextid, int $postid, array $hrefs): void {
        global $DB;
        $fs = get_file_storage();
        $stored = 0;
        foreach ($hrefs as $href) {
            $absolute = safe_path::within($this->packageroot, $href);
            if ($absolute === null || !is_file($absolute)) {
                continue;
            }
            $filename = clean_param(basename($href), PARAM_FILE);
            if ($filename === '' || $fs->file_exists($contextid, 'mod_forum', 'attachment', $postid, '/', $filename)) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $contextid,
                'component' => 'mod_forum',
                'filearea' => 'attachment',
                'itemid' => $postid,
                'filepath' => '/',
                'filename' => $filename,
            ], $absolute);
            $stored++;
        }
        if ($stored > 0) {
            // Match mod_forum, which flags a post carrying attachments with "1".
            $DB->set_field('forum_posts', 'attachment', '1', ['id' => $postid]);
        }
    }

    /**
     * Assemble the moduleinfo for add_moduleinfo(), mirroring the mod_forum form
     * defaults for a plain, unassessed discussion forum.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum Section number.
     * @param int $moduleid The forum entry in the modules table.
     * @param string $name Activity name.
     * @return stdClass
     */
    private function moduleinfo(stdClass $course, int $sectionnum, int $moduleid, string $name): stdClass {
        return (object) [
            'modulename' => 'forum',
            'module' => $moduleid,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'cmidnumber' => '',
            'name' => shorten_text($name, 255),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'type' => 'general',
            'forcesubscribe' => FORUM_CHOOSESUBSCRIBE,
            'trackingtype' => FORUM_TRACKING_OPTIONAL,
            'assessed' => 0,
            'scale' => 0,
            'ratingtime' => 0,
            'assesstimestart' => 0,
            'assesstimefinish' => 0,
            'grade_forum' => 0,
            'maxbytes' => 0,
            'maxattachments' => 1,
            'rsstype' => 0,
            'rssarticles' => 0,
            'warnafter' => 0,
            'blockafter' => 0,
            'blockperiod' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
            'displaywordcount' => 0,
            'lockdiscussionafter' => 0,
            'completion' => 0,
            'completiondiscussions' => 0,
            'completionreplies' => 0,
            'completionposts' => 0,
        ];
    }

    /**
     * Read the discussion topic title and body from the package.
     *
     * Common Cartridge stores the topic in an XML file (imsdt) shaped
     * <topic><title>…</title><text texttype="text/html">…</text></topic>. Parsed
     * namespace-agnostically with DOMDocument.
     *
     * @param item $modelitem The discussion item.
     * @return array|null ['title','text','plain'=>bool,'attachments'=>string[]] or null if unreadable.
     */
    private function read_topic(item $modelitem): ?array {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml$/i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute === null || !is_readable($absolute)) {
                continue;
            }
            $parsed = $this->parse_topic((string) @file_get_contents($absolute));
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * Parse a Common Cartridge discussion-topic XML document.
     *
     * @param string $xml The topic XML.
     * @return array|null ['title','text','plain'=>bool,'attachments'=>string[]] or null if not a topic.
     */
    private function parse_topic(string $xml): ?array {
        if (trim($xml) === '') {
            return null;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $topics = $dom->getElementsByTagNameNS('*', 'topic');
        if ($topics->length === 0) {
            return null;
        }
        $titles = $dom->getElementsByTagNameNS('*', 'title');
        $texts = $dom->getElementsByTagNameNS('*', 'text');
        $text = $texts->item(0);
        // Treat the body as plain text only when the schema says so explicitly;
        // Canvas marks HTML as texttype="text/html" and omitting it is rare.
        $plain = $text instanceof DOMElement
            && strtolower(trim($text->getAttribute('texttype'))) === 'text/plain';

        $attachments = [];
        foreach ($dom->getElementsByTagNameNS('*', 'attachment') as $att) {
            if ($att instanceof DOMElement && trim($att->getAttribute('href')) !== '') {
                $attachments[] = trim($att->getAttribute('href'));
            }
        }

        return [
            'title' => $titles->length > 0 ? trim($titles->item(0)->textContent) : '',
            'text' => $text !== null ? trim($text->textContent) : '',
            'plain' => $plain,
            'attachments' => $attachments,
        ];
    }
}
