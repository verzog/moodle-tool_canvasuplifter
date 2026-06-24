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

use stdClass;
use tool_canvasuplifter\local\model\item;

/**
 * Builds one mod_book activity from a run of consecutive Canvas page items, with
 * one book chapter per page.
 *
 * Unlike the per-item builders, this one collapses many model items into a
 * single activity, so it exposes {@see build_group()} rather than the uniform
 * build(course, sectionnum, item) signature. course_builder routes maximal runs
 * of pages here when the "combine pages" build option is set to "book".
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_builder {
    /** @var int Book numbering style "none" (mod_book's BOOK_NUM_NONE). */
    private const NUMBERING_NONE = 0;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var array<string, string> Package-relative path => resource identifier, for relative-link rewriting. */
    private array $pathtoid;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param array $pathtoid Map of package-relative path to resource identifier (for cross-resource links).
     */
    public function __construct(string $packageroot, array $pathtoid = []) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->pathtoid = $pathtoid;
    }

    /**
     * Create a mod_book whose chapters are the given pages, in order.
     *
     * Pages whose HTML payload cannot be read are skipped; if none survive, the
     * empty book is removed and null is returned so the caller can fall back to
     * building individual pages.
     *
     * @param stdClass $course Course record (must have id and format).
     * @param int $sectionnum Section number to place the book in.
     * @param string $name Activity name (typically the section title).
     * @param array $pages The page items to turn into chapters.
     * @return array|null ['cmid' => int, 'pages' => array of per-chapter records], or null on failure.
     */
    public function build_group(stdClass $course, int $sectionnum, string $name, array $pages): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/book/lib.php');

        $module = $DB->get_record('modules', ['name' => 'book']);
        if (!$module) {
            return null;
        }

        $moduleinfo = (object) [
            'modulename' => 'book',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $name !== '' ? $name : get_string('defaultbookname', 'tool_canvasuplifter'),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            // Canvas page titles often already carry their own numbering
            // ("Chapter 1: ..."), so leave Moodle's automatic numbering off.
            'numbering' => self::NUMBERING_NONE,
            'navstyle' => 1,
            'customtitles' => 0,
            'revision' => 1,
        ];
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;
        $bookid = (int) $created->instance;
        $context = \context_module::instance($cmid);
        $embedder = new file_embedder($this->packageroot);

        $out = [];
        $pagenum = 1;
        $now = time();
        foreach ($pages as $page) {
            $html = page_payload::html($this->packageroot, $page);
            if ($html === null) {
                continue;
            }
            // Strip ILIAS viewer chrome (no-op for non-ILIAS HTML) before storing.
            $html = ilias_cleaner::clean($html);
            // Rewrite relative refs to a folded bundle's sibling assets (eXe /
            // ILIAS CSS, JS, images) to @@PLUGINFILE@@ before storing; the files
            // themselves are imported into the chapter file area below. Runs
            // before the cross-resource link pass so a page's own assets are
            // left alone by it.
            $bundleassets = $page->bundleassets ?? [];
            if (!empty($bundleassets)) {
                $html = bundle_assets::rewrite_refs($html, $bundleassets);
            }
            // Turn relative cross-resource links (ILIAS module-to-module) into
            // object-reference tokens, resolved to activity URLs by the grouped
            // second link pass. Done per chapter so each resolves against its
            // own source directory.
            if (!empty($this->pathtoid)) {
                $basedir = page_payload::basedir($this->packageroot, $page);
                $html = (new link_rewriter())->rewrite_relative_links($html, $basedir, $this->pathtoid);
            }
            $chapter = (object) [
                'bookid' => $bookid,
                'pagenum' => $pagenum,
                'subchapter' => 0,
                'title' => page_payload::title($page),
                'content' => $html,
                'contentformat' => FORMAT_HTML,
                'hidden' => $page->isvisible ? 0 : 1,
                'importsrc' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $chapterid = (int) $DB->insert_record('book_chapters', $chapter);

            // Import any files the page embeds into this chapter's file area and
            // rewrite the references so they resolve through pluginfile.php.
            $newhtml = $embedder->embed($context->id, 'mod_book', 'chapter', $html, $chapterid);
            if ($newhtml !== $html) {
                $DB->set_field('book_chapters', 'content', $newhtml, ['id' => $chapterid]);
            }

            // Copy the folded bundle's sibling files into this chapter's file
            // area so the @@PLUGINFILE@@ refs rewritten above resolve.
            if (!empty($bundleassets)) {
                bundle_assets::import($this->packageroot, $context->id, 'mod_book', 'chapter', $chapterid, $bundleassets);
            }

            $url = (new \moodle_url('/mod/book/view.php', ['id' => $cmid, 'chapterid' => $chapterid]))->out(false);
            $out[] = [
                'item' => $page,
                'url' => $url,
                'rewrite' => ['table' => 'book_chapters', 'id' => $chapterid, 'field' => 'content'],
            ];
            $pagenum++;
        }

        if (empty($out)) {
            // No chapter had a readable payload; drop the empty book.
            course_delete_module($cmid);
            return null;
        }
        return ['cmid' => $cmid, 'pages' => $out];
    }
}
