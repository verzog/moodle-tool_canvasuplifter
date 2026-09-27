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

namespace repository_largefile;

/**
 * Tests for the chunked-upload store.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\chunk_store
 */
final class chunk_store_test extends \advanced_testcase {
    /**
     * A brand-new token starts in the unused state and owned by the current user.
     *
     * @return void
     */
    public function test_create_token(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $this->assertNotNull($id);
        $record = chunk_store::get_record($id);
        $this->assertNotNull($record);
        $this->assertEquals($USER->id, $record->userid);
        $this->assertEquals(chunk_store::STATE_UNUSED, (int) $record->state);
        $this->assertFalse(chunk_store::is_complete($id));
    }

    /**
     * A guest may not create an upload token.
     *
     * @return void
     */
    public function test_guest_cannot_create_token(): void {
        $this->resetAfterTest();
        $this->setGuestUser();
        $this->assertNull(chunk_store::create_token(\context_system::instance()->id, -1));
    }

    /**
     * A file uploaded in two chunks assembles to the original bytes and is marked
     * complete, and appears in the owner's completed listing.
     *
     * @return void
     */
    public function test_two_chunk_assembly(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(5000);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);

        // First chunk (0..3000): starts the upload but does not finish it.
        $record = chunk_store::get_record($id);
        $error = chunk_store::apply_start($record, 0, 3000, strlen($data), 'big.bin', substr($data, 0, 3000));
        $this->assertNull($error);
        $this->assertFalse(chunk_store::is_complete($id));
        $progress = chunk_store::get_progress($id);
        $this->assertEquals(3000, $progress['currentpos']);
        $this->assertEquals(chunk_store::STATE_STARTED, $progress['state']);

        // Second chunk (3000..5000): completes the file.
        $record = chunk_store::get_record($id);
        $error = chunk_store::apply_proceed($record, 3000, 5000, substr($data, 3000));
        $this->assertNull($error);
        $this->assertTrue(chunk_store::is_complete($id));

        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);

        $completed = chunk_store::list_completed((int) $USER->id);
        $this->assertCount(1, $completed);
        $only = reset($completed);
        $this->assertEquals('big.bin', $only->filename);
        $this->assertEquals(5000, (int) $only->length);

        chunk_store::delete($id);
        $this->assertNull(chunk_store::get_record($id));
        $this->assertFileDoesNotExist(chunk_store::get_path_for_id($id));
    }

    /**
     * A single chunk that spans the whole file completes it immediately.
     *
     * @return void
     */
    public function test_single_chunk_completes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(1024);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        $this->assertNull(chunk_store::apply_start($record, 0, 1024, 1024, 'small.bin', $data));
        $this->assertTrue(chunk_store::is_complete($id));
    }

    /**
     * Re-sending a chunk the server already stored is accepted as a no-op and does
     * not corrupt the file (resume after a lost response).
     *
     * @return void
     */
    public function test_resent_chunk_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(4000);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 2000, strlen($data), 'x.bin', substr($data, 0, 2000));

        // Resend the first chunk: currentpos must not advance and bytes must stand.
        $record = chunk_store::get_record($id);
        $this->assertNull(chunk_store::apply_proceed($record, 0, 2000, substr($data, 0, 2000)));
        $this->assertEquals(2000, chunk_store::get_progress($id)['currentpos']);

        $record = chunk_store::get_record($id);
        chunk_store::apply_proceed($record, 2000, 4000, substr($data, 2000));
        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);
    }

    /**
     * A chunk that does not begin where the last one ended is rejected.
     *
     * @return void
     */
    public function test_check_bounds_rejects_gap(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 1000, 3000, 'x.bin', random_bytes(1000));

        $record = chunk_store::get_record($id);
        // Starts at 2000 but only 1000 bytes are stored — a gap.
        $this->assertNotNull(chunk_store::check_bounds($record, 2000, 3000));
        // End beyond the declared length.
        $this->assertNotNull(chunk_store::check_bounds($record, 1000, 4000));
        // Valid continuation.
        $this->assertNull(chunk_store::check_bounds($record, 1000, 3000));
    }

    /**
     * apply_start rejects a chunk for a file larger than the token's cap.
     *
     * @return void
     */
    public function test_start_rejects_oversize(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, 1000);
        $record = chunk_store::get_record($id);
        $this->assertNotNull(chunk_store::apply_start($record, 0, 500, 2000, 'x.bin', random_bytes(500)));
    }

    /**
     * adopt_file takes an already-downloaded file as the token's payload and marks
     * it complete (the URL-import path).
     *
     * @return void
     */
    public function test_adopt_file(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $src = $CFG->tempdir . '/repository_largefile_adopt_' . uniqid() . '.bin';
        $data = random_bytes(2048);
        file_put_contents($src, $data);

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $this->assertTrue(chunk_store::adopt_file($id, $src, 'fetched.bin'));
        $this->assertTrue(chunk_store::is_complete($id));
        $this->assertFileDoesNotExist($src, 'source file should have been moved');
        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);
        $record = chunk_store::get_record($id);
        $this->assertEquals('fetched.bin', $record->filename);
        $this->assertEquals(2048, (int) $record->length);
    }

    /**
     * reset discards a partial upload and returns the token to the unused state.
     *
     * @return void
     */
    public function test_reset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 500, 5000, 'x.bin', random_bytes(500));
        $this->assertFileExists(chunk_store::get_path_for_id($id));

        chunk_store::reset($id);
        $this->assertFileDoesNotExist(chunk_store::get_path_for_id($id));
        $record = chunk_store::get_record($id);
        $this->assertEquals(chunk_store::STATE_UNUSED, (int) $record->state);
        $this->assertEquals(0, (int) $record->currentpos);
    }
}
