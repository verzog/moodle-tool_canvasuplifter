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
 * Derived from local_chunkupload (2020 Justus Dieckmann WWU).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\task;

use repository_largefile\chunk_store;

/**
 * Cleanup task for stale chunked-upload tokens and files.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_chunks extends \core\task\scheduled_task {
    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanup_task', 'repository_largefile');
    }

    /**
     * Removes old chunked-upload files and their tracking rows.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $config = get_config('repository_largefile');
        $state0duration = $config->state0duration ?? 3600;
        $state1duration = $config->state1duration ?? 3600;
        $state2duration = $config->state2duration ?? 86400;

        $this->purge(chunk_store::STATE_UNUSED, (int) $state0duration);
        $this->purge(chunk_store::STATE_STARTED, (int) $state1duration);
        $this->purge(chunk_store::STATE_COMPLETED, (int) $state2duration);
    }

    /**
     * Delete every token in the given state last touched before the cutoff, plus
     * its partial file on disk.
     *
     * @param int $state The chunk_store state to purge.
     * @param int $maxage Maximum age in seconds before a row is removed.
     * @return void
     */
    private function purge(int $state, int $maxage): void {
        global $DB;
        $ids = $DB->get_fieldset_select(
            chunk_store::TABLE,
            'id',
            'lastmodified < :time AND state = :state',
            ['time' => time() - $maxage, 'state' => $state]
        );
        if (!$ids) {
            return;
        }
        $DB->delete_records_list(chunk_store::TABLE, 'id', $ids);
        foreach ($ids as $id) {
            $path = chunk_store::get_path_for_id((string) $id);
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
