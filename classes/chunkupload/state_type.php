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
 * Upload states for the chunked-upload field.
 *
 * Folded in from local_chunkupload (2020 Laura Troost, Nina Herrmann WWU).
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Laura Troost, Nina Herrmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_canvasuplifter\chunkupload;

/**
 * Upload states for the chunked-upload field.
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Laura Troost, Nina Herrmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_type {
    /** @var int Token generated when the field rendered, no upload yet. */
    const UNUSED_TOKEN_GENERATED = 0;

    /** @var int Upload has started but not all chunks have arrived. */
    const UPLOAD_STARTED = 1;

    /** @var int All chunks received; the file is complete. */
    const UPLOAD_COMPLETED = 2;
}
