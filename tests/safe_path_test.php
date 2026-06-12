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

use tool_canvasuplifter\local\build\safe_path;

/**
 * Tests for the package path-containment helper.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\safe_path
 */
final class safe_path_test extends \advanced_testcase {
    /**
     * A file inside the root resolves to its real absolute path.
     *
     * @return void
     */
    public function test_within_returns_contained_file(): void {
        $root = make_request_directory();
        mkdir($root . '/wiki_content');
        file_put_contents($root . '/wiki_content/page.html', 'x');

        $resolved = safe_path::within($root, 'wiki_content/page.html');

        $this->assertNotNull($resolved);
        $this->assertSame(realpath($root . '/wiki_content/page.html'), $resolved);
    }

    /**
     * A path escaping the root via ".." is rejected.
     *
     * @return void
     */
    public function test_within_rejects_traversal(): void {
        $root = make_request_directory();
        mkdir($root . '/pkg');
        file_put_contents($root . '/secret.txt', 'top secret');

        $this->assertNull(safe_path::within($root . '/pkg', '../secret.txt'));
        $this->assertNull(safe_path::within($root . '/pkg', '../../etc/passwd'));
    }

    /**
     * A missing file returns null rather than a bogus path.
     *
     * @return void
     */
    public function test_within_returns_null_for_missing(): void {
        $root = make_request_directory();
        $this->assertNull(safe_path::within($root, 'does-not-exist.html'));
    }
}
