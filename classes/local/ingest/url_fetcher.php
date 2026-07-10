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
 * Downloads a Canvas .imscc package from a URL to a temporary file.
 *
 * Follows redirects under Moodle's curl security policy, sends a browser-like
 * User-Agent (many WAF-fronted repositories such as SkillsCommons reject the
 * default MoodleBot agent), and — when a URL resolves to an HTML landing page
 * rather than a zip — finds the package download link and follows it. For
 * DSpace 7 (Angular) repositories, whose item pages render their file links
 * client-side, it resolves the package through the DSpace REST API
 * (see {@see dspace_resolver}) instead of scraping the empty page shell.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_fetcher {
    /** @var string Error key: URL could not be downloaded. */
    public const ERROR_DOWNLOAD = 'errordownloadfailed';
    /** @var string Error key: downloaded file exceeds the site upload limit. */
    public const ERROR_TOOBIG = 'errordownloadtoobig';
    /** @var string Error key: the URL resolved to something that isn't a package. */
    public const ERROR_NOPACKAGE = 'errordownloadnopackage';
    /** @var string Error key: a JavaScript-rendered repository page we cannot scrape. */
    public const ERROR_JSPAGE = 'errordownloadjspage';

    /** @var string Browser-like User-Agent so WAF/CDN-fronted repositories serve the file. */
    private const FETCH_USER_AGENT =
        'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /** @var int Bytes of an HTML response to scan for a download link. */
    private const HTML_SCAN_BYTES = 1048576;

    /** @var int Cap on bitstream pages followed per bundle, guarding against runaway pagination. */
    private const DSPACE_MAX_PAGES = 20;

    /** @var string|null Diagnostic detail from the last failed fetch. */
    private ?string $lastdetail = null;

    /** @var string|null Effective URL of the most recent download (after redirects). */
    private ?string $lastfinalurl = null;

    /** @var string|null Content-Type header of the most recent download. */
    private ?string $lastcontenttype = null;

    /**
     * Free-form diagnostic from the most recent fetch (curl error or HTTP code).
     *
     * @return string|null
     */
    public function get_last_detail(): ?string {
        return $this->lastdetail;
    }

    /**
     * Download $url to a new temporary file and return its path.
     *
     * If the URL resolves to an HTML page rather than a package, its download
     * link is extracted and followed once. Respects the site's max upload size.
     *
     * @param string $url Absolute HTTP(S) URL.
     * @return string Absolute path to the downloaded file.
     * @throws \RuntimeException With a language string key as the message.
     */
    public function fetch(string $url): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $this->lastdetail = null;
        $maxbytes = (int) get_max_upload_file_size($CFG->maxbytes);

        $target = $this->download_to($url, $maxbytes);
        if ($this->looks_like_zip($target)) {
            return $target;
        }

        // Not a zip. If it's an HTML landing page (common for repositories like
        // SkillsCommons), find the package download link and follow it.
        if ($this->looks_like_html($target)) {
            $html = (string) @file_get_contents($target, false, null, 0, self::HTML_SCAN_BYTES);
            @unlink($target);
            $pageurl = $this->lastfinalurl ?? $url;

            // DSpace 7 (Angular) item pages render their file links client-side,
            // so a server-side fetch only sees an empty shell. Resolve the
            // package through the REST API the JS app would have called.
            $isdspace = dspace_resolver::looks_like_dspace_shell($html);
            if ($isdspace) {
                $resturl = $this->resolve_via_dspace_rest($pageurl, $html);
                if ($resturl !== null) {
                    $target = $this->download_to($resturl, $maxbytes);
                    if ($this->looks_like_zip($target)) {
                        return $target;
                    }
                    @unlink($target);
                }
            }

            // Scrape a download link out of the HTML (server-rendered DSpace,
            // MERLOT, OER Commons and the like) and follow it once.
            $link = download_link_extractor::find($html, $pageurl);
            if ($link !== null && $link !== $url) {
                $target = $this->download_to($link, $maxbytes);
                if ($this->looks_like_zip($target)) {
                    return $target;
                }
                @unlink($target);
            }

            // Nothing resolved. A DSpace/JS shell needs the user to paste the
            // direct file link, so report that specifically; otherwise report a
            // generic "no link on the page".
            if ($isdspace) {
                $this->lastdetail = $this->detail('the DSpace page builds its file links with JavaScript and the REST '
                    . 'lookup found no Common Cartridge bitstream');
                throw new \RuntimeException(self::ERROR_JSPAGE);
            }
            $this->lastdetail = $this->detail('the URL returned a web page with no package download link');
            throw new \RuntimeException(self::ERROR_NOPACKAGE);
        }

        // Neither a zip nor HTML: hand the bytes to the downstream package
        // validator, which reports a precise "not a valid package" error.
        return $target;
    }

    /**
     * Download a single URL to a fresh temp file, enforcing size and HTTP status.
     *
     * Uses Moodle's curl wrapper so the site's curlsecurityblockedhosts /
     * allowed-ports policy is applied on each redirect hop. That policy only
     * blocks internal services / cloud-metadata endpoints when the admin has
     * configured a blocklist (it is empty by default), so SSRF protection
     * depends on that site setting; the URL — and any REST base derived from a
     * fetched page — is treated as untrusted and only ever reached through this
     * security-checked wrapper. The transfer is aborted mid-stream once it
     * exceeds $maxbytes, so an oversize (or endless) body cannot fill the disk.
     *
     * @param string $url Absolute HTTP(S) URL.
     * @param int $maxbytes Maximum accepted size (0 = unlimited).
     * @return string Absolute path to the downloaded temp file.
     * @throws \RuntimeException With a language string key as the message.
     */
    private function download_to(string $url, int $maxbytes): string {
        $this->lastfinalurl = null;
        $this->lastcontenttype = null;

        $target = tempnam(make_request_directory(), 'canvasuplifter_');
        if ($target === false) {
            $this->lastdetail = 'Could not create temp file';
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            $this->lastdetail = 'Could not open temp file for writing';
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }

        $curl = new \curl();
        $curl->setHeader('Accept: application/zip, application/octet-stream, text/html, */*');
        $options = [
            'CURLOPT_FILE' => $fh,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 600,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::FETCH_USER_AGENT,
        ];
        if ($maxbytes > 0) {
            // Abort the transfer as soon as it exceeds the cap (by declared size
            // or bytes received so far), rather than only checking the size after
            // the whole body has already been written to disk.
            $options['CURLOPT_NOPROGRESS'] = 0;
            $options['CURLOPT_PROGRESSFUNCTION'] = function ($ch, $dltotal, $dlnow) use ($maxbytes) {
                return ($dltotal > $maxbytes || $dlnow > $maxbytes) ? 1 : 0;
            };
        }
        $result = $curl->download_one($url, null, $options);
        fclose($fh);

        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        $this->lastfinalurl = $curl->info['url'] ?? $url;
        $this->lastcontenttype = strtolower((string) ($curl->info['content_type'] ?? ''));

        // CURLE_ABORTED_BY_CALLBACK (42): the progress callback stopped an
        // oversize transfer. Report it as "too big", not a generic failure.
        if ((int) ($curl->errno ?? 0) === 42) {
            $this->lastdetail = 'Download exceeded the ' . $maxbytes . '-byte limit';
            @unlink($target);
            throw new \RuntimeException(self::ERROR_TOOBIG);
        }
        if ($result !== true || !empty($curl->errno)) {
            $this->lastdetail = $curl->error !== '' ? $curl->error : 'download failed';
            @unlink($target);
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }
        if ($httpcode >= 400) {
            $this->lastdetail = "HTTP $httpcode";
            @unlink($target);
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }
        if (filesize($target) === 0) {
            $this->lastdetail = 'Empty response';
            @unlink($target);
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }
        if ($maxbytes > 0 && filesize($target) > $maxbytes) {
            $this->lastdetail = 'Downloaded ' . filesize($target) . ' bytes; limit ' . $maxbytes;
            @unlink($target);
            throw new \RuntimeException(self::ERROR_TOOBIG);
        }

        return $target;
    }

    /**
     * Resolve a DSpace 7 item page to its Common Cartridge download URL through
     * the REST API, trying each candidate REST base until one resolves.
     *
     * @param string $pageurl The (post-redirect) URL of the fetched page.
     * @param string $html The fetched page HTML, scanned for a REST base hint.
     * @return string|null Absolute download URL of the package bitstream, or null if it could not be resolved.
     */
    private function resolve_via_dspace_rest(string $pageurl, string $html): ?string {
        $ref = dspace_resolver::parse_reference($pageurl);
        if ($ref === null) {
            return null;
        }
        foreach (dspace_resolver::rest_base_candidates($pageurl, $html) as $base) {
            $href = $this->dspace_package_from_base($base, $ref);
            if ($href !== null) {
                return $href;
            }
        }
        return null;
    }

    /**
     * Resolve the package download for a reference against one REST base. A
     * directly-referenced bitstream (e.g. a copied .../bitstreams/<uuid>/download
     * link) is its own package, so its content endpoint is returned straight
     * away; otherwise fetch the item, then its bundles with bitstreams, then pick
     * the .imscc/.zip file.
     *
     * @param string $base REST API base URL ending in "/server".
     * @param array $ref Reference: ['uuid'|'bitstream' => string] or ['handle' => string].
     * @return string|null The bitstream content href, or null if this base could not resolve it.
     */
    private function dspace_package_from_base(string $base, array $ref): ?string {
        if (isset($ref['bitstream'])) {
            // Validate the bitstream exists at this base before committing to it, so a
            // split-host install still falls through to the REST host when the UI host
            // has no API. Prefer the content href the API reports over a synthesised one.
            $bitstream = $this->http_get_json($base . '/api/core/bitstreams/' . $ref['bitstream']);
            if (!is_array($bitstream)) {
                return null;
            }
            return $bitstream['_links']['content']['href']
                ?? ($base . '/api/core/bitstreams/' . $ref['bitstream'] . '/content');
        }
        $item = $this->dspace_item($base, $ref);
        if ($item === null) {
            return null;
        }
        $bundleshref = $item['_links']['bundles']['href']
            ?? ($base . '/api/core/items/' . $item['uuid'] . '/bundles');
        $bundles = $this->http_get_json(dspace_resolver::bundles_url($bundleshref));
        if (!is_array($bundles)) {
            return null;
        }
        // The .imscc is the definitive package: if it is among the embedded
        // bitstreams, take it without any further requests.
        $embedded = dspace_resolver::bitstreams_from_bundles($bundles);
        $imscc = dspace_resolver::find_href($embedded, 'imscc');
        if ($imscc !== null) {
            return $imscc;
        }
        // Otherwise the embedded list may be empty or only a first page: page through
        // each bundle's full bitstreams collection, following DSpace's pagination
        // links, and pick across the complete set — so a package on a later page is
        // not missed and a support .zip is not returned ahead of an .imscc that
        // paginated out of view.
        $bitstreams = $embedded;
        foreach (dspace_resolver::bundle_bitstreams_hrefs($bundles) as $bhref) {
            $next = dspace_resolver::with_page_size($bhref);
            for ($page = 0; $next !== null && $page < self::DSPACE_MAX_PAGES; $page++) {
                $collection = $this->http_get_json($next);
                if (!is_array($collection)) {
                    break;
                }
                $bitstreams = array_merge($bitstreams, dspace_resolver::bitstreams_from_collection($collection));
                $next = dspace_resolver::next_page_href($collection);
            }
        }
        return dspace_resolver::pick_href($bitstreams);
    }

    /**
     * Resolve a reference to its DSpace item JSON against one REST base, trying
     * each candidate lookup URL (a Handle is attempted both bare and "hdl:"-
     * prefixed) until one returns an item. The pid/find endpoint issues a 302 to
     * the item endpoint, which curl follows.
     *
     * @param string $base REST API base URL ending in "/server".
     * @param array $ref Item reference: ['uuid' => string] or ['handle' => string].
     * @return array|null The decoded item JSON, or null if none resolved.
     */
    private function dspace_item(string $base, array $ref): ?array {
        foreach (dspace_resolver::item_lookup_urls($base, $ref) as $lookup) {
            $item = $this->http_get_json($lookup);
            if (is_array($item) && !empty($item['uuid'])) {
                return $item;
            }
        }
        return null;
    }

    /**
     * GET a URL expected to return JSON and decode it, following redirects under
     * Moodle's curl security policy and sending the browser User-Agent. Returns
     * null on any transport error, non-2xx status or non-JSON body.
     *
     * @param string $url Absolute HTTP(S) URL.
     * @return array|null Decoded JSON as an associative array, or null.
     */
    private function http_get_json(string $url): ?array {
        $curl = new \curl();
        $curl->setHeader('Accept: application/json');
        $body = $curl->get($url, [], [
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::FETCH_USER_AGENT,
        ]);
        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        if (!empty($curl->errno) || $httpcode >= 400 || !is_string($body) || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Whether the file begins with the ZIP magic bytes ("PK..."), which every
     * .imscc package does.
     *
     * @param string $path Absolute file path.
     * @return bool
     */
    private function looks_like_zip(string $path): bool {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = (string) fread($handle, 2);
        fclose($handle);
        return $magic === 'PK';
    }

    /**
     * Whether the most recent response looks like an HTML page — by its
     * Content-Type, or by the first non-whitespace byte being '<'.
     *
     * @param string $path Absolute file path of the downloaded body.
     * @return bool
     */
    private function looks_like_html(string $path): bool {
        if ($this->lastcontenttype !== null && str_contains($this->lastcontenttype, 'html')) {
            return true;
        }
        $head = ltrim((string) @file_get_contents($path, false, null, 0, 512));
        return $head !== '' && $head[0] === '<';
    }

    /**
     * Append a content-type/HTTP hint to a human-facing detail message.
     *
     * @param string $message The base explanation.
     * @return string
     */
    private function detail(string $message): string {
        $ct = $this->lastcontenttype !== null && $this->lastcontenttype !== ''
            ? " (content-type: {$this->lastcontenttype})"
            : '';
        return $message . $ct;
    }
}
