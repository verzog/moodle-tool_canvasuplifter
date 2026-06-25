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
 * Tests for the package upload form's built-in chunked-upload field.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\form\upload_form
 */
final class upload_form_test extends \advanced_testcase {
    /**
     * The chunked uploader is bundled, so it is always available and the form
     * builds with the large-file field present without a separate plugin.
     *
     * @return void
     */
    public function test_chunkupload_is_always_available(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertTrue(upload_form::chunkupload_available());

        $form = new upload_form();
        // Nothing has been submitted yet, so no upload was resolved via chunks.
        $this->assertFalse($form->used_chunkupload());
    }

    /**
     * With no file uploaded and no URL given, the form fails validation with an
     * error on the package field.
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

    /**
     * A single URL source that is not http(s) is rejected with a URL error.
     *
     * @return void
     */
    public function test_validation_rejects_non_http_url(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $form = new upload_form();
        $errors = $form->validation(['packageurl' => 'ftp://example.com/course.imscc'], []);
        $this->assertArrayHasKey('packageurl', $errors);
    }
}
