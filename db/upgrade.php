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
 * Upgrade steps.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply schema and data upgrades for tool_canvasuplifter.
 *
 * @param int $oldversion Previously-installed version.
 * @return bool
 */
function xmldb_tool_canvasuplifter_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061101) {
        $table = new xmldb_table('tool_canvasuplifter_jobs');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_canvasuplifter_jobs');
        }
        upgrade_plugin_savepoint(true, 2026061101, 'tool', 'canvasuplifter');
    }

    return true;
}
