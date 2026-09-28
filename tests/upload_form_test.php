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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the package upload form's built-in chunked-upload field.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\tool_canvasuplifter\form\upload_form::class)]
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
     * Whether the built form has a given element.
     *
     * @param upload_form $form The form.
     * @param string $name The element name.
     * @return bool
     */
    private function has_element(upload_form $form, string $name): bool {
        $mform = (new \ReflectionProperty($form, '_form'))->getValue($form);
        return $mform->elementExists($name);
    }

    /**
     * Without the Large file repository on the site, the form offers this plugin's own
     * large-package and URL fields and no pointer to the repository.
     *
     * @return void
     */
    public function test_form_without_largefile_repository(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        if (\core_component::get_component_directory('repository_largefile') !== null) {
            $this->markTestSkipped('repository_largefile is installed on this site.');
        }

        $this->assertFalse(upload_form::largefile_repository_available());
        $form = new upload_form();
        $this->assertTrue($this->has_element($form, 'packagelargefile'));
        $this->assertTrue($this->has_element($form, 'packageurl'));
        $this->assertFalse($this->has_element($form, 'largefilehint'));
    }

    /**
     * With the Large file repository installed and enabled, the form points to it in the file
     * picker instead of showing its own large-package field (the URL field stays); a user without
     * the ignore-size-limits capability, or a disabled repository, keeps the built-in field.
     *
     * @return void
     */
    public function test_form_defers_to_enabled_largefile_repository(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        if (\core_component::get_component_directory('repository_largefile') === null) {
            $this->markTestSkipped('repository_largefile is not installed on this site.');
        }
        global $CFG;
        require_once($CFG->dirroot . '/repository/lib.php');

        $type = \repository::get_type_by_typename('largefile');
        if (!$type) {
            // Enable the repository type through the core API (it ships no data generator);
            // creating the type also creates its site-wide instance.
            (new \repository_type('largefile', [], true))->create(true);
            $type = \repository::get_type_by_typename('largefile');
        }
        $type->update_visibility(true);
        $this->assertTrue(upload_form::largefile_repository_available());
        $form = new upload_form();
        $this->assertTrue($this->has_element($form, 'packagefile'));
        $this->assertTrue($this->has_element($form, 'largefilehint'));
        $this->assertFalse($this->has_element($form, 'packagelargefile'));
        // The URL field stays: this plugin's fetcher also resolves repository landing pages.
        $this->assertTrue($this->has_element($form, 'packageurl'));

        // A user the file picker still size-limits keeps the built-in chunked field.
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:ignorefilesizelimits', CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $manager->id, \context_system::instance());
        $this->setUser($manager);
        $this->assertFalse(upload_form::largefile_repository_available());
        $this->assertTrue($this->has_element(new upload_form(), 'packagelargefile'));
        $this->setAdminUser();

        $type->update_visibility(false);
        $this->assertFalse(upload_form::largefile_repository_available());
        $this->assertTrue($this->has_element(new upload_form(), 'packagelargefile'));
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
