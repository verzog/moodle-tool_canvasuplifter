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
 * Builds one mod_lesson activity from a run of consecutive Canvas page items,
 * with one content (branch table) page per Canvas page, chained linearly.
 *
 * Like {@see book_builder}, this collapses many model items into a single
 * activity, so it exposes {@see build_group()} rather than the uniform
 * build(course, sectionnum, item) signature. course_builder routes maximal runs
 * of pages here when the "combine pages" build option is set to "lesson".
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_builder {
    /** @var int Lesson "content" (branch table) page type (mod_lesson's LESSON_PAGE_BRANCHTABLE). */
    private const PAGE_CONTENT = 20;

    /** @var int Jump value "next page" (mod_lesson's LESSON_NEXTPAGE). */
    private const JUMP_NEXTPAGE = -1;

    /** @var int Jump value "end of lesson" (mod_lesson's LESSON_EOL). */
    private const JUMP_EOL = -9;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var array<string, string> Package-relative path => resource identifier, for relative-link rewriting. */
    private array $pathtoid;

    /** @var media_report|null Shared collector for unresolved media references. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param array $pathtoid Map of package-relative path to resource identifier (for cross-resource links).
     * @param media_report|null $mediareport Shared collector for unresolved media references (null to skip).
     */
    public function __construct(string $packageroot, array $pathtoid = [], ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->pathtoid = $pathtoid;
        $this->mediareport = $mediareport;
    }

    /**
     * Create a mod_lesson whose content pages are the given pages, in order.
     *
     * Pages whose HTML payload cannot be read are skipped; if none survive, the
     * empty lesson is removed and null is returned so the caller can fall back
     * to building individual pages.
     *
     * @param stdClass $course Course record (must have id and format).
     * @param int $sectionnum Section number to place the lesson in.
     * @param string $name Activity name (typically the section title).
     * @param array $pages The page items to turn into content pages.
     * @return array|null ['cmid' => int, 'pages' => array of per-page records], or null on failure.
     */
    public function build_group(stdClass $course, int $sectionnum, string $name, array $pages): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/lesson/lib.php');
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        require_once($CFG->libdir . '/filelib.php');

        $module = $DB->get_record('modules', ['name' => 'lesson']);
        if (!$module) {
            return null;
        }

        $moduleinfo = (object) (self::lesson_defaults() + [
            'modulename' => 'lesson',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $name !== '' ? $name : get_string('defaultlessonname', 'tool_canvasuplifter'),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            // The lesson_add_instance() handler reads mediafile as a file-manager
            // draft id; an unused (empty) draft means "no intro media" without a warning.
            'mediafile' => file_get_unused_draft_itemid(),
        ]);
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;
        $lessonid = (int) $created->instance;
        $context = \context_module::instance($cmid);
        $embedder = new file_embedder($this->packageroot, $this->mediareport);

        $out = [];
        $pageids = [];
        $now = time();
        foreach ($pages as $page) {
            $html = page_payload::html($this->packageroot, $page);
            if ($html === null) {
                continue;
            }
            // Strip ILIAS viewer chrome (no-op for non-ILIAS HTML) before storing.
            $html = ilias_cleaner::clean($html);
            // The page's own folder within the package: media it references with a
            // bare $IMS-CC-FILEBASE$name or a ../ climb resolves relative to this.
            $basedir = page_payload::basedir($this->packageroot, $page);
            // Rewrite relative refs to a folded bundle's sibling assets (eXe /
            // ILIAS CSS, JS, images) to @@PLUGINFILE@@ before storing; the files
            // themselves are imported into the page file area below. Runs before
            // the cross-resource link pass so a page's own assets are left alone
            // by it.
            $bundleassets = $page->bundleassets ?? [];
            if (!empty($bundleassets)) {
                $html = bundle_assets::rewrite_refs($html, $bundleassets);
            }
            // Turn relative cross-resource links (ILIAS module-to-module) into
            // object-reference tokens, resolved to activity URLs by the grouped
            // second link pass. Done per page so each resolves against its own
            // source directory.
            if (!empty($this->pathtoid)) {
                $html = (new link_rewriter())->rewrite_relative_links($html, $basedir, $this->pathtoid);
            }
            // Wrap so the plugin stylesheet (scoped to this class) can style the
            // imported content without affecting the rest of Moodle.
            $html = content_styler::wrap($html);
            $record = (object) [
                'lessonid' => $lessonid,
                'prevpageid' => 0,
                'nextpageid' => 0,
                'qtype' => self::PAGE_CONTENT,
                'qoption' => 0,
                'layout' => 1,
                // The left-hand menu lists pages where display is set; keep
                // unpublished Canvas pages out of it (and off the direct links)
                // by mirroring the page's visibility, as the book builder does
                // with its chapter "hidden" flag.
                'display' => $page->isvisible ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'title' => page_payload::title($page),
                'contents' => $html,
                'contentsformat' => FORMAT_HTML,
            ];
            $pageid = (int) $DB->insert_record('lesson_pages', $record);

            $newhtml = $embedder->embed($context->id, 'mod_lesson', 'page_contents', $html, $pageid, $basedir);
            if ($newhtml !== $html) {
                $DB->set_field('lesson_pages', 'contents', $newhtml, ['id' => $pageid]);
            }

            // Copy the folded bundle's sibling files into this page's file area
            // so the @@PLUGINFILE@@ refs rewritten above resolve.
            if (!empty($bundleassets)) {
                bundle_assets::import(
                    $this->packageroot,
                    $context->id,
                    'mod_lesson',
                    'page_contents',
                    $pageid,
                    $bundleassets,
                    $this->mediareport
                );
            }

            // A content (branch table) page needs at least one navigation button;
            // a single "Continue" jumping to the next page gives linear reading.
            $DB->insert_record('lesson_answers', (object) [
                'lessonid' => $lessonid,
                'pageid' => $pageid,
                'jumpto' => self::JUMP_NEXTPAGE,
                'grade' => 0,
                'score' => 0,
                'flags' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'answer' => get_string('continue'),
                'answerformat' => FORMAT_HTML,
                'response' => '',
                'responseformat' => FORMAT_HTML,
            ]);

            $pageids[] = $pageid;
            $url = (new \moodle_url('/mod/lesson/view.php', ['id' => $cmid, 'pageid' => $pageid]))->out(false);
            $out[] = [
                'item' => $page,
                'url' => $url,
                'rewrite' => ['table' => 'lesson_pages', 'id' => $pageid, 'field' => 'contents'],
            ];
        }

        if (empty($out)) {
            course_delete_module($cmid);
            return null;
        }
        $this->link_pages($pageids);
        return ['cmid' => $cmid, 'pages' => $out];
    }

    /**
     * Chain the lesson pages with prev/next pointers and send the final page's
     * button to end-of-lesson rather than "next page".
     *
     * @param array $pageids Lesson page ids in display order.
     * @return void
     */
    private function link_pages(array $pageids): void {
        global $DB;
        $count = count($pageids);
        foreach ($pageids as $i => $pageid) {
            $prev = $i > 0 ? $pageids[$i - 1] : 0;
            $next = $i < $count - 1 ? $pageids[$i + 1] : 0;
            $DB->set_field('lesson_pages', 'prevpageid', $prev, ['id' => $pageid]);
            $DB->set_field('lesson_pages', 'nextpageid', $next, ['id' => $pageid]);
        }
        $DB->set_field('lesson_answers', 'jumpto', self::JUMP_EOL, ['pageid' => $pageids[$count - 1]]);
    }

    /**
     * Default values for every lesson column, so add_moduleinfo() and
     * lesson_add_instance() never read an undefined property and the build is
     * a plain, ungraded, linear reading lesson.
     *
     * displayleft is on so the lesson always shows its left-hand page menu: with
     * a run of pages folded into one lesson, the menu lists every page up front
     * so it is immediately clear what content the lesson holds. displayleftif is
     * 0 (no minimum grade) so the menu shows on an ungraded reading lesson.
     *
     * @return array
     */
    private static function lesson_defaults(): array {
        return [
            'practice' => 0, 'modattempts' => 0, 'usepassword' => 0, 'password' => '',
            'dependency' => 0, 'conditions' => '', 'grade' => 0, 'custom' => 0, 'ongoing' => 0,
            'usemaxgrade' => 0, 'maxanswers' => 4, 'maxattempts' => 5, 'review' => 0,
            'nextpagedefault' => 0, 'feedback' => 1, 'minquestions' => 0, 'maxpages' => 0,
            'timelimit' => 0, 'retake' => 1, 'activitylink' => 0, 'mediaheight' => 100,
            'mediawidth' => 650, 'mediaclose' => 0, 'slideshow' => 0, 'width' => 640,
            'height' => 480, 'bgcolor' => '#FFFFFF', 'displayleft' => 1, 'displayleftif' => 0,
            'progressbar' => 0, 'available' => 0, 'deadline' => 0, 'completionendreached' => 0,
            'completiontimespent' => 0, 'allowofflineattempts' => 0,
        ];
    }
}
