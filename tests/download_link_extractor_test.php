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

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\ingest\download_link_extractor;

/**
 * Tests the HTML landing-page download-link extractor.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\ingest\download_link_extractor
 */
final class download_link_extractor_test extends \advanced_testcase {
    /**
     * A relative .imscc anchor resolves against the page URL.
     *
     * @return void
     */
    public function test_relative_imscc_link(): void {
        $html = '<html><body><a href="files/course-export.imscc">Download</a></body></html>';
        $this->assertSame(
            'https://repo.example.org/items/files/course-export.imscc',
            download_link_extractor::find($html, 'https://repo.example.org/items/view')
        );
    }

    /**
     * .imscc is preferred over .zip when both are present.
     *
     * @return void
     */
    public function test_imscc_preferred_over_zip(): void {
        $html = '<a href="/a/bundle.zip">zip</a> <a href="/a/course.imscc">cc</a>';
        $this->assertSame(
            'https://x.test/a/course.imscc',
            download_link_extractor::find($html, 'https://x.test/page')
        );
    }

    /**
     * A .zip download is taken when no .imscc is offered.
     *
     * @return void
     */
    public function test_zip_when_no_imscc(): void {
        $html = '<a href="https://cdn.test/pkg/export.zip?token=1">Download ZIP</a>';
        $this->assertSame(
            'https://cdn.test/pkg/export.zip?token=1',
            download_link_extractor::find($html, 'https://x.test/page')
        );
    }

    /**
     * DSpace-style bitstream download endpoints (no file extension) are found.
     *
     * @return void
     */
    public function test_dspace_bitstream_download(): void {
        $html = '<a class="btn" href="/bitstreams/9798eb1e-f87e-4c2c-bc62-6ac8dafbf0c5/download">Download</a>';
        $this->assertSame(
            'https://library.skillscommons.org/bitstreams/9798eb1e-f87e-4c2c-bc62-6ac8dafbf0c5/download',
            download_link_extractor::find($html, 'https://library.skillscommons.org/items/abc')
        );
    }

    /**
     * A meta-refresh redirect is used as a last resort.
     *
     * @return void
     */
    public function test_meta_refresh(): void {
        $html = '<head><meta http-equiv="refresh" content="0; url=/dl/course.imscc"></head>';
        $this->assertSame(
            'https://x.test/dl/course.imscc',
            download_link_extractor::find($html, 'https://x.test/landing/page.html')
        );
    }

    /**
     * Non-navigational and irrelevant links yield nothing.
     *
     * @return void
     */
    public function test_no_link_found(): void {
        $html = '<a href="mailto:me@x.test">mail</a><a href="/about">About</a><a href="#top">top</a>';
        $this->assertNull(download_link_extractor::find($html, 'https://x.test/page'));
    }

    /**
     * Parent-relative (../) references collapse correctly.
     *
     * @return void
     */
    public function test_parent_relative_resolution(): void {
        $html = '<a href="../downloads/course.imscc">cc</a>';
        $this->assertSame(
            'https://x.test/downloads/course.imscc',
            download_link_extractor::find($html, 'https://x.test/items/view.html')
        );
    }
}
