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
 * Plugin version and metadata.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_canvasuplifter';
$plugin->version   = 2026081447;      // YYYYMMDDXX. This release.
$plugin->requires  = 2025041400;      // Moodle 5.0.0 (the lowest supported release).
$plugin->supported = [500, 502];      // Supports Moodle 5.0 to 5.2 inclusive.
$plugin->maturity  = MATURITY_ALPHA;  // Recover every file of a multi-file dependency as one resource (#163).
$plugin->release   = '0.73.1';
