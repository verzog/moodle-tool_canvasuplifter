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

/**
 * Rewrites and imports the sibling files of a folded lesson bundle.
 *
 * eXe / ILIAS / IGEN exports ship a page as an index.html plus a tree of
 * sibling CSS, JavaScript and image files referenced by relative URL. The
 * manifest parser folds those siblings onto the anchor page as "bundle assets"
 * (each a ['source','relpath'] pair). This helper turns the relative references
 * in the stored HTML into @@PLUGINFILE@@ links and copies the sibling files into
 * the activity's file area so the links resolve. It is the same job whichever
 * activity the page lands in — a mod_page on its own, or a mod_book chapter /
 * mod_lesson page when consecutive pages are combined — so the logic lives here
 * rather than being duplicated in each builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bundle_assets {
    /**
     * Rewrite relative href/src URLs that point at known bundle assets to
     * Moodle pluginfile references. Anything that doesn't match a listed
     * asset (external links, in-page anchors, javascript: URLs) is left alone.
     *
     * @param string $content The original page HTML.
     * @param array $bundleassets List of ['source','relpath'] entries.
     * @return string Rewritten HTML.
     */
    public static function rewrite_refs(string $content, array $bundleassets): string {
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
            // Match on the decoded path: the package-relative bundle asset paths
            // are decoded, but the HTML may carry a percent-encoded URL — either
            // authored that way or encoded when an upstream DOM pass (the ILIAS
            // cleaner) re-serialised a path with a space or a non-ASCII
            // character. Without this, "data/my pic.jpg" stored as
            // "data/my%20pic.jpg" would never match and the link would 404.
            $decoded = rawurldecode($candidate);
            if (!isset($assetset[$decoded])) {
                return $m[0];
            }
            // Emit a re-encoded path so a reserved character in the filename
            // (a literal # or ?) cannot terminate the URL early — e.g. the
            // decoded "data/a#b.png" must be written as "data/a%23b.png", not
            // "data/a#b.png" which the browser would treat as a fragment.
            $encoded = implode('/', array_map('rawurlencode', explode('/', $decoded)));
            return $m[1] . '=' . $m[2] . '@@PLUGINFILE@@/' . $encoded . $suffix . $m[2];
        }, $content);
    }

    /**
     * Copy bundle sibling files into an activity's file area so the rewritten
     * pluginfile URLs resolve. Each asset is stored at its relative-to-anchor
     * path so the same path the HTML references is also the path inside
     * pluginfile.
     *
     * @param string $packageroot Absolute path to the extracted package root.
     * @param int $contextid Module context id to store the files against.
     * @param string $component Frankenstyle component, e.g. "mod_page" or "mod_book".
     * @param string $filearea File area, e.g. "content" or "chapter".
     * @param int $itemid File area item id (0 for content areas, the record id for chapter/page areas).
     * @param array $bundleassets List of ['source','relpath'] entries.
     * @param media_report|null $mediareport Collector to record embedded bundle files into (null to skip).
     * @return void
     */
    public static function import(
        string $packageroot,
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        array $bundleassets,
        ?media_report $mediareport = null
    ): void {
        $packageroot = rtrim($packageroot, '/');
        $fs = get_file_storage();
        foreach ($bundleassets as $asset) {
            $source = (string) ($asset['source'] ?? '');
            $relpath = ltrim((string) ($asset['relpath'] ?? ''), '/');
            if ($source === '' || $relpath === '') {
                continue;
            }
            $absolute = safe_path::within($packageroot, $source);
            if ($absolute === null || !is_readable($absolute)) {
                continue;
            }
            $filename = basename($relpath);
            // Trim only slashes (not dots), so a leading-dot directory such as
            // ".data" keeps its name and matches the URL rewrite_refs() emits;
            // a top-level file (dirname ".") maps to the filearea root.
            $dir = dirname($relpath);
            $filepath = ($dir === '' || $dir === '.') ? '/' : '/' . trim($dir, '/') . '/';
            if (!$fs->file_exists($contextid, $component, $filearea, $itemid, $filepath, $filename)) {
                $fs->create_file_from_pathname([
                    'contextid' => $contextid,
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => $itemid,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ], $absolute);
            }
            // Record the folded asset as embedded (once it is confirmed in the area, already
            // present or just created), so course_builder's reconciliation does not recover a
            // file bundled into this built activity as a duplicate standalone download.
            if ($mediareport !== null) {
                $mediareport->record_embedded($absolute);
            }
        }
    }
}
