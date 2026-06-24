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
 * Resolves a Common Cartridge download from a DSpace 7 repository page.
 *
 * DSpace 7 (used by SkillsCommons and many institutional repositories) is an
 * Angular single-page app: an item page served to a plain HTTP client is an
 * empty shell whose download links only appear after JavaScript runs, so there
 * is nothing in the HTML for {@see download_link_extractor} to scrape. This
 * class works out the REST API calls the JS app would make — resolve the item,
 * list its bitstreams, pick the package — so {@see url_fetcher} can fetch the
 * file directly.
 *
 * Pure string/URL/array logic with no Moodle or network dependencies: the HTTP
 * calls live in url_fetcher and feed the decoded JSON to the picker methods
 * here, so all of this is unit-testable in isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dspace_resolver {
    /** @var string A UUID, as DSpace uses for items, bundles and bitstreams. */
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Whether the HTML is a DSpace Angular app (an un-rendered shell, or a
     * server-rendered page) rather than some other repository's HTML. Used to
     * decide whether a REST resolution attempt is worthwhile.
     *
     * @param string $html The fetched page HTML.
     * @return bool
     */
    public static function looks_like_dspace_shell(string $html): bool {
        return stripos($html, '<ds-app') !== false
            || (bool) preg_match('~<meta[^>]+generator[^>]+dspace~i', $html);
    }

    /**
     * Parse a DSpace UI URL into the reference needed to resolve its item:
     * either an item UUID (from /items/<uuid> or /entities/<type>/<uuid>) or a
     * Handle (from /handle/<prefix>/<suffix>).
     *
     * @param string $url The pasted/landing page URL.
     * @return array|null ['uuid' => string] or ['handle' => string], or null if neither is present.
     */
    public static function parse_reference(string $url): ?array {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }
        if (preg_match('~/(?:items|entities/[^/]+)/(' . self::UUID . ')~i', $path, $m)) {
            return ['uuid' => strtolower($m[1])];
        }
        if (preg_match('~/handle/([^/]+/[^/?#]+)~i', $path, $m)) {
            return ['handle' => rtrim($m[1], '/')];
        }
        return null;
    }

    /**
     * Candidate REST API base URLs (each ending in "/server"), best first.
     *
     * Prefers an explicit hint in the page (an OpenSearch/REST descriptor),
     * then the UI host's own /server, then — for split-host deployments such as
     * SkillsCommons, whose www UI is backed by a library.* REST host — the
     * sibling library host.
     *
     * @param string $url The pasted/landing page URL.
     * @param string $html The fetched page HTML, scanned for a REST hint (may be empty).
     * @return string[] Distinct base URLs to try.
     */
    public static function rest_base_candidates(string $url, string $html = ''): array {
        $candidates = [];
        if (preg_match('~https?://[a-z0-9.\-]+/server(?=/(?:opensearch|api)\b)~i', $html, $m)) {
            $candidates[] = rtrim($m[0], '/');
        }
        $parts = parse_url($url);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $scheme = $parts['scheme'];
            $host = $parts['host'];
            $candidates[] = $scheme . '://' . $host . '/server';
            if (stripos($host, 'www.') === 0) {
                $candidates[] = $scheme . '://library.' . substr($host, 4) . '/server';
            }
        }
        return array_values(array_unique($candidates));
    }

    /**
     * Flatten the bitstreams out of an items/<uuid>/bundles?embed=bitstreams
     * response (bitstreams are nested two _embedded levels under each bundle).
     *
     * @param array $bundles Decoded JSON of the bundles response.
     * @return array List of bitstream objects (associative arrays).
     */
    public static function bitstreams_from_bundles(array $bundles): array {
        $out = [];
        foreach ($bundles['_embedded']['bundles'] ?? [] as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }
            foreach ($bundle['_embedded']['bitstreams']['_embedded']['bitstreams'] ?? [] as $bitstream) {
                if (is_array($bitstream)) {
                    $out[] = $bitstream;
                }
            }
        }
        return $out;
    }

    /**
     * Flatten the bitstreams out of a bundles/<uuid>/bitstreams response, used
     * as a fallback when the bundles call did not embed them.
     *
     * @param array $collection Decoded JSON of the bitstreams collection response.
     * @return array List of bitstream objects (associative arrays).
     */
    public static function bitstreams_from_collection(array $collection): array {
        $out = [];
        foreach ($collection['_embedded']['bitstreams'] ?? [] as $bitstream) {
            if (is_array($bitstream)) {
                $out[] = $bitstream;
            }
        }
        return $out;
    }

    /**
     * The per-bundle bitstreams links from a bundles response, for the fallback
     * path that fetches each bundle's bitstreams when none were embedded.
     *
     * @param array $bundles Decoded JSON of the bundles response.
     * @return string[] Bitstreams collection hrefs.
     */
    public static function bundle_bitstreams_hrefs(array $bundles): array {
        $out = [];
        foreach ($bundles['_embedded']['bundles'] ?? [] as $bundle) {
            $href = $bundle['_links']['bitstreams']['href'] ?? '';
            if (is_string($href) && $href !== '') {
                $out[] = $href;
            }
        }
        return $out;
    }

    /**
     * Pick the package bitstream's content (download) URL from a flat bitstream
     * list, preferring a .imscc file over a .zip.
     *
     * @param array $bitstreams List of bitstream objects (associative arrays).
     * @return string|null The content href, or null if no package bitstream is present.
     */
    public static function pick_href(array $bitstreams): ?string {
        foreach (['imscc', 'zip'] as $ext) {
            foreach ($bitstreams as $bitstream) {
                $name = (string) ($bitstream['name'] ?? '');
                $href = (string) ($bitstream['_links']['content']['href'] ?? '');
                if ($href !== '' && preg_match('~\.' . $ext . '($|\?)~i', $name)) {
                    return $href;
                }
            }
        }
        return null;
    }
}
