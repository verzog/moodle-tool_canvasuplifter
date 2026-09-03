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
 * Admin settings for repository_largefile (chunk size and upload retention).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'repository_largefile/chunkheading',
        get_string('settings', 'repository_largefile'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'repository_largefile/chunksize',
        new lang_string('setting:chunksize', 'repository_largefile'),
        new lang_string('setting:chunksize_desc', 'repository_largefile'),
        20,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configduration(
        'repository_largefile/state0duration',
        new lang_string('setting:state0duration', 'repository_largefile'),
        new lang_string('setting:state0duration_desc', 'repository_largefile'),
        3600,
        3600
    ));
    $settings->add(new admin_setting_configduration(
        'repository_largefile/state1duration',
        new lang_string('setting:state1duration', 'repository_largefile'),
        new lang_string('setting:state1duration_desc', 'repository_largefile'),
        3600,
        3600
    ));
    $settings->add(new admin_setting_configduration(
        'repository_largefile/state2duration',
        new lang_string('setting:state2duration', 'repository_largefile'),
        new lang_string('setting:state2duration_desc', 'repository_largefile'),
        86400,
        86400
    ));
}
