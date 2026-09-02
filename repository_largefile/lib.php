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
 * The Large file repository: import a file from a URL or a chunked upload.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

use repository_largefile\chunk_store;

/**
 * The Large file repository.
 *
 * Both ways of bringing in a file — a server-side fetch from a URL, and a
 * chunked browser upload that is not bound by PHP's per-request upload size —
 * end up as a "staged" file on disk (one {repository_largefile_chunks} row plus
 * a file under dataroot). The file picker lists a user's staged files and, when
 * one is selected, {@see get_file()} hands its bytes to the draft area. Because
 * the file arrives through the picker's "download" action rather than a
 * multipart upload, PHP's upload_max_filesize / post_max_size never apply.
 *
 * The upload/URL UI is launched from the file picker's "Upload a file" toolbar
 * button: {@see get_listing()} advertises `uploadfile`/`uploadevent`, the
 * bundled AMD module subscribes to that event and opens a dialogue, and its
 * completion callback re-lists this repository so the new staged file appears.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_largefile extends repository {
    /** @var string PubSub event the file picker publishes when the upload button is clicked. */
    public const UPLOAD_EVENT = 'repository_largefile_upload';

    /** @var string Prefix distinguishing a staged-file token source from anything else. */
    private const SOURCE_PREFIX = 'largefile:';

    /**
     * Constructor.
     *
     * @param int $repositoryid Repository instance id.
     * @param \stdClass|int $context Context this instance runs in.
     * @param array $options Repository options.
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = []) {
        global $PAGE;
        parent::__construct($repositoryid, $context, $options);
        // Register the dialogue handler for the file picker's upload button. It is
        // a no-op until the "Upload a file" button publishes the upload event.
        $PAGE->requires->js_call_amd('repository_largefile/upload', 'init');
    }

    /**
     * No interactive login is needed; the picker goes straight to the listing.
     *
     * @return bool Always true.
     */
    public function check_login() {
        return true;
    }

    /**
     * List the current user's staged (completed) large files.
     *
     * @param string $path Ignored; this repository is flat.
     * @param string $page Ignored; the listing is not paged.
     * @return array The file-picker listing, advertising the custom upload flow.
     */
    public function get_listing($path = '', $page = '') {
        global $OUTPUT, $USER;

        $list = [];
        foreach (chunk_store::list_completed((int) $USER->id) as $record) {
            $filename = (string) $record->filename;
            $list[] = [
                'title' => $filename,
                'source' => self::SOURCE_PREFIX . $record->id,
                'size' => (int) $record->length,
                'datemodified' => (int) $record->lastmodified,
                'datecreated' => (int) $record->lastmodified,
                'thumbnail' => $OUTPUT->image_url(file_extension_icon($filename, 64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
            ];
        }

        return [
            'list' => $list,
            'dynload' => false,
            'nologin' => true,
            'nosearch' => true,
            'norefresh' => false,
            'uploadfile' => true,
            'uploadevent' => self::UPLOAD_EVENT,
            'repo_id' => $this->id,
            'contextid' => $this->context->id,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Hand a selected staged file to the file picker (which copies it into the
     * draft area). The staged file is moved into the per-request temp directory
     * and its tracking row removed, so it is consumed exactly once.
     *
     * @param string $source The listing item's source (a staged-file token).
     * @param string $filename Filename the picker wants to save the file as.
     * @return array Keys 'path' (temp file to copy in) and 'url'.
     */
    public function get_file($source, $filename = '') {
        global $USER;

        $id = $this->token_from_source($source);
        $record = $id !== null ? chunk_store::get_record($id) : null;
        if (!$record || (int) $record->userid !== (int) $USER->id || !chunk_store::is_complete($id)) {
            throw new \moodle_exception('tokenexpired', 'repository_largefile');
        }

        $stagedpath = chunk_store::get_path_for_id($id);
        $target = $this->prepare_file($filename !== '' ? $filename : (string) $record->filename);

        // Move (not copy) the staged file into the temp path the picker will
        // consume: an atomic rename within dataroot avoids a second full-size copy
        // of a potentially multi-gigabyte file. Fall back to a copy across devices.
        if (!@rename($stagedpath, $target)) {
            if (!@copy($stagedpath, $target)) {
                throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
            }
            @unlink($stagedpath);
        }
        // The bytes now live in $target; drop the tracking row and any remnant.
        chunk_store::delete($id);

        return ['path' => $target, 'url' => ''];
    }

    /**
     * Extract the staged-file token from a listing source, or null if it is not
     * one of ours.
     *
     * @param string $source The listing item's source.
     * @return string|null The token id, or null.
     */
    private function token_from_source(string $source): ?string {
        if (strpos($source, self::SOURCE_PREFIX) !== 0) {
            return null;
        }
        $id = substr($source, strlen(self::SOURCE_PREFIX));
        return ($id !== '' && ctype_digit($id)) ? $id : null;
    }

    /**
     * Files are copied into Moodle (not linked), so only the internal return type
     * is supported.
     *
     * @return int The FILE_INTERNAL return type.
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }

    /**
     * Every file type is accepted; the destination form still applies its own
     * accepted-types restriction to the picked file.
     *
     * @return string The "all types" marker.
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     * Staged uploads belong to the user who made them, so this repository holds
     * per-user data.
     *
     * @return bool Always true.
     */
    public function contains_private_data() {
        return true;
    }
}
