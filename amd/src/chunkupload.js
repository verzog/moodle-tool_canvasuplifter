// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Manage the upload of a large package in chunks.
 *
 * Folded in from local_chunkupload (2020 Justus Dieckmann WWU); the upload loop
 * was reworked to retry a chunk through transient network/5xx failures and to
 * reconcile the resume position with the server after each failure.
 *
 * @module    tool_canvasuplifter/chunkupload
 * @copyright  2020 Justus Dieckmann WWU
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import $ from 'jquery';
import {get_strings as getStrings} from 'core/str';
import notification from 'core/notification';

/** @var {string} URL of the action-dispatched chunk upload endpoint, relative to wwwroot. */
const ENDPOINT = "/admin/tool/canvasuplifter/chunkupload_ajax.php?";

/** @var {number} How many times to retry a chunk after a transient failure before giving up. */
const MAX_RETRIES = 5;

/** @var {number} Base backoff between retries in ms; doubles each attempt up to the cap. */
const BACKOFF_BASE_MS = 1000;

/** @var {number} Upper bound on a single backoff wait in ms. */
const BACKOFF_CAP_MS = 16000;

/** @var {number} Server-side "upload completed" state (mirrors state_type.php). */
const STATE_COMPLETED = 2;

/**
 * Init
 * @param {String} elementid string The id of the input element
 * @param {String|String[]} acceptedTypes The accepted Types
 * @param {int} maxBytes The maximal allowed amount of bytes
 * @param {string} wwwroot The wwwroot
 * @param {int} chunksize The chunksize in bytes
 * @param {string} browsetext Text to display when no file is uploaded.
 */
export function init(elementid, acceptedTypes, maxBytes, wwwroot, chunksize, browsetext) {
    let wwwRoot = wwwroot;
    let chunkSize = chunksize;

    let fileinput = $('#' + elementid + "_file");
    let token = $('#' + elementid).val();
    let parentelem = fileinput.next();
    let filename = parentelem.find('.chunkupload-filename');
    let progress = parentelem.find('.chunkupload-progress');
    let progressicon = parentelem.find('.chunkupload-icon');
    let deleteicon = parentelem.next();

    fileinput.change(() => {
        reset();
        let file = fileinput.get(0).files[0];
        let fileextension = ".";
        if (file.name.indexOf(".") !== -1) {
            let splits = file.name.split(".");
            fileextension = "." + splits[splits.length - 1].toLowerCase();
        }
        if (!(acceptedTypes === '*' ||
            acceptedTypes instanceof Array && (acceptedTypes.indexOf(fileextension) !== -1 || acceptedTypes.indexOf('*') !== -1))) {
            fileinput.val(null);
            notifyError({key: 'invalidfiletype', component: 'core_repository', param: fileextension});
            return;
        } else if (maxBytes !== -1 && file.size > maxBytes) {
            fileinput.val(null);
            notifyError({key: 'errorpostmaxsize', component: 'core_repository'});
            return;
        }
        filename.text(file.name);
        uploadFile(file);
    });

    deleteicon.on('click', (event) => {
        reset();
        let params = {
            action: 'delete',
            id: token
        };
        let xhr = new XMLHttpRequest();
        xhr.open('post', wwwRoot + ENDPOINT + $.param(params));
        xhr.send(null);
        filename.text(browsetext);
        fileinput.val(null);
        event.stopPropagation();
    });

    /**
     * POST one request to the endpoint. Resolves with the HTTP status and
     * response text once the request completes; a network-level failure
     * resolves with status 0 (treated as transient) rather than rejecting.
     *
     * @param {object} params Query parameters (action, id, ...).
     * @param {Blob|null} body Request body, or null for a bodyless request.
     * @param {function|null} onprogress Optional callback given the bytes uploaded so far.
     * @return {Promise} Resolves with {status, text}.
     */
    function postRequest(params, body, onprogress) {
        return new Promise((resolve) => {
            let xhr = new XMLHttpRequest();
            xhr.open('post', wwwRoot + ENDPOINT + $.param(params));
            if (onprogress && xhr.upload) {
                xhr.upload.onprogress = (e) => onprogress(e.loaded);
            }
            xhr.onreadystatechange = () => {
                if (xhr.readyState === 4) {
                    resolve({status: xhr.status, text: xhr.responseText});
                }
            };
            xhr.onerror = () => resolve({status: 0, text: ''});
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.send(body);
        });
    }

    /**
     * Ask the server how many bytes of this upload it has actually stored, so a
     * retry after a lost response resumes from the true position instead of
     * dead-ending on a chunk-alignment error.
     *
     * @return {Promise} Resolves with {state, currentpos, length}, or null if unknown.
     */
    async function queryStatus() {
        let result = await postRequest({action: 'status', id: token}, null, null);
        if (result.status !== 200) {
            return null;
        }
        try {
            let snap = JSON.parse(result.text);
            if (snap.error !== undefined || snap.currentpos === undefined) {
                return null;
            }
            return snap;
        } catch (e) {
            return null;
        }
    }

    /**
     * Resolve after the given number of milliseconds.
     *
     * @param {number} ms Milliseconds to wait.
     * @return {Promise} Resolves once the delay elapses.
     */
    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    /**
     * Upload the whole file chunk by chunk. A chunk is retried through transient
     * network/5xx failures with exponential backoff, reconciling the resume
     * position with the server after each failure. A 4xx response or an explicit
     * server error message is terminal.
     *
     * @param {File} file The file to upload.
     * @return {Promise} Resolves once the upload finishes or fails terminally.
     */
    async function uploadFile(file) {
        let confirmed = 0;      // Bytes the server has confirmed stored.
        let retries = 0;
        let started = false;    // A chunk for THIS file has been accepted by the server.
        while (confirmed < file.size) {
            let start = confirmed;
            let end = Math.min(start + chunkSize, file.size);
            let firstchunk = start === 0;
            let params = firstchunk
                ? {action: 'start', start: start, end: end, length: file.size, filename: file.name, id: token}
                : {action: 'proceed', start: start, end: end, id: token};
            let slice = file.slice(start, end);
            let result = await postRequest(params, slice, (loaded) => setProgress(start + loaded, file.size));

            if (result.status === 200) {
                let response = null;
                try {
                    response = JSON.parse(result.text);
                } catch (e) {
                    response = null;
                }
                if (response !== null && response.error !== undefined) {
                    // Explicit server-side rejection — terminal.
                    notifyError(response.error);
                    return;
                }
                if (response !== null) {
                    // Chunk accepted.
                    confirmed = end;
                    started = true;
                    retries = 0;
                    setProgress(confirmed, file.size);
                    continue;
                }
                // A 200 with an unreadable body falls through to a retry.
            } else if (isTerminal(result.status)) {
                // Terminal client-side rejection (e.g. 413: chunk too large for a proxy).
                notifyError(result.status === 413
                    ? {key: 'errorchunktoolarge', component: 'tool_canvasuplifter'}
                    : {key: 'erroruploadfailed', component: 'tool_canvasuplifter'});
                return;
            }

            // Transient failure (network, 5xx, 408/429, or an unreadable 200):
            // back off, reconcile the resume position with the server, then retry.
            if (retries >= MAX_RETRIES) {
                notifyError({key: 'erroruploadfailed', component: 'tool_canvasuplifter'});
                return;
            }
            retries++;
            await sleep(Math.min(BACKOFF_BASE_MS * Math.pow(2, retries - 1), BACKOFF_CAP_MS));
            // Only trust the server's position once a chunk for THIS file has
            // been accepted (so a stale row from a previous completed upload is
            // never mistaken for this one) and its reported length matches.
            if (started) {
                let snap = await queryStatus();
                if (snap !== null && snap.length === file.size) {
                    let advanced = snap.currentpos > confirmed;
                    confirmed = (snap.state === STATE_COMPLETED || snap.currentpos >= file.size)
                        ? file.size : snap.currentpos;
                    if (advanced) {
                        // The failed chunk actually committed — real progress, so
                        // this attempt does not count against the retry budget.
                        retries = 0;
                    }
                    setProgress(confirmed, file.size);
                }
            }
        }
        // The server holds the whole file; mark the bar finished.
        setProgress(file.size, file.size);
    }

    /**
     * Whether an HTTP status is a terminal client-side rejection. 408 (request
     * timeout) and 429 (too many requests) are transient and handled by the
     * retry path; other 4xx responses are validation errors and terminal.
     *
     * @param {number} status The HTTP status code.
     * @return {boolean} True if the upload should abort on this status.
     */
    function isTerminal(status) {
        return status >= 400 && status < 500 && status !== 408 && status !== 429;
    }

    /**
     * Resets the Progress and the Filepicker name.
     */
    function reset() {
        setProgress(0, 1);
        filename.text("");
    }

    /**
     * Sets the progressbar
     * @param {int} loaded
     * @param {int} total
     */
    function setProgress(loaded, total) {
        if (loaded === total) {
            // Hide progressbar on finish.
            progress.css('width', '0');
        } else {
            progress.css('width', loaded * 100 / total + "%");
        }
        progressicon.prop('hidden', loaded !== total);
        deleteicon.prop('hidden', loaded !== total);
    }

    /**
     * Notify error
     * @param {object|string} errorstring Either Object as accepted by getString, or a string, to describe the error.
     */
    function notifyError(errorstring) {
        reset();
        if (typeof errorstring === "string") {
            getStrings([
                {key: 'error'},
                {key: 'ok'},
            ]).done(function(s) {
                    notification.alert(s[0], errorstring, s[1]);
                }
            ).fail(notification.exception);
        } else {
            getStrings([
                {key: 'error'},
                errorstring,
                {key: 'ok'},
            ]).done(function(s) {
                    notification.alert(s[0], s[1], s[2]);
                }
            ).fail(notification.exception);
        }
    }
}
