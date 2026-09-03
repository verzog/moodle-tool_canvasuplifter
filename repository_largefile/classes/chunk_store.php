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

/**
 * Server-side state and disk storage for chunked large-file uploads.
 *
 * Derived from the chunked-upload logic in tool_canvasuplifter (itself folded in
 * from local_chunkupload, 2020 Justus Dieckmann WWU), generalised here so a large
 * file can be uploaded to a repository in chunks without hitting PHP's
 * per-request upload/post size limits. Each in-flight upload is one row in
 * {repository_largefile_chunks} plus a partial file on disk under dataroot; the
 * file grows as chunks arrive and is handed to the file picker's draft area once
 * complete (see {@see \repository_largefile::get_file()}).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile;

/**
 * Server-side state and disk storage for chunked large-file uploads.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chunk_store {
    /** @var int Token generated when the picker screen rendered, no upload yet. */
    public const STATE_UNUSED = 0;

    /** @var int Upload has started but not all chunks have arrived. */
    public const STATE_STARTED = 1;

    /** @var int All chunks received; the file is complete. */
    public const STATE_COMPLETED = 2;

    /** @var string Database table backing the upload tokens. */
    public const TABLE = 'repository_largefile_chunks';

    /**
     * Create a new upload token owned by the current user, and return its id.
     *
     * @param int $contextid Context the upload was started in.
     * @param int $maxbytes Maximum accepted size in bytes, or -1 for unlimited.
     * @return string|null The new token id, or null for a guest (who may not upload).
     */
    public static function create_token(int $contextid, int $maxbytes): ?string {
        global $DB, $USER;

        if (isguestuser() || !isloggedin()) {
            return null;
        }

        do {
            $id = (string) random_int(1, 10000000000);
        } while ($DB->record_exists(self::TABLE, ['id' => $id]));

        $record = new \stdClass();
        $record->id = $id;
        $record->userid = $USER->id;
        $record->contextid = $contextid;
        $record->maxlength = $maxbytes;
        $record->state = self::STATE_UNUSED;
        $record->currentpos = 0;
        $record->length = 0;
        $record->lastmodified = time();
        $DB->insert_record_raw(self::TABLE, $record, false, false, true);
        return $id;
    }

    /**
     * Fetch one token row.
     *
     * @param string $id The token id.
     * @return \stdClass|null The row, or null if it does not exist.
     */
    public static function get_record(string $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * List a user's completed, not-yet-consumed staged uploads, newest first.
     *
     * @param int $userid The owning user's id.
     * @return array Array of token rows in the completed state.
     */
    public static function list_completed(int $userid): array {
        global $DB;
        return $DB->get_records(self::TABLE, [
            'userid' => $userid,
            'state' => self::STATE_COMPLETED,
        ], 'lastmodified DESC');
    }

    /**
     * Adopt an already-downloaded file as the stored file for a token (used by the
     * URL-fetch path). The source file is moved into the token's chunk path and
     * the row is marked completed.
     *
     * @param string $id The token id.
     * @param string $srcpath Absolute path of the fetched file to adopt.
     * @param string $filename The file's display name.
     * @return bool True on success.
     */
    public static function adopt_file(string $id, string $srcpath, string $filename): bool {
        global $CFG, $DB;
        $record = self::get_record($id);
        if (!$record || !is_readable($srcpath)) {
            return false;
        }
        $dirpath = self::get_base_folder();
        if (!file_exists($dirpath)) {
            mkdir($dirpath, $CFG->directorypermissions, true);
        }
        $target = self::get_path_for_id($id);
        if (!@rename($srcpath, $target)) {
            if (!@copy($srcpath, $target)) {
                return false;
            }
            @unlink($srcpath);
        }
        $record->filename = $filename;
        $record->length = (int) filesize($target);
        $record->currentpos = $record->length;
        $record->state = self::STATE_COMPLETED;
        $record->lastmodified = time();
        $DB->update_record(self::TABLE, $record);
        return true;
    }

    /**
     * Base folder under dataroot where partial chunk files live.
     *
     * @return string Absolute path ending in a directory separator.
     */
    public static function get_base_folder(): string {
        global $CFG;
        return "$CFG->dataroot/repository_largefile/chunks/";
    }

    /**
     * Absolute path of the partial file for a token.
     *
     * @param string|null $id The token id.
     * @return string|null The path, or null when no id was given.
     */
    public static function get_path_for_id(?string $id): ?string {
        if ($id === null || $id === '') {
            return null;
        }
        return self::get_base_folder() . $id;
    }

    /**
     * Snapshot of how far the server has actually stored an in-flight upload, so
     * the browser can reconcile against it after a failed chunk (e.g. a 504 that
     * timed out the response but still committed the write) instead of
     * dead-ending on a chunk-alignment error.
     *
     * @param string $id The token id.
     * @return array|null Keys state, currentpos, length; or null if not found.
     */
    public static function get_progress(string $id): ?array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], 'id, state, currentpos, length', IGNORE_MISSING);
        if (!$record) {
            return null;
        }
        return [
            'state' => (int) $record->state,
            'currentpos' => (int) $record->currentpos,
            'length' => (int) $record->length,
        ];
    }

    /**
     * Write the first chunk of a new upload, creating the partial file on disk.
     *
     * @param \stdClass $record The token row (mutated and saved on success).
     * @param int $start Offset the chunk begins at (must be 0 for the first chunk).
     * @param int $end Offset the chunk ends at.
     * @param int $length Total declared file length in bytes.
     * @param string $filename The uploaded file's name.
     * @param string $content The chunk bytes; length must equal end.
     * @return string|null An error message, or null on success.
     */
    public static function apply_start($record, int $start, int $end, int $length, string $filename, string $content): ?string {
        global $CFG, $DB;

        if ($length <= 0) {
            return 'Must not be empty!';
        }
        if ($start !== 0) {
            return 'A start chunk must begin at 0.';
        }
        if ((int) $record->maxlength !== -1 && $length > (int) $record->maxlength) {
            return get_string('errorfiletoobig', 'moodle', (int) $record->maxlength);
        }
        if ($end > $length) {
            return 'Chunk is longer than specified length';
        }
        if (strlen($content) !== $end) {
            return 'Filechunk is not as long as it should be.';
        }

        $dirpath = self::get_base_folder();
        if (!file_exists($dirpath)) {
            mkdir($dirpath, $CFG->directorypermissions, true);
        }
        // Only advance the stored position by the bytes actually persisted, so a
        // short write (disk full, quota) can never mark the upload further along
        // than the file really is — which would hand the picker a truncated file.
        $written = file_put_contents(self::get_path_for_id($record->id), $content);
        if ($written === false || $written !== strlen($content)) {
            return 'Failed to write chunk to disk.';
        }

        $record->currentpos = $end;
        $record->length = $length;
        $record->lastmodified = time();
        $record->state = $end === $length ? self::STATE_COMPLETED : self::STATE_STARTED;
        $record->filename = $filename;
        $DB->update_record(self::TABLE, $record);
        return null;
    }

    /**
     * Append a "proceed" chunk to a partially uploaded file, tolerating a re-sent
     * or partially written chunk left behind by an interrupted request.
     *
     * The stored currentpos is the source of truth: any bytes a half-finished
     * attempt wrote past it are truncated before writing, so re-sending a chunk
     * can never double-append; a chunk already fully stored (the client retried
     * after a lost response) is accepted as a no-op.
     *
     * @param \stdClass $record The token row (mutated and saved on success).
     * @param int $start Offset the client believes the chunk begins at.
     * @param int $end Offset the chunk ends at.
     * @param string $content The chunk bytes; length must equal end - start.
     * @return string|null An error message, or null on success.
     */
    public static function apply_proceed($record, int $start, int $end, string $content): ?string {
        global $DB;
        $error = self::check_bounds($record, $start, $end);
        if ($error !== null) {
            return $error;
        }
        if (strlen($content) !== $end - $start) {
            return 'Filechunk is not as long as it should be.';
        }
        $path = self::get_path_for_id($record->id);
        if ($path === null || !file_exists($path)) {
            return 'Begin of file does not exist on this server.';
        }

        $currentpos = (int) $record->currentpos;
        if ($end > $currentpos) {
            // Trust the stored position: drop any bytes an interrupted retry left
            // past it, then write only the portion beyond currentpos.
            $handle = fopen($path, 'r+b');
            if ($handle === false) {
                return 'Begin of file does not exist on this server.';
            }
            $towrite = substr($content, $currentpos - $start);
            if (ftruncate($handle, $currentpos) === false || fseek($handle, $currentpos) !== 0) {
                fclose($handle);
                return 'Could not position the upload file for writing.';
            }
            // Advance the stored position only by the bytes fwrite actually
            // persisted, so a short write (disk full, quota) never marks the
            // upload further along than the file really is; the client then
            // resumes from the true position. Persist that position before
            // reporting the failure so the resume is accurate.
            $written = fwrite($handle, $towrite);
            fclose($handle);
            if ($written === false) {
                return 'Failed to write chunk to disk.';
            }
            $record->currentpos = $currentpos + $written;
            if ($written < strlen($towrite)) {
                $record->state = self::STATE_STARTED;
                $record->lastmodified = time();
                $DB->update_record(self::TABLE, $record);
                return 'Failed to write the whole chunk to disk.';
            }
        }
        // Otherwise the whole chunk is already stored — accept it as a no-op.
        $record->state = (int) $record->currentpos === (int) $record->length
            ? self::STATE_COMPLETED : self::STATE_STARTED;
        $record->lastmodified = time();
        $DB->update_record(self::TABLE, $record);
        return null;
    }

    /**
     * Validate a "proceed" chunk's byte range against the stored upload — the
     * checks that need no request body, so the endpoint can reject a malformed or
     * replayed request from its token's state alone before buffering the payload.
     *
     * @param \stdClass $record The token row.
     * @param int $start Offset the client believes the chunk begins at.
     * @param int $end Offset the chunk ends at.
     * @return string|null An error message, or null if the range is acceptable.
     */
    public static function check_bounds($record, int $start, int $end): ?string {
        if ($start < 0 || $end < $start) {
            return 'Filechunk range is invalid.';
        }
        if ($start > (int) $record->currentpos) {
            return 'Filechunk does not begin where the last one left off.';
        }
        if ($end > (int) $record->length) {
            return 'Filechunk is too long and exceeds the length of the whole file.';
        }
        return null;
    }

    /**
     * Whether a completed file is stored for the given token.
     *
     * @param string|null $id The token id.
     * @return bool True when the upload finished and the file is on disk.
     */
    public static function is_complete(?string $id): bool {
        if ($id === null || $id === '') {
            return false;
        }
        $record = self::get_record($id);
        if (!$record || (int) $record->state !== self::STATE_COMPLETED) {
            return false;
        }
        $path = self::get_path_for_id($id);
        return $path !== null && file_exists($path);
    }

    /**
     * Reset a token to the unused state and remove any partial file.
     *
     * @param string $id The token id.
     * @return void
     */
    public static function reset(string $id): void {
        global $DB;
        $path = self::get_path_for_id($id);
        if ($path !== null && file_exists($path)) {
            unlink($path);
        }
        $record = self::get_record($id);
        if ($record) {
            $record->currentpos = 0;
            $record->length = 0;
            $record->filename = '';
            $record->state = self::STATE_UNUSED;
            $record->lastmodified = time();
            $DB->update_record(self::TABLE, $record);
        }
    }

    /**
     * Delete a token row and its partial file.
     *
     * @param string $id The token id.
     * @return void
     */
    public static function delete(string $id): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $path = self::get_path_for_id($id);
        if ($path !== null && file_exists($path)) {
            unlink($path);
        }
    }
}
