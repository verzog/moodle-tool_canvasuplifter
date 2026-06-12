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
 * Rewrites the Canvas placeholder links found in exported page HTML.
 *
 * Canvas Common Cartridge does not store real URLs in page content. Instead it
 * leaves placeholder tokens:
 *  - $IMS-CC-FILEBASE$/path        embedded files (images, attachments)
 *  - $WIKI_REFERENCE$/pages/slug   links to other wiki pages
 *  - $CANVAS_OBJECT_REFERENCE$/... links to other activities
 *
 * This class converts file placeholders to Moodle @@PLUGINFILE@@ references
 * (and reports which package files back them, so the caller can import them),
 * and converts internal references to real activity URLs given a lookup map.
 *
 * It has no Moodle dependencies so it can be unit-tested in isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_rewriter {
    /**
     * Rewrite embedded-file placeholders to @@PLUGINFILE@@ references.
     *
     * @param string $html The page HTML.
     * @param string $packageroot Absolute path to the extracted package root.
     * @return array{html: string, files: array<int, array{package: string, filepath: string, filename: string}>}
     *         The rewritten HTML and the list of package files to import into the page's file area.
     */
    public function rewrite_files(string $html, string $packageroot): array {
        $files = [];
        $seen = [];
        $pattern = '#(?:\$IMS-CC-FILEBASE\$|%24IMS-CC-FILEBASE%24)([^"\'\s>)]*)#i';
        $rewritten = preg_replace_callback($pattern, function ($matches) use ($packageroot, &$files, &$seen) {
            $rawpath = preg_replace('/[?#].*$/', '', $matches[1]);
            $decoded = ltrim(rawurldecode((string) $rawpath), '/');
            if ($decoded === '') {
                return $matches[0];
            }
            $absolute = $this->resolve_in_package($packageroot, $decoded);
            if ($absolute === null) {
                // Cannot find the file; leave the placeholder untouched.
                return $matches[0];
            }
            [$filepath, $filename] = $this->split_path($decoded);
            $key = $filepath . $filename;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $files[] = ['package' => $absolute, 'filepath' => $filepath, 'filename' => $filename];
            }
            return $this->pluginfile_url($filepath, $filename);
        }, $html);

        return ['html' => $rewritten ?? $html, 'files' => $files];
    }

    /**
     * Rewrite internal wiki/object references to real Moodle activity URLs.
     *
     * Unresolved references (no matching entry in the map) are left untouched
     * so no information is lost.
     *
     * @param string $html The page HTML.
     * @param array $urlmap Keys like "wiki:<slug>" or "id:<identifier>" mapped to URLs.
     * @return string The rewritten HTML.
     */
    public function rewrite_internal_links(string $html, array $urlmap): string {
        // Wiki page references: $WIKI_REFERENCE$/pages/<slug>.
        $wikipattern = '~(?:\$WIKI_REFERENCE\$|%24WIKI_REFERENCE%24)/pages/([^"\'\s>?#]*)~i';
        $html = preg_replace_callback($wikipattern, function ($matches) use ($urlmap) {
            $slug = rawurldecode($matches[1]);
            return $urlmap['wiki:' . $slug] ?? $matches[0];
        }, $html) ?? $html;

        // Object references: $CANVAS_OBJECT_REFERENCE$/<type>/<identifier>.
        $objectpattern = '~(?:\$CANVAS_OBJECT_REFERENCE\$|%24CANVAS_OBJECT_REFERENCE%24)/[^/"\'\s>]+/([^"\'\s>?#]*)~i';
        $html = preg_replace_callback($objectpattern, function ($matches) use ($urlmap) {
            $id = rawurldecode($matches[1]);
            return $urlmap['id:' . $id] ?? $matches[0];
        }, $html) ?? $html;

        return $html;
    }

    /**
     * Resolve a package-relative reference to a real file, trying the common
     * Canvas file-base locations.
     *
     * @param string $root Absolute package root.
     * @param string $decoded Decoded, root-relative reference path.
     * @return string|null Absolute path within the package, or null if not found.
     */
    private function resolve_in_package(string $root, string $decoded): ?string {
        foreach ([$decoded, 'web_resources/' . $decoded] as $candidate) {
            $absolute = safe_path::within($root, $candidate);
            if ($absolute !== null && is_file($absolute)) {
                return $absolute;
            }
        }
        return null;
    }

    /**
     * Split a relative path into a Moodle filearea filepath and filename.
     *
     * @param string $decoded Decoded, root-relative path.
     * @return array{0: string, 1: string} [filepath (with leading/trailing slash), filename].
     */
    private function split_path(string $decoded): array {
        $decoded = ltrim($decoded, '/');
        $pos = strrpos($decoded, '/');
        if ($pos === false) {
            return ['/', $decoded];
        }
        return ['/' . substr($decoded, 0, $pos + 1), substr($decoded, $pos + 1)];
    }

    /**
     * Build the @@PLUGINFILE@@ reference for a stored file, URL-encoding each segment.
     *
     * @param string $filepath Filearea filepath (with leading/trailing slash).
     * @param string $filename File name.
     * @return string The @@PLUGINFILE@@ reference.
     */
    private function pluginfile_url(string $filepath, string $filename): string {
        $relative = ltrim($filepath, '/') . $filename;
        $segments = array_map('rawurlencode', explode('/', $relative));
        return '@@PLUGINFILE@@/' . implode('/', $segments);
    }
}
