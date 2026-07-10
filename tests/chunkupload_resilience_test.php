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
 * Tests the chunked uploader's resume/reconcile behaviour: get_progress
 * reports the stored position, and apply_proceed tolerates a chunk the client
 * retries after an interrupted request without corrupting the file.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\chunkupload\form_element
 */
final class chunkupload_resilience_test extends \advanced_testcase {
    /**
     * Insert a partial upload row and write its on-disk bytes.
     *
     * @param int $id The chunk token.
     * @param string $ondisk Bytes currently on disk (may exceed currentpos).
     * @param int $currentpos Bytes the server considers confirmed.
     * @param int $length Total expected file length.
     * @return \stdClass The freshly read tracking row.
     */
    private function partial(int $id, string $ondisk, int $currentpos, int $length): \stdClass {
        global $DB, $USER;
        $DB->insert_record_raw('tool_canvasuplifter_chunks', (object) [
            'id' => $id,
            'userid' => $USER->id,
            'contextid' => \context_system::instance()->id,
            'maxlength' => -1,
            'lastmodified' => time(),
            'state' => state_type::UPLOAD_STARTED,
            'filename' => 'course.imscc',
            'length' => $length,
            'currentpos' => $currentpos,
        ], false, false, true);
        $dir = form_element::get_base_folder();
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(form_element::get_path_for_id($id), $ondisk);
        return $DB->get_record('tool_canvasuplifter_chunks', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * get_progress returns null for an unknown token and the stored position
     * for a known one.
     *
     * @return void
     */
    public function test_get_progress(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertNull(form_element::get_progress(999));

        $this->partial(300, '0123456789', 10, 16);
        $snap = form_element::get_progress(300);
        $this->assertSame(state_type::UPLOAD_STARTED, $snap['state']);
        $this->assertSame(10, $snap['currentpos']);
        $this->assertSame(16, $snap['length']);
    }

    /**
     * A normal sequential chunk appends and, on reaching the length, completes.
     *
     * @return void
     */
    public function test_apply_proceed_appends_and_completes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $record = $this->partial(301, '0123456789', 10, 16);
        $error = form_element::apply_proceed($record, 10, 16, 'ABCDEF');

        $this->assertNull($error);
        $this->assertSame('0123456789ABCDEF', file_get_contents(form_element::get_path_for_id(301)));
        $this->assertSame(16, (int) $record->currentpos);
        $this->assertSame(state_type::UPLOAD_COMPLETED, (int) $record->state);
    }

    /**
     * A chunk the server has already stored (the client retried after a lost
     * response) is accepted as a no-op — the file and position are unchanged.
     *
     * @return void
     */
    public function test_apply_proceed_resent_chunk_is_noop(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $record = $this->partial(302, '0123456789', 10, 16);
        // The server already advanced to 10; the client re-sends [4, 10].
        $error = form_element::apply_proceed($record, 4, 10, '456789');

        $this->assertNull($error);
        $this->assertSame('0123456789', file_get_contents(form_element::get_path_for_id(302)));
        $this->assertSame(10, (int) $record->currentpos);
    }

    /**
     * A half-written interrupted attempt left stray bytes past currentpos; the
     * retried chunk truncates them and writes the correct data, so the file is
     * not corrupted by a double-append.
     *
     * @return void
     */
    public function test_apply_proceed_truncates_stray_partial_write(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // DB says 10 bytes confirmed, but disk has 3 stray bytes past that.
        $record = $this->partial(303, '0123456789XYZ', 10, 16);
        $error = form_element::apply_proceed($record, 10, 16, 'ABCDEF');

        $this->assertNull($error);
        $this->assertSame('0123456789ABCDEF', file_get_contents(form_element::get_path_for_id(303)));
        $this->assertSame(16, (int) $record->currentpos);
    }

    /**
     * A chunk that overlaps the confirmed position (client resumed from a stale
     * offset) writes only the portion beyond currentpos, landing correct data.
     *
     * @return void
     */
    public function test_apply_proceed_overlapping_chunk(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $record = $this->partial(304, '0123456789', 10, 16);
        // Client resends from a stale start=6; only [10,16] is new.
        $error = form_element::apply_proceed($record, 6, 16, '6789ABCDEF');

        $this->assertNull($error);
        $this->assertSame('0123456789ABCDEF', file_get_contents(form_element::get_path_for_id(304)));
        $this->assertSame(16, (int) $record->currentpos);
    }

    /**
     * check_bounds rejects a gap, an over-length end and a backwards range from
     * the stored state alone (so the endpoint can reject before reading a body),
     * while accepting a valid, a resent and an overlapping range.
     *
     * @return void
     */
    public function test_check_bounds(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $record = $this->partial(306, '0123456789', 10, 16);
        // Rejected without needing the request body.
        $this->assertNotNull(form_element::check_bounds($record, 12, 16));   // Gap.
        $this->assertNotNull(form_element::check_bounds($record, 10, 20));   // Past length.
        $this->assertNotNull(form_element::check_bounds($record, 8, 4));     // Backwards.
        // Accepted: the endpoint may read the body and let apply_proceed reconcile.
        $this->assertNull(form_element::check_bounds($record, 10, 16));      // Next chunk.
        $this->assertNull(form_element::check_bounds($record, 4, 10));       // Already stored.
        $this->assertNull(form_element::check_bounds($record, 6, 16));       // Overlapping.
    }

    /**
     * A chunk that begins past the confirmed position (a gap) is rejected.
     *
     * @return void
     */
    public function test_apply_proceed_rejects_gap(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $record = $this->partial(305, '0123456789', 10, 16);
        $error = form_element::apply_proceed($record, 12, 16, 'CDEF');

        $this->assertNotNull($error);
        $this->assertSame('0123456789', file_get_contents(form_element::get_path_for_id(305)));
        $this->assertSame(10, (int) $record->currentpos);
    }
}
