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
 * Adds the Canvas Uplifter page under Site administration > Courses.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('courses', new admin_externalpage(
        'tool_canvasuplifter',
        get_string('pluginname', 'tool_canvasuplifter'),
        new moodle_url('/admin/tool/canvasuplifter/index.php'),
        'tool/canvasuplifter:use'
    ));

    // Settings for the chunked-upload field on the package upload form
    // (folded in from the former local_chunkupload plugin).
    $settings = new admin_settingpage(
        'tool_canvasuplifter_settings',
        get_string('settings', 'tool_canvasuplifter')
    );
    $settings->add(new admin_setting_configtext(
        'tool_canvasuplifter/chunksize',
        new lang_string('setting:chunksize', 'tool_canvasuplifter'),
        new lang_string('setting:chunksize_desc', 'tool_canvasuplifter'),
        64,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configduration(
        'tool_canvasuplifter/state0duration',
        new lang_string('setting:state0duration', 'tool_canvasuplifter'),
        new lang_string('setting:state0duration_desc', 'tool_canvasuplifter'),
        3600,
        3600
    ));
    $settings->add(new admin_setting_configduration(
        'tool_canvasuplifter/state1duration',
        new lang_string('setting:state1duration', 'tool_canvasuplifter'),
        new lang_string('setting:state1duration_desc', 'tool_canvasuplifter'),
        3600,
        3600
    ));
    $settings->add(new admin_setting_configduration(
        'tool_canvasuplifter/state2duration',
        new lang_string('setting:state2duration', 'tool_canvasuplifter'),
        new lang_string('setting:state2duration_desc', 'tool_canvasuplifter'),
        86400,
        86400
    ));
    $ADMIN->add('tools', $settings);
}
