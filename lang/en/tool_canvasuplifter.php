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
$string['analysestatusheading'] = 'Analysis status';
$string['buildcourse'] = 'Build course';
$string['buildsnow_later'] = 'Later phase';
$string['buildsnow_yes'] = 'Yes';
$string['buildsnowsummary'] = '{$a->now} items will be built into the course now; '
    . '{$a->later} are reported for a later phase and will be skipped.';
$string['buildstatusheading'] = 'Build status';
$string['builtcoursesummary'] = 'Built {$a->created} of {$a->itemcount} content items across '
    . '{$a->sectioncount} sections ({$a->skipped} skipped).';
$string['canvasuplifter:use'] = 'Upload a Canvas package and view the conversion report';
$string['chosenbuildoptions'] = 'Page grouping (fixed when the package was analysed) — combine consecutive '
    . 'pages: {$a->grouping}.';
$string['chunkuploadactive'] = 'For a very large package, use the optional "Large package (chunked upload)" '
    . 'field below — it uploads in chunks and isn\'t limited by this server\'s PHP upload size.';
$string['cleanup_task'] = 'Clean up stale chunked uploads';
$string['colbuildsnow'] = 'Builds now';
$string['colconfidence'] = 'Mapping';
$string['colcount'] = 'Count';
$string['colcreated'] = 'Created';
$string['colkind'] = 'Content type';
$string['colnote'] = 'Notes';
$string['colplacement'] = 'Placed in';
$string['colresourcetype'] = 'Common Cartridge resource type';
$string['colskipped'] = 'Skipped';
$string['coltarget'] = 'Moodle target';
$string['coltitle'] = 'Title';
$string['confidence_full'] = 'Maps cleanly';
$string['confidence_manual'] = 'Needs manual finishing';
$string['confidence_none'] = 'Cannot map yet';
$string['confidence_partial'] = 'Maps, some detail may be lost';
$string['coursename'] = 'Course name';
$string['defaultbookname'] = 'Course pages';
$string['defaultcoursename'] = 'Imported course';
$string['defaultcoursenamesource'] = 'Imported {$a} course';
$string['defaultlessonname'] = 'Course pages';
$string['deletefile'] = 'Delete file from Moodle';
$string['detectedsource'] = 'Detected source: {$a}';
$string['errorbadmanifestxml'] = 'The imsmanifest.xml file could not be parsed as XML.';
$string['errorbadurl'] = 'The download URL must start with http:// or https://.';
$string['errorbothsources'] = 'Provide just one package source, not several.';
$string['errorchunktoolarge'] = 'The server rejected an upload chunk as too large. Ask an administrator to lower the '
    . '"Chunk size (MB)" setting, then upload the package again.';
$string['errordownloadfailed'] = 'The Canvas package could not be downloaded from that URL.';
$string['errordownloadjspage'] = 'That page builds its download links with JavaScript (a DSpace repository), so the file link is not in the page we receive, and the repository\'s API did not return a Common Cartridge file. Open the page in your browser, then copy the direct file link under "Files" (it looks like .../bitstreams/<id>/download) and paste that here.';
$string['errordownloadnopackage'] = 'That URL did not return a Common Cartridge package. If it is a repository landing page, link directly to the .imscc download.';
$string['errordownloadtoobig'] = 'The downloaded file is larger than the site upload limit.';
$string['errorjobnotfound'] = 'That build job no longer exists.';
$string['errornomanifest'] = 'No imsmanifest.xml was found, so this is not a valid Canvas package.';
$string['errornosource'] = 'Provide a Canvas export file or a download URL.';
$string['errornotzip'] = 'That file could not be opened as a Common Cartridge (zip) package.';
$string['errorpackagetoolarge'] = 'That package is too large to extract safely (it expands past the allowed size or file count).';
$string['erroruploadfailed'] = 'The upload could not be completed after several attempts. Check your connection and try '
    . 'uploading the package again.';
$string['extraquizzesbuilt'] = 'Also built {$a} runnable quiz(zes) from standalone question banks (in "Additional resources").';
$string['itemcount'] = 'Content items';
$string['itemdetailheading'] = 'Item-by-item detail';
$string['jobstatusis'] = 'Status: {$a}.';
$string['lti_placeholder_note'] = 'Imported as a hidden placeholder from a Canvas LTI link. Configure or replace the external tool (set the consumer key and shared secret, or pick a preconfigured tool) and unhide the activity before students use it.';
$string['matrixcolsupported'] = 'Converts';
$string['matrixcoltype'] = 'Question type';
$string['matrixexplain'] = '{$a->supported} of {$a->total} questions will convert. '
    . 'Questions of a supported type that are missing data Moodle needs (for example a single-option '
    . 'question we could not complete) are listed by type; types we cannot map are listed by their '
    . 'Canvas profile. Both are skipped.';
$string['matrixheading'] = 'Question-type matrix';
$string['matrixskippedfrom'] = 'from {$a}';
$string['matrixsupported_incomplete'] = 'Skipped (incomplete)';
$string['matrixsupported_no'] = 'Skipped';
$string['matrixsupported_yes'] = 'Yes';
$string['maxsize'] = 'Maximum file size: {$a}';
$string['note_assignment'] = 'Name, instructions, due dates and Canvas rubrics (including per-rating long descriptions) convert. CC 1.3 IMS assignment-profile packages from non-Canvas exporters are also recognised. Outcome links do not carry across.';
$string['note_discussion'] = 'Discussion topics become forums with the prompt as the opening post; Canvas does not export the replies, so existing threads do not carry across.';
$string['note_file'] = 'Files convert directly to file resources.';
$string['note_file_obsolete'] = 'Imported as a file, but this is a Flash (.swf) resource that modern '
    . 'browsers no longer play, so it will not function.';
$string['note_lti'] = 'Imported as a hidden placeholder; the admin must reconfigure the consumer key and shared secret (or pick a preconfigured tool) and unhide the activity before students use it.';
$string['note_page'] = 'Pages convert, including embedded images and internal links to other '
    . 'pages, files and URLs. Links to content types that are not built yet are left unchanged.';
$string['note_page_grouped_book'] = 'Combined with their neighbouring pages into a single book (mod_book), '
    . 'one chapter per page, because you chose to combine consecutive pages.';
$string['note_page_grouped_lesson'] = 'Combined with their neighbouring pages into a single lesson (mod_lesson), '
    . 'one page per page, because you chose to combine consecutive pages.';
$string['note_questionbank'] = 'Imported as question bank (mod_qbank) activities.';
$string['note_quiz'] = 'Becomes a Moodle quiz; the Canvas time limit, attempts, scoring, availability dates, '
    . 'navigation and password carry over, and questions convert where the QTI question type is supported.';
$string['note_subheader'] = 'Canvas module subheaders become Moodle labels with the heading as the body.';
$string['note_unknown'] = 'Resource type not recognised; these are skipped.';
$string['note_url'] = 'External links convert directly.';
$string['notecanvasboilerplate'] = 'Dropped {$a} Canvas platform item(s) with no Moodle equivalent (help-guide '
    . 'links to Canvas\'s documentation, and leftover ANGEL migration objects).';
$string['notegradeletters'] = 'Imported {$a} letter-grade boundaries from the Canvas grading scheme as this '
    . 'course\'s grade letters.';
$string['noteoutcomesimported'] = 'Imported {$a} Canvas learning outcome(s) as course grade outcomes, each with a '
    . 'scale built from its mastery ratings. Outcomes stay hidden until the site\'s "Enable outcomes" advanced '
    . 'setting is turned on.';
$string['noterubricsunlinked'] = 'Found {$a} rubric(s) in the package not linked to any activity, so they could '
    . 'not be imported; recreate them on the relevant activities if needed.';
$string['nowarnings'] = 'No warnings. The package looks straightforward to convert.';
$string['openbuiltcourse'] = 'Open the built course';
$string['orphansexplain'] = 'These resources are in the package but are not linked from any module. '
    . 'Each is still imported — most into an "Additional resources" section, with the syllabus '
    . 'surfaced at the top of the course — so nothing is lost.';
$string['orphansheading'] = 'Unreferenced resources';
$string['outcomesheading'] = 'Learning outcomes';
$string['outcomesmalformed'] = 'The learning_outcomes.xml file is present but could not be read (it appears truncated '
    . 'or malformed), so no outcomes can be imported from it. Re-export the package if the outcomes matter.';
$string['outcomessummary'] = '{$a->total} Canvas learning outcome(s) found; {$a->importable} will import as course '
    . 'grade outcomes and {$a->skipped} will be skipped (their ratings do not form a usable scale). Imported '
    . 'outcomes stay hidden until the site\'s "Enable outcomes" advanced setting is turned on.';
$string['outcomeuntitled'] = 'Untitled outcome';
$string['packagefile'] = 'Canvas export (.imscc)';
$string['packagefile_help'] = 'Upload a course exported from Canvas as a Common Cartridge file. '
    . 'Analyse-only inspects the file; Build course creates a new Moodle course.';
$string['packagelargefile'] = 'Large package (chunked upload)';
$string['packagelargefile_help'] = 'Optional. For a package larger than this server\'s upload limit, '
    . 'use this field instead of the file picker above — it uploads the file in small chunks, so PHP\'s '
    . 'per-request upload size doesn\'t apply. Leave it empty to use the file picker or a download URL.';
$string['packageurl'] = 'Download URL';
$string['packageurl_help'] = 'Alternatively, paste an HTTPS link to a Canvas .imscc file '
    . '(for example a signed S3 link or a direct download URL). The site upload limit applies. '
    . 'Note: signed CloudFront / S3 URLs are often IP-pinned and will fail when fetched server-side.';
$string['pagegrouping'] = 'Combine consecutive pages';
$string['pagegrouping_book'] = 'Into a book (mod_book)';
$string['pagegrouping_help'] = 'Canvas wiki pages normally build as one Page activity (mod_page) each, which can leave a long run of separate pages. Choose Book or Lesson to combine each run of two or more consecutive pages into a single activity — one book chapter or lesson page per Canvas page — named after its section. A lone page between other activities stays a Page. Links between the combined pages are rewritten to point at the right chapter/page. Choose this before analysing and the report reflects it, showing those pages building into a book or lesson.';
$string['pagegrouping_lesson'] = 'Into a lesson (mod_lesson)';
$string['pagegrouping_none'] = 'No — one page activity each';
$string['placement_extras'] = 'Additional resources section';
$string['placement_top'] = 'Top of course';
$string['pluginname'] = 'Canvas Uplifter';
$string['privacy:chunkspath'] = 'Chunked uploads';
$string['privacy:jobspath'] = 'Canvas Uplifter build jobs';
$string['privacy:metadata:tool_canvasuplifter_chunks'] = 'Temporary records of large packages uploaded in chunks before they are saved.';
$string['privacy:metadata:tool_canvasuplifter_chunks:contextid'] = 'The context in which the file was uploaded.';
$string['privacy:metadata:tool_canvasuplifter_chunks:filename'] = 'The name of the uploaded file.';
$string['privacy:metadata:tool_canvasuplifter_chunks:lastmodified'] = 'The time the upload was last modified.';
$string['privacy:metadata:tool_canvasuplifter_chunks:userid'] = 'The user who uploaded the file.';
$string['privacy:metadata:tool_canvasuplifter_jobs'] = 'Details of each Canvas-to-Moodle build run started by a user.';
$string['privacy:metadata:tool_canvasuplifter_jobs:categoryid'] = 'The course category the build targeted.';
$string['privacy:metadata:tool_canvasuplifter_jobs:courseid'] = 'The course created by the build, if any.';
$string['privacy:metadata:tool_canvasuplifter_jobs:errormsg'] = 'Any error message recorded if the build failed.';
$string['privacy:metadata:tool_canvasuplifter_jobs:packageurl'] = 'The download URL of the package, when the run was started from a URL rather than an upload.';
$string['privacy:metadata:tool_canvasuplifter_jobs:status'] = 'The state of the build (queued, running, done or failed).';
$string['privacy:metadata:tool_canvasuplifter_jobs:timecreated'] = 'The time the build was started.';
$string['privacy:metadata:tool_canvasuplifter_jobs:timemodified'] = 'The time the build was last updated.';
$string['privacy:metadata:tool_canvasuplifter_jobs:userid'] = 'The user who started the build.';
$string['progresscoursecreated'] = 'Course created. Building activities…';
$string['progressextract'] = 'Extracting package…';
$string['progressfetch'] = 'Downloading package…';
$string['progressitem'] = 'Built {$a->done} of {$a->total} items ({$a->kind})…';
$string['progressparse'] = 'Parsing manifest…';
$string['qtype_essay'] = 'Essay';
$string['qtype_multianswer'] = 'Multiple response';
$string['qtype_multichoice'] = 'Multiple choice';
$string['qtype_shortanswer'] = 'Fill in the blank / short answer';
$string['qtype_truefalse'] = 'True/false';
$string['quizfrombank'] = 'Also build a runnable quiz from each standalone question bank';
$string['quizfrombank_help'] = 'Standalone assessments (not linked anywhere in the Canvas course) import as reusable question banks. Tick this to also create a runnable quiz from each one, placed in the "Additional resources" section. Quizzes linked within the course are always built as quizzes regardless of this setting.';
$string['quizplaceholderintro'] = '<div class="alert alert-warning">This quiz was imported from Canvas '
    . '<strong>without its questions</strong>. Canvas exported the quiz settings but not the questions — '
    . 'usually because they live in a New Quizzes item bank that the Common Cartridge export leaves out. '
    . 'Add the questions here, then make this activity visible to students. To recover the originals, '
    . 're-export from Canvas with item banks included, or convert the New Quiz to a Classic Quiz first.</div>';
$string['readytobuildexplain'] = 'The package has been analysed and is held ready. '
    . 'Choose a category and click Build course to create the course in Moodle.';
$string['readytobuildheading'] = 'Build this course';
$string['reportheading'] = 'Conversion report';
$string['sectioncount'] = 'Sections';
$string['sectionitemscount'] = '{$a->title} ({$a->count} items)';
$string['setting:chunksize'] = 'Chunk size (MB)';
$string['setting:chunksize_desc'] = 'Size of each chunk sent to the server when uploading a large package, in megabytes. '
    . 'Lower this if large uploads fail — some web servers, reverse proxies and firewalls reject large request bodies.';
$string['setting:state0duration'] = 'Keep unused upload tokens for';
$string['setting:state0duration_desc'] = 'How long an upload token that was generated but never used is kept before the cleanup task removes it.';
$string['setting:state1duration'] = 'Keep unfinished uploads for';
$string['setting:state1duration_desc'] = 'How long a partially uploaded file is kept before the cleanup task removes it.';
$string['setting:state2duration'] = 'Keep completed uploads for';
$string['setting:state2duration_desc'] = 'How long a completed upload that was never consumed is kept before the cleanup task removes it.';
$string['settings'] = 'Chunked upload settings';
$string['skipreasonsheading'] = 'Skip reasons (debug)';
$string['source_angel'] = 'ANGEL (eXe learning modules)';
$string['source_blackboard'] = 'Blackboard Learn';
$string['source_canvas'] = 'Canvas';
$string['source_d2l'] = 'D2L Brightspace';
$string['source_exe'] = 'eXe';
$string['source_generic'] = 'Common Cartridge';
$string['status_done'] = 'Done';
$string['status_failed'] = 'Failed';
$string['status_queued'] = 'Queued';
$string['status_running'] = 'Running';
$string['syllabuspage'] = 'Syllabus';
$string['targetcategory'] = 'Target course category';
$string['targetcategory_help'] = 'The new course will be created in this category. '
    . 'You need the "create courses" capability in the chosen category.';
$string['taskanalyse'] = 'Analyse Canvas package';
$string['taskbuild'] = 'Build course from Canvas package';
$string['tokenexpired'] = 'The upload token has expired. Try refreshing the page to receive a new one.';
$string['trustedsourcewarning'] = 'Only import Common Cartridge packages from a source you trust. '
    . 'Imported page and description HTML is stored and shown as authored (Moodle does not re-clean it), '
    . 'so a package from an untrusted source could contain active content that runs for anyone viewing the course.';
$string['unknownheading'] = 'Unclassified resource types (debug)';
$string['untitledsection'] = 'Untitled section';
$string['uploaded'] = 'File uploaded';
$string['uploadnotfinished'] = 'Upload did not finish!';
$string['warningsheading'] = 'Notes and warnings';
$string['warningskippedfornow'] = '{$a} content items were not created (their content type is not yet supported by the builder).';
$string['warningunclassified'] = '{$a} unclassified resources will be skipped.';
$string['warnoutcomesmalformed'] = 'The package\'s learning_outcomes.xml could not be read (it appears truncated or '
    . 'malformed), so no learning outcomes were imported from it. Re-export the package if the outcomes matter.';
$string['warnoutcomesskipped'] = 'Skipped {$a} Canvas learning outcome(s) whose mastery ratings did not form a '
    . 'usable scale (Moodle scales need at least two distinct rating labels); recreate those outcomes by hand '
    . 'if needed.';
$string['warnquizplaceholders'] = '{$a} quiz(zes) were imported as hidden placeholders because Canvas '
    . 'did not export their questions (e.g. New Quizzes backed by an item bank). Their settings carried '
    . 'over; add questions and unhide them.';
$string['warnreportduplicates'] = 'Some resources look like duplicate copies of other files in the package '
    . '(for example "name (2)" or "name-1"); all are imported, so review the resource list for repeats.';
$string['warnreportlti'] = 'External (LTI) tools need their keys reconfigured by hand after import.';
$string['warnreportobsolete'] = 'Some resources are Flash (.swf) or other obsolete formats that modern browsers '
    . 'no longer play; they are imported but will not function.';
$string['warnreportquiz'] = 'Quiz questions depend on type support; check the question-type matrix.';
$string['warnreportquizfrombank'] = 'Some assessments are not linked anywhere in the course, so they import as reusable question banks only. To also create a runnable quiz from each, tick "Also build a runnable quiz from each standalone question bank" before building.';
$string['warnreportunclassified'] = 'Some resources could not be classified and will be skipped.';
