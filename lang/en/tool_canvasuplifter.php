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

$string['additionalresources'] = 'Additional resources';
$string['analyse'] = 'Analyse package';
$string['analyseanother'] = 'Analyse another package';
$string['buildcourse'] = 'Build course';
$string['buildsnow_later'] = 'Later phase';
$string['buildsnow_yes'] = 'Yes';
$string['buildsnowsummary'] = '{$a->now} items will be built into the course now; '
    . '{$a->later} are reported for a later phase and will be skipped.';
$string['buildstatusheading'] = 'Build status';
$string['builtcoursesummary'] = 'Built {$a->created} of {$a->itemcount} content items across '
    . '{$a->sectioncount} sections ({$a->skipped} skipped).';
$string['canvasuplifter:use'] = 'Upload a Canvas package and view the conversion report';
$string['colbuildsnow'] = 'Builds now';
$string['colconfidence'] = 'Mapping';
$string['colcount'] = 'Count';
$string['colcreated'] = 'Created';
$string['colkind'] = 'Content type';
$string['colnote'] = 'Notes';
$string['colresourcetype'] = 'Common Cartridge resource type';
$string['colskipped'] = 'Skipped';
$string['coltarget'] = 'Moodle target';
$string['coltitle'] = 'Title';
$string['confidence_full'] = 'Maps cleanly';
$string['confidence_manual'] = 'Needs manual finishing';
$string['confidence_none'] = 'Cannot map yet';
$string['confidence_partial'] = 'Maps, some detail may be lost';
$string['coursename'] = 'Course name';
$string['defaultcoursename'] = 'Imported Canvas course';
$string['errorbadmanifestxml'] = 'The imsmanifest.xml file could not be parsed as XML.';
$string['errorbadurl'] = 'The download URL must start with http:// or https://.';
$string['errorbothsources'] = 'Provide either a file or a URL, not both.';
$string['errordownloadfailed'] = 'The Canvas package could not be downloaded from that URL.';
$string['errordownloadtoobig'] = 'The downloaded file is larger than the site upload limit.';
$string['errorjobnotfound'] = 'That build job no longer exists.';
$string['errornomanifest'] = 'No imsmanifest.xml was found, so this is not a valid Canvas package.';
$string['errornosource'] = 'Provide a Canvas export file or a download URL.';
$string['errornotzip'] = 'That file could not be opened as a Common Cartridge (zip) package.';
$string['itemcount'] = 'Content items';
$string['itemdetailheading'] = 'Item-by-item detail';
$string['jobstatusis'] = 'Status: {$a}.';
$string['note_assignment'] = 'Name, instructions and due dates will convert; rubrics and advanced grading will not.';
$string['note_discussion'] = 'Discussion topics will become forums; not every thread or reply may carry across.';
$string['note_file'] = 'Files convert directly to file resources.';
$string['note_lti'] = 'Imported as a placeholder; keys and secrets must be reconfigured per site.';
$string['note_page'] = 'Pages convert, including embedded images and internal links to other '
    . 'pages, files and URLs. Links to content types that are not built yet are left unchanged.';
$string['note_questionbank'] = 'Imported as question bank (mod_qbank) activities.';
$string['note_quiz'] = 'Becomes a Moodle quiz; questions convert where the QTI question type is supported.';
$string['note_unknown'] = 'Resource type not recognised; these are skipped.';
$string['note_url'] = 'External links convert directly.';
$string['nowarnings'] = 'No warnings. The package looks straightforward to convert.';
$string['openbuiltcourse'] = 'Open the built course';
$string['orphansexplain'] = 'These resources are in the package but are not linked from any module. '
    . 'They are imported into an "Additional resources" section so nothing is lost.';
$string['orphansheading'] = 'Unreferenced resources';
$string['packagefile'] = 'Canvas export (.imscc)';
$string['packagefile_help'] = 'Upload a course exported from Canvas as a Common Cartridge file. '
    . 'Analyse-only inspects the file; Build course creates a new Moodle course.';
$string['packageurl'] = 'Download URL';
$string['packageurl_help'] = 'Alternatively, paste an HTTPS link to a Canvas .imscc file '
    . '(for example a signed S3 link or a direct download URL). The site upload limit applies. '
    . 'Note: signed CloudFront / S3 URLs are often IP-pinned and will fail when fetched server-side.';
$string['pluginname'] = 'Canvas Uplifter';
$string['privacy:jobspath'] = 'Canvas Uplifter build jobs';
$string['privacy:metadata:tool_canvasuplifter_jobs'] = 'Details of each Canvas-to-Moodle build run started by a user.';
$string['privacy:metadata:tool_canvasuplifter_jobs:categoryid'] = 'The course category the build targeted.';
$string['privacy:metadata:tool_canvasuplifter_jobs:courseid'] = 'The course created by the build, if any.';
$string['privacy:metadata:tool_canvasuplifter_jobs:errormsg'] = 'Any error message recorded if the build failed.';
$string['privacy:metadata:tool_canvasuplifter_jobs:status'] = 'The state of the build (queued, running, done or failed).';
$string['privacy:metadata:tool_canvasuplifter_jobs:timecreated'] = 'The time the build was started.';
$string['privacy:metadata:tool_canvasuplifter_jobs:timemodified'] = 'The time the build was last updated.';
$string['privacy:metadata:tool_canvasuplifter_jobs:userid'] = 'The user who started the build.';
$string['progresscoursecreated'] = 'Course created. Building activities…';
$string['progressextract'] = 'Extracting package…';
$string['progressitem'] = 'Built {$a->done} of {$a->total} items ({$a->kind})…';
$string['progressparse'] = 'Parsing manifest…';
$string['readytobuildexplain'] = 'The package has been analysed and is held ready. '
    . 'Choose a category and click Build course to create the course in Moodle.';
$string['readytobuildheading'] = 'Build this course';
$string['reportheading'] = 'Conversion report';
$string['sectioncount'] = 'Sections';
$string['sectionitemscount'] = '{$a->title} ({$a->count} items)';
$string['skipreasonsheading'] = 'Skip reasons (debug)';
$string['status_done'] = 'Done';
$string['status_failed'] = 'Failed';
$string['status_queued'] = 'Queued';
$string['status_running'] = 'Running';
$string['syllabuspage'] = 'Syllabus';
$string['targetcategory'] = 'Target course category';
$string['targetcategory_help'] = 'The new course will be created in this category. '
    . 'You need the "create courses" capability in the chosen category.';
$string['unknownheading'] = 'Unclassified resource types (debug)';
$string['untitledsection'] = 'Untitled section';
$string['warningsheading'] = 'Notes and warnings';
$string['warningskippedfornow'] = '{$a} content items were not created (their content type is not yet supported by the builder).';
$string['warningunclassified'] = '{$a} unclassified resources will be skipped.';
$string['warnreportlti'] = 'External (LTI) tools need their keys reconfigured by hand after import.';
$string['warnreportquiz'] = 'Quiz questions depend on type support; check the question-type matrix.';
$string['warnreportunclassified'] = 'Some resources could not be classified and will be skipped.';
