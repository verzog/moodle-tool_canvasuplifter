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

use tool_canvasuplifter\local\build\link_rewriter;

/**
 * Tests for the Canvas link rewriter.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\link_rewriter
 */
final class link_rewriter_test extends \advanced_testcase {
    /**
     * File placeholders resolve to package files and become @@PLUGINFILE@@.
     *
     * @return void
     */
    public function test_rewrite_files_imports_and_rewrites(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        mkdir($root . '/web_resources/Uploaded Media');
        file_put_contents($root . '/web_resources/logo.png', 'PNG');
        file_put_contents($root . '/web_resources/Uploaded Media/p q.png', 'PNG');

        $html = '<img src="$IMS-CC-FILEBASE$/logo.png">'
            . '<img src="$IMS-CC-FILEBASE$/Uploaded%20Media/p%20q.png">';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertStringContainsString('@@PLUGINFILE@@/logo.png', $result['html']);
        $this->assertStringContainsString('@@PLUGINFILE@@/Uploaded%20Media/p%20q.png', $result['html']);
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $result['html']);
        $this->assertCount(2, $result['files']);

        $names = array_column($result['files'], 'filename');
        $this->assertContains('logo.png', $names);
        $this->assertContains('p q.png', $names);
    }

    /**
     * A reference to a file that isn't in the package is left untouched.
     *
     * @return void
     */
    public function test_rewrite_files_leaves_unresolved(): void {
        $root = make_request_directory();
        $html = '<img src="$IMS-CC-FILEBASE$/missing.png">';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['files']);
    }

    /**
     * Wiki and object references resolve via the supplied map; unknowns stay.
     *
     * @return void
     */
    public function test_rewrite_internal_links(): void {
        $html = '<a href="$WIKI_REFERENCE$/pages/welcome">Welcome</a>'
            . '<a href="$CANVAS_OBJECT_REFERENCE$/assignments/abc123">Essay</a>'
            . '<a href="$WIKI_REFERENCE$/pages/unmapped">Other</a>';
        $urlmap = [
            'wiki:welcome' => 'https://moodle.test/mod/page/view.php?id=5',
            'id:abc123' => 'https://moodle.test/mod/assign/view.php?id=9',
        ];

        $out = (new link_rewriter())->rewrite_internal_links($html, $urlmap);

        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=5"', $out);
        $this->assertStringContainsString('href="https://moodle.test/mod/assign/view.php?id=9"', $out);
        // The unmapped reference is preserved rather than broken further.
        $this->assertStringContainsString('$WIKI_REFERENCE$/pages/unmapped', $out);
    }
}
