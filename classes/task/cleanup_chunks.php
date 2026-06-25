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
 * Cleanup task for stale chunked-upload tokens and files.
 *
 * Folded in from local_chunkupload (2020 Justus Dieckmann WWU).
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_canvasuplifter\task;

use tool_canvasuplifter\chunkupload\form_element;
use tool_canvasuplifter\chunkupload\state_type;

/**
 * Cleanup task for stale chunked-upload tokens and files.
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_chunks extends \core\task\scheduled_task {
    /**
     * Returns the name of the cron task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanup_task', 'tool_canvasuplifter');
    }

    /**
     * Cleans up old chunked-upload files and records.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $config = get_config('tool_canvasuplifter');
        $state0duration = $config->state0duration ?? 3600;
        $state1duration = $config->state1duration ?? 3600;
        $state2duration = $config->state2duration ?? 86400;

        // State UNUSED_TOKEN_GENERATED (0): only a row, no file on disk yet.
        $DB->delete_records_select(
            'tool_canvasuplifter_chunks',
            'state = :state AND lastmodified < :time',
            ['time' => time() - $state0duration, 'state' => state_type::UNUSED_TOKEN_GENERATED]
        );

        // State UPLOAD_STARTED (1): partial upload abandoned.
        $ids = $DB->get_fieldset_select(
            'tool_canvasuplifter_chunks',
            'id',
            'lastmodified < :time AND state = :state',
            ['time' => time() - $state1duration,
            'state' => state_type::UPLOAD_STARTED,
            ]
        );
        $DB->delete_records_list('tool_canvasuplifter_chunks', 'id', $ids);
        foreach ($ids as $id) {
            $path = form_element::get_path_for_id($id);
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }

        // State UPLOAD_COMPLETED (2): completed but never consumed.
        $ids = $DB->get_fieldset_select(
            'tool_canvasuplifter_chunks',
            'id',
            'lastmodified < :time AND state = :state',
            ['time' => time() - $state2duration,
            'state' => state_type::UPLOAD_COMPLETED,
            ]
        );
        $DB->delete_records_list('tool_canvasuplifter_chunks', 'id', $ids);
        foreach ($ids as $id) {
            $path = form_element::get_path_for_id($id);
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
