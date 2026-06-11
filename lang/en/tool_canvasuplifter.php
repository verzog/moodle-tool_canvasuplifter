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

$string['pluginname'] = 'Canvas Uplifter';
$string['canvasuplifter:use'] = 'Upload a Canvas package and view the conversion report';

// Upload form.
$string['packagefile'] = 'Canvas export (.imscc)';
$string['packagefile_help'] = 'Upload a course exported from Canvas as a Common Cartridge file. '
    . 'In Phase 0 the file is only inspected; nothing is created in Moodle.';
$string['analyse'] = 'Analyse package';

// Report.
$string['reportheading'] = 'Conversion report';
$string['coursename'] = 'Course name';
$string['sectioncount'] = 'Sections';
$string['itemcount'] = 'Content items';
$string['colkind'] = 'Content type';
$string['colcount'] = 'Count';
$string['coltarget'] = 'Moodle target';
$string['colconfidence'] = 'Mapping';
$string['warningsheading'] = 'Notes and warnings';
$string['nowarnings'] = 'No warnings. The package looks straightforward to convert.';

// Confidence labels.
$string['confidence_full'] = 'Maps cleanly';
$string['confidence_partial'] = 'Maps, some detail may be lost';
$string['confidence_manual'] = 'Needs manual finishing';
$string['confidence_none'] = 'Cannot map yet';

// Errors.
$string['errornotzip'] = 'That file could not be opened as a Common Cartridge (zip) package.';
$string['errornomanifest'] = 'No imsmanifest.xml was found, so this is not a valid Canvas package.';

// Privacy.
$string['privacy:metadata'] = 'The Canvas Uplifter plugin does not store any personal data. '
    . 'Uploaded packages are inspected and then discarded.';
