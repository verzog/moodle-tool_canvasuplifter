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

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
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
            mtrace(sprintf(
                'tool_canvasuplifter: page "%s" skipped — no readable payload (href=%s, files=%s)',
                $modelitem->title,
                $modelitem->href,
                implode(',', $modelitem->files)
            ));
            return null;
        }
        $content = (string) @file_get_contents($contentpath);
        if ($content === '') {
            mtrace("tool_canvasuplifter: page \"$modelitem->title\" skipped — empty payload at $contentpath");
            return null;
        }

        $module = $this->module_record('page');
        if ($module === null) {
            return null;
        }

        $title = $modelitem->title !== '' ? $modelitem->title : pathinfo($modelitem->href, PATHINFO_FILENAME);

        // For bundle-promoted pages, rewrite relative <link>/<script>/<img>
        // references to @@PLUGINFILE@@ before the page is stored, so the saved
        // HTML already points at the eventual pluginfile URLs.
        $bundleassets = $modelitem->bundleassets ?? [];
        if (!empty($bundleassets)) {
            $content = $this->rewrite_bundle_refs($content, $bundleassets);
        }

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
            $this->import_bundle_assets($cmid, $bundleassets);
        }

        return $cmid;
    }

    /**
     * Rewrite relative href/src URLs that point at known bundle assets to
     * Moodle pluginfile references. Anything that doesn't match a listed
     * asset (external links, in-page anchors, javascript: URLs) is left alone.
     *
     * @param string $content The original page HTML.
     * @param array $bundleassets List of ['source','relpath'] entries.
     * @return string Rewritten HTML.
     */
    private function rewrite_bundle_refs(string $content, array $bundleassets): string {
        $assetset = [];
        foreach ($bundleassets as $asset) {
            $rel = ltrim((string) ($asset['relpath'] ?? ''), '/');
            if ($rel !== '') {
                $assetset[$rel] = true;
            }
        }
        if (empty($assetset)) {
            return $content;
        }
        // Capture the URL path (up to a ?/# suffix) and the suffix separately
        // so cache-busting query strings and #fragments survive the rewrite.
        // Absolute URLs (http://, https://, //host, /root) are left alone.
        // Delimiter ~ so the literal '#' in the URL char classes doesn't
        // terminate the pattern early (which would only see [^"\'?] and
        // throw "Unknown modifier ']'").
        $pattern = '~\b(href|src)\s*=\s*(["\'])([^"\'?#]+)([?#][^"\']*)?\2~i';
        return (string) preg_replace_callback($pattern, function (array $m) use ($assetset): string {
            $path = $m[3];
            $suffix = $m[4] ?? '';
            if (
                $path === '' || $path[0] === '/'
                || strpos($path, '://') !== false || strpos($path, '//') === 0
            ) {
                return $m[0];
            }
            $candidate = ltrim(preg_replace('#^\./#', '', $path), '/');
            if (!isset($assetset[$candidate])) {
                return $m[0];
            }
            return $m[1] . '=' . $m[2] . '@@PLUGINFILE@@/' . $candidate . $suffix . $m[2];
        }, $content);
    }

    /**
     * Copy bundle sibling files into the page's content filearea so the
     * rewritten pluginfile URLs resolve. Each asset is stored at its
     * relative-to-anchor path so the same path the HTML references is also
     * the path inside pluginfile.
     *
     * @param int $cmid The page's course module id.
     * @param array $bundleassets List of ['source','relpath'] entries.
     * @return void
     */
    private function import_bundle_assets(int $cmid, array $bundleassets): void {
        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        foreach ($bundleassets as $asset) {
            $source = (string) ($asset['source'] ?? '');
            $relpath = ltrim((string) ($asset['relpath'] ?? ''), '/');
            if ($source === '' || $relpath === '') {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $source);
            if ($absolute === null || !is_readable($absolute)) {
                continue;
            }
            $filename = basename($relpath);
            $filepath = '/' . trim((string) dirname($relpath), '/.');
            if ($filepath !== '/' && !str_ends_with($filepath, '/')) {
                $filepath .= '/';
            }
            if ($fs->file_exists($context->id, 'mod_page', 'content', 0, $filepath, $filename)) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_page',
                'filearea' => 'content',
                'itemid' => 0,
                'filepath' => $filepath,
                'filename' => $filename,
            ], $absolute);
        }
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
