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

namespace tool_canvasuplifter\local\ingest;

/**
 * Finds the package download link inside an HTML landing page.
 *
 * Repositories such as SkillsCommons (DSpace), MERLOT and OER Commons often
 * expose a human-facing landing page rather than a direct file URL. When a
 * pasted URL resolves to HTML instead of a Common Cartridge zip, this extracts
 * the most likely download link so {@see url_fetcher} can follow it once.
 *
 * Pure string/URL logic with no Moodle dependencies, so it is unit-testable in
 * isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_link_extractor {
    /**
     * Pick the best package download link from an HTML page.
     *
     * Preference order: an anchor pointing at a .imscc file, then a .zip, then a
     * repository "bitstream"/download endpoint (DSpace etc.), then a
     * meta-refresh target. Relative links are resolved against the page URL.
     *
     * @param string $html The page HTML.
     * @param string $baseurl The absolute URL the HTML was fetched from (for resolving relative links).
     * @return string|null An absolute URL to try, or null if none was found.
     */
    public static function find(string $html, string $baseurl): ?string {
        if (trim($html) === '') {
            return null;
        }
        $hrefs = self::anchor_hrefs($html);

        // 1. Direct package files, .imscc ahead of .zip.
        foreach (['imscc', 'zip'] as $ext) {
            foreach ($hrefs as $href) {
                $path = preg_replace('/[?#].*$/', '', $href);
                if (preg_match('~\.' . $ext . '$~i', (string) $path)) {
                    return self::absolutize($href, $baseurl);
                }
            }
        }

        // 2. Repository download endpoints with no file extension, e.g.
        // DSpace's /bitstreams/<uuid>/download.
        foreach ($hrefs as $href) {
            if (
                preg_match('~/bitstreams?/[^/"\']+/download~i', $href)
                || (stripos($href, 'bitstream') !== false && stripos($href, 'download') !== false)
            ) {
                return self::absolutize($href, $baseurl);
            }
        }

        // 3. A meta-refresh redirect to the file. Handle the http-equiv and
        // content attributes in either order (valid HTML allows both).
        if (preg_match_all('~<meta\b[^>]*>~i', $html, $metas)) {
            foreach ($metas[0] as $meta) {
                if (!preg_match('~http-equiv\s*=\s*["\']?\s*refresh~i', $meta)) {
                    continue;
                }
                if (
                    preg_match('~content\s*=\s*("|\')(.*?)\1~i', $meta, $cm)
                    && preg_match('~\burl\s*=\s*([^"\'>\s]+)~i', $cm[2], $um)
                ) {
                    return self::absolutize(html_entity_decode(trim($um[1])), $baseurl);
                }
            }
        }

        return null;
    }

    /**
     * Collect every <a href="..."> value in the HTML, entity-decoded.
     *
     * @param string $html The page HTML.
     * @return string[] The href values in document order.
     */
    private static function anchor_hrefs(string $html): array {
        if (!preg_match_all('~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
            return [];
        }
        return array_map(fn($h) => trim(html_entity_decode($h)), $m[2]);
    }

    /**
     * Resolve a possibly-relative URL against a base URL.
     *
     * Handles absolute URLs, scheme-relative (//host/...), root-relative
     * (/path) and path-relative (sub/file) references. Anything that can't be
     * resolved (empty, javascript:, mailto:, data:) yields null.
     *
     * @param string $url The href to resolve.
     * @param string $baseurl The absolute base URL.
     * @return string|null The absolute URL, or null when it can't be made absolute.
     */
    private static function absolutize(string $url, string $baseurl): ?string {
        $url = trim($url);
        if ($url === '' || $url[0] === '#') {
            return null;
        }
        if (preg_match('~^(javascript|mailto|tel|data):~i', $url)) {
            return null;
        }
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $base = parse_url($baseurl);
        if (empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $authority = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }
        if ($url[0] === '/') {
            return $authority . $url;
        }
        // Path-relative: resolve against the base path's directory.
        $basepath = $base['path'] ?? '/';
        $slash = strrpos($basepath, '/');
        $dir = $slash === false ? '/' : substr($basepath, 0, $slash + 1);
        return $authority . self::collapse_dots($dir . $url);
    }

    /**
     * Collapse ./ and ../ segments in an absolute path.
     *
     * @param string $path The path, with a leading slash.
     * @return string The normalised path.
     */
    private static function collapse_dots(string $path): string {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        return '/' . implode('/', $out);
    }
}
