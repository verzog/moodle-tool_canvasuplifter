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

use tool_canvasuplifter\local\build\content_styler;

/**
 * Tests wrapping imported HTML in the styled container.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\content_styler
 */
final class content_styler_test extends \basic_testcase {
    /**
     * Content is wrapped in the scoped container div.
     *
     * @return void
     */
    public function test_wraps_content(): void {
        $this->assertSame(
            '<div class="canvasuplifter-content"><p>Hi</p></div>',
            content_styler::wrap('<p>Hi</p>')
        );
    }

    /**
     * Empty or whitespace-only content is left untouched (nothing to style).
     *
     * @return void
     */
    public function test_empty_unchanged(): void {
        $this->assertSame('', content_styler::wrap(''));
        $this->assertSame('   ', content_styler::wrap('   '));
    }

    /**
     * Wrapping is idempotent: already-wrapped content is not nested twice.
     *
     * @return void
     */
    public function test_not_double_wrapped(): void {
        $once = content_styler::wrap('<p>Hi</p>');
        $this->assertSame($once, content_styler::wrap($once));
    }
}
