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
 * Language strings for tool_canvasuplifter (Australian English).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['analyse'] = 'Analyse package';
$string['canvasuplifter:use'] = 'Upload a Canvas package and view the conversion report';
$string['colconfidence'] = 'Mapping';
$string['colcount'] = 'Count';
$string['colkind'] = 'Content type';
$string['colresourcetype'] = 'Common Cartridge resource type';
$string['coltarget'] = 'Moodle target';
$string['confidence_full'] = 'Maps cleanly';
$string['confidence_manual'] = 'Needs manual finishing';
$string['confidence_none'] = 'Cannot map yet';
$string['confidence_partial'] = 'Maps, some detail may be lost';
$string['coursename'] = 'Course name';
$string['errorbadurl'] = 'The download URL must start with http:// or https://.';
$string['errorbothsources'] = 'Provide either a file or a URL, not both.';
$string['errordownloadfailed'] = 'The Canvas package could not be downloaded from that URL.';
$string['errordownloadtoobig'] = 'The downloaded file is larger than the site upload limit.';
$string['errornomanifest'] = 'No imsmanifest.xml was found, so this is not a valid Canvas package.';
$string['errornosource'] = 'Provide a Canvas export file or a download URL.';
$string['errornotzip'] = 'That file could not be opened as a Common Cartridge (zip) package.';
$string['itemcount'] = 'Content items';
$string['nowarnings'] = 'No warnings. The package looks straightforward to convert.';
$string['packagefile'] = 'Canvas export (.imscc)';
$string['packagefile_help'] = 'Upload a course exported from Canvas as a Common Cartridge file. '
    . 'In Phase 0 the file is only inspected; nothing is created in Moodle.';
$string['packageurl'] = 'Download URL';
$string['packageurl_help'] = 'Alternatively, paste an HTTPS link to a Canvas .imscc file '
    . '(for example a signed S3 link or a direct download URL). The site upload limit applies.';
$string['pluginname'] = 'Canvas Uplifter';
$string['privacy:metadata'] = 'The Canvas Uplifter plugin does not store any personal data. '
    . 'Uploaded packages are inspected and then discarded.';
$string['reportheading'] = 'Conversion report';
$string['sectioncount'] = 'Sections';
$string['unknownheading'] = 'Unclassified resource types (debug)';
$string['warningsheading'] = 'Notes and warnings';
