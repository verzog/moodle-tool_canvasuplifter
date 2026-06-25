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
    /** @var string Regex fragment matching Canvas's $IMS-CC-FILEBASE$ token, raw or URL-encoded. */
    public const FILEBASE_TOKEN = '(?:\$IMS-CC-FILEBASE\$|%24IMS-CC-FILEBASE%24)';

    /**
     * Resolve a decoded, root-relative path from an $IMS-CC-FILEBASE$ token to a
     * real package file, trying the bare path and the web_resources/ location
     * Canvas commonly uses. Shared by the page file embedder (filearea storage)
     * and the question writer (base64 import XML) so the token resolves one way.
     *
     * @param string $packageroot Absolute package root.
     * @param string $relpath Decoded, root-relative reference path.
     * @return string|null Absolute path within the package, or null if not found.
     */
    public static function resolve_filebase(string $packageroot, string $relpath): ?string {
        $relpath = ltrim($relpath, '/');
        if ($relpath === '' || strpos($relpath, "\0") !== false) {
            return null;
        }
        foreach ([$relpath, 'web_resources/' . $relpath] as $candidate) {
            $absolute = safe_path::within($packageroot, $candidate);
            if ($absolute !== null && is_file($absolute)) {
                return $absolute;
            }
        }
        return null;
    }

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
        $pattern = '#' . self::FILEBASE_TOKEN . '([^"\'\s>)]*)#i';
        $rewritten = preg_replace_callback($pattern, function ($matches) use ($packageroot, &$files, &$seen) {
            $rawpath = preg_replace('/[?#].*$/', '', $matches[1]);
            $decoded = ltrim(rawurldecode((string) $rawpath), '/');
            if ($decoded === '') {
                return $matches[0];
            }
            $absolute = self::resolve_filebase($packageroot, $decoded);
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

        // Object references: $CANVAS_OBJECT_REFERENCE$/<type>/<identifier>, plus
        // any trailing ?query/#fragment the source link carried.
        $objectpattern = '~(?:\$CANVAS_OBJECT_REFERENCE\$|%24CANVAS_OBJECT_REFERENCE%24)'
            . '/[^/"\'\s>]+/([^"\'\s>?#]*)([?#][^"\'\s>]*)?~i';
        $html = preg_replace_callback($objectpattern, function ($matches) use ($urlmap) {
            $id = rawurldecode($matches[1]);
            if (!isset($urlmap['id:' . $id])) {
                return $matches[0];
            }
            return self::join_suffix($urlmap['id:' . $id], $matches[2] ?? '');
        }, $html) ?? $html;

        return $html;
    }

    /**
     * Append a preserved ?query/#fragment suffix to a resolved activity URL
     * without producing a double "?": the generated Moodle URLs already carry
     * "?id=…", so a suffix that also opens a query string is joined with "&".
     *
     * @param string $url The resolved activity URL.
     * @param string $suffix The carried suffix ('' / '?…' / '#…' / '?…#…').
     * @return string The combined URL.
     */
    private static function join_suffix(string $url, string $suffix): string {
        if ($suffix === '') {
            return $url;
        }
        if ($suffix[0] === '?' && strpos($url, '?') !== false) {
            $suffix = '&' . substr($suffix, 1);
        }
        return $url . $suffix;
    }

    /**
     * Rewrite relative cross-resource links to Canvas object-reference tokens.
     *
     * Non-Canvas exporters (e.g. ILIAS) link between learning modules with plain
     * relative paths like <a href="../OTHER_LM/index.html"> rather than Canvas
     * placeholder tokens. Given the package directory the page's source HTML
     * lives in and a map of package-relative path => built resource identifier,
     * this resolves such a link to its target resource and rewrites it to a
     * $CANVAS_OBJECT_REFERENCE$ token, so the existing internal-link pass turns
     * it into the real activity URL once every target is built. Absolute URLs,
     * in-page anchors, mailto:/javascript: schemes and references that don't
     * resolve to a built resource are left untouched.
     *
     * Only the href of navigational <a> anchors is considered. Other relative
     * references (a <link rel="stylesheet">, a <base href>, an <img src>) are
     * left alone so a stylesheet or image whose path also happens to back a
     * file resource is not turned into an activity URL.
     *
     * @param string $html The page HTML (after file/bundle rewriting).
     * @param string $basedir Package-relative directory of the page's source HTML ('' at root).
     * @param array $pathtoid Map of package-relative path to resource identifier.
     * @return string The rewritten HTML.
     */
    public function rewrite_relative_links(string $html, string $basedir, array $pathtoid): string {
        if (empty($pathtoid)) {
            return $html;
        }
        // Rewrite the href only within <a …> opening tags, leaving every other
        // element's references (link/base/img/…) untouched.
        return (string) preg_replace_callback('~<a\b[^>]*>~i', function (array $tag) use ($basedir, $pathtoid): string {
            return (string) preg_replace_callback(
                '~\bhref\s*=\s*(["\'])([^"\']+)\1~i',
                function (array $m) use ($basedir, $pathtoid): string {
                    $value = $m[2];
                    // Keep any ?query / #fragment suffix so it survives the rewrite.
                    $path = (string) preg_replace('/[?#].*$/', '', $value);
                    $suffix = substr($value, strlen($path));
                    if ($path === '' || $path[0] === '#' || $path[0] === '/') {
                        return $m[0];
                    }
                    // Leave protocol-relative (//host) and scheme URLs (http:, mailto:, …).
                    if (str_starts_with($path, '//') || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $path)) {
                        return $m[0];
                    }
                    $resolved = self::normalize_path($basedir, rawurldecode($path));
                    if ($resolved === null || !isset($pathtoid[$resolved])) {
                        return $m[0];
                    }
                    return 'href=' . $m[1] . '$CANVAS_OBJECT_REFERENCE$/ilias/' . $pathtoid[$resolved] . $suffix . $m[1];
                },
                $tag[0]
            );
        }, $html) ?? $html;
    }

    /**
     * Resolve a relative reference against a base directory into a normalised,
     * root-relative package path, collapsing '.' and '..' segments.
     *
     * @param string $basedir Package-relative directory ('' at root).
     * @param string $relative The relative reference (no scheme, not root-absolute).
     * @return string|null The normalised path, or null if it escapes the package root.
     */
    public static function normalize_path(string $basedir, string $relative): ?string {
        $combined = trim($basedir, '/');
        $combined = $combined === '' ? $relative : $combined . '/' . $relative;
        $segments = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($segments)) {
                    // The reference climbs above the package root; treat as unresolvable.
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
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
