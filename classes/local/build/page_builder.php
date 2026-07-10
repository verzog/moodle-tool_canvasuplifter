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
 * Creates a mod_page activity from a parsed page item.
 *
 * Reads the HTML payload referenced by the item out of the extracted package
 * directory and hands a fully-populated moduleinfo to {@see add_moduleinfo()}.
 * Link rewriting (placeholders like $IMS-CC-FILEBASE$) is intentionally not
 * done here yet — that's a later patch once files and assignments exist.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_builder {
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
     * Create a mod_page activity in the given section.
     *
     * @param stdClass $course Course record (must have id and category).
     * @param int $sectionnum 0-indexed section number to add the activity to.
     * @param item $modelitem The page item from the parsed model.
     * @return int|null Created course module id, or null if the page payload was missing.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/page/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $contentpath = $this->payload_path($modelitem);
        if ($contentpath === null) {
            if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
                mtrace(sprintf(
                    'tool_canvasuplifter: page "%s" skipped — no readable payload (href=%s, files=%s)',
                    $modelitem->title,
                    $modelitem->href,
                    implode(',', $modelitem->files)
                ));
            }
            return null;
        }
        $content = (string) @file_get_contents($contentpath);
        if ($content === '') {
            if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
                mtrace("tool_canvasuplifter: page \"$modelitem->title\" skipped — empty payload at $contentpath");
            }
            return null;
        }

        $module = $this->module_record('page');
        if ($module === null) {
            return null;
        }

        $title = $modelitem->title !== '' ? $modelitem->title : pathinfo($modelitem->href, PATHINFO_FILENAME);

        // Strip ILIAS viewer chrome (the "Activities" navigation column, focus
        // scripts and "not available" dialogs) so only the page content imports.
        // A no-op for non-ILIAS HTML.
        $content = ilias_cleaner::clean($content);

        // For bundle-promoted pages, rewrite relative <link>/<script>/<img>
        // references to @@PLUGINFILE@@ before the page is stored, so the saved
        // HTML already points at the eventual pluginfile URLs.
        $bundleassets = $modelitem->bundleassets ?? [];
        if (!empty($bundleassets)) {
            $content = bundle_assets::rewrite_refs($content, $bundleassets);
        }

        // Turn relative cross-resource links (ILIAS learning modules linking to
        // each other by path) into $CANVAS_OBJECT_REFERENCE$ tokens, resolved to
        // real activity URLs by course_builder's second link pass. Runs after
        // bundle rewriting so a page's own assets (already @@PLUGINFILE@@) are
        // left alone, and only when a path map was supplied.
        if (!empty($this->pathtoid)) {
            $basedir = page_payload::basedir($this->packageroot, $modelitem);
            $content = (new link_rewriter())->rewrite_relative_links($content, $basedir, $this->pathtoid);
        }

        // Wrap the imported HTML so the plugin stylesheet (scoped to this class)
        // can style it without affecting the rest of Moodle.
        $content = content_styler::wrap($content);

        $moduleinfo = (object) [
            'modulename' => 'page',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $title,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'content' => $content,
            'contentformat' => FORMAT_HTML,
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'displayoptions' => serialize([]),
            'printheading' => 1,
            'printintro' => 0,
            'printlastmodified' => 1,
            'revision' => 1,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        // Import any files the page embeds and rewrite the references so they
        // resolve through pluginfile.php instead of 404ing.
        $this->embed_files($cmid, (int) $created->instance, $content);

        // Import bundle siblings (CSS/JS/images referenced by relative URL).
        if (!empty($bundleassets)) {
            $contextid = \context_module::instance($cmid)->id;
            bundle_assets::import($this->packageroot, $contextid, 'mod_page', 'content', 0, $bundleassets);
        }

        return $cmid;
    }

    /**
     * Import package files referenced by the page and rewrite the stored
     * content to @@PLUGINFILE@@ references.
     *
     * @param int $cmid Course module id of the new page.
     * @param int $instanceid The page instance id.
     * @param string $content The original page HTML.
     * @return void
     */
    private function embed_files(int $cmid, int $instanceid, string $content): void {
        global $DB;

        $context = \context_module::instance($cmid);
        $newcontent = (new file_embedder($this->packageroot))->embed($context->id, 'mod_page', 'content', $content);
        if ($newcontent !== $content) {
            $DB->set_field('page', 'content', $newcontent, ['id' => $instanceid]);
        }
    }

    /**
     * Resolve the HTML file inside the package that backs this page.
     *
     * @param item $modelitem
     * @return string|null Absolute path, or null if nothing is readable.
     */
    private function payload_path(item $modelitem): ?string {
        return page_payload::locate($this->packageroot, $modelitem);
    }

    /**
     * Look up the modules row for a given module name.
     *
     * @param string $name Module name, e.g. "page" or "url".
     * @return stdClass|null
     */
    private function module_record(string $name): ?stdClass {
        global $DB;
        $module = $DB->get_record('modules', ['name' => $name]);
        return $module ?: null;
    }
}
