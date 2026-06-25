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
 * AJAX endpoint backing the chunked-upload form element.
 *
 * Handles the three steps the front-end performs while uploading a large
 * package in chunks: start (first chunk), proceed (subsequent chunks) and
 * delete (discard a partial upload). Folded in from local_chunkupload
 * (2020 Justus Dieckmann), consolidated into one action-dispatched script.
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Justus Dieckmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');

use tool_canvasuplifter\chunkupload\form_element;
use tool_canvasuplifter\chunkupload\state_type;

$action = optional_param('action', null, PARAM_ALPHA);
$id = optional_param('id', null, PARAM_ALPHANUM);

// Emit a JSON error payload and stop. Never returns; every caller treats a
// returned error as terminal.
$senderror = function (string $message): void {
    die(json_encode((object) ['error' => $message]));
};

if (!$id) {
    $PAGE->set_context(context_system::instance());
    echo $OUTPUT->header();
    $senderror('Parameter id is missing.');
}

$record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $id]);
if (!$record) {
    $PAGE->set_context(context_system::instance());
    echo $OUTPUT->header();
    $senderror(get_string('tokenexpired', 'tool_canvasuplifter'));
}

$context = context::instance_by_id($record->contextid, IGNORE_MISSING);
if (!$context) {
    $PAGE->set_context(context_system::instance());
    echo $OUTPUT->header();
    $senderror('Context for that id not found.');
}

$PAGE->set_context($context);
echo $OUTPUT->header();
require_login();

if ($USER->id != $record->userid) {
    $senderror('Request was made by a different user!');
}

switch ($action) {
    case 'start':
        $start = optional_param('start', null, PARAM_INT);
        $length = optional_param('length', 0, PARAM_INT);
        $end = optional_param('end', null, PARAM_INT);
        $filename = optional_param('filename', null, PARAM_FILE);

        if ($length == 0) {
            $senderror('Must not be empty!');
        }
        if ($start === null) {
            $senderror('Param start is missing');
        }
        if ($end === null) {
            $senderror('Param end is missing');
        }
        if ($record->maxlength != -1 && $length > $record->maxlength) {
            $senderror('File is too long');
        }
        if ($end > $length) {
            $senderror('Chunk is longer than specified length');
        }

        $path = form_element::get_path_for_id($id);
        $content = file_get_contents('php://input', false, null, 0, $end);
        if (strlen($content) != $end) {
            $senderror('Filechunk is not as long as it should be.');
        }

        if (!file_exists($dirpath = form_element::get_base_folder())) {
            mkdir($dirpath, $CFG->directorypermissions, true);
        }
        file_put_contents($path, $content);

        $record->currentpos = $end;
        $record->length = $length;
        $record->lastmodified = time();
        $record->state = $end == $length ? state_type::UPLOAD_COMPLETED : state_type::UPLOAD_STARTED;
        $record->filename = $filename;
        $DB->update_record('tool_canvasuplifter_chunks', $record);
        break;

    case 'proceed':
        $start = optional_param('start', null, PARAM_INT);
        $end = optional_param('end', null, PARAM_INT);

        if ($start === null) {
            $senderror('Param start is missing');
        }
        if ($end === null) {
            $senderror('Param end is missing');
        }
        if ($record->state != state_type::UPLOAD_STARTED) {
            $senderror("File is in state $record->state, unable to proceed upload!");
        }
        if ($record->currentpos != $start) {
            $senderror('Filechunk does not begin where the last one left off.');
        }
        if ($end > $record->length) {
            $senderror('Filechunk is too long and exceeds the length of the whole file.');
        }

        $path = form_element::get_path_for_id($id);
        if (!file_exists($path)) {
            $senderror('Begin of file does not exist on this server.');
        }

        $content = file_get_contents('php://input', false, null, 0, $end - $start);
        if (strlen($content) != $end - $start) {
            $senderror('Filechunk is not as long as it should be.');
        }
        file_put_contents($path, $content, FILE_APPEND);

        $record->state = $end == $record->length ? state_type::UPLOAD_COMPLETED : state_type::UPLOAD_STARTED;
        $record->currentpos = $end;
        $record->lastmodified = time();
        $DB->update_record('tool_canvasuplifter_chunks', $record);
        break;

    case 'delete':
        $path = form_element::get_path_for_id($id);
        if (file_exists($path)) {
            unlink($path);

            $record->currentpos = 0;
            $record->length = 0;
            $record->lastmodified = time();
            $record->state = state_type::UNUSED_TOKEN_GENERATED;
            $record->filename = "";
            $DB->update_record('tool_canvasuplifter_chunks', $record);
        }
        break;

    default:
        $senderror('Unknown action.');
}

die(json_encode(new stdClass()));
