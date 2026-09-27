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

namespace repository_largefile\local;

/**
 * Tests for the URL fetcher's input validation.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\url_fetcher
 */
final class url_fetcher_test extends \basic_testcase {
    /**
     * Only absolute http(s) URLs with a host are accepted as fetchable.
     *
     * @dataProvider fetchable_url_provider
     * @param string $url The candidate URL.
     * @param bool $expected Whether it should be considered fetchable.
     * @return void
     */
    public function test_is_fetchable_url(string $url, bool $expected): void {
        $this->assertSame($expected, url_fetcher::is_fetchable_url($url));
    }

    /**
     * Data provider for {@see test_is_fetchable_url()}.
     *
     * @return array Rows of [url, expected].
     */
    public static function fetchable_url_provider(): array {
        return [
            'https' => ['https://example.com/file.mbz', true],
            'http' => ['http://example.com/a.zip', true],
            'with port and path' => ['https://host.example:8443/path/to/file', true],
            'trailing spaces' => ['  https://example.com/f.bin  ', true],
            'empty' => ['', false],
            'no scheme' => ['example.com/file.mbz', false],
            'ftp scheme' => ['ftp://example.com/file', false],
            'file scheme' => ['file:///etc/passwd', false],
            'scheme only' => ['https://', false],
            'not a url' => ['just some text', false],
        ];
    }
}
