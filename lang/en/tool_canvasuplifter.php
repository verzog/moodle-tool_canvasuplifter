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
$string['buildcourse'] = 'Build course';
$string['buildstatusheading'] = 'Build status';
$string['builtcoursesummary'] = 'Built {$a->sectioncount} sections covering {$a->itemcount} items '
    . '({$a->skipped} items not yet created — coming in subsequent phases).';
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
$string['defaultcoursename'] = 'Imported Canvas course';
$string['errorbadurl'] = 'The download URL must start with http:// or https://.';
$string['errorbothsources'] = 'Provide either a file or a URL, not both.';
$string['errordownloadfailed'] = 'The Canvas package could not be downloaded from that URL.';
$string['errordownloadtoobig'] = 'The downloaded file is larger than the site upload limit.';
$string['errorjobnotfound'] = 'That build job no longer exists.';
$string['errornomanifest'] = 'No imsmanifest.xml was found, so this is not a valid Canvas package.';
$string['errornosource'] = 'Provide a Canvas export file or a download URL.';
$string['errornotzip'] = 'That file could not be opened as a Common Cartridge (zip) package.';
$string['itemcount'] = 'Content items';
$string['jobstatusis'] = 'Status: {$a}.';
$string['nowarnings'] = 'No warnings. The package looks straightforward to convert.';
$string['openbuiltcourse'] = 'Open the built course';
$string['packagefile'] = 'Canvas export (.imscc)';
$string['packagefile_help'] = 'Upload a course exported from Canvas as a Common Cartridge file. '
    . 'Analyse-only inspects the file; Build course creates a new Moodle course.';
$string['packageurl'] = 'Download URL';
$string['packageurl_help'] = 'Alternatively, paste an HTTPS link to a Canvas .imscc file '
    . '(for example a signed S3 link or a direct download URL). The site upload limit applies. '
    . 'Note: signed CloudFront / S3 URLs are often IP-pinned and will fail when fetched server-side.';
$string['pluginname'] = 'Canvas Uplifter';
$string['privacy:metadata'] = 'The Canvas Uplifter plugin does not store any personal data. '
    . 'Uploaded packages are inspected, used to build a course, and then discarded.';
$string['reportheading'] = 'Conversion report';
$string['sectioncount'] = 'Sections';
$string['status_done'] = 'Done';
$string['status_failed'] = 'Failed';
$string['status_queued'] = 'Queued';
$string['status_running'] = 'Running';
$string['targetcategory'] = 'Target course category';
$string['targetcategory_help'] = 'The new course will be created in this category. '
    . 'You need the "create courses" capability in the chosen category.';
$string['unknownheading'] = 'Unclassified resource types (debug)';
$string['warningsheading'] = 'Notes and warnings';
$string['warningskippedfornow'] = '{$a} content items were not created (their content type is not yet supported by the builder).';
$string['warningunclassified'] = '{$a} unclassified resources will be skipped.';
