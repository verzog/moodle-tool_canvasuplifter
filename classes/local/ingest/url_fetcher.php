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

        $maxbytes = (int)get_max_upload_file_size($CFG->maxbytes);
        $target = tempnam(make_request_directory(), 'canvasuplifter_');

        $curl = new \curl(['ignoresecurity' => false]);
        $options = [
            'CURLOPT_TIMEOUT' => 600,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
        ];
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }
        try {
            $result = $curl->download_one($url, null, ['file' => $fh] + $options);
        } finally {
            fclose($fh);
        }

        if ($result !== true || $curl->get_errno() || $curl->get_info()['http_code'] >= 400) {
            @unlink($target);
            throw new \RuntimeException(self::ERROR_DOWNLOAD);
        }

        if ($maxbytes > 0 && filesize($target) > $maxbytes) {
            @unlink($target);
            throw new \RuntimeException(self::ERROR_TOOBIG);
        }

        return $target;
    }
}
