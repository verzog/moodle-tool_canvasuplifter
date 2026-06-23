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

use tool_canvasuplifter\local\ingest\package;

/**
 * Tests unpacking and locating the manifest, including deeply nested and
 * wrapped (zip-in-zip) repository downloads.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\ingest\package
 */
final class package_test extends \advanced_testcase {
    /**
     * Write a zip with the given path => contents entries.
     *
     * @param array $entries Map of in-zip path to file contents.
     * @return string Absolute path to the created zip.
     */
    private function make_zip(array $entries): string {
        $path = make_request_directory() . '/pkg.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    /**
     * A manifest at the zip root is found.
     *
     * @return void
     */
    public function test_manifest_at_root(): void {
        $zip = $this->make_zip(['imsmanifest.xml' => '<manifest/>', 'wiki_content/a.html' => 'x']);
        $root = (new package())->extract($zip, make_request_directory());
        $this->assertFileExists($root . '/imsmanifest.xml');
    }

    /**
     * A manifest nested several folders deep is still found.
     *
     * @return void
     */
    public function test_manifest_nested_deep(): void {
        $zip = $this->make_zip(['export/v1/course/imsmanifest.xml' => '<manifest/>']);
        $root = (new package())->extract($zip, make_request_directory());
        $this->assertFileExists($root . '/imsmanifest.xml');
        $this->assertStringEndsWith('/export/v1/course', $root);
    }

    /**
     * A download that wraps the real cartridge inside another archive (e.g. a
     * SkillsCommons bitstream zip containing course.imscc + a readme) is
     * unwrapped one level to reach the manifest.
     *
     * @return void
     */
    public function test_wrapped_imscc_is_unwrapped(): void {
        // Build the inner cartridge first.
        $inner = make_request_directory() . '/course.imscc';
        $innerzip = new \ZipArchive();
        $innerzip->open($inner, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $innerzip->addFromString('imsmanifest.xml', '<manifest/>');
        $innerzip->addFromString('wiki_content/page.html', '<p>hi</p>');
        $innerzip->close();

        // Wrap it alongside a readme, with no manifest in the outer archive.
        $outer = make_request_directory() . '/download.zip';
        $outerzip = new \ZipArchive();
        $outerzip->open($outer, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $outerzip->addFile($inner, 'course.imscc');
        $outerzip->addFromString('readme.txt', 'About this resource');
        $outerzip->close();

        $root = (new package())->extract($outer, make_request_directory());
        $this->assertFileExists($root . '/imsmanifest.xml');
        $this->assertFileExists($root . '/wiki_content/page.html');
    }

    /**
     * A zip with no manifest anywhere is rejected with the manifest error.
     *
     * @return void
     */
    public function test_no_manifest_throws(): void {
        $zip = $this->make_zip(['readme.txt' => 'nothing here', 'docs/info.html' => 'x']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(package::ERROR_NO_MANIFEST);
        (new package())->extract($zip, make_request_directory());
    }

    /**
     * A file that isn't a zip is rejected as such.
     *
     * @return void
     */
    public function test_not_a_zip_throws(): void {
        $path = make_request_directory() . '/notzip.imscc';
        file_put_contents($path, 'this is plain text, not a zip');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(package::ERROR_NOT_ZIP);
        (new package())->extract($path, make_request_directory());
    }
}
