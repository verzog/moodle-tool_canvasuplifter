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

use tool_canvasuplifter\chunkupload\form_element;
use tool_canvasuplifter\chunkupload\state_type;

/**
 * Tests for the bundled chunked-upload form element's file API.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\chunkupload\form_element
 */
final class chunkupload_form_element_test extends \advanced_testcase {
    /**
     * Insert a chunk-tracking row with an on-disk file when a state requires it.
     *
     * @param int $id The chunk id (token).
     * @param int $state One of the state_type constants.
     * @param string $filename The stored filename.
     * @param string $content File content to write, or '' to write no file.
     * @return void
     */
    private function make_chunk(int $id, int $state, string $filename, string $content = ''): void {
        global $DB, $USER;
        $record = (object) [
            'id' => $id,
            'userid' => $USER->id,
            'contextid' => \context_system::instance()->id,
            'maxlength' => -1,
            'lastmodified' => time(),
            'state' => $state,
            'filename' => $filename,
            'length' => strlen($content),
            'currentpos' => strlen($content),
        ];
        $DB->insert_record_raw('tool_canvasuplifter_chunks', $record, false, false, true);

        if ($content !== '') {
            $dir = form_element::get_base_folder();
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents(form_element::get_path_for_id($id), $content);
        }
    }

    /**
     * The on-disk path is the base folder plus the id, and null for no id.
     *
     * @return void
     */
    public function test_get_path_for_id(): void {
        $this->assertNull(form_element::get_path_for_id(0));
        $this->assertNull(form_element::get_path_for_id(null));
        $this->assertSame(form_element::get_base_folder() . '42', form_element::get_path_for_id(42));
    }

    /**
     * is_file_uploaded() is true only for a record in the completed state.
     *
     * @return void
     */
    public function test_is_file_uploaded(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertFalse(form_element::is_file_uploaded(null));
        $this->assertFalse(form_element::is_file_uploaded(999));

        $this->make_chunk(100, state_type::UPLOAD_STARTED, 'started.imscc', 'partial');
        $this->assertFalse(form_element::is_file_uploaded(100));

        $this->make_chunk(101, state_type::UPLOAD_COMPLETED, 'done.imscc', 'all done');
        $this->assertTrue(form_element::is_file_uploaded(101));
    }

    /**
     * create_token() inserts a tracking row owned by the current user.
     *
     * @return void
     */
    public function test_create_token(): void {
        global $DB, $PAGE, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());

        $element = new form_element('packagelargefile', 'Large package', null, ['maxbytes' => -1]);
        $id = $element->create_token();

        $this->assertIsInt($id);
        $record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $id]);
        $this->assertEquals($USER->id, $record->userid);
        $this->assertEquals(state_type::UNUSED_TOKEN_GENERATED, $record->state);
    }

    /**
     * A completed upload exports into a Moodle file area; a partial one does not.
     *
     * @return void
     */
    public function test_export_to_filearea(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $context = \context_system::instance();

        $this->make_chunk(200, state_type::UPLOAD_COMPLETED, 'course.imscc', 'PK-zip-bytes');
        $file = form_element::export_to_filearea(200, $context->id, 'tool_canvasuplifter', 'unittest');
        $this->assertInstanceOf(\stored_file::class, $file);
        $this->assertSame('course.imscc', $file->get_filename());
        $this->assertSame('PK-zip-bytes', $file->get_content());

        $this->make_chunk(201, state_type::UPLOAD_STARTED, 'partial.imscc', 'half');
        $this->assertNull(form_element::export_to_filearea(201, $context->id, 'tool_canvasuplifter', 'unittest'));
    }

    /**
     * delete_file() removes both the tracking row and the on-disk file.
     *
     * @return void
     */
    public function test_delete_file(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->make_chunk(300, state_type::UPLOAD_COMPLETED, 'gone.imscc', 'bytes');
        $path = form_element::get_path_for_id(300);
        $this->assertFileExists($path);

        form_element::delete_file(300);

        $this->assertFalse($DB->record_exists('tool_canvasuplifter_chunks', ['id' => 300]));
        $this->assertFileDoesNotExist($path);
    }
}
