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
 * AJAX endpoint backing the Large file repository's upload dialogue.
 *
 * Actions:
 *  - newtoken: allocate an upload token for the current user.
 *  - start:    write the first chunk of a new upload.
 *  - proceed:  append a subsequent chunk.
 *  - status:   report how many bytes the server has stored (for resume).
 *  - delete:   discard a partial upload.
 *  - fetchurl: fetch a remote URL server-side into the token (URL import).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use repository_largefile\chunk_store;
use repository_largefile\local\url_fetcher;

$PAGE->set_context(context_system::instance());
require_login();
require_sesskey();

if (isguestuser()) {
    throw new \moodle_exception('noguest');
}

$action = optional_param('action', null, PARAM_ALPHA);

// Emit a JSON error payload and stop.
$senderror = function (string $message): void {
    echo json_encode((object) ['error' => $message]);
    die;
};

// Allocate a new upload token. Kept separate from the first chunk so the browser
// can obtain the token before it starts streaming bytes.
if ($action === 'newtoken') {
    $contextid = required_param('contextid', PARAM_INT);
    $context = context::instance_by_id($contextid, IGNORE_MISSING);
    if (!$context) {
        $senderror('Context not found.');
    }
    // The server-side ceiling for a staged file: the site upload limit, or
    // unlimited when the site imposes none. The destination form re-checks its
    // own (possibly smaller) limit when the file is selected.
    $sitemax = (int) ($CFG->maxbytes ?? 0);
    $maxbytes = $sitemax > 0 ? $sitemax : -1;
    $id = chunk_store::create_token($contextid, $maxbytes);
    if ($id === null) {
        $senderror(get_string('erroruploadfailed', 'repository_largefile'));
    }
    // Chunk size in bytes, from the admin setting, with a 20 MB fallback so a
    // missing or zero setting can never stall the uploader.
    $chunkmb = (int) get_config('repository_largefile', 'chunksize');
    if ($chunkmb <= 0) {
        $chunkmb = 20;
    }
    echo json_encode((object) ['id' => $id, 'maxbytes' => $maxbytes, 'chunksize' => $chunkmb * 1024 * 1024]);
    die;
}

// Every remaining action operates on an existing token the caller must own.
$id = optional_param('id', null, PARAM_ALPHANUM);
if (!$id) {
    $senderror('Parameter id is missing.');
}
$record = chunk_store::get_record($id);
if (!$record) {
    $senderror(get_string('tokenexpired', 'repository_largefile'));
}
if ((int) $record->userid !== (int) $USER->id) {
    $senderror('Request was made by a different user!');
}

switch ($action) {
    case 'start':
        $start = optional_param('start', null, PARAM_INT);
        $length = optional_param('length', 0, PARAM_INT);
        $end = optional_param('end', null, PARAM_INT);
        $filename = clean_param(optional_param('filename', '', PARAM_FILE), PARAM_FILE);

        if ($start === null || $end === null) {
            $senderror('Param start or end is missing');
        }
        $content = file_get_contents('php://input', false, null, 0, $end);
        $error = chunk_store::apply_start($record, $start, $end, $length, $filename, (string) $content);
        if ($error !== null) {
            $senderror($error);
        }
        break;

    case 'proceed':
        $start = optional_param('start', null, PARAM_INT);
        $end = optional_param('end', null, PARAM_INT);
        if ($start === null || $end === null) {
            $senderror('Param start or end is missing');
        }
        // Reject a malformed or replayed range from the stored state before
        // buffering the request body.
        $bounds = chunk_store::check_bounds($record, $start, $end);
        if ($bounds !== null) {
            $senderror($bounds);
        }
        $content = file_get_contents('php://input', false, null, 0, $end - $start);
        $error = chunk_store::apply_proceed($record, $start, $end, (string) $content);
        if ($error !== null) {
            $senderror($error);
        }
        break;

    case 'status':
        $progress = chunk_store::get_progress($id);
        echo json_encode((object) ($progress ?? ['error' => get_string('tokenexpired', 'repository_largefile')]));
        die;

    case 'delete':
        chunk_store::reset($id);
        break;

    case 'fetchurl':
        $url = trim(required_param('url', PARAM_RAW_TRIMMED));
        if (!url_fetcher::is_fetchable_url($url)) {
            $senderror(get_string('errorbadurl', 'repository_largefile'));
        }
        // A large fetch can run long; free the session lock and lift the time
        // limit so it neither blocks the user's other requests nor is cut short.
        \core\session\manager::write_close();
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $maxbytes = (int) $record->maxlength;
        try {
            $fetcher = new url_fetcher();
            $result = $fetcher->fetch($url, $maxbytes > 0 ? $maxbytes : 0);
        } catch (\moodle_exception $e) {
            $senderror($e->getMessage());
        }
        if (!chunk_store::adopt_file($id, $result['path'], $result['filename'])) {
            @unlink($result['path']);
            $senderror(get_string('errordownloadfailed', 'repository_largefile'));
        }
        echo json_encode((object) ['filename' => $result['filename']]);
        die;

    default:
        $senderror('Unknown action.');
}

echo json_encode(new stdClass());
die;
