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

use tool_canvasuplifter\form\upload_form;

/**
 * Tests for the package upload form's optional chunkupload integration.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\form\upload_form
 */
final class upload_form_test extends \advanced_testcase {
    /**
     * Without local_chunkupload installed the form must fall back to the stock
     * filepicker, and detection must report the dependency as absent. This is
     * the soft-dependency contract: the form builds and works either way.
     *
     * @return void
     */
    public function test_chunkupload_unavailable_falls_back_to_filepicker(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // The test Moodle does not ship local_chunkupload.
        $this->assertFalse(upload_form::chunkupload_available());

        $form = new upload_form();
        $this->assertFalse($form->used_chunkupload());
    }

    /**
     * With no file uploaded and no URL given, the form fails validation with an
     * error on the package field — exercised on the filepicker fallback path.
     *
     * @return void
     */
    public function test_validation_requires_a_source(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $form = new upload_form();
        $errors = $form->validation(['packageurl' => ''], []);
        $this->assertArrayHasKey('packagefile', $errors);
    }
}
