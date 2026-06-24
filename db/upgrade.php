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

    if ($oldversion < 2026061104) {
        $table = new xmldb_table('tool_canvasuplifter_jobs');

        $progress = new xmldb_field('progress', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'errormsg');
        if (!$dbman->field_exists($table, $progress)) {
            $dbman->add_field($table, $progress);
        }

        $progressmessage = new xmldb_field('progressmessage', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'progress');
        if (!$dbman->field_exists($table, $progressmessage)) {
            $dbman->add_field($table, $progressmessage);
        }

        upgrade_plugin_savepoint(true, 2026061104, 'tool', 'canvasuplifter');
    }

    if ($oldversion < 2026062405) {
        $table = new xmldb_table('tool_canvasuplifter_jobs');

        // Whether a run produces a report (analyse) or a course (build).
        // Existing rows are builds, which is the default.
        $kind = new xmldb_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'build', 'userid');
        if (!$dbman->field_exists($table, $kind)) {
            $dbman->add_field($table, $kind);
        }

        // Remote package URL fetched in the task, for runs started from a URL.
        $packageurl = new xmldb_field('packageurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'fileid');
        if (!$dbman->field_exists($table, $packageurl)) {
            $dbman->add_field($table, $packageurl);
        }

        // A URL run has no stored file until its task fetches one, so fileid
        // becomes nullable.
        $fileid = new xmldb_field('fileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'categoryid');
        if ($dbman->field_exists($table, $fileid)) {
            $dbman->change_field_notnull($table, $fileid);
        }

        upgrade_plugin_savepoint(true, 2026062405, 'tool', 'canvasuplifter');
    }

    return true;
}
