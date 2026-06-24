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

use tool_canvasuplifter\local\build\ilias_cleaner;

/**
 * Tests stripping ILIAS viewer chrome from exported pages.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\ilias_cleaner
 */
final class ilias_cleaner_test extends \basic_testcase {
    /**
     * A folder/landing page: the "Activities" navigation column, its sibling
     * learning-module links and icons, the focus script and the "not available"
     * dialog are removed, while the right-hand content column (title, media,
     * objectives across several cells) is kept.
     *
     * @return void
     */
    public function test_strips_navigation_and_keeps_content(): void {
        $html = '<html><head><script src="basic.js"></script></head><body class="std">'
            . '<table class="ilc_page_cont_PageContainer"><tr><td><div>'
            . '<script>focus();</script>'
            . '<form action="" class="il_Form"><div align="left">'
            . '<table class="ilc_table_MainTable"><tr valign="top">'
            . '<td width="275px">'
            . '<table class="ilc_table_Navigation"><tr><td class="ilc_table_cell_NavTitle">'
            . '<div>Activities</div></td></tr><tr><td>'
            . '<img src="CC_ICONS/icon_lm.gif" alt="Learning Module Ilias">'
            . '<a href="../OTHER_LM/index.html">Reading Assignment</a>'
            . '<div>Type: Learning Module Ilias</div></td></tr></table>'
            . '<div id="dialog_999">This LM is not available from this page. Use the LMS navigation.</div>'
            . '</td>'
            . '<td><div align="left"><table class="ilc_table_TextTable">'
            . '<tr><td class="ilc_table_cell_Cell2">'
            . '<div class="ilc_text_block_Title">My Unit Title</div>'
            . '<img src="data/nwtp/mobs/mm_1/pic.jpg" alt="content image">'
            . '</td></tr>'
            . '<tr><td class="ilc_table_cell_LightBlue"><strong>Objectives</strong>'
            . '<ul><li>Learn the material</li></ul></td></tr>'
            . '</table></div></td>'
            . '</tr></table></div></form>'
            . '</div></td></tr></table></body></html>';

        $out = ilias_cleaner::clean($html);

        // Chrome is gone.
        $this->assertStringNotContainsString('ilc_table_Navigation', $out);
        $this->assertStringNotContainsString('CC_ICONS', $out);
        $this->assertStringNotContainsString('not available from this page', $out);
        $this->assertStringNotContainsString('focus()', $out);
        $this->assertStringNotContainsString('il_Form', $out);
        $this->assertStringNotContainsString('Reading Assignment', $out);
        $this->assertStringNotContainsString('Type: Learning Module', $out);

        // Content is kept, including the body-side media image.
        $this->assertStringContainsString('My Unit Title', $out);
        $this->assertStringContainsString('Objectives', $out);
        $this->assertStringContainsString('Learn the material', $out);
        $this->assertStringContainsString('data/nwtp/mobs/mm_1/pic.jpg', $out);
    }

    /**
     * A single learning-module page has no navigation layout table, so the page
     * body is kept with the focus script and icon images stripped.
     *
     * @return void
     */
    public function test_learning_module_page_strips_chrome_keeps_body(): void {
        $html = '<html><head></head><body>'
            . '<table class="ilc_page_cont_PageContainer"><tr><td><div>'
            . '<script>focus();</script>'
            . '<img src="CC_ICONS/icon_lm.gif">'
            . '<div class="ilc_text_block_Standard">The lesson body text.</div>'
            . '</div></td></tr></table></body></html>';

        $out = ilias_cleaner::clean($html);

        $this->assertStringNotContainsString('focus()', $out);
        $this->assertStringNotContainsString('CC_ICONS', $out);
        $this->assertStringContainsString('The lesson body text.', $out);
    }

    /**
     * A blank gutter cell between the navigation column and the content cell is
     * skipped: the content (in the ilc_table_TextTable cell) is returned rather
     * than the empty spacer that comes first.
     *
     * @return void
     */
    public function test_blank_gutter_cell_is_skipped(): void {
        $html = '<html><body><table class="ilc_page_cont_PageContainer"><tr><td>'
            . '<table class="ilc_table_MainTable"><tr>'
            . '<td width="275"><table class="ilc_table_Navigation"><tr><td>Activities</td></tr></table></td>'
            . '<td width="20">&nbsp;</td>'
            . '<td><table class="ilc_table_TextTable"><tr><td class="ilc_table_cell_Cell2">'
            . '<h1>Real Lesson Content</h1></td></tr></table></td>'
            . '</tr></table></td></tr></table></body></html>';

        $out = ilias_cleaner::clean($html);

        $this->assertStringContainsString('Real Lesson Content', $out);
        $this->assertStringNotContainsString('Activities', $out);
        // The gutter cell was not returned in place of the content.
        $this->assertStringNotContainsString('width="20"', $out);
    }

    /**
     * Non-ILIAS HTML (Canvas, eXe, plain webcontent) carries none of the ILIAS
     * layout markers and is returned byte-for-byte unchanged.
     *
     * @return void
     */
    public function test_non_ilias_html_is_unchanged(): void {
        $html = '<p>Hello <a href="page.html">world</a></p><img src="logo.png">';
        $this->assertSame($html, ilias_cleaner::clean($html));
    }
}
