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
 * Uninstall steps for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove staged chunk files left under dataroot when the plugin is uninstalled.
 *
 * The partial and completed uploads are stored directly under dataroot (outside
 * Moodle's file API), so Moodle's component-file cleanup cannot find them once
 * the tracking table and cleanup task are gone. Delete the directory here so no
 * user data or disk usage is left behind.
 *
 * @return bool Always true.
 */
function xmldb_repository_largefile_uninstall() {
    global $CFG;
    $dir = $CFG->dataroot . '/repository_largefile';
    if (is_dir($dir)) {
        remove_dir($dir);
    }
    return true;
}
