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
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_fetcher {
    /** @var string Error key: URL could not be downloaded. */
    public const ERROR_DOWNLOAD = 'errordownloadfailed';
    /** @var string Error key: downloaded file exceeds the site upload limit. */
    public const ERROR_TOOBIG = 'errordownloadtoobig';

    /** @var string|null Diagnostic detail from the last failed fetch. */
    private ?string $lastdetail = null;

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
     * Respects the site's max upload size as the cap, mirroring what a
     * file upload through the form would accept.
     *
     * @param string $url Absolute HTTP(S) URL.
     * @return string Absolute path to the downloaded file.
     * @throws \RuntimeException With a language string key as the message.
     */
    public function fetch(string $url): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $this->lastdetail = null;
        $maxbytes = (int)get_max_upload_file_size($CFG->maxbytes);
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

        // Use raw cURL so we don't fight Moodle's curl wrapper over option
        // translation or response handling for streamed binary downloads.
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MoodleCanvasUplifter/0.1 (+' . $CFG->wwwroot . ')',
            CURLOPT_ACCEPT_ENCODING => '',
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $errno !== 0) {
            $this->lastdetail = "cURL error $errno: $errmsg";
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
}
