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
 * Language strings for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addfile'] = 'Add file';
$string['chooselargefile'] = 'Choose a large file';
$string['cleanup_task'] = 'Clean up stale chunked uploads';
$string['configplugin'] = 'Large file repository settings';
$string['errorbadurl'] = 'That does not look like a valid http(s) download URL.';
$string['errorchunktoolarge'] = 'The server rejected an upload chunk as too large. Ask an administrator to lower the '
    . '"Chunk size (MB)" setting, then upload the file again.';
$string['errordownloadempty'] = 'The URL returned an empty response.';
$string['errordownloadfailed'] = 'The file could not be downloaded from that URL.';
$string['errordownloadhttp'] = 'The server returned HTTP status {$a} for that URL.';
$string['errordownloadtoobig'] = 'The file at that URL is larger than the site upload limit.';
$string['erroremptyfile'] = 'The selected file is empty.';
$string['erroruploadfailed'] = 'The upload could not be completed after several attempts. Check your connection and try '
    . 'uploading the file again.';
$string['largefile:view'] = 'Use the Large file repository in the file picker';
$string['pluginname'] = 'Large file (URL or chunked upload)';
$string['pluginname_help'] = 'Bring in a file that is too big for a normal upload: fetch it from a URL server-side, '
    . 'or upload it from your computer in small chunks that are not limited by this server\'s PHP upload size.';
$string['privacy:chunkspath'] = 'Chunked uploads';
$string['privacy:metadata:repository_largefile_chunks'] = 'Temporary records of large files uploaded in chunks before '
    . 'they are handed to the file picker.';
$string['privacy:metadata:repository_largefile_chunks:contextid'] = 'The context in which the file was uploaded.';
$string['privacy:metadata:repository_largefile_chunks:filename'] = 'The name of the uploaded file.';
$string['privacy:metadata:repository_largefile_chunks:lastmodified'] = 'The time the upload was last modified.';
$string['privacy:metadata:repository_largefile_chunks:userid'] = 'The user who uploaded the file.';
$string['selectuploaded'] = 'Select uploaded file';
$string['setting:chunksize'] = 'Chunk size (MB)';
$string['setting:chunksize_desc'] = 'Size of each chunk sent to the server when uploading a large file, in megabytes. '
    . 'Lower this if large uploads fail — some web servers, reverse proxies and firewalls reject large request bodies.';
$string['setting:state0duration'] = 'Keep unused upload tokens for';
$string['setting:state0duration_desc'] = 'How long an upload token that was generated but never used is kept before the '
    . 'cleanup task removes it.';
$string['setting:state1duration'] = 'Keep unfinished uploads for';
$string['setting:state1duration_desc'] = 'How long a partially uploaded file is kept before the cleanup task removes it.';
$string['setting:state2duration'] = 'Keep completed uploads for';
$string['setting:state2duration_desc'] = 'How long a completed upload that was never selected is kept before the cleanup '
    . 'task removes it.';
$string['settings'] = 'Chunked upload settings';
$string['tabupload'] = 'Upload a large file';
$string['taburl'] = 'From a URL';
$string['tokenexpired'] = 'The upload session has expired. Close and reopen the file picker to start again.';
$string['uploaded'] = 'File uploaded';
$string['uploading'] = 'Uploading…';
$string['uploadinstructions'] = 'The file is uploaded in small chunks, so PHP\'s per-request upload size does not apply. '
    . 'Keep this window open until the upload finishes.';
$string['uploadnotfinished'] = 'The upload did not finish.';
$string['url'] = 'File URL';
$string['url_help'] = 'Paste a direct http(s) download link (for example a signed S3 link). The site fetches it on the '
    . 'server, so it is not limited by the browser upload size. The site upload limit still applies.';
