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
 * rather than a zip — extracts the package download link and follows it once.
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

    /** @var string Browser-like User-Agent so WAF/CDN-fronted repositories serve the file. */
    private const FETCH_USER_AGENT =
        'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /** @var int Bytes of an HTML response to scan for a download link. */
    private const HTML_SCAN_BYTES = 1048576;

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
        // SkillsCommons), pull the package link out of it and follow that once.
        if ($this->looks_like_html($target)) {
            $html = (string) @file_get_contents($target, false, null, 0, self::HTML_SCAN_BYTES);
            $link = download_link_extractor::find($html, $this->lastfinalurl ?? $url);
            @unlink($target);
            if ($link === null || $link === $url) {
                $this->lastdetail = $this->detail('the URL returned a web page with no package download link');
                throw new \RuntimeException(self::ERROR_NOPACKAGE);
            }
            $target = $this->download_to($link, $maxbytes);
            if ($this->looks_like_zip($target)) {
                return $target;
            }
            @unlink($target);
            $this->lastdetail = $this->detail('the extracted download link did not resolve to a package');
            throw new \RuntimeException(self::ERROR_NOPACKAGE);
        }

        // Neither a zip nor HTML: hand the bytes to the downstream package
        // validator, which reports a precise "not a valid package" error.
        return $target;
    }

    /**
     * Download a single URL to a fresh temp file, enforcing size and HTTP status.
     *
     * Uses Moodle's curl wrapper, which applies the site's
     * curlsecurityblockedhosts / allowed-ports policy on every redirect hop —
     * preventing SSRF to internal services or cloud-metadata endpoints.
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
        $result = $curl->download_one($url, null, [
            'CURLOPT_FILE' => $fh,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 600,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::FETCH_USER_AGENT,
        ]);
        fclose($fh);

        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        $this->lastfinalurl = $curl->info['url'] ?? $url;
        $this->lastcontenttype = strtolower((string) ($curl->info['content_type'] ?? ''));

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
