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
 * Upload dialogue for the Large file repository.
 *
 * Subscribes to the file picker's upload event, opens a dialogue offering a
 * chunked upload from the user's computer or a server-side fetch from a URL, and
 * refreshes the picker listing once a file has been staged. The chunk loop
 * retries transient failures with exponential backoff and reconciles its resume
 * position with the server, so a large upload survives a dropped chunk.
 *
 * @module     repository_largefile/upload
 * @copyright  2026 SCCA
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {subscribe} from 'core/pubsub';
import SaveCancelModal from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {getString} from 'core/str';
import Notification from 'core/notification';
import * as config from 'core/config';

/** @constant {string} Upload endpoint, relative to wwwroot. */
const ENDPOINT = '/repository/largefile/upload_ajax.php';

/** @constant {number} How many times to retry a chunk after a transient failure. */
const MAX_RETRIES = 5;

/** @constant {number} Base backoff between retries in ms; doubles each attempt up to the cap. */
const BACKOFF_BASE_MS = 1000;

/** @constant {number} Upper bound on a single backoff wait in ms. */
const BACKOFF_CAP_MS = 16000;

/** @constant {number} Server-side "upload completed" state (mirrors chunk_store). */
const STATE_COMPLETED = 2;

/** @var {boolean} Whether the pubsub listener has been registered. */
let listenersRegistered = false;

/**
 * POST one request to the endpoint. Resolves with the HTTP status and response
 * text; a network-level failure resolves with status 0 (treated as transient).
 *
 * @param {object} params Query parameters (action, id, ...); sesskey is added.
 * @param {Blob|FormData|null} body Request body, or null.
 * @param {boolean} octet Whether to send the body as application/octet-stream.
 * @param {function|null} onprogress Optional callback given the bytes uploaded so far.
 * @return {Promise} Resolves with {status, text}.
 */
const postRequest = (params, body, octet, onprogress) => {
    return new Promise((resolve) => {
        const query = Object.assign({sesskey: config.sesskey}, params);
        const qs = Object.keys(query).map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(query[k])).join('&');
        const xhr = new XMLHttpRequest();
        xhr.open('post', config.wwwroot + ENDPOINT + '?' + qs);
        if (onprogress && xhr.upload) {
            xhr.upload.onprogress = (e) => onprogress(e.loaded);
        }
        xhr.onreadystatechange = () => {
            if (xhr.readyState === 4) {
                resolve({status: xhr.status, text: xhr.responseText});
            }
        };
        xhr.onerror = () => resolve({status: 0, text: ''});
        if (octet) {
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        }
        xhr.send(body);
    });
};

/**
 * Parse a JSON response, returning null when it cannot be parsed.
 *
 * @param {string} text The response text.
 * @return {object|null} The parsed object, or null.
 */
const parseJson = (text) => {
    try {
        return JSON.parse(text);
    } catch (e) {
        return null;
    }
};

/**
 * Resolve after the given number of milliseconds.
 *
 * @param {number} ms Milliseconds to wait.
 * @return {Promise} Resolves once the delay elapses.
 */
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Whether an HTTP status is a terminal client-side rejection. 408 and 429 are
 * transient and handled by the retry path; other 4xx responses are terminal.
 *
 * @param {number} status The HTTP status code.
 * @return {boolean} True if the upload should abort on this status.
 */
const isTerminal = (status) => status >= 400 && status < 500 && status !== 408 && status !== 429;

/**
 * Allocate a new upload token for the current context.
 *
 * @param {number} contextId The context id.
 * @return {Promise} Resolves with {id, maxbytes, chunksize}, or rejects with a message.
 */
const newToken = async(contextId) => {
    const result = await postRequest({action: 'newtoken', contextid: contextId}, null, false, null);
    const response = parseJson(result.text);
    if (result.status !== 200 || response === null || response.error !== undefined || !response.id) {
        throw new Error(response && response.error ? response.error : await getString('erroruploadfailed', 'repository_largefile'));
    }
    return response;
};

/**
 * Ask the server how many bytes of this upload it has stored, so a retry after a
 * lost response resumes from the true position.
 *
 * @param {string} token The upload token id.
 * @return {Promise} Resolves with {state, currentpos, length}, or null if unknown.
 */
const queryStatus = async(token) => {
    const result = await postRequest({action: 'status', id: token}, null, false, null);
    if (result.status !== 200) {
        return null;
    }
    const snap = parseJson(result.text);
    if (snap === null || snap.error !== undefined || snap.currentpos === undefined) {
        return null;
    }
    return snap;
};

/**
 * Upload one file chunk by chunk. A chunk is retried through transient network or
 * 5xx failures with exponential backoff, reconciling the resume position with the
 * server after each failure. A 4xx response or explicit server error is terminal.
 *
 * @param {File} file The file to upload.
 * @param {string} token The upload token id.
 * @param {number} chunkSize The chunk size in bytes.
 * @param {function} onProgress Callback given (bytesConfirmed, total).
 * @return {Promise} Resolves true on success, or rejects with an error message.
 */
const uploadFileChunked = async(file, token, chunkSize, onProgress) => {
    let confirmed = 0;
    let retries = 0;
    let started = false;
    while (confirmed < file.size) {
        const start = confirmed;
        const end = Math.min(start + chunkSize, file.size);
        const params = start === 0
            ? {action: 'start', start: start, end: end, length: file.size, filename: file.name, id: token}
            : {action: 'proceed', start: start, end: end, id: token};
        const slice = file.slice(start, end);
        const result = await postRequest(params, slice, true, (loaded) => onProgress(start + loaded, file.size));

        if (result.status === 200) {
            const response = parseJson(result.text);
            if (response !== null && response.error !== undefined) {
                throw new Error(response.error);
            }
            if (response !== null) {
                confirmed = end;
                started = true;
                retries = 0;
                onProgress(confirmed, file.size);
                continue;
            }
        } else if (isTerminal(result.status)) {
            const key = result.status === 413 ? 'errorchunktoolarge' : 'erroruploadfailed';
            throw new Error(await getString(key, 'repository_largefile'));
        }

        // Transient failure: back off, reconcile with the server, then retry.
        if (retries >= MAX_RETRIES) {
            throw new Error(await getString('erroruploadfailed', 'repository_largefile'));
        }
        retries++;
        await sleep(Math.min(BACKOFF_BASE_MS * Math.pow(2, retries - 1), BACKOFF_CAP_MS));
        if (started) {
            const snap = await queryStatus(token);
            if (snap !== null && snap.length === file.size) {
                const advanced = snap.currentpos > confirmed;
                confirmed = (snap.state === STATE_COMPLETED || snap.currentpos >= file.size) ? file.size : snap.currentpos;
                if (advanced) {
                    retries = 0;
                }
                onProgress(confirmed, file.size);
            }
        }
    }
    return true;
};

/**
 * Fetch a remote URL server-side into the token.
 *
 * @param {string} url The URL to fetch.
 * @param {string} token The upload token id.
 * @return {Promise} Resolves true on success, or rejects with an error message.
 */
const fetchUrl = async(url, token) => {
    const result = await postRequest({action: 'fetchurl', id: token, url: url}, null, false, null);
    const response = parseJson(result.text);
    if (result.status !== 200 || response === null || response.error !== undefined) {
        throw new Error(response && response.error ? response.error : await getString('errordownloadfailed', 'repository_largefile'));
    }
    return true;
};

/**
 * Open the upload dialogue.
 *
 * @param {object} data The pubsub payload: {repoId, contextId, callback}.
 * @return {Promise} Resolves once the modal is created.
 */
const openUploadModal = async(data) => {
    const body = await Templates.render('repository_largefile/upload_dialogue', {});
    const modal = await SaveCancelModal.create({
        title: getString('pluginname', 'repository_largefile'),
        body: body,
        large: true,
        buttons: {save: getString('addfile', 'repository_largefile')},
    });

    const root = modal.getRoot();
    let selectedFile = null;
    let busy = false;

    const el = (selector) => root.find(selector).get(0);
    const setStatus = (text) => {
        const status = el('[data-region="status"]');
        if (status) {
            status.textContent = text;
        }
    };
    const setProgress = (loaded, total) => {
        const bar = el('[data-region="progressbar"]');
        if (bar) {
            const pct = total > 0 ? Math.round(loaded * 100 / total) : 0;
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
        }
    };

    root.on(ModalEvents.shown, () => {
        selectedFile = null;
        busy = false;
        setProgress(0, 1);
        const input = el('[data-region="fileinput"]');
        if (input) {
            input.addEventListener('change', () => {
                selectedFile = input.files.length ? input.files[0] : null;
                setStatus(selectedFile ? selectedFile.name : '');
            });
        }
    });

    root.on(ModalEvents.hidden, () => modal.destroy());

    root.on(ModalEvents.save, async(e) => {
        e.preventDefault();
        if (busy) {
            return;
        }
        // The active tab decides which source we commit.
        const urlTabActive = root.find('[data-region="tab-url"]').hasClass('active');
        try {
            busy = true;
            if (urlTabActive) {
                const urlInput = el('[data-region="urlinput"]');
                const url = urlInput ? urlInput.value.trim() : '';
                if (!url) {
                    busy = false;
                    return;
                }
                setStatus(await getString('uploading', 'repository_largefile'));
                const token = await newToken(data.contextId);
                await fetchUrl(url, token.id);
            } else {
                if (!selectedFile) {
                    busy = false;
                    return;
                }
                const token = await newToken(data.contextId);
                if (token.maxbytes > 0 && selectedFile.size > token.maxbytes) {
                    throw new Error(await getString('errordownloadtoobig', 'repository_largefile'));
                }
                setStatus(await getString('uploading', 'repository_largefile'));
                await uploadFileChunked(selectedFile, token.id, token.chunksize, setProgress);
            }
            modal.hide();
            data.callback();
        } catch (error) {
            busy = false;
            setProgress(0, 1);
            const strings = await Promise.all([getString('error', 'core'), getString('ok', 'core')]);
            Notification.alert(strings[0], error.message, strings[1]);
        }
    });

    modal.show();
    return modal;
};

/**
 * Register the pubsub listener once.
 *
 * @return {void}
 */
const registerEventListeners = () => {
    if (!listenersRegistered) {
        subscribe('repository_largefile_upload', (data) => {
            openUploadModal(data);
        });
        listenersRegistered = true;
    }
};

/**
 * Initialise the upload module.
 *
 * @return {void}
 */
export const init = () => {
    registerEventListeners();
};
